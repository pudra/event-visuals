<?php

namespace App\Http\Controllers;

use App\Mail\AttendeeConfirmationMail;
use App\Models\Event;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EventController extends Controller
{
    /** Lean column set the listings need, never the 1.5KB payload blob. */
    private const LIST_COLUMNS = [
        'id', 'name', 'description', 'type', 'status', 'city', 'venue_name',
        'min_price', 'latitude', 'longitude', 'created_time',
    ];

    /** Hard cap on map points returned in one request (browser + payload sanity). */
    private const MAP_CAP = 1500;

    /** The only statuses the public browse/detail surfaces, never draft/cancelled. */
    public const PUBLIC_STATUSES = Event::PUBLIC_STATUSES;

    /** Upper bound on stored images per event (the upload endpoint is public). */
    private const MAX_IMAGES_PER_EVENT = 12;

    public function show(Event $event): Response
    {
        // Detail page is the one place we read the full payload.
        return Inertia::render('Events/Show', [
            'event' => array_merge($this->toCard($event->load('images')), [
                'ends_at_iso' => optional($event->endsAt())?->toIso8601String(),
                'payload' => $event->payload,
            ]),
        ]);
    }

    // ---- visual browsing pages (the deliverable) ---------------------------

    /** Paginated card listing for Visual One (grid). */
    public function listing(Request $request): JsonResponse
    {
        if ($invalid = $this->invalidFilters($request)) {
            return $invalid;
        }

        $start = microtime(true);

        $events = $this->filtered($request)
            ->with('images')
            ->select(self::LIST_COLUMNS)
            ->orderBy('created_time')
            ->paginate(24)
            ->withQueryString();

        return response()->json([
            'data' => collect($events->items())->map(fn (Event $e) => $this->toCard($e)),
            'current_page' => $events->currentPage(),
            'last_page' => $events->lastPage(),
            'total' => $events->total(),
            'stats' => ['ms' => (int) round((microtime(true) - $start) * 1000)],
        ]);
    }

    /** Lightweight points for Visual Two (map), capped for browser sanity. */
    public function mapPoints(Request $request): JsonResponse
    {
        if ($invalid = $this->invalidFilters($request)) {
            return $invalid;
        }

        $start = microtime(true);

        // Fetch one past the cap to detect "capped" without a separate full count()
        // over the public partition. The is_public + created_time index serves both
        // the filter and the ORDER BY, so this is a bounded index range scan, not a full sort.
        $rows = $this->filtered($request)
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->with('images')
            ->select(self::LIST_COLUMNS)
            ->orderBy('created_time')
            ->limit(self::MAP_CAP + 1)
            ->get();

        $capped = $rows->count() > self::MAP_CAP;
        $points = $rows->take(self::MAP_CAP)->map(fn (Event $e) => $this->toCard($e))->values();

        return response()->json([
            'points' => $points,
            'shown' => $points->count(),
            'capped' => $capped,
            'cap' => self::MAP_CAP,
            'stats' => ['ms' => (int) round((microtime(true) - $start) * 1000)],
        ]);
    }

    /** Filter options: cities (by frequency), categories, and the date bounds. */
    public function filters(): JsonResponse
    {
        // City frequencies + date bounds change negligibly at this scale, but the
        // group-by/min-max scan the public partition. Cache so it is sub-ms on the
        // common path (it fires on first paint of both visual pages); the backfill
        // forgets this key so a reseed shows fresh options.
        $options = Cache::remember('events:filters:v1', now()->addMinutes(10), function () {
            // Scope on the same is_public boolean the listing/map use, so a sold_out
            // event's city/date is never silently dropped from the filter options.
            $cities = Event::query()
                ->where('is_public', true)
                ->whereNotNull('city')
                ->selectRaw('city, count(*) as c')
                ->groupBy('city')
                ->orderByDesc('c')
                ->limit(60)
                ->pluck('city');

            $bounds = Event::query()
                ->where('is_public', true)
                ->selectRaw('min(created_time) as lo, max(created_time) as hi')
                ->first();

            return [
                'cities' => $cities,
                'categories' => Event::CATEGORIES,
                'date_min' => $bounds?->lo ? date('Y-m-d', (int) $bounds->lo) : null,
                'date_max' => $bounds?->hi ? date('Y-m-d', (int) $bounds->hi) : null,
            ];
        });

        return response()->json($options);
    }

    /** Register interest/attendance and queue a confirmation email. */
    public function register(Request $request, Event $event): JsonResponse
    {
        // Validate explicitly and return JSON 422 directly: these endpoints are
        // hit by fetch(), and the Inertia/web stack would otherwise turn a failed
        // validation into a redirect instead of a JSON error the dialog can read.
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('event_attendees')->where(fn ($q) => $q->where('event_id', $event->id)),
            ],
        ], [
            'email.unique' => "You're already on the list for this event.",
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Stamp confirmation_sent_at in the single insert (not a second write), so the
            // flow can't leave an attendee row with a null stamp if the queue push fails.
            $attendee = $event->attendees()->create($validator->validated() + [
                'confirmation_sent_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two concurrent requests can both clear the unique validator and race to
            // insert; the DB constraint is the backstop. Return the same clean 422.
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['email' => ["You're already on the list for this event."]],
            ], 422);
        }

        Mail::to($attendee->email)->queue(new AttendeeConfirmationMail($attendee));

        return response()->json([
            'ok' => true,
            'message' => "You're on the list. Check your inbox for a confirmation.",
        ]);
    }

    /**
     * Upload one or more images for an event. This is the write half of the
     * end-to-end image support: files are stored on the local 'public' disk (no
     * external/hotlinked URLs) and recorded as owned event_images rows, which then
     * win over the placeholder fallback in Event::displayImages().
     */
    public function storeImage(Request $request, Event $event): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'images' => ['required', 'array', 'min:1', 'max:10'],
            'images.*' => ['image', 'mimes:jpeg,jpg,png,webp,gif', 'max:5120'], // 5 MB each
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Cap stored images per event so the endpoint can't grow storage unbounded.
        if ($event->images()->count() + count($request->file('images')) > self::MAX_IMAGES_PER_EVENT) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['images' => ['This event already has the maximum number of images.']],
            ], 422);
        }

        $position = (int) $event->images()->max('position');
        $created = [];

        foreach ($request->file('images') as $file) {
            $path = $file->store('event-images', 'public');
            $created[] = $event->images()->create(['path' => $path, 'position' => ++$position]);
        }

        return response()->json([
            'ok' => true,
            'images' => collect($created)->map(fn ($i) => ['url' => $i->url]),
        ], 201);
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * Reject filter params that aren't a plain calendar date with an explicit JSON 422
     * (same pattern as register/storeImage; the web stack would otherwise redirect a
     * failed validation instead of returning JSON the fetch caller can read). The looser
     * `date` rule accepts "2024-06-01 12:00:00", which makes the strtotime() parse in
     * filtered() return false and silently drop the date floor, so we pin it to Y-m-d,
     * exactly what the DatePicker emits.
     */
    private function invalidFilters(Request $request): ?JsonResponse
    {
        $validator = Validator::make($request->only('from', 'to'), [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $validator->fails()
            ? response()->json(['message' => 'The given data was invalid.', 'errors' => $validator->errors()], 422)
            : null;
    }

    /**
     * Shared filtered query for the visual pages. Filters only hit lean, indexed
     * columns (is_public, created_time, city, type), never the payload.
     */
    private function filtered(Request $request): Builder
    {
        return Event::query()
            // The public scope is NOT user-controllable: draft/cancelled events are
            // never exposed. is_public is a derived boolean so the equality seek lets
            // the (is_public, created_time) index serve the ORDER BY without a temp b-tree.
            ->where('is_public', true)
            ->when($request->city, fn ($q, $city) => $q->where('city', $city))
            // Only honour a category that is actually one of ours (and lets the type index work).
            ->when(in_array($request->category, Event::CATEGORIES, true), fn ($q) => $q->where('type', $request->category))
            // Default to UPCOMING events; an explicit 'from' lets a user browse the past on purpose.
            ->when(
                $request->from,
                fn ($q, $from) => $q->where('created_time', '>=', strtotime($from.' 00:00:00 UTC')),
                fn ($q) => $q->where('created_time', '>=', now()->timestamp),
            )
            ->when($request->to, fn ($q, $to) => $q->where('created_time', '<=', strtotime($to.' 23:59:59 UTC')));
    }

    /** @return array<string, mixed> */
    private function toCard(Event $event): array
    {
        return [
            'id' => $event->id,
            'name' => $event->name,
            'description' => $event->description,
            'category' => $event->type,
            'status' => $event->status,
            'city' => $event->city,
            'venue_name' => $event->venue_name,
            'min_price' => $event->min_price,
            'lat' => $event->latitude,
            'lng' => $event->longitude,
            'starts_at_iso' => optional($event->startsAt())?->toIso8601String(),
            'images' => $event->displayImages(),
        ];
    }
}

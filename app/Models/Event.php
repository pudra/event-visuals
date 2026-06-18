<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'latitude' => 'float',
        'longitude' => 'float',
        'min_price' => 'float',
    ];

    public const CATEGORIES = ['concert', 'conference', 'meetup', 'workshop', 'festival', 'sports', 'networking', 'exhibition'];

    /** The only statuses the public browse/detail surfaces, never draft/cancelled. */
    public const PUBLIC_STATUSES = ['published', 'sold_out'];

    /**
     * Keep the denormalized public-scope boolean in lockstep with status on every
     * model save, so it can never drift from the source of truth. (The bulk backfill
     * sets it directly since it writes via the query builder, not the model.)
     */
    protected static function booted(): void
    {
        static::saving(function (Event $event): void {
            $event->is_public = in_array($event->status, self::PUBLIC_STATUSES, true);
        });
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<EventImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(EventImage::class)->orderBy('position');
    }

    /** @return HasMany<EventAttendee, $this> */
    public function attendees(): HasMany
    {
        return $this->hasMany(EventAttendee::class);
    }

    /** Event start as a UTC instant. `created_time` is the seeded unix start time. */
    public function startsAt(): ?CarbonImmutable
    {
        return $this->created_time ? CarbonImmutable::createFromTimestamp($this->created_time, 'UTC') : null;
    }

    public function endsAt(): ?CarbonImmutable
    {
        $ends = $this->payload['schedule']['ends_at'] ?? null;

        return $ends ? CarbonImmutable::createFromTimestamp((int) $ends, 'UTC') : null;
    }

    /**
     * 2+ locally-served images. Uploaded rows win; otherwise a deterministic
     * local placeholder set so every event renders without per-row image data.
     *
     * @return array<int, array{url: string, alt: string}>
     */
    public function displayImages(): array
    {
        $uploaded = $this->relationLoaded('images') ? $this->images : $this->images()->get();

        $images = $uploaded
            ->map(fn (EventImage $i, int $n) => ['url' => $i->url, 'alt' => $this->imageAlt($n)])
            ->values()
            ->all();

        // Guarantee the "2+ images per event" requirement regardless of how many were
        // uploaded: top up from the deterministic placeholder set when short.
        if (count($images) < 2) {
            $images = array_merge($images, array_slice($this->placeholderImages(), 0, 3 - count($images)));
        }

        return $images;
    }

    /**
     * Deterministic local placeholder images (category cover + two scenes), so every
     * event renders without materialising ~2.5M real image rows.
     *
     * @return array<int, array{url: string, alt: string}>
     */
    private function placeholderImages(): array
    {
        $category = in_array($this->type, self::CATEGORIES, true) ? $this->type : 'meetup';
        $scenes = ['a', 'b', 'c', 'd', 'e'];
        $h = crc32((string) $this->id);
        $paths = [
            "images/events/cat-{$category}.svg",
            'images/events/scene-'.$scenes[$h % 5].'.svg',
            'images/events/scene-'.$scenes[($h + 2) % 5].'.svg',
        ];

        // Root-relative so images resolve against whatever host/port serves the app
        // (php artisan serve, any port) rather than a hardcoded APP_URL.
        return array_map(
            fn (string $p, int $n) => ['url' => '/'.$p, 'alt' => $this->imageAlt($n)],
            $paths,
            array_keys($paths),
        );
    }

    /** Distinct per-image alt text so a carousel doesn't repeat the same name. */
    private function imageAlt(int $index): string
    {
        return (string) $this->name.', image '.($index + 1);
    }
}

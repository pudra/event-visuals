<?php

use App\Mail\AttendeeConfirmationMail;
use App\Mail\EventReminderMail;
use App\Models\Event;
use App\Models\EventAttendee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/** Count the DB queries the listing endpoint issues for a given event count. */
function listingQueryCount(int $events): int
{
    Event::factory()->count($events)->startingInHours(24)->create();

    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->getJson(route('events.listing'))->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('lists only published events as lean cards', function () {
    Event::factory()->count(3)->startingInHours(24)->create(); // published + upcoming
    Event::factory()->create(['status' => 'draft']);

    $response = $this->getJson(route('events.listing'))->assertOk();

    expect($response->json('total'))->toBe(3);
    expect($response->json('data.0'))
        ->toHaveKeys(['id', 'name', 'category', 'city', 'venue_name', 'min_price', 'starts_at_iso', 'images']);
    // 2+ locally-served images per event.
    expect(count($response->json('data.0.images')))->toBeGreaterThanOrEqual(2);
});

it('filters by city, category and date range on indexed columns', function () {
    Event::factory()->create(['status' => 'published', 'city' => 'Berlin', 'type' => 'concert', 'created_time' => strtotime('2026-07-01 12:00:00 UTC')]);
    Event::factory()->create(['status' => 'published', 'city' => 'Paris', 'type' => 'concert', 'created_time' => strtotime('2026-07-01 12:00:00 UTC')]);
    Event::factory()->create(['status' => 'published', 'city' => 'Berlin', 'type' => 'meetup', 'created_time' => strtotime('2026-09-01 12:00:00 UTC')]);

    expect($this->getJson(route('events.listing', ['city' => 'Berlin']))->json('total'))->toBe(2);
    expect($this->getJson(route('events.listing', ['city' => 'Berlin', 'category' => 'concert']))->json('total'))->toBe(1);
    expect($this->getJson(route('events.listing', ['from' => '2026-08-01', 'to' => '2026-10-01']))->json('total'))->toBe(1);
});

it('runs a constant number of queries regardless of how many events are listed', function () {
    // The images relation is eager-loaded, so the query count must not grow with the
    // number of rows (no N+1). More events on the page must not mean more queries.
    expect(listingQueryCount(18))->toBe(listingQueryCount(3));
});

it('rejects a non-date filter value instead of silently dropping the bound', function () {
    // The looser `date` rule accepts a datetime, which then breaks the strtotime parse
    // and would fail open to "everything since epoch". Only Y-m-d is allowed.
    $this->getJson(route('events.listing', ['from' => '2024-06-01 12:00:00']))
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['from']]);

    $this->getJson(route('events.listing', ['to' => 'not-a-date']))
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['to']]);
});

it('always renders at least two images even with a single upload', function () {
    Storage::fake('public');
    $event = Event::factory()->create(['status' => 'published']);

    $this->postJson(route('events.images.store', $event), [
        'images' => [UploadedFile::fake()->image('only.jpg')],
    ])->assertStatus(201);

    // One uploaded row must still satisfy the "2+ images per event" requirement.
    $images = $event->fresh()->displayImages();
    expect(count($images))->toBeGreaterThanOrEqual(2);
    expect($images[0]['url'])->toContain('/storage/event-images/');
});

it('registers an attendee and queues a confirmation email', function () {
    Mail::fake();
    $event = Event::factory()->create(['status' => 'published']);

    $this->postJson(route('events.register', $event), ['name' => 'Ada', 'email' => 'ada@example.com'])
        ->assertOk()
        ->assertJson(['ok' => true]);

    $this->assertDatabaseHas('event_attendees', ['event_id' => $event->id, 'email' => 'ada@example.com']);
    Mail::assertQueued(AttendeeConfirmationMail::class, fn ($mail) => $mail->hasTo('ada@example.com'));
});

it('rejects a duplicate registration for the same event', function () {
    $event = Event::factory()->create(['status' => 'published']);
    EventAttendee::query()->create(['event_id' => $event->id, 'name' => 'First', 'email' => 'dupe@example.com']);

    $this->postJson(route('events.register', $event), ['name' => 'Dup', 'email' => 'dupe@example.com'])
        ->assertStatus(422)
        ->assertJsonStructure(['message', 'errors' => ['email']]);

    expect(EventAttendee::where('event_id', $event->id)->count())->toBe(1);
});

it('does not expose a draft event by uuid', function () {
    $draft = Event::factory()->create(['status' => 'draft']);

    $this->get(route('events.show', $draft))->assertNotFound();
    $this->postJson(route('events.register', $draft), ['name' => 'X', 'email' => 'x@example.com'])->assertNotFound();
});

it('never exposes draft or cancelled events via the public list or map, even with ?status', function () {
    Event::factory()->startingInHours(24)->create(); // published + upcoming
    Event::factory()->create(['status' => 'draft']);
    Event::factory()->create(['status' => 'cancelled']);

    // The status query param is ignored; only published/sold_out are ever returned.
    expect($this->getJson(route('events.listing', ['status' => 'draft']))->json('total'))->toBe(1);
    expect($this->getJson(route('events.map', ['status' => 'cancelled']))->json('points'))->toHaveCount(1);
});

it('defaults to upcoming events and hides past ones', function () {
    $past = Event::factory()->create(['status' => 'published', 'created_time' => now()->subMonths(2)->timestamp]);
    $upcoming = Event::factory()->startingInHours(48)->create();

    $ids = collect($this->getJson(route('events.listing'))->json('data'))->pluck('id');

    expect($ids)->toContain($upcoming->id);
    expect($ids)->not->toContain($past->id);
});

it('uploads images locally end to end and they win over placeholders', function () {
    Storage::fake('public');
    $event = Event::factory()->create(['status' => 'published']);

    $this->postJson(route('events.images.store', $event), [
        'images' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
    ])->assertStatus(201)->assertJsonPath('ok', true);

    expect($event->images()->count())->toBe(2);
    Storage::disk('public')->assertExists($event->images()->first()->path);

    // displayImages now returns the locally-stored uploads, not the placeholders.
    $images = $event->fresh()->displayImages();
    expect($images)->toHaveCount(2);
    expect($images[0]['url'])->toContain('/storage/event-images/');
});

it('rejects a non-image upload', function () {
    Storage::fake('public');
    $event = Event::factory()->create(['status' => 'published']);

    $this->postJson(route('events.images.store', $event), [
        'images' => [UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')],
    ])->assertStatus(422)->assertJsonStructure(['errors']);
});

it('sends 3-day and 24-hour reminders once each, idempotently', function () {
    Mail::fake();

    $threeDay = Event::factory()->startingInHours(66)->create(); // in the 2.5-3 day band
    $oneDay = Event::factory()->startingInHours(12)->create();
    $gap = Event::factory()->startingInHours(30)->create(); // 24-48h: must get NEITHER wave yet
    EventAttendee::query()->create(['event_id' => $threeDay->id, 'name' => 'A', 'email' => 'a@example.com']);
    EventAttendee::query()->create(['event_id' => $oneDay->id, 'name' => 'B', 'email' => 'b@example.com']);
    EventAttendee::query()->create(['event_id' => $gap->id, 'name' => 'C', 'email' => 'c@example.com']);

    $this->artisan('events:send-reminders')->assertSuccessful();

    Mail::assertQueued(EventReminderMail::class, fn ($m) => $m->hasTo('a@example.com') && $m->window === '3 days');
    Mail::assertQueued(EventReminderMail::class, fn ($m) => $m->hasTo('b@example.com') && $m->window === '24 hours');
    // The 24-48h event is never mislabeled "in 3 days" (nor sent a 24h email yet).
    Mail::assertNotQueued(EventReminderMail::class, fn ($m) => $m->hasTo('c@example.com'));
    Mail::assertQueuedCount(2);

    // Idempotent: a second run sends nothing new.
    $this->artisan('events:send-reminders')->assertSuccessful();
    Mail::assertQueuedCount(2);
});

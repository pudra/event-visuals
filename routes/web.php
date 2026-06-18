<?php

use App\Http\Controllers\EventController;
use App\Models\Event;
use Illuminate\Support\Facades\Route;

// Public {event} routes only resolve to browsable events; a draft (or its raw
// payload) is never reachable by guessing/knowing a UUID. 404 otherwise.
Route::bind('event', fn (string $id) => Event::whereIn('status', EventController::PUBLIC_STATUSES)->findOrFail($id));

Route::redirect('/', '/events-visual-1')->name('home');

// Visual browsing pages (the deliverable) + their JSON data endpoints. Specific
// paths are declared before the events/{event} wildcard so it can't swallow them.
Route::inertia('events-visual-1', 'Events/VisualOne')->name('events.visual1');
Route::inertia('events-visual-2', 'Events/VisualTwo')->name('events.visual2');
Route::get('events/listing', [EventController::class, 'listing'])->name('events.listing');
Route::get('events/map', [EventController::class, 'mapPoints'])->name('events.map');
Route::get('events/filters', [EventController::class, 'filters'])->name('events.filters');

Route::get('events/{event}', [EventController::class, 'show'])->name('events.show');
// Public writes are throttled.
Route::post('events/{event}/register', [EventController::class, 'register'])
    ->middleware('throttle:20,1')->name('events.register');
Route::post('events/{event}/images', [EventController::class, 'storeImage'])
    ->middleware('throttle:5,1')->name('events.images.store');

Route::inertia('dashboard', 'Dashboard')->name('dashboard');

require __DIR__.'/settings.php';

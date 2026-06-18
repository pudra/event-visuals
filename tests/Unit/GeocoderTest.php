<?php

use App\Support\Geocoder;

it('resolves an exact anchor to its City, Country', function () {
    expect(Geocoder::resolve(40.7128, -74.0060))->toBe('New York, USA');
});

it('resolves a jittered coordinate to the nearest metro', function () {
    // The seeder jitters ±0.5° around anchors; a nearby point still resolves to NY.
    expect(Geocoder::resolve(40.55, -73.9))->toBe('New York, USA');
});

it('returns null when either coordinate is missing', function () {
    expect(Geocoder::resolve(null, null))->toBeNull();
    expect(Geocoder::resolve(40.7128, null))->toBeNull();
    expect(Geocoder::resolve(null, -74.0060))->toBeNull();
});

it('city() returns just the city portion', function () {
    expect(Geocoder::city(40.7128, -74.0060))->toBe('New York');
    expect(Geocoder::city(null, null))->toBeNull();
});

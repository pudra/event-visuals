<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\User;
use App\Support\Geocoder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        $type = fake()->randomElement(Event::CATEGORIES);
        $lat = fake()->latitude();
        $lng = fake()->longitude();
        $startsAt = fake()->numberBetween(strtotime('-1 year'), strtotime('+1 year'));

        $name = ucwords(fake()->words(3, true));
        $venue = fake()->company();
        $description = "Join us for {$name}, a {$type} you won't want to miss.";
        $price = fake()->randomFloat(2, 0, 250);

        return [
            'user_id' => User::factory(),
            'type' => $type,
            'status' => fake()->randomElement(['draft', 'published', 'cancelled', 'sold_out']),
            'created_time' => $startsAt,
            'latitude' => $lat,
            'longitude' => $lng,
            // Denormalized display/filter columns kept in sync with the payload, so
            // factory-built events are immediately listable without a backfill pass.
            'name' => $name,
            'description' => $description,
            'venue_name' => $venue,
            'min_price' => $price,
            'city' => Geocoder::city($lat, $lng),
            'payload' => [
                'name' => $name,
                'category' => $type,
                'description' => $description,
                'venue' => ['name' => $venue, 'capacity' => fake()->numberBetween(20, 50000)],
                'location' => ['lat' => $lat, 'lng' => $lng],
                'schedule' => ['starts_at' => $startsAt, 'ends_at' => $startsAt + 7200],
                'pricing' => ['currency' => 'USD', 'min_price' => $price],
            ],
        ];
    }

    /** A published event starting a given number of hours from now. */
    public function startingInHours(int $hours): static
    {
        $ts = now()->addHours($hours)->timestamp;

        return $this->state(fn () => [
            'status' => 'published',
            'created_time' => $ts,
            'payload->schedule->starts_at' => $ts,
        ]);
    }
}

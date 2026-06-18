<?php

namespace Database\Seeders;

use App\Models\Event;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Attaches a couple of real, locally-stored images to a handful of events so the
 * uploaded-images path in Event::displayImages() is exercised live (not just by the
 * upload feature test). Every other event falls back to the deterministic placeholder
 * set, so the full 1.25M render without materialising millions of image rows.
 */
class EventImageSeeder extends Seeder
{
    public function run(): void
    {
        $disk = Storage::disk('public');

        // Stage a few sample files on the public disk, exactly as an upload would.
        $samples = [];
        foreach (['scene-a', 'scene-b', 'scene-c'] as $name) {
            $source = public_path("images/events/{$name}.svg");
            if (! file_exists($source)) {
                continue;
            }
            $path = "event-images/sample-{$name}.svg";
            $disk->put($path, file_get_contents($source));
            $samples[] = $path;
        }

        if (count($samples) < 2) {
            return;
        }

        Event::where('status', 'published')->limit(6)->get()->each(function (Event $event) use ($samples) {
            foreach (array_slice($samples, 0, 2) as $i => $path) {
                $event->images()->firstOrCreate(['path' => $path], ['position' => $i]);
            }
        });
    }
}

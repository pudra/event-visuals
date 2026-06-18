<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Support\Geocoder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * One-time projection of the JSON payload + reverse-geocoded city into the lean
 * indexed columns the listings query. Chunked and idempotent (only fills rows
 * not yet backfilled), so it is safe to resume and scales to the full 1.25M.
 */
class BackfillEvents extends Command
{
    protected $signature = 'events:backfill {--fresh : Re-backfill every row, not just un-backfilled ones} {--chunk=2000}';

    protected $description = 'Project payload + geocoded city into indexed display columns';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');

        $query = Event::query()
            ->select(['id', 'type', 'status', 'latitude', 'longitude', 'payload'])
            ->when(! $this->option('fresh'), fn ($q) => $q->whereNull('name'));

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('Nothing to backfill.');

            return self::SUCCESS;
        }

        $this->info("Backfilling {$total} events...");
        $bar = $this->output->createProgressBar($total);
        $done = 0;

        $query->chunkById($chunk, function ($events) use (&$done, $bar) {
            // One transaction per chunk instead of per row, ~625 commits across 1.25M.
            DB::transaction(function () use ($events) {
                foreach ($events as $event) {
                    $p = $event->payload ?? [];
                    DB::table('events')->where('id', $event->id)->update([
                        'name' => $p['name'] ?? 'Untitled Event',
                        'description' => $p['description'] ?? null,
                        'venue_name' => $p['venue']['name'] ?? null,
                        'min_price' => isset($p['pricing']['min_price']) ? (float) $p['pricing']['min_price'] : null,
                        'city' => Geocoder::city($event->latitude, $event->longitude),
                        // Derive the public-scope boolean the browse indexes seek on.
                        'is_public' => in_array($event->status, Event::PUBLIC_STATUSES, true),
                    ]);
                }
            });

            $done += $events->count();
            $bar->advance($events->count());
        });

        $bar->finish();
        $this->newLine();
        $this->info("Done. Backfilled {$done} events.");

        // The filter options (cities / date bounds) are derived from these columns.
        Cache::forget('events:filters:v1');

        // Build index statistics so SQLite's planner can choose between the
        // (is_public, city, …) and (is_public, type, …) indexes by selectivity
        // for a combined city+category filter instead of guessing.
        $this->info('Analyzing indexes...');
        DB::statement('ANALYZE');

        return self::SUCCESS;
    }
}

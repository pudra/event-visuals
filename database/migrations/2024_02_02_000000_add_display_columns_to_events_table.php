<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalize the handful of fields the listings actually display and filter on
 * out of the 1.5KB `payload` JSON blob and into lean, indexed columns.
 *
 * Why: the dataset is 1.25M rows (~2.5GB of payloads). Reading + JSON-decoding
 * the blob for every row in a list is the single biggest cost. By projecting
 * name/description/venue/price/city/start-time into real columns we keep list,
 * filter, sort and paginate queries off the blob entirely; payload is only
 * ever read on the single-event detail page. Backfilled once by
 * `php artisan events:backfill`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('name')->nullable()->after('status');
            $table->text('description')->nullable()->after('name');
            $table->string('venue_name')->nullable()->after('description');
            $table->string('city')->nullable()->after('venue_name');
            $table->decimal('min_price', 10, 2)->nullable()->after('city');
            // The public browse scope is status IN ('published','sold_out'). A 2-value
            // IN cannot be ordered straight from a (status, ...) index; SQLite merges
            // two ranges and falls back to a temp b-tree for the ORDER BY. Projecting
            // the scope into a single derived boolean makes it an equality seek, so the
            // ORDER BY created_time is served directly from the index (no temp b-tree).
            $table->boolean('is_public')->default(false)->after('status');

            $table->index('created_time', 'events_time_idx');
            // The common "public, upcoming, newest first" access pattern, served end to
            // end from the index for both the gallery and the map.
            $table->index(['is_public', 'created_time'], 'events_public_time_idx');
            // City-filtered listings ordered by start time (and the city group-by).
            $table->index(['is_public', 'city', 'created_time'], 'events_public_city_time_idx');
            // Category filter: seek a sparse category within the public set instead of
            // scanning ~875K rows testing type per row (which never fills the LIMIT).
            $table->index(['is_public', 'type', 'created_time'], 'events_public_type_time_idx');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex('events_time_idx');
            $table->dropIndex('events_public_time_idx');
            $table->dropIndex('events_public_city_time_idx');
            $table->dropIndex('events_public_type_time_idx');
            $table->dropColumn(['name', 'description', 'venue_name', 'city', 'min_price', 'is_public']);
        });
    }
};

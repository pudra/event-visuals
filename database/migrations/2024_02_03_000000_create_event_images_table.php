<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real, end-to-end image support: every image is an owned row pointing at a
 * locally-served file (no hotlinked URLs). The Event model falls back to a
 * deterministic local placeholder set when a given event has no uploaded rows,
 * so all 1.25M seeded events still render 2+ images without materialising
 * ~2.5M image rows. Uploading real images for an event just inserts here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_images', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->string('path');          // relative path on the public disk
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['event_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_images');
    }
};

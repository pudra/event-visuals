<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People who registered interest/attendance for an event. The *_sent_at columns
 * make the confirmation + reminder emails idempotent: the reminder command only
 * picks rows whose window has arrived and whose stamp is still null, so it is
 * safe to run on a schedule and to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->timestamp('confirmation_sent_at')->nullable();
            $table->timestamp('reminder_3d_sent_at')->nullable();
            $table->timestamp('reminder_24h_sent_at')->nullable();
            $table->timestamps();

            // One registration per email per event.
            $table->unique(['event_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_attendees');
    }
};

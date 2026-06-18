<?php

namespace App\Console\Commands;

use App\Mail\EventReminderMail;
use App\Models\Event;
use App\Models\EventAttendee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the two reminder waves. Designed to be run on a schedule (hourly) and to
 * be safe under missed or repeated runs:
 *  - 3-day wave: events starting in 48-72h (~2-3 days), attendees w/o a 3-day stamp.
 *  - 24-hour wave: events starting in 0-24h, attendees without a 24-hour stamp.
 * The 24h-wide 3-day band gives an hourly run plenty of catch-up margin; events 24-48h
 * out get only the 24-hour reminder.
 * The window is a range (not a single instant) so a missed run still catches up,
 * and the per-attendee stamp guarantees each wave fires at most once.
 */
class SendEventReminders extends Command
{
    protected $signature = 'events:send-reminders';

    protected $description = 'Email 3-day and 24-hour reminders to event attendees';

    public function handle(): int
    {
        $now = now()->timestamp;

        // Two non-overlapping waves so an event is never mislabeled:
        //   3-day wave: events ~2-3 days out (48-72h) -> "3 days"
        //   24-hour wave: events 0-24h out -> "24 hours"
        // The 24h-wide 3-day band means a missed hourly run still has many catch-up
        // chances; the per-attendee stamp makes each wave fire at most once. The 24-48h
        // gap is intentional (those attendees get only the 24h reminder).
        $sent = $this->dispatchWave([$now + 172800, $now + 259200], 'reminder_3d_sent_at', '3 days')
            + $this->dispatchWave([$now, $now + 86400], 'reminder_24h_sent_at', '24 hours');

        $this->info("Queued {$sent} reminder email(s).");

        return self::SUCCESS;
    }

    /**
     * @param  array{0: int, 1: int}  $window  [lower, upper] unix bounds on event start
     */
    private function dispatchWave(array $window, string $stamp, string $label): int
    {
        $count = 0;

        EventAttendee::query()
            ->whereNull($stamp)
            ->whereHas('event', fn ($q) => $q
                ->whereBetween('created_time', $window)
                // Same public-scope whitelist the browse paths use, one source of truth,
                // so a future status can't silently start receiving reminders.
                ->whereIn('status', Event::PUBLIC_STATUSES))
            ->with('event')
            ->chunkById(500, function ($attendees) use ($stamp, $label, &$count) {
                foreach ($attendees as $attendee) {
                    Mail::to($attendee->email)->queue(new EventReminderMail($attendee, $label));
                    $attendee->forceFill([$stamp => now()])->save();
                    $count++;
                }
            });

        return $count;
    }
}

<?php

namespace App\Mail;

use App\Models\EventAttendee;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EventReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param string $window Human label for the window, e.g. "3 days" or "24 hours". */
    public function __construct(public EventAttendee $attendee, public string $window)
    {
        $this->attendee->loadMissing('event');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reminder: '.$this->attendee->event->name.' is in '.$this->window);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.reminder', with: [
            'attendee' => $this->attendee,
            'event' => $this->attendee->event,
            'window' => $this->window,
        ]);
    }
}

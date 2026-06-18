<?php

namespace App\Mail;

use App\Models\EventAttendee;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AttendeeConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public EventAttendee $attendee)
    {
        $this->attendee->loadMissing('event');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You're on the list: ".$this->attendee->event->name);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.confirmation', with: [
            'attendee' => $this->attendee,
            'event' => $this->attendee->event,
        ]);
    }
}

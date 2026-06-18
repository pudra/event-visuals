@component('mail::message')
# You're on the list 🎉

Hi {{ $attendee->name }},

You're confirmed for **{{ $event->name }}**.

@component('mail::panel')
📍 {{ $event->city ?? 'Location TBC' }} · {{ $event->venue_name }}
🗓 {{ optional($event->startsAt())->format('D, j M Y · H:i') }} UTC
@endcomponent

We'll send you a reminder as the event gets closer. See you there!

Thanks,<br>
{{ config('app.name') }}
@endcomponent

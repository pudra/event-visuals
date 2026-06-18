@component('mail::message')
# {{ $event->name }} is in {{ $window }} ⏰

Hi {{ $attendee->name }},

A quick reminder that **{{ $event->name }}** starts in **{{ $window }}**.

@component('mail::panel')
📍 {{ $event->city ?? 'Location TBC' }} · {{ $event->venue_name }}
🗓 {{ optional($event->startsAt())->format('D, j M Y · H:i') }} UTC
@endcomponent

@component('mail::button', ['url' => route('events.show', $event)])
View event details
@endcomponent

See you soon,<br>
{{ config('app.name') }}
@endcomponent

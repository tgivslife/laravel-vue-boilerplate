{{--
    Password-reset mail, same shape as the magic-link mail: heading, short
    description, the reset button, then the requesting device's facts in an
    accent-bordered panel so the reader can judge whether the request was
    theirs. Slot content must stay flush-left - indented lines would be
    parsed as markdown code blocks.
--}}
<x-mail::message>
# {{ __('api.auth.password_reset.mail.heading') }}

{{ __('api.auth.password_reset.mail.intro') }}

<x-mail::button :url="$actionUrl">
{{ $actionText }}
</x-mail::button>

{{ __('api.auth.password_reset.mail.requested_from') }}

<x-mail::panel>
**{{ __('api.auth.password_reset.mail.device_label') }}:** {{ $deviceName }}<br>
**{{ __('api.auth.password_reset.mail.ip_label') }}:** {{ $ipAddress }}<br>
**{{ __('api.auth.password_reset.mail.time_label') }}:** {{ $requestedAt }}
</x-mail::panel>

{{ __('api.auth.password_reset.mail.expiry', ['minutes' => $expiresInMinutes]) }}

{{ __('api.auth.password_reset.mail.ignore') }}

<p class="salutation">
{{ __('api.mail.salutation') }},<br>
{{ __('api.mail.team', ['app' => config('app.name')]) }}
</p>

<x-slot:subcopy>
{{ __('api.auth.password_reset.mail.trouble', ['action' => $actionText]) }} <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
</x-mail::message>

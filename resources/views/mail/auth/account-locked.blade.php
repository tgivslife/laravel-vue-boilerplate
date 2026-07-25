{{--
    Lockout alert, shaped like the SPA's UAlert: heading, short description,
    then the key facts grouped in an accent-bordered panel instead of loose
    sentences. Slot content must stay flush-left - indented lines would be
    parsed as markdown code blocks.
--}}
<x-mail::message>
# {{ __('api.auth.lockout.mail.heading') }}

{{ __('api.auth.lockout.mail.intro') }}

<x-mail::panel>
**{{ __('api.auth.lockout.mail.unlock_label') }}**<br>
{{ $unlockAt }}

**{{ __('api.auth.lockout.mail.device_label') }}:** {{ $deviceName }}<br>
**{{ __('api.auth.lockout.mail.ip_label') }}:** {{ $ipAddress }}
</x-mail::panel>

@if ($hasPassword)
{{ __('api.auth.lockout.mail.you') }}

{{ __('api.auth.lockout.mail.not_you') }}
@else
{{ __('api.auth.lockout.mail.passwordless') }}
@endif

<p class="salutation">
{{ __('api.mail.salutation') }},<br>
{{ __('api.mail.team', ['app' => config('app.name')]) }}
</p>
</x-mail::message>

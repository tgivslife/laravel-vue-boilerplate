{{--
    New-device alert, same UAlert-like shape as the lockout mail: heading,
    short description, key facts in an accent-bordered panel. Slot content
    must stay flush-left - indented lines would be parsed as markdown code
    blocks.
--}}
<x-mail::message>
# {{ __('api.auth.new_device.mail.heading') }}

{{ __('api.auth.new_device.mail.intro') }}

<x-mail::panel>
**{{ __('api.auth.new_device.mail.device_label') }}:** {{ $deviceName }}<br>
**{{ __('api.auth.new_device.mail.ip_label') }}:** {{ $ipAddress }}<br>
**{{ __('api.auth.new_device.mail.time_label') }}:** {{ $loginAt }}
</x-mail::panel>

{{ __('api.auth.new_device.mail.you') }}

@if ($hasPassword)
{{ __('api.auth.new_device.mail.not_you') }}
@else
{{ __('api.auth.new_device.mail.not_you_passwordless') }}
@endif

<p class="salutation">
{{ __('api.mail.salutation') }},<br>
{{ __('api.mail.team', ['app' => config('app.name')]) }}
</p>
</x-mail::message>

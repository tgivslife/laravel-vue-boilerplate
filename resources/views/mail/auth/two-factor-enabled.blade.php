{{--
    Two-factor-enabled alert, same UAlert-like shape as the other auth
    mails: heading, short description, key facts in an accent-bordered
    panel. Slot content must stay flush-left - indented lines would be
    parsed as markdown code blocks.
--}}
<x-mail::message>
# {{ __('api.auth.two_factor_enabled.mail.heading') }}

{{ __('api.auth.two_factor_enabled.mail.intro') }}

<x-mail::panel>
**{{ __('api.auth.two_factor_enabled.mail.device_label') }}:** {{ $deviceName }}<br>
**{{ __('api.auth.two_factor_enabled.mail.ip_label') }}:** {{ $ipAddress }}<br>
**{{ __('api.auth.two_factor_enabled.mail.time_label') }}:** {{ $changedAt }}
</x-mail::panel>

{{ __('api.auth.two_factor_enabled.mail.you') }}

{{ __('api.auth.two_factor_enabled.mail.not_you', ['url' => url('/auth/forgot-password')]) }}

<p class="salutation">
{{ __('api.mail.salutation') }},<br>
{{ __('api.mail.team', ['app' => config('app.name')]) }}
</p>
</x-mail::message>

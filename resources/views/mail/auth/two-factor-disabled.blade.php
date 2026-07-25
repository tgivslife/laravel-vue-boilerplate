{{--
    Two-factor-disabled alert, same UAlert-like shape as the other auth
    mails. The device panel only renders for the self-service disable:
    an administrative reset carries no device facts, and its intro says
    who acted instead. Slot content must stay flush-left - indented
    lines would be parsed as markdown code blocks.
--}}
<x-mail::message>
# {{ __('api.auth.two_factor_disabled.mail.heading') }}

@if($byAdministrator)
{{ __('api.auth.two_factor_disabled.mail.intro_admin') }}
@else
{{ __('api.auth.two_factor_disabled.mail.intro') }}

<x-mail::panel>
**{{ __('api.auth.two_factor_disabled.mail.device_label') }}:** {{ $deviceName }}<br>
**{{ __('api.auth.two_factor_disabled.mail.ip_label') }}:** {{ $ipAddress }}<br>
**{{ __('api.auth.two_factor_disabled.mail.time_label') }}:** {{ $changedAt }}
</x-mail::panel>
@endif

{{ __('api.auth.two_factor_disabled.mail.you') }}

{{ __('api.auth.two_factor_disabled.mail.not_you', ['url' => url('/auth/forgot-password')]) }}

<p class="salutation">
{{ __('api.mail.salutation') }},<br>
{{ __('api.mail.team', ['app' => config('app.name')]) }}
</p>
</x-mail::message>

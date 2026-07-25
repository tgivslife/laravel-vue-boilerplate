{{--
    Magic-link sign-in mail, same shape as the other auth mails: heading, short description, the sign-in button,
    then the requesting device's facts in an accent-bordered panel so the reader can judge whether the request was theirs.
    Slot content must stay flush-left - indented lines would be parsed as markdown code blocks.
    $provisioning swaps the heading and intro for the welcome copy (the consumed link creates the account); everything else is shared.
--}}
<x-mail::message>
# {{ __($provisioning ? 'api.auth.magic_link.mail.welcome_heading' : 'api.auth.magic_link.mail.heading') }}

{{ __($provisioning ? 'api.auth.magic_link.mail.welcome_intro' : 'api.auth.magic_link.mail.intro') }}

<x-mail::button :url="$actionUrl">
{{ $actionText }}
</x-mail::button>

{{ __('api.auth.magic_link.mail.requested_from') }}

<x-mail::panel>
**{{ __('api.auth.magic_link.mail.device_label') }}:** {{ $deviceName }}<br>
**{{ __('api.auth.magic_link.mail.ip_label') }}:** {{ $ipAddress }}<br>
**{{ __('api.auth.magic_link.mail.time_label') }}:** {{ $requestedAt }}
</x-mail::panel>

{{ __('api.auth.magic_link.mail.expiry', ['minutes' => $expiresInMinutes]) }}

{{ __('api.auth.magic_link.mail.ignore') }}

<p class="salutation">
{{ __('api.mail.salutation') }},<br>
{{ __('api.mail.team', ['app' => config('app.name')]) }}
</p>

<x-slot:subcopy>
{{ __('api.auth.magic_link.mail.trouble', ['action' => $actionText]) }} <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
</x-mail::message>

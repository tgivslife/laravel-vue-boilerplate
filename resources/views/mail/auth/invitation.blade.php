{{--
    Admin-invitation mail: an account was created for the recipient and the button is their first sign-in.
    Same shape as the other auth mails minus the requesting-device panel - the mail is admin-initiated,
    so there is no device for the recipient to judge.
    Slot content must stay flush-left - indented lines would be parsed as markdown code blocks.
    $requiresPassword swaps the intro for the choose-your-password copy (password login is the only door).
--}}
<x-mail::message>
# {{ __('api.auth.invitation.mail.heading', ['app' => config('app.name')]) }}

{{ __($requiresPassword ? 'api.auth.invitation.mail.intro_password' : 'api.auth.invitation.mail.intro') }}

<x-mail::button :url="$actionUrl">
{{ $actionText }}
</x-mail::button>

{{ __('api.auth.invitation.mail.expiry', ['days' => $expiresInDays]) }}

{{ __('api.auth.invitation.mail.ignore') }}

<p class="salutation">
{{ __('api.mail.salutation') }},<br>
{{ __('api.mail.team', ['app' => config('app.name')]) }}
</p>

<x-slot:subcopy>
{{ __('api.auth.invitation.mail.trouble', ['action' => $actionText]) }} <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
</x-mail::message>

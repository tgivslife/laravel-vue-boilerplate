{{--
    Pre-closure warning for a long-inactive account: the scheduled date in an
    accent-bordered panel, then how to keep the account. Slot content must stay
    flush-left - indented lines would be parsed as markdown code blocks.
--}}
<x-mail::message>
# {{ __('api.auth.inactivity.notice.mail.heading') }}

{{ __('api.auth.inactivity.notice.mail.intro', ['app' => config('app.name')]) }}

<x-mail::panel>
**{{ __('api.auth.inactivity.notice.mail.closure_label') }}**<br>
{{ $closureDate }}
</x-mail::panel>

{{ __('api.auth.inactivity.notice.mail.keep') }}

{{ __('api.auth.inactivity.notice.mail.ignore') }}

<p class="salutation">
{{ __('api.mail.salutation') }},<br>
{{ __('api.mail.team', ['app' => config('app.name')]) }}
</p>
</x-mail::message>

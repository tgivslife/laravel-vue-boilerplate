{{--
    Closure confirmation for an account retired by the inactivity policy.
    Slot content must stay flush-left - indented lines would be parsed as
    markdown code blocks.
--}}
<x-mail::message>
# {{ __('api.auth.inactivity.closed.mail.heading') }}

{{ __('api.auth.inactivity.closed.mail.intro', ['app' => config('app.name')]) }}

{{ __('api.auth.inactivity.closed.mail.outro') }}

<p class="salutation">
{{ __('api.mail.salutation') }},<br>
{{ __('api.mail.team', ['app' => config('app.name')]) }}
</p>
</x-mail::message>

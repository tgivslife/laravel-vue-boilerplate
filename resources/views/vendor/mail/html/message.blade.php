<x-mail::layout>
{{-- Header --}}
{{--
    Icon + wordmark matching AppLogo.vue. The icon is a raster copy of the
    mark (public/mail-logo.png - replace it together with the SPA logo when
    branding the app); the wordmark is text so it adapts to the reader's
    color scheme and follows APP_NAME automatically.
--}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
<img src="{{ asset('mail-logo.png') }}" class="header-logo" alt=""><span class="header-accent">{{ config('app.name') }}</span>
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
{{ __('api.mail.auto_generated') }}

© {{ date('Y') }} {{ config('app.name') }}.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>

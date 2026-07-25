<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<style>
@media only screen and (max-width: 600px) {
.inner-body {
width: 100% !important;
}

.footer {
width: 100% !important;
}
}

@media only screen and (max-width: 500px) {
.button {
width: 100% !important;
}
}

{{--
    Dark theme, following the reader's client/browser preference. The light
    theme (themes/default.css) is inlined into style attributes at render
    time, so every rule here needs !important to win over it. Colors mirror
    the SPA's dark surfaces (Tailwind neutral-900/800) with the same rose
    primary; the button stays rose in both schemes and needs no override.
    Support depends on the mail client: Apple Mail/iOS honor it, Gmail
    applies its own auto-inversion instead of these rules.
--}}
@media (prefers-color-scheme: dark) {
body,
.wrapper,
.body {
background-color: #171717 !important;
border-color: #171717 !important;
}

.inner-body {
background-color: #262626 !important;
border-color: #404040 !important;
}

h1, h2, h3,
.header a {
color: #f5f5f5 !important;
}

.header {
border-bottom-color: #404040 !important;
}

.header-name {
color: #a3a3a3 !important;
}

p, ul, ol, blockquote,
.table td,
.panel-content p {
color: #d4d4d4 !important;
}

a,
.inner-body a {
color: #fb7185 !important;
}

.subcopy {
border-top-color: #404040 !important;
}

.subcopy p,
.footer p,
.footer a {
color: #a3a3a3 !important;
}

.table th {
border-bottom-color: #404040 !important;
color: #f5f5f5 !important;
}

.panel-content {
background-color: #171717 !important;
}

{{--
    Must out-rank the `.inner-body a` link-color rule above (the button is
    an anchor inside .inner-body), or the button text turns rose on rose.
--}}
.inner-body a.button {
color: #ffffff !important;
}
}
</style>
{!! $head ?? '' !!}
</head>
<body>

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;">
<table class="inner-body" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
{{-- Header lives inside the card, above the body content. --}}
{!! $header ?? '' !!}

<!-- Body content -->
<tr>
<td class="content-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>

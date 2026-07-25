@props(['url'])
<tr>
<td class="header">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="header-brand">
<a href="{{ $url }}" style="display: inline-block;">
{!! $slot !!}
</a>
</td>
<td class="header-name">
{!! nl2br(e(__('api.mail.app_full_name'))) !!}
</td>
</tr>
</table>
</td>
</tr>

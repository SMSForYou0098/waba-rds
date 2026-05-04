@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if (trim($slot) === 'Laravel')
<img src="https://web.smsforyou.biz/img/logo.png" class="logo" alt="Laravel Logo" height="auto" width="100%">
@else
{{ $slot }}
@endif
</a>
</td>
</tr>

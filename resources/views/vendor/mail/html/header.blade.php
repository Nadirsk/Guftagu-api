@props(['url'])
<tr>
<td class="header">
<table align="center" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 auto;">
<tr>
<td style="padding-right: 8px; vertical-align: middle;">
{{-- Three rising bars — the same mark on the console's own sidebar. --}}
<svg width="16" height="16" viewBox="0 0 16 16" style="display: block;">
<rect x="1" y="9" width="3" height="6" fill="#7a5720" />
<rect x="6.5" y="5" width="3" height="10" fill="#f0a93b" />
<rect x="12" y="2" width="3" height="13" fill="#7a5720" />
</svg>
</td>
<td style="vertical-align: middle; text-align: left;">
<div style="font-size: 16px; font-weight: 600; letter-spacing: -0.2px; color: #eef2f7; line-height: 1.2;">{{ $slot }}</div>
<div style="font-size: 10px; font-weight: 600; letter-spacing: 1.5px; text-transform: uppercase; color: #5d6b81; line-height: 1.2;">Console</div>
</td>
</tr>
</table>
</td>
</tr>

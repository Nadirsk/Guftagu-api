<x-mail::message>
# {{ $purpose === 'reauth' ? 'Confirm this action' : 'Your login code' }}

Hello {{ $name }},

@if ($purpose === 'reauth')
You are about to grant a **high-risk permission**. Enter this code to confirm it is you.
@else
Use this code to finish signing in to the Guftagu admin panel.
@endif

<x-mail::panel>
<p style="font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace; font-size: 10px; font-weight: 600; letter-spacing: 0.14em; text-transform: uppercase; color: #5d6b81; margin: 0 0 6px;">{{ $purpose === 'reauth' ? 'Confirmation code' : 'Login code' }}</p>

# {{ $otp }}
</x-mail::panel>

This code expires in **{{ $ttl }} minutes** and can only be used once.

If you did not request it, someone may have your password — change it immediately and tell the platform owner.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

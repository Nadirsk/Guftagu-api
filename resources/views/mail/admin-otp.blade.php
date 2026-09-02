<x-mail::message>
# {{ $purpose === 'reauth' ? 'Confirm this action' : 'Your login code' }}

Hello {{ $name }},

@if ($purpose === 'reauth')
You are about to grant a **high-risk permission**. Enter this code to confirm it is you.
@else
Use this code to finish signing in to the Guftagu admin panel.
@endif

<x-mail::panel>
# {{ $otp }}
</x-mail::panel>

This code expires in **{{ $ttl }} minutes** and can only be used once.

If you did not request it, someone may have your password — change it immediately and tell the platform owner.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

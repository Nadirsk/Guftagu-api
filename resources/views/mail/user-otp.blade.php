<x-mail::message>
# {{ $purpose === 'reset_password' ? 'Reset your password' : 'Your verification code' }}

@if ($purpose === 'reset_password')
Use this code to confirm it's you before choosing a new password.
@else
Use this code to sign in to Guftagu.
@endif

<x-mail::panel>
<p style="font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace; font-size: 10px; font-weight: 600; letter-spacing: 0.14em; text-transform: uppercase; color: #8a93a3; margin: 0 0 6px;">Verification code</p>

# {{ $otp }}
</x-mail::panel>

This code expires in **{{ $ttl }} minutes** and can only be used once.

If you did not request it, you can safely ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

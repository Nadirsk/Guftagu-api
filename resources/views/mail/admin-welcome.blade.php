<x-mail::message>
# You've been added to Guftagu

Hello {{ $name }},

An account was created for you on the Guftagu admin panel, with the **{{ $roleName }}** role.

<x-mail::panel>
<p style="font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace; font-size: 10px; font-weight: 600; letter-spacing: 0.14em; text-transform: uppercase; color: #8a93a3; margin: 0 0 6px;">Sign-in email</p>
<p style="font-size: 16px; color: #1f2430; margin: 0 0 18px;">{{ $email }}</p>
<p style="font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace; font-size: 10px; font-weight: 600; letter-spacing: 0.14em; text-transform: uppercase; color: #8a93a3; margin: 0 0 6px;">Temporary password</p>
<p style="font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace; font-size: 20px; font-weight: 700; letter-spacing: 1px; color: #1f2430; margin: 0;">{{ $password }}</p>
</x-mail::panel>

<x-mail::button :url="$panelUrl" color="primary">
Open admin panel
</x-mail::button>

For your own security, sign in and change this password as soon as you can — treat it as one-time.

If you weren't expecting this account, ignore this email or tell the platform owner.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

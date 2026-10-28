<x-mail::message>
# Karibu, {{ $tenant->first_name }}

@if ($home)
**{{ $account->name }}** has invited you to the Rentflow tenant portal for **{{ $home }}**.
@else
**{{ $account->name }}** has invited you to the Rentflow tenant portal.
@endif
See your bills, your balance and your payments any time.

<x-mail::button :url="$link">
Set up my login
</x-mail::button>

@if ($tenant->national_id)
You'll log in with this email address or your national ID, and the password you choose.
@else
You'll log in with this email address and the password you choose.
@endif

@php($android = \App\Support\TenantAppLinks::android())
@php($ios = \App\Support\TenantAppLinks::ios())
**Get the app** to keep track of your bills on your phone:
@if ($android || $ios)
@if ($android)
- Android: [{{ $android }}]({{ $android }})
@endif
@if ($ios)
- iPhone: [{{ $ios }}]({{ $ios }})
@endif
@else
open [{{ \App\Support\TenantAppLinks::portal() }}]({{ \App\Support\TenantAppLinks::portal() }}) on your phone and choose "Add to home screen".
@endif

This link works once and expires in 3 days. If it has expired, ask {{ $account->name }} to send a new one.

Thanks,<br>
{{ $account->name }}
</x-mail::message>

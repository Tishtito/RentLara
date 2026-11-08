<x-mail::message>
# Hi {{ $tenant->first_name }}

@if ($home)
Someone asked to reset your Rentflow tenant portal password for **{{ $home }}** with **{{ $account->name }}**.
@else
Someone asked to reset your Rentflow tenant portal password with **{{ $account->name }}**.
@endif

<x-mail::button :url="$link">
Choose a new password
</x-mail::button>

This link works once and expires in {{ $validFor }}. You'll be logged out of the Rentflow app on your other devices.

If you didn't ask for this, you can ignore this email; your password stays the same.

Thanks,<br>
{{ $account->name }}
</x-mail::message>

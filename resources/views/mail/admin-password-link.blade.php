<x-mail::message>
@if ($invitation)
# Karibu, {{ \Illuminate\Support\Str::before($user->name, ' ') }}

@if ($accountName)
**{{ $accountName }}** has added you to their team on Rentflow.
@else
You've been added to a team on Rentflow.
@endif
Set a password to start.

<x-mail::button :url="$link">
Set up my login
</x-mail::button>

You'll log in with this email address. The link works once and expires in 3 days.
@else
# Reset your password

Someone (hopefully you) asked to reset the password for {{ $user->email }}.

<x-mail::button :url="$link">
Choose a new password
</x-mail::button>

The link expires in 60 minutes. If you didn't ask for this, ignore this email; your password stays the same.
@endif

Thanks,<br>
Rentflow
</x-mail::message>

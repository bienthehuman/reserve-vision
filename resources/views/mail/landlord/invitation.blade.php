<x-mail::message>
# {{ __('You\'re invited to :account', ['account' => $accountName]) }}

{{ __(':inviter invited you to help manage :account on :app, as a :role.', ['inviter' => $inviterName, 'account' => $accountName, 'app' => config('app.name'), 'role' => $roleLabel]) }}
{{ __('You\'ll see its properties and reservations, and you can answer renters. You can\'t change prices or invite people.') }}

<x-mail::button :url="$url">
{{ __('See the invitation') }}
</x-mail::button>

{{ __('The link works for :days days. If it has expired, ask :inviter to send a new one.', ['days' => $days, 'inviter' => $inviterName]) }}

{{ __('If you weren\'t expecting this, you can ignore this email. Nothing happens unless you accept.') }}

<x-slot:subcopy>
{{ __('If the button doesn\'t work, copy this link into your browser:') }} [{{ $url }}]({{ $url }})
</x-slot:subcopy>
</x-mail::message>

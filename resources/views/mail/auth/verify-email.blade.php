<x-mail::message>
# {{ __('Hi :name, confirm your email', ['name' => $firstName]) }}

{{ __('Tap the button to confirm this is your email address.') }}
@if ($isLandlord)
{{ __('Once it is confirmed, you can open your landlord dashboard.') }}
@else
{{ __('Once it is confirmed, you can reserve rooms and beds.') }}
@endif

<x-mail::button :url="$url">
{{ __('Confirm my email') }}
</x-mail::button>

{{ __('The link works for 60 minutes. If it has expired, log in and ask for a new one.') }}

{{ __('If you didn\'t sign up for :app, you can ignore this email.', ['app' => config('app.name')]) }}

<x-slot:subcopy>
{{ __('If the button doesn\'t work, copy this link into your browser:') }} [{{ $url }}]({{ $url }})
</x-slot:subcopy>
</x-mail::message>

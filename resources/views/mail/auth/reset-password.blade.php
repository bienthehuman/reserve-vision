<x-mail::message>
# {{ __('Hi :name, reset your password', ['name' => $firstName]) }}

{{ __('Someone asked to reset the password for your :app account. If it was you, tap the button and choose a new one.', ['app' => config('app.name')]) }}

<x-mail::button :url="$url">
{{ __('Choose a new password') }}
</x-mail::button>

{{ __('The link works for :minutes minutes.', ['minutes' => $expiresInMinutes]) }}

{{ __('If you didn\'t ask for this, ignore this email: your password stays the same.') }}

<x-slot:subcopy>
{{ __('If the button doesn\'t work, copy this link into your browser:') }} [{{ $url }}]({{ $url }})
</x-slot:subcopy>
</x-mail::message>

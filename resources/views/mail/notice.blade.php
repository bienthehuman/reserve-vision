{{-- Raw HTML blocks on purpose: user text (a reason, a note, a name) is escaped once by {{ }} and never read as Markdown. --}}
<x-mail::message>
<h1>{{ $notice->heading }}</h1>
@foreach ($notice->paragraphs as $paragraph)
<p>{{ $paragraph }}</p>
@endforeach
@if ($notice->quote !== null)
<x-mail::panel>
<div>@if ($notice->quoteLabel !== null)<strong>{{ $notice->quoteLabel }}</strong><br>@endif{!! nl2br(e($notice->quote)) !!}</div>
</x-mail::panel>
@endif
@if ($notice->facts !== [])
<div class="table">
<table role="presentation">
@foreach ($notice->facts as $label => $value)
<tr><td><strong>{{ $label }}</strong></td><td>{!! nl2br(e($value)) !!}</td></tr>
@endforeach
</table>
</div>
@endif
<x-mail::button :url="$notice->buttonUrl">
{{ $notice->buttonLabel }}
</x-mail::button>
@if ($notice->footnote !== null)
<p>{{ $notice->footnote }}</p>
@endif
<x-slot:subcopy>
{{ __('If the button doesn\'t work, copy this link into your browser:') }} [{{ $notice->buttonUrl }}]({{ $notice->buttonUrl }})
</x-slot:subcopy>
</x-mail::message>

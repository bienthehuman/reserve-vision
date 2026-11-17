{{-- The plain-text part of a status mail (slice 6.3): the same notice, no markup. --}}
{!! $notice->heading !!}

@foreach ($notice->paragraphs as $paragraph)
{!! $paragraph !!}

@endforeach
@if ($notice->quote !== null)
{!! $notice->quoteLabel ?? '' !!}: {!! $notice->quote !!}

@endif
@foreach ($notice->facts as $label => $value)
{!! $label !!}: {!! $value !!}
@endforeach

{!! $notice->buttonLabel !!}: {!! $notice->buttonUrl !!}
@if ($notice->footnote !== null)

{!! $notice->footnote !!}
@endif

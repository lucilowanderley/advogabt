{!! $heading !!}

@foreach ($lines as $line)
{!! $line !!}

@endforeach
@foreach ($items as $item)
- {!! $item['title'] !!}{!! ! empty($item['meta']) ? ' ('.$item['meta'].')' : '' !!}
@endforeach
@if ($buttonUrl)

{!! $buttonUrl !!}
@endif

@if ($paginator->hasPages())
<nav class="pagination" aria-label="Paginação">
    @if ($paginator->onFirstPage())
        <span class="disabled"><x-icon name="chevron-left" class="sm" /></span>
    @else
        <a href="{{ $paginator->previousPageUrl() }}" rel="prev"><x-icon name="chevron-left" class="sm" /></a>
    @endif
    @foreach ($elements as $element)
        @if (is_string($element))
            <span class="disabled">{{ $element }}</span>
        @endif
        @if (is_array($element))
            @foreach ($element as $page => $url)
                @if ($page == $paginator->currentPage())
                    <span class="current">{{ $page }}</span>
                @else
                    <a href="{{ $url }}">{{ $page }}</a>
                @endif
            @endforeach
        @endif
    @endforeach
    @if ($paginator->hasMorePages())
        <a href="{{ $paginator->nextPageUrl() }}" rel="next"><x-icon name="chevron-right" class="sm" /></a>
    @else
        <span class="disabled"><x-icon name="chevron-right" class="sm" /></span>
    @endif
</nav>
@endif

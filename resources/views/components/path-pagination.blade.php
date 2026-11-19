@if ($paginator->hasPages())
    @php
        $windowSize = 5;
        $lastPage = $paginator->lastPage();
        $currentPage = $paginator->currentPage();
        $windowStart = max(1, min($currentPage - 2, $lastPage - $windowSize + 1));
        $windowEnd = min($lastPage, $windowStart + $windowSize - 1);
    @endphp

    <div class="path-pagination-shell" data-pagination-name="{{ $paginator->getPageName() }}">
        <nav class="path-pagination" role="navigation" aria-label="Pagination Navigation">
            <p class="path-pagination-summary">
                Showing <strong>{{ $paginator->firstItem() }}</strong>&ndash;<strong>{{ $paginator->lastItem() }}</strong>
                of <strong>{{ $paginator->total() }}</strong>
            </p>

            <div class="path-pagination-pages">
                @if ($paginator->onFirstPage())
                    <span class="path-page-button path-page-arrow disabled" aria-disabled="true" aria-label="Previous page">&lsaquo;</span>
                @else
                    <a class="path-page-button path-page-arrow" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Previous page">&lsaquo;</a>
                @endif

                @for ($page = $windowStart; $page <= $windowEnd; $page++)
                    @if ($page === $currentPage)
                        <span class="path-page-button active" aria-current="page" aria-label="Current page, page {{ $page }}">{{ $page }}</span>
                    @else
                        <a class="path-page-button" href="{{ $paginator->url($page) }}" aria-label="Go to page {{ $page }}">{{ $page }}</a>
                    @endif
                @endfor

                @if ($paginator->hasMorePages())
                    <a class="path-page-button path-page-arrow" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Next page">&rsaquo;</a>
                @else
                    <span class="path-page-button path-page-arrow disabled" aria-disabled="true" aria-label="Next page">&rsaquo;</span>
                @endif
            </div>
        </nav>
    </div>
@endif

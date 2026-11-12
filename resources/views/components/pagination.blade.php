@props(['paginator', 'label' => 'Pages', 'asButtons' => false])

{{--
    Page links for a list. With asButtons, the pages are submit buttons of the
    surrounding form, so what was ticked on this page goes along to the next one.
--}}
@if ($paginator->hasPages())
    @php
        $window = \Illuminate\Pagination\UrlWindow::make($paginator);
        $pages = array_filter([$window['first'], is_array($window['slider']) ? '...' : null, $window['slider'], is_array($window['last']) ? '...' : null, $window['last']]);
        $pageName = $paginator->getPageName();
    @endphp

    <nav class="pagination" aria-label="{{ $label }}">
        <p class="pagination__summary">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}</p>

        <ul class="pagination__pages">
            <li>
                @if ($paginator->onFirstPage())
                    <span class="pagination__link is-disabled" aria-hidden="true">Previous</span>
                @elseif ($asButtons)
                    <button class="pagination__link" type="submit" formaction="{{ $paginator->path() }}" name="{{ $pageName }}" value="{{ $paginator->currentPage() - 1 }}">Previous</button>
                @else
                    <a class="pagination__link" href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a>
                @endif
            </li>

            @foreach ($pages as $part)
                @if ($part === '...')
                    <li><span class="pagination__gap" aria-hidden="true">…</span></li>
                @else
                    @foreach ($part as $page => $url)
                        <li>
                            @if ($page === $paginator->currentPage())
                                <span class="pagination__link is-current" aria-current="page"><span class="sr-only">Page </span>{{ $page }}</span>
                            @elseif ($asButtons)
                                <button class="pagination__link" type="submit" formaction="{{ $paginator->path() }}" name="{{ $pageName }}" value="{{ $page }}"><span class="sr-only">Page </span>{{ $page }}</button>
                            @else
                                <a class="pagination__link" href="{{ $url }}"><span class="sr-only">Page </span>{{ $page }}</a>
                            @endif
                        </li>
                    @endforeach
                @endif
            @endforeach

            <li>
                @if (! $paginator->hasMorePages())
                    <span class="pagination__link is-disabled" aria-hidden="true">Next</span>
                @elseif ($asButtons)
                    <button class="pagination__link" type="submit" formaction="{{ $paginator->path() }}" name="{{ $pageName }}" value="{{ $paginator->currentPage() + 1 }}">Next</button>
                @else
                    <a class="pagination__link" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>
                @endif
            </li>
        </ul>
    </nav>
@endif

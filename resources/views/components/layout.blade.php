@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' · ' : '' }}Higher Education Guidance System</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=red-hat-mono:400,500,700&display=swap">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    <script>document.documentElement.classList.add('js');</script>
    @stack('scripts')
</head>
<body>
    <a class="skip-link" href="#main">Skip to main content</a>

    <header class="site-header">
        <div class="container site-header__inner">
            {{-- The site name also links to the home page --}}
            <a class="brand" href="{{ route('home') }}" @if (request()->routeIs('home')) aria-current="page" @endif>
                <span class="brand__text">
                    <strong>Higher Education</strong>
                    <span>Guidance System</span>
                </span>
            </a>

            {{-- Until results are added, every page needs them first, so the menu offers only Home and adding results --}}
            @php($hasResults = session()->has(\App\Http\Controllers\AcademicProfileController::SESSION_KEY))
            <nav class="site-nav" aria-label="Main">
                <a href="{{ route('home') }}" @class(['site-nav__link', 'is-active' => request()->routeIs('home')]) @if (request()->routeIs('home')) aria-current="page" @endif>Home</a>
                @if ($hasResults)
                    @php($favouriteCount = count(session(\App\Http\Controllers\FavouriteController::SESSION_KEY, [])))
                    <a href="{{ route('academic-profile.show') }}" @class(['site-nav__link', 'is-active' => request()->routeIs('academic-profile.*')]) @if (request()->routeIs('academic-profile.*')) aria-current="page" @endif>Results</a>
                    <a href="{{ route('eligibility.index') }}" @class(['site-nav__link', 'is-active' => request()->routeIs('eligibility.*', 'trends.*')]) @if (request()->routeIs('eligibility.*')) aria-current="page" @endif>Courses</a>
                    <a href="{{ route('favourites.index') }}" data-favourites-link @class(['site-nav__link', 'is-active' => request()->routeIs('favourites.*')]) @if (request()->routeIs('favourites.*')) aria-current="page" @endif>
                        Favourites
                        @if ($favouriteCount > 0)<span class="site-nav__count"><span class="sr-only">(</span>{{ $favouriteCount }}<span class="sr-only">)</span></span>@endif
                    </a>
                @endif
            </nav>

            @unless ($hasResults)
                <a class="button button--primary site-header__cta" href="{{ route('academic-profile.create') }}">Add results</a>
            @endunless
        </div>
    </header>

    @if (session('status'))
        <div class="container">
            <p class="flash" role="status">{{ session('status') }}</p>
        </div>
    @endif

    <main id="main">
        {{ $slot }}
    </main>

    <footer class="site-footer">
        <div class="container site-footer__inner">
            <div>
                <p class="site-footer__heading">Official websites</p>
                <ul class="site-footer__links">
                    <li><a href="https://online.mohe.gov.my/epanduan/" rel="noopener" target="_blank">e-Panduan UPUOnline</a></li>
                    <li><a href="https://online.mohe.gov.my/" rel="noopener" target="_blank">UPUOnline</a></li>
                </ul>
            </div>
            <div>
                <p class="site-footer__heading">Privacy</p>
                <p class="site-footer__text">Your results are kept only while you use the site. They're never saved, and you can delete them at any time.</p>
            </div>
        </div>
        <div class="container site-footer__base">
            <p>&copy; {{ date('Y') }} Higher Education Guidance System</p>
        </div>
    </footer>
</body>
</html>

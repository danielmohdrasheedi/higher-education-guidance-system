<x-layout title="Compare courses">
    <section class="page-header">
        <div class="container page-header__split">
            <div>
                <nav class="breadcrumb" aria-label="Breadcrumb">
                    <a href="{{ route('home') }}">Home</a>
                    <span aria-hidden="true">/</span>
                    <a href="{{ route('favourites.index') }}">Favourites</a>
                    <span aria-hidden="true">/</span>
                    <span>Compare</span>
                </nav>
                <h1 class="page-header__title">Compare courses</h1>
                <p class="page-header__lead">{{ count($courses) }} courses compared, checked against your results as {{ $category->label() }}.</p>
            </div>
            <div class="page-header__actions">
                <a class="button button--dark" href="{{ route('favourites.index') }}">Change courses</a>
            </div>
        </div>
    </section>

    @php($rows = [
        'location' => 'Location',
        'result' => 'Your result',
        'requirements' => 'Entry requirements',
        'competition' => 'Competition',
        'offer_rate' => 'Offer rate',
        'applications' => 'Applications and offers',
        'merit' => 'Average merit score',
    ])

    <div class="container results">
        {{-- Wide screens: the courses side by side in a table --}}
        <div class="compare-scroll" role="region" aria-label="Course comparison" tabindex="0">
            <table class="compare-table">
                <thead>
                    <tr>
                        <td class="compare-table__corner"></td>
                        @foreach ($courses as $course)
                            <th scope="col">
                                <span class="course__name">{{ $course['programme']->programme_name }}</span>
                                <span class="course__meta">{{ $course['programme']->institution_name }} · {{ $course['programme']->programme_code }}</span>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row => $label)
                        <tr>
                            <th scope="row">{{ $label }}</th>
                            @foreach ($courses as $course)
                                <td>@include('favourites.partials.compare-value')</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Phones and tablets: one card per course, so nothing scrolls sideways --}}
        <div class="compare-cards">
            @foreach ($courses as $course)
                <article class="compare-card">
                    <h2 class="compare-card__title">
                        <span class="course__name">{{ $course['programme']->programme_name }}</span>
                        <span class="course__meta">{{ $course['programme']->institution_name }} · {{ $course['programme']->programme_code }}</span>
                    </h2>
                    <dl class="compare-card__rows">
                        @foreach ($rows as $row => $label)
                            <div class="compare-card__row">
                                <dt>{{ $label }}</dt>
                                <dd>@include('favourites.partials.compare-value')</dd>
                            </div>
                        @endforeach
                    </dl>
                </article>
            @endforeach
        </div>
    </div>
</x-layout>

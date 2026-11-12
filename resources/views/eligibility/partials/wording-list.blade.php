{{-- The items of a list e-Panduan gives under a requirement, e.g. the diplomas a course accepts --}}
@if ($items !== [])
    <ul class="wording-list">
        @foreach ($items as $item)
            <li>{{ \App\Eligibility\Wording::inEnglish($item) }}</li>
        @endforeach
    </ul>
@endif

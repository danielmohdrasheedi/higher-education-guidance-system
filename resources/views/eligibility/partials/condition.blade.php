{{-- One requirement: its status label (when shown), what it asks for and what the applicant has --}}
@php
    // A requirement with a list, e.g. accepted diplomas, has the list one item a line after its first line.
    // Lines in e-Panduan's Malay are shown in English when lang/en.json has them. A long translation can be
    // split the same way, into a first line and a list.
    $lines = explode("\n", $condition->description);
    $firstLines = explode("\n", \App\Eligibility\Wording::inEnglish(array_shift($lines)));
    $lines = [...array_slice($firstLines, 1), ...$lines];
    $text = \App\Eligibility\Wording::sentence($firstLines[0], $lines !== []);
@endphp
@if ($withStatus)
    <li class="requirement requirement--{{ $condition->status->value }}">
        <span class="requirement__status">{{ $condition->status->label() }}</span>
        <span class="requirement__text">{{ $text }}</span>
        @include('eligibility.partials.wording-list', ['items' => $lines])
        @if ($condition->reason)
            <span class="course__reason">{{ $condition->reason }}</span>
        @endif
    </li>
@else
    <li>
        {{ $text }}
        @include('eligibility.partials.wording-list', ['items' => $lines])
        @if ($condition->reason)
            <span class="course__reason">{{ $condition->reason }}</span>
        @endif
    </li>
@endif

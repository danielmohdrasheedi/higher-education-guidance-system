{{--
    The average merit score e-Panduan gives a course for the applicant's category, beside its competition.
    Expects: $programme, $category (one e-Panduan gives merit scores for).
--}}
@php($meritScore = \App\Trends\MeritScore::for($programme, $category))
<span class="course__fact" title="The average merit score on e-Panduan for {{ $category->label() }} applicants, out of 100">{{ $meritScore->score === null ? 'No merit score' : 'Merit score: '.$meritScore->value() }}</span>

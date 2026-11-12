{{--
    FR06: similar courses for a course the applicant doesn't qualify for, or has to check the rest of, shown in its
    requirements pop-up.
    Expects: $alternatives (from App\Eligibility\AlternativeCourses), $favouriteCourses, $category.
--}}
<h4 class="dialog__heading">Similar courses you qualify for</h4>
@if ($alternatives['qualify']->total() > 0)
    @include('eligibility.partials.alternative-course-list', ['courses' => $alternatives['qualify'], 'label' => 'Similar courses you qualify for'])
@else
    <p class="course__reason">You don't qualify for any course like this one.</p>
@endif

@if ($alternatives['check-yourself']->total() > 0)
    <h4 class="dialog__heading">Similar courses to check yourself</h4>
    <p class="course__reason">Your results meet every requirement this site can check, but each course has a few you need to check yourself.</p>
    @include('eligibility.partials.alternative-course-list', ['courses' => $alternatives['check-yourself'], 'label' => 'Similar courses to check yourself'])
@endif

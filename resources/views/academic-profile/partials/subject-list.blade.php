{{--
    A labelled list of subject and grade rows for one level.
    Expects: $level, $levelLabel, $label, $hint, $rows, $subjects, $grades, $maxRows.
    Optional: $levels, the levels a subject can be taken at, e.g. IB's HL and SL, for a level column.
--}}
@php
    $levels ??= null;
@endphp
<div class="field" data-section="{{ $level }}">
    <span class="field__label">{{ $label }}</span>
    <p class="field__hint">{{ $hint }}</p>
    <div class="subject-list" data-subject-list="{{ $level }}" data-max-rows="{{ $maxRows }}">
        <div @class(['subject-list__head', 'subject-list__head--levels' => $levels]) aria-hidden="true">
            <span>Subject</span>
            @if ($levels)
                <span>Level</span>
            @endif
            <span>Grade</span>
        </div>
        <div data-rows>
            @foreach ($rows as $index => $row)
                @include('academic-profile.partials.subject-row', [
                    'level' => $level,
                    'levelLabel' => $levelLabel,
                    'index' => $index,
                    'row' => $row,
                    'subjects' => $subjects,
                    'grades' => $grades,
                    'levels' => $levels,
                ])
            @endforeach
        </div>
        <template data-row-template>
            @include('academic-profile.partials.subject-row', [
                'level' => $level,
                'levelLabel' => $levelLabel,
                'index' => '__INDEX__',
                'row' => [],
                'subjects' => $subjects,
                'grades' => $grades,
                'levels' => $levels,
            ])
        </template>
        <button type="button" class="button button--outline" data-add-row>+ Add a subject</button>
    </div>
    @error("{$level}_subjects")
        <p class="field__error">{{ $message }}</p>
    @enderror
</div>

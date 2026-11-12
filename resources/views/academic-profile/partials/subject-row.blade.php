{{--
    One subject and grade row.
    Expects: $level ("spm", "stpm" or "matriculation"), $index, $row (array with subject and grade), $subjects, $grades.
    Optional: $levelLabel, the level as shown to screen reader users (defaults to the level in capitals), and $levels, the
    levels a subject can be taken at, e.g. IB's HL and SL, for a level select.
--}}
@php
    $field = $level.'_subjects';
    $subjectError = $errors->first("{$field}.{$index}.subject");
    $gradeError = $errors->first("{$field}.{$index}.grade");
    $takenAtError = $errors->first("{$field}.{$index}.level");
    $levelLabel ??= strtoupper($level);
    $levels ??= null;
@endphp

<div @class(['subject-row', 'subject-row--levels' => $levels]) data-subject-row>
    <div class="field">
        <label class="sr-only" for="{{ $field }}-{{ $index }}-subject">{{ $levelLabel }} subject</label>
        <select
            id="{{ $field }}-{{ $index }}-subject"
            name="{{ $field }}[{{ $index }}][subject]"
            @class(['input', 'input--invalid' => $subjectError])
            @if ($subjectError) aria-invalid="true" aria-describedby="{{ $field }}-{{ $index }}-subject-error" @endif
        >
            <option value="">Choose a subject</option>
            @foreach ($subjects as $subject)
                <option value="{{ $subject }}" @selected(($row['subject'] ?? '') === $subject)>{{ $subject }}</option>
            @endforeach
        </select>
        @if ($subjectError)
            <p class="field__error" id="{{ $field }}-{{ $index }}-subject-error">{{ $subjectError }}</p>
        @endif
    </div>

    @if ($levels)
        <div class="field">
            <label class="sr-only" for="{{ $field }}-{{ $index }}-level">{{ $levelLabel }} level</label>
            <select
                id="{{ $field }}-{{ $index }}-level"
                name="{{ $field }}[{{ $index }}][level]"
                @class(['input', 'input--invalid' => $takenAtError])
                @if ($takenAtError) aria-invalid="true" aria-describedby="{{ $field }}-{{ $index }}-level-error" @endif
            >
                <option value="">Level</option>
                @foreach ($levels as $value => $name)
                    <option value="{{ $value }}" @selected(($row['level'] ?? '') === $value)>{{ $name }}</option>
                @endforeach
            </select>
            @if ($takenAtError)
                <p class="field__error" id="{{ $field }}-{{ $index }}-level-error">{{ $takenAtError }}</p>
            @endif
        </div>
    @endif

    <div class="field">
        <label class="sr-only" for="{{ $field }}-{{ $index }}-grade">{{ $levelLabel }} grade</label>
        <select
            id="{{ $field }}-{{ $index }}-grade"
            name="{{ $field }}[{{ $index }}][grade]"
            @class(['input', 'input--invalid' => $gradeError])
            @if ($gradeError) aria-invalid="true" aria-describedby="{{ $field }}-{{ $index }}-grade-error" @endif
        >
            <option value="">Grade</option>
            @foreach ($grades as $grade)
                <option value="{{ $grade }}" @selected(($row['grade'] ?? '') === $grade)>{{ $grade }}</option>
            @endforeach
        </select>
        @if ($gradeError)
            <p class="field__error" id="{{ $field }}-{{ $index }}-grade-error">{{ $gradeError }}</p>
        @endif
    </div>

    <button type="button" class="icon-button" data-remove-row aria-label="Remove this {{ $levelLabel }} subject">
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
    </button>
</div>

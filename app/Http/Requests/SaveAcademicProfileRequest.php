<?php

namespace App\Http\Requests;

use App\ApplicantCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAcademicProfileRequest extends FormRequest
{
    /**
     * The academic profile form is open to every visitor.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Drop subject rows the applicant left completely empty and re-index the rest.
     */
    protected function prepareForValidation(): void
    {
        foreach (['spm_subjects', 'stpm_subjects', 'matriculation_subjects', 'diploma_subjects', 'equivalent_subjects', 'prau_subjects', 'ib_subjects'] as $field) {
            $rows = $this->input($field);

            if (! is_array($rows)) {
                continue;
            }

            $filledRows = array_filter(
                $rows,
                fn (mixed $row): bool => ! is_array($row) || filled($row['subject'] ?? null) || filled($row['level'] ?? null) || filled($row['grade'] ?? null),
            );

            $this->merge([$field => array_values($filledRows)]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Fields that do not apply to the selected applicant category are excluded,
     * so they are neither validated nor saved.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $category = $this->applicantCategory();

        $excludeUnless = fn (bool $applies): mixed => Rule::excludeIf($category === null || ! $applies);

        return [
            'applicant_category' => ['required', Rule::enum(ApplicantCategory::class)],
            'gender' => ['required', Rule::in(array_keys(config('academic.genders')))],

            'spm_stream' => [$excludeUnless($category?->requiresSpmStream() ?? false), 'required', Rule::in(array_keys(config('academic.spm_streams')))],

            'spm_subjects' => ['required', 'array', 'min:1', 'max:20'],
            'spm_subjects.*' => ['array:subject,grade'],
            'spm_subjects.*.subject' => ['required', 'string', 'distinct', Rule::in(config('academic.spm_subjects'))],
            'spm_subjects.*.grade' => ['required', Rule::in(config('academic.spm_grades'))],

            'stpm_subjects' => [$excludeUnless($category?->requiresStpmResults() ?? false), 'required', 'array', 'min:1', 'max:6'],
            'stpm_subjects.*' => [$excludeUnless($category?->requiresStpmResults() ?? false), 'array:subject,grade'],
            'stpm_subjects.*.subject' => [$excludeUnless($category?->requiresStpmResults() ?? false), 'required', 'string', 'distinct', Rule::in(config('academic.stpm_subjects'))],
            'stpm_subjects.*.grade' => [$excludeUnless($category?->requiresStpmResults() ?? false), 'required', Rule::in(config('academic.stpm_grades'))],

            'matriculation_subjects' => [$excludeUnless($category?->requiresMatriculationResults() ?? false), 'required', 'array', 'min:1', 'max:12'],
            'matriculation_subjects.*' => [$excludeUnless($category?->requiresMatriculationResults() ?? false), 'array:subject,grade'],
            'matriculation_subjects.*.subject' => [$excludeUnless($category?->requiresMatriculationResults() ?? false), 'required', 'string', 'distinct', Rule::in(config('academic.matriculation_subjects'))],
            'matriculation_subjects.*.grade' => [$excludeUnless($category?->requiresMatriculationResults() ?? false), 'required', Rule::in(config('academic.stpm_grades'))],

            'diploma_subjects' => [$excludeUnless($category?->acceptsDiplomaResults() ?? false), 'nullable', 'array', 'max:12'],
            'diploma_subjects.*' => [$excludeUnless($category?->acceptsDiplomaResults() ?? false), 'array:subject,grade'],
            'diploma_subjects.*.subject' => [$excludeUnless($category?->acceptsDiplomaResults() ?? false), 'required', 'string', 'distinct', Rule::in(config('academic.diploma_subjects'))],
            'diploma_subjects.*.grade' => [$excludeUnless($category?->acceptsDiplomaResults() ?? false), 'required', Rule::in(config('academic.stpm_grades'))],

            'equivalent_subjects' => [$excludeUnless($category?->requiresEquivalentResults() ?? false), 'required', 'array', 'min:1', 'max:10'],
            'equivalent_subjects.*' => [$excludeUnless($category?->requiresEquivalentResults() ?? false), 'array:subject,grade'],
            'equivalent_subjects.*.subject' => [$excludeUnless($category?->requiresEquivalentResults() ?? false), 'required', 'string', 'distinct', Rule::in(config('academic.equivalent_subjects'))],
            'equivalent_subjects.*.grade' => [$excludeUnless($category?->requiresEquivalentResults() ?? false), 'required', Rule::in(config('academic.equivalent_grades'))],

            'ib_subjects' => [$excludeUnless($category?->requiresIbResults() ?? false), 'required', 'array', 'min:1', 'max:10'],
            'ib_subjects.*' => [$excludeUnless($category?->requiresIbResults() ?? false), 'array:subject,level,grade'],
            'ib_subjects.*.subject' => [$excludeUnless($category?->requiresIbResults() ?? false), 'required', 'string', 'distinct', Rule::in(config('academic.ib_subjects'))],
            'ib_subjects.*.level' => [$excludeUnless($category?->requiresIbResults() ?? false), 'required', Rule::in(array_keys(config('academic.ib_levels')))],
            'ib_subjects.*.grade' => [$excludeUnless($category?->requiresIbResults() ?? false), 'required', Rule::in(config('academic.ib_grades'))],
            'ib_points' => [$excludeUnless($category?->requiresIbResults() ?? false), 'nullable', 'integer', 'between:0,45'],

            'atar' => [$excludeUnless($category?->acceptsAusmatScores() ?? false), 'nullable', 'numeric', 'between:0,99.95', 'decimal:0,2'],
            'ausmat_aggregate' => [$excludeUnless($category?->acceptsAusmatScores() ?? false), 'nullable', 'integer', 'between:0,100'],

            'prau_subjects' => [$excludeUnless($category?->requiresPrauResults() ?? false), 'required', 'array', 'min:1', 'max:10'],
            'prau_subjects.*' => [$excludeUnless($category?->requiresPrauResults() ?? false), 'array:subject,grade'],
            'prau_subjects.*.subject' => [$excludeUnless($category?->requiresPrauResults() ?? false), 'required', 'string', 'distinct', Rule::in(config('academic.prau_subjects'))],
            'prau_subjects.*.grade' => [$excludeUnless($category?->requiresPrauResults() ?? false), 'required', Rule::in(config('academic.stpm_grades'))],

            'cgpa' => [
                $excludeUnless($category?->acceptsCgpa() ?? false),
                ($category?->requiresCgpa() ?? false) ? 'required' : 'nullable',
                'numeric',
                'between:0,4',
                'decimal:0,2',
            ],

            'muet_band' => [$excludeUnless($category?->requiresMuet() ?? false), 'required', Rule::in([...config('academic.muet_bands'), config('academic.no_muet')])],

            'stam_grade' => [$excludeUnless($category?->requiresStamGrade() ?? false), 'required', Rule::in(config('academic.stam_grades'))],

            'age' => ['required', 'integer', 'between:15,70'],
            'sports_level' => ['nullable', Rule::in(array_keys(config('academic.sports_levels')))],
            'eligible_descent' => ['required', Rule::in(array_keys(config('academic.bumiputera_statuses')))],
            'colour_blind' => ['required', Rule::in(array_keys(config('academic.colour_visions')))],
            'ielts_band' => ['nullable', Rule::in(config('academic.ielts_bands'))],
            'toefl_ibt_score' => ['nullable', 'integer', 'between:0,120'],
            'music_grade' => ['nullable', Rule::in(array_keys(config('academic.music_grades')))],
            'quran_juz' => ['nullable', 'integer', 'between:0,30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'applicant_category.required' => 'Choose what you are applying with.',
            'gender.required' => 'Choose your gender.',
            'spm_stream.required' => 'Choose your SPM stream.',
            'spm_subjects.required' => 'Add at least one SPM subject.',
            'spm_subjects.*.subject.required' => 'Choose a subject in every SPM row.',
            'spm_subjects.*.subject.in' => 'Choose your SPM subjects from the list.',
            'spm_subjects.*.subject.distinct' => 'You have added the same SPM subject more than once.',
            'spm_subjects.*.grade.required' => 'Choose a grade for every SPM subject.',
            'stpm_subjects.required' => 'Add at least one STPM subject.',
            'stpm_subjects.*.subject.required' => 'Choose a subject in every STPM row.',
            'stpm_subjects.*.subject.in' => 'Choose your STPM subjects from the list.',
            'stpm_subjects.*.subject.distinct' => 'You have added the same STPM subject more than once.',
            'stpm_subjects.*.grade.required' => 'Choose a grade for every STPM subject.',
            'matriculation_subjects.required' => 'Add at least one matriculation or foundation subject.',
            'matriculation_subjects.*.subject.required' => 'Choose a subject in every matriculation or foundation row.',
            'matriculation_subjects.*.subject.in' => 'Choose your matriculation or foundation subjects from the list.',
            'matriculation_subjects.*.subject.distinct' => 'You have added the same matriculation or foundation subject more than once.',
            'matriculation_subjects.*.grade.required' => 'Choose a grade for every matriculation or foundation subject.',
            'diploma_subjects.*.subject.required' => 'Choose a subject in every diploma row.',
            'diploma_subjects.*.subject.in' => 'Choose your diploma subjects from the list.',
            'diploma_subjects.*.subject.distinct' => 'You have added the same diploma subject more than once.',
            'diploma_subjects.*.grade.required' => 'Choose a grade for every diploma subject.',
            'equivalent_subjects.required' => 'Add at least one subject from your qualification.',
            'equivalent_subjects.*.subject.required' => 'Choose a subject in every qualification row.',
            'equivalent_subjects.*.subject.in' => 'Choose your qualification subjects from the list.',
            'equivalent_subjects.*.subject.distinct' => 'You have added the same qualification subject more than once.',
            'equivalent_subjects.*.grade.required' => 'Choose a grade for every qualification subject.',
            'ib_subjects.required' => 'Add at least one IB subject.',
            'ib_subjects.*.subject.required' => 'Choose a subject in every IB row.',
            'ib_subjects.*.subject.in' => 'Choose your IB subjects from the list.',
            'ib_subjects.*.subject.distinct' => 'You have added the same IB subject more than once.',
            'ib_subjects.*.level.required' => 'Choose HL or SL for every IB subject.',
            'ib_subjects.*.level.in' => 'Choose HL or SL for every IB subject.',
            'ib_subjects.*.grade.required' => 'Choose a grade for every IB subject.',
            'ib_points.integer' => 'Enter your IB total as a whole number, like 32.',
            'ib_points.between' => 'Your IB total must be between 0 and 45.',
            'atar.numeric' => 'Your ATAR must be a number, like 85.50.',
            'atar.between' => 'Your ATAR must be between 0 and 99.95.',
            'atar.decimal' => 'Use no more than 2 decimal places for your ATAR.',
            'ausmat_aggregate.integer' => 'Enter your AUSMAT aggregate as a whole number, like 80.',
            'ausmat_aggregate.between' => 'Your AUSMAT aggregate must be between 0 and 100.',
            'prau_subjects.required' => 'Add at least one Pra-U subject.',
            'prau_subjects.*.subject.required' => 'Choose a subject in every Pra-U row.',
            'prau_subjects.*.subject.in' => 'Choose your Pra-U subjects from the list.',
            'prau_subjects.*.subject.distinct' => 'You have added the same Pra-U subject more than once.',
            'prau_subjects.*.grade.required' => 'Choose a grade for every Pra-U subject.',
            'cgpa.required' => 'Enter your CGPA.',
            'cgpa.numeric' => 'Your CGPA must be a number, like 3.50.',
            'cgpa.between' => 'Your CGPA must be between 0.00 and 4.00.',
            'cgpa.decimal' => 'Use no more than 2 decimal places for your CGPA.',
            'muet_band.required' => 'Choose your MUET band.',
            'stam_grade.required' => 'Choose your STAM grade.',
            'age.required' => 'Enter your age.',
            'age.integer' => 'Enter your age as a whole number, like 19.',
            'age.between' => 'Your age must be between 15 and 70.',
            'sports_level.in' => 'Choose your sports level from the list.',
            'eligible_descent.required' => 'Choose your Bumiputera status.',
            'eligible_descent.in' => 'Choose your Bumiputera status from the list.',
            'colour_blind.required' => 'Choose your colour vision.',
            'colour_blind.in' => 'Choose your colour vision from the list.',
            'ielts_band.in' => 'Choose your IELTS band from the list.',
            'toefl_ibt_score.integer' => 'Enter your TOEFL iBT score as a whole number, like 80.',
            'toefl_ibt_score.between' => 'Your TOEFL iBT score must be between 0 and 120.',
            'music_grade.in' => 'Choose your music grade from the list.',
            'quran_juz.integer' => 'Enter the number of juz as a whole number, like 30.',
            'quran_juz.between' => 'The number of juz must be between 0 and 30.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'applicant_category' => 'applicant category',
            'spm_stream' => 'SPM stream',
            'spm_subjects.*.subject' => 'SPM subject',
            'spm_subjects.*.grade' => 'SPM grade',
            'stpm_subjects.*.subject' => 'STPM subject',
            'stpm_subjects.*.grade' => 'STPM grade',
            'matriculation_subjects.*.subject' => 'matriculation or foundation subject',
            'matriculation_subjects.*.grade' => 'matriculation or foundation grade',
            'diploma_subjects.*.subject' => 'diploma subject',
            'diploma_subjects.*.grade' => 'diploma grade',
            'equivalent_subjects.*.subject' => 'qualification subject',
            'equivalent_subjects.*.grade' => 'qualification grade',
            'ib_subjects.*.subject' => 'IB subject',
            'ib_subjects.*.level' => 'IB level',
            'ib_subjects.*.grade' => 'IB grade',
            'ib_points' => 'IB total points',
            'atar' => 'ATAR',
            'ausmat_aggregate' => 'AUSMAT aggregate',
            'prau_subjects.*.subject' => 'Pra-U subject',
            'prau_subjects.*.grade' => 'Pra-U grade',
            'cgpa' => 'CGPA',
            'muet_band' => 'MUET band',
            'stam_grade' => 'STAM grade',
            'age' => 'age',
            'sports_level' => 'sports level',
            'eligible_descent' => 'Bumiputera status',
            'colour_blind' => 'colour vision',
            'ielts_band' => 'IELTS band',
            'toefl_ibt_score' => 'TOEFL iBT score',
            'music_grade' => 'music grade',
            'quran_juz' => 'Quran memorisation',
        ];
    }

    /**
     * The academic profile to keep in the session, with every attribute present.
     *
     * @return array{
     *     applicant_category: string,
     *     gender: string,
     *     spm_stream: ?string,
     *     spm_subjects: list<array{subject: string, grade: string}>,
     *     stpm_subjects: ?list<array{subject: string, grade: string}>,
     *     matriculation_subjects: ?list<array{subject: string, grade: string}>,
     *     diploma_subjects: ?list<array{subject: string, grade: string}>,
     *     equivalent_subjects: ?list<array{subject: string, grade: string}>,
     *     prau_subjects: ?list<array{subject: string, grade: string}>,
     *     ib_subjects: ?list<array{subject: string, level: string, grade: string}>,
     *     ib_points: ?int,
     *     atar: ?float,
     *     ausmat_aggregate: ?int,
     *     cgpa: ?float,
     *     muet_band: ?string,
     *     stam_grade: ?string,
     *     age: int,
     *     sports_level: ?string,
     *     eligible_descent: string,
     *     colour_blind: string,
     *     ielts_band: ?string,
     *     toefl_ibt_score: ?int,
     *     music_grade: ?string,
     *     quran_juz: ?int,
     *     saved_at: string,
     * }
     */
    public function profile(): array
    {
        $validated = $this->validated();

        return [
            'applicant_category' => $validated['applicant_category'],
            'gender' => $validated['gender'],
            'spm_stream' => $validated['spm_stream'] ?? null,
            'spm_subjects' => array_values($validated['spm_subjects']),
            'stpm_subjects' => isset($validated['stpm_subjects']) ? array_values($validated['stpm_subjects']) : null,
            'matriculation_subjects' => isset($validated['matriculation_subjects']) ? array_values($validated['matriculation_subjects']) : null,
            'diploma_subjects' => isset($validated['diploma_subjects']) ? array_values($validated['diploma_subjects']) : null,
            'equivalent_subjects' => isset($validated['equivalent_subjects']) ? array_values($validated['equivalent_subjects']) : null,
            'prau_subjects' => isset($validated['prau_subjects']) ? array_values($validated['prau_subjects']) : null,
            'ib_subjects' => isset($validated['ib_subjects']) ? array_values($validated['ib_subjects']) : null,
            'ib_points' => isset($validated['ib_points']) ? (int) $validated['ib_points'] : null,
            'atar' => isset($validated['atar']) ? round((float) $validated['atar'], 2) : null,
            'ausmat_aggregate' => isset($validated['ausmat_aggregate']) ? (int) $validated['ausmat_aggregate'] : null,
            'cgpa' => isset($validated['cgpa']) ? round((float) $validated['cgpa'], 2) : null,
            'muet_band' => $validated['muet_band'] ?? null,
            'stam_grade' => $validated['stam_grade'] ?? null,
            'age' => (int) $validated['age'],
            'sports_level' => $validated['sports_level'] ?? null,
            'eligible_descent' => $validated['eligible_descent'],
            'colour_blind' => $validated['colour_blind'],
            'ielts_band' => $validated['ielts_band'] ?? null,
            'toefl_ibt_score' => isset($validated['toefl_ibt_score']) ? (int) $validated['toefl_ibt_score'] : null,
            'music_grade' => $validated['music_grade'] ?? null,
            'quran_juz' => isset($validated['quran_juz']) ? (int) $validated['quran_juz'] : null,
            'saved_at' => now()->toIso8601String(),
        ];
    }

    private function applicantCategory(): ?ApplicantCategory
    {
        $value = $this->input('applicant_category');

        return is_string($value) ? ApplicantCategory::tryFrom($value) : null;
    }
}

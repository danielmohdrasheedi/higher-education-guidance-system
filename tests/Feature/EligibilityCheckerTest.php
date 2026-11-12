<?php

use App\Eligibility\ApplicantResults;
use App\Eligibility\ConditionResult;
use App\Eligibility\EligibilityChecker;
use App\Eligibility\EligibilityResult;
use App\Eligibility\RequirementStatus;
use App\Models\EligibilityRequirement;

/**
 * An STPM Science applicant who meets the usual general requirements.
 *
 * @param  array<string, mixed>  $overrides
 */
function stpmApplicant(array $overrides = []): ApplicantResults
{
    return ApplicantResults::fromProfile(array_merge([
        'applicant_category' => 'stpm_science_current_year',
        'gender' => 'female',
        'spm_subjects' => [
            ['subject' => 'Bahasa Melayu', 'grade' => 'B'],
            ['subject' => 'Sejarah', 'grade' => 'C'],
            ['subject' => 'Matematik', 'grade' => 'A'],
            ['subject' => 'Fizik', 'grade' => 'B+'],
        ],
        'stpm_subjects' => [
            ['subject' => 'Pengajian Am', 'grade' => 'B'],
            ['subject' => 'Mathematics T', 'grade' => 'B+'],
            ['subject' => 'Physics', 'grade' => 'C'],
            ['subject' => 'Chemistry', 'grade' => 'C+'],
        ],
        'cgpa' => 3.2,
        'muet_band' => '3.5',
        'stam_grade' => null,
    ], $overrides));
}

/**
 * @param  list<string>  $subjects
 * @return array<string, mixed>
 */
function subjectCondition(string $group, string $grade, array $subjects, int $count = 1, string $type = 'subject_requirement'): array
{
    return [
        'condition_type' => $type,
        'condition_group' => $group,
        'condition_count' => $count,
        'minimum_grade' => $grade,
        'subject_list' => $subjects,
    ];
}

/**
 * @param  list<array<string, mixed>>  $conditions
 * @return array<string, mixed>
 */
function requirementSection(string $group, array $conditions): array
{
    return ['condition_group' => $group, 'conditions' => $conditions];
}

/**
 * @param  list<array<string, mixed>>  $general
 * @param  list<array<string, mixed>|string>  $specific
 */
function checkEligibility(ApplicantResults $applicant, array $general, array $specific = [], string $level = 'stpm'): EligibilityResult
{
    return (new EligibilityChecker)->check($general, $specific, $applicant, $level);
}

describe('general requirements', function () {
    it('is met when every section is met', function () {
        $result = checkEligibility(stpmApplicant(), [
            requirementSection('SPM', [subjectCondition('SPM', 'C', ['Bahasa Melayu', 'Bahasa Melayu (Kertas Julai)'])]),
            ['condition_group' => 'Logic', 'conditions' => 'AND'],
            requirementSection('STPM', [
                ['condition_type' => 'cgpa_requirement', 'condition_group' => 'STPM', 'condition_operator' => '>=', 'condition_value' => 2.0],
                subjectCondition('STPM', 'C', ['Pengajian Am']),
            ]),
            ['condition_group' => 'Logic', 'conditions' => 'AND'],
            requirementSection('MUET', [['condition_type' => 'muet_requirement', 'condition_operator' => '>=', 'condition_value' => 1.0]]),
        ]);

        expect($result->status)->toBe(RequirementStatus::Met)
            ->and($result->conditions)->toHaveCount(4);
    });

    it('is not met when a grade is below the minimum, and says what the applicant has', function () {
        $result = checkEligibility(stpmApplicant(), [
            requirementSection('SPM', [subjectCondition('SPM', 'B', ['Sejarah'])]),
        ]);

        expect($result->status)->toBe(RequirementStatus::NotMet)
            ->and($result->unmetConditions()[0]->description)->toBe('Grade B or better in SPM Sejarah')
            ->and($result->unmetConditions()[0]->reason)->toBe('Your grade: Sejarah: C.');
    });

    it('accepts any one of the listed alternatives', function () {
        $applicant = stpmApplicant(['spm_subjects' => [['subject' => 'Bahasa Melayu (Kertas Julai)', 'grade' => 'C']]]);

        $result = checkEligibility($applicant, [
            requirementSection('SPM', [subjectCondition('SPM', 'C', ['Bahasa Melayu', 'Bahasa Melayu (Kertas Julai)'])]),
        ]);

        expect($result->status)->toBe(RequirementStatus::Met);
    });

    it('does not count a subject twice for "any other subjects"', function () {
        $result = checkEligibility(stpmApplicant(), [
            requirementSection('STPM', [
                subjectCondition('STPM', 'C', ['Pengajian Am']),
                ['condition_type' => 'any_subject_requirement', 'condition_group' => 'STPM', 'condition_count' => 3, 'minimum_grade' => 'C', 'subject_list' => 'ANY'],
            ]),
        ]);

        expect($result->status)->toBe(RequirementStatus::Met);

        $result = checkEligibility(stpmApplicant(), [
            requirementSection('STPM', [
                subjectCondition('STPM', 'C', ['Pengajian Am']),
                ['condition_type' => 'any_subject_requirement', 'condition_group' => 'STPM', 'condition_count' => 4, 'minimum_grade' => 'C', 'subject_list' => 'ANY'],
            ]),
        ]);

        expect($result->status)->toBe(RequirementStatus::NotMet)
            ->and($result->unmetConditions()[0]->description)->toBe('Grade C or better in 4 other STPM subjects')
            ->and($result->unmetConditions()[0]->reason)->toBe('You have 3.');
    });

    it('meets an OR section through the qualification the applicant holds', function () {
        $matriculationApplicant = stpmApplicant([
            'applicant_category' => 'matriculation_or_foundation_science',
            'stpm_subjects' => [],
        ]);

        $general = [
            requirementSection('MATRIK / ASASI', [['condition_type' => 'cgpa_requirement', 'condition_group' => 'MATRIK / ASASI', 'condition_operator' => '>=', 'condition_value' => 2.0]]),
            ['condition_group' => 'Logic', 'conditions' => 'OR'],
            requirementSection('STPM', [subjectCondition('STPM', 'C', ['Pengajian Am'])]),
            ['condition_group' => 'Logic', 'conditions' => 'AND'],
            requirementSection('MUET', [['condition_type' => 'muet_requirement', 'condition_operator' => '>=', 'condition_value' => 1.0]]),
        ];

        $result = checkEligibility($matriculationApplicant, $general);

        expect($result->status)->toBe(RequirementStatus::Met)
            ->and(collect($result->conditions)->pluck('description'))->not->toContain('Grade C or better in STPM Pengajian Am');

        $result = checkEligibility(stpmApplicant(['applicant_category' => 'matriculation_or_foundation_science', 'cgpa' => 1.5]), $general);

        expect($result->status)->toBe(RequirementStatus::NotMet);
    });

    it('is not met when a section needs a qualification the applicant does not hold', function () {
        $result = checkEligibility(stpmApplicant(), [
            requirementSection('STAM', [['condition_type' => 'stam_requirement', 'condition_operator' => '>=', 'condition_value' => 'JAYYID']]),
        ]);

        expect($result->status)->toBe(RequirementStatus::NotMet)
            ->and($result->unmetConditions()[0]->description)->toBe('A STAM qualification');
    });
});

describe('specific requirements', function () {
    it('needs the given number of entries from a subject group', function () {
        $group = subjectCondition('Khas', 'C', ['Mathematics T', 'Physics / Chemistry / Biology', 'Ekonomi'], 2, 'subject_group_requirement');

        expect(checkEligibility(stpmApplicant(), [], [$group])->status)->toBe(RequirementStatus::Met);

        $group['condition_count'] = 3;
        $result = checkEligibility(stpmApplicant(), [], [$group]);

        expect($result->status)->toBe(RequirementStatus::NotMet)
            ->and($result->unmetConditions()[0]->reason)->toBe('You have 2 of 3.');
    });

    it('is met when any one set of requirements joined by "ATAU" is met', function () {
        $alternatives = [
            'condition_type' => 'alternative_requirements',
            'alternatives' => [
                [subjectCondition('STPM', 'C', ['Biology'])],
                [subjectCondition('STPM', 'C', ['Mathematics T']), subjectCondition('SPM', 'C', ['Fizik'])],
            ],
        ];

        expect(checkEligibility(stpmApplicant(), [], [$alternatives])->status)->toBe(RequirementStatus::Met);

        $alternatives['alternatives'][1][1] = subjectCondition('SPM', 'A+', ['Fizik']);
        $result = checkEligibility(stpmApplicant(), [], [$alternatives]);

        expect($result->status)->toBe(RequirementStatus::NotMet)
            ->and(collect($result->unmetConditions())->pluck('description')->all())->toBe([
                'Grade C or better in STPM Biology',
                'Grade A+ or better in SPM Fizik',
            ])
            ->and(collect($result->unmetConditions())->pluck('option')->all())->toBe([1, 2])
            ->and(collect($result->unmetConditions())->pluck('optionCount')->all())->toBe([2, 2]);
    });

    it('groups the requirements of each option together for display', function () {
        $result = checkEligibility(stpmApplicant(), [], [
            ['condition_type' => 'muet_requirement', 'condition_operator' => '>=', 'condition_value' => 5.0],
            [
                'condition_type' => 'alternative_requirements',
                'alternatives' => [
                    [subjectCondition('STPM', 'A+', ['Biology'])],
                    [subjectCondition('STPM', 'A+', ['Mathematics T']), subjectCondition('SPM', 'A+', ['Fizik'])],
                ],
            ],
        ]);

        $grouped = ConditionResult::grouped($result->unmetConditions());

        expect($grouped)->toHaveCount(2)
            ->and($grouped[0])->toHaveKey('condition')
            ->and(array_keys($grouped[1]['options']))->toBe([1, 2])
            ->and($grouped[1]['options'][1])->toHaveCount(1)
            ->and($grouped[1]['options'][2])->toHaveCount(2);
    });

    it('says none of the subjects is in the results when a choice of subjects is missing', function () {
        $result = checkEligibility(stpmApplicant(), [], [
            subjectCondition('SPM', 'C', ['Matematik Tambahan / Kimia']),
        ]);

        expect($result->conditions[0]->description)->toBe('Grade C or better in one SPM subject: Matematik Tambahan / Kimia')
            ->and($result->conditions[0]->reason)->toBe('None of these subjects is in your results.');
    });

    it('treats a set of requirements that cannot be read as unknown', function () {
        $alternatives = [
            'condition_type' => 'alternative_requirements',
            'alternatives' => [[subjectCondition('STPM', 'A', ['Biology'])], []],
        ];

        expect(checkEligibility(stpmApplicant(), [], [$alternatives])->status)->toBe(RequirementStatus::Unknown);
    });

    it('does not accept a set of requirements for a qualification the applicant does not hold', function () {
        $alternatives = [
            'condition_type' => 'alternative_requirements',
            'alternatives' => [
                [['condition_type' => 'cgpa_requirement', 'condition_group' => 'MATRIKULASI', 'condition_operator' => '>=', 'condition_value' => 2.0]],
                [subjectCondition('STPM', 'A', ['Biology'])],
            ],
        ];

        expect(checkEligibility(stpmApplicant(), [], [$alternatives])->status)->toBe(RequirementStatus::NotMet);
    });

    it('counts each choice separately when one entry lists more choices than needed', function () {
        $condition = subjectCondition('STPM', 'C', ['Mathematics T / Chemistry / Physics / Information And Communication Technology'], 2, 'subject_group_requirement');

        expect(checkEligibility(stpmApplicant(), [], [$condition])->status)->toBe(RequirementStatus::Met)
            ->and(checkEligibility(stpmApplicant(), [], [subjectCondition('STPM', 'C', ['Biology / Chemistry'], 2, 'subject_group_requirement')])->status)
            ->toBe(RequirementStatus::Unknown);
    });

    it('reads a choice between subjects collected as a list', function () {
        $group = subjectCondition('Khas', 'C', ['Mathematics T', ['Physics', 'Chemistry', 'Biology'], 'Ekonomi'], 2, 'subject_group_requirement');

        expect(checkEligibility(stpmApplicant(), [], [$group])->status)->toBe(RequirementStatus::Met);

        $group['subject_list'] = [['Physics', 'Chemistry'], ['Biology', 'Ekonomi']];
        $result = checkEligibility(stpmApplicant(), [], [$group]);

        expect($result->status)->toBe(RequirementStatus::NotMet)
            ->and($result->unmetConditions()[0]->description)->toBe('Grade C or better in 2 of these STPM subjects: Physics / Chemistry; Biology / Ekonomi');
    });

    it('checks a descent condition collected into a subject list on its own', function () {
        $group = subjectCondition('STPM', 'C', ['Mathematics T', 'Physics / Biology', 'Berketurunan Melayu, Anak Negeri Sabah, Anak Negeri Sarawak DAN Orang Asli Sahaja.'], 2, 'subject_group_requirement');
        $result = checkEligibility(stpmApplicant(['eligible_descent' => 'yes']), [], [$group]);

        expect($result->status)->toBe(RequirementStatus::Met)
            ->and($result->conditions[0]->description)->toBe('Grade C or better in 2 of these STPM subjects: Mathematics T; Physics / Biology')
            ->and($result->conditions[1]->description)->toBe('Bumiputera');
    });

    it('does not qualify an applicant with none of the listed SPM subjects, including Fundamentals of Programming', function () {
        $result = checkEligibility(stpmApplicant(['spm_subjects' => [['subject' => 'Matematik', 'grade' => 'A']]]), [], [
            subjectCondition('SPM', 'C', [['Fizik', 'Kimia', 'Biologi', 'Fundamentals Of Programming', 'Sains Komputer']]),
        ]);

        expect($result->status)->toBe(RequirementStatus::NotMet);
    });

    it('leaves subjects it does not need for later "other subjects" conditions', function () {
        $result = checkEligibility(stpmApplicant(), [], [
            subjectCondition('STPM', 'C', ['Mathematics T', 'Physics', 'Chemistry'], 1, 'subject_group_requirement'),
            ['condition_type' => 'any_subject_requirement', 'condition_group' => 'STPM', 'condition_count' => 2, 'minimum_grade' => 'C', 'subject_list' => 'ANY'],
        ]);

        expect($result->status)->toBe(RequirementStatus::Met);
    });

    it('words a group needing one subject as a list of alternatives', function () {
        $result = checkEligibility(stpmApplicant(), [], [
            subjectCondition('STPM', 'B', ['Biology', 'Chemistry'], 1, 'subject_group_requirement'),
        ]);

        expect($result->unmetConditions()[0]->description)->toBe('Grade B or better in one STPM subject: Biology / Chemistry')
            ->and($result->unmetConditions()[0]->reason)->toBe('Your grade: Chemistry: C+.');
    });

    it('lists a repeated requirement once', function () {
        $condition = subjectCondition('STPM', 'A', ['Physics']);

        expect(checkEligibility(stpmApplicant(), [], [$condition, $condition])->unmetConditions())->toHaveCount(1);
    });

    it('tidies the capitalisation of subject names', function () {
        $result = checkEligibility(stpmApplicant(), [], [
            subjectCondition('SPM', 'C', ["Pendidikan Syari'Ah Islamiah / Pendidikan Al-Quran DAN Al-Sunnah"]),
        ], 'spm');

        expect($result->unmetConditions()[0]->description)
            ->toBe("Grade C or better in one SPM subject: Pendidikan Syari'ah Islamiah / Pendidikan Al-Quran dan Al-Sunnah");
    });

    it('checks "Khas" conditions against STPM results when every subject is an STPM subject', function () {
        $stpmOnly = subjectCondition('Khas', 'B', ['Mathematics T']);
        $spmSubject = subjectCondition('Khas', 'B', ['Matematik']);

        expect(checkEligibility(stpmApplicant(), [], [$stpmOnly])->status)->toBe(RequirementStatus::Met)
            ->and(checkEligibility(stpmApplicant(), [], [$spmSubject])->status)->toBe(RequirementStatus::Met)
            ->and(checkEligibility(stpmApplicant(), [], [subjectCondition('Khas', 'B', ['Physics'])])->status)->toBe(RequirementStatus::NotMet);
    });

    it('skips conditions written for a qualification the applicant does not hold', function () {
        $result = checkEligibility(stpmApplicant(), [], [
            ['condition_type' => 'cgpa_requirement', 'condition_group' => 'MATRIKULASI', 'condition_operator' => '>=', 'condition_value' => 3.9],
        ]);

        expect($result->status)->toBe(RequirementStatus::Met)
            ->and($result->conditions)->toBeEmpty();
    });

    it('checks gender requirements collected as a subject list', function () {
        $menOnly = subjectCondition('Khas', 'C', ['Jantina: Lelaki'], type: 'subject_group_requirement');

        $result = checkEligibility(stpmApplicant(['gender' => 'female']), [], [$menOnly], 'spm');

        expect($result->status)->toBe(RequirementStatus::NotMet)
            ->and($result->unmetConditions()[0]->description)->toBe('Open to men only')
            ->and(checkEligibility(stpmApplicant(['gender' => 'male']), [], [$menOnly], 'spm')->status)->toBe(RequirementStatus::Met);
    });
});

describe('scores', function () {
    it('compares MUET bands, counting 5+ above 5.0', function () {
        $muet = [['condition_type' => 'muet_requirement', 'condition_operator' => '>=', 'condition_value' => 5.0]];

        expect(checkEligibility(stpmApplicant(['muet_band' => '5+']), [], $muet)->status)->toBe(RequirementStatus::Met)
            ->and(checkEligibility(stpmApplicant(['muet_band' => '4.5']), [], $muet)->status)->toBe(RequirementStatus::NotMet);
    });

    it('compares STAM grades by rank', function () {
        $stam = [['condition_type' => 'stam_requirement', 'condition_operator' => '>=', 'condition_value' => 'JAYYID']];
        $stamApplicant = fn (string $grade): ApplicantResults => stpmApplicant(['applicant_category' => 'stam_current_year', 'stam_grade' => $grade]);

        expect(checkEligibility($stamApplicant('Jayyid Jiddan'), [], $stam)->status)->toBe(RequirementStatus::Met)
            ->and(checkEligibility($stamApplicant('Maqbul'), [], $stam)->status)->toBe(RequirementStatus::NotMet);
    });

    it('cannot check a CGPA requirement without a CGPA', function () {
        $result = checkEligibility(stpmApplicant(['cgpa' => null]), [], [
            ['condition_type' => 'cgpa_requirement', 'condition_group' => 'Khas', 'condition_operator' => '>=', 'condition_value' => 2.5],
        ]);

        expect($result->status)->toBe(RequirementStatus::Unknown)
            ->and($result->uncheckedConditions()[0]->reason)->toBe('Add your CGPA to check this.');
    });
});

describe('data that cannot be checked', function () {
    it('returns unknown when a subject name is missing from e-Panduan', function () {
        $result = checkEligibility(stpmApplicant(), [
            requirementSection('STPM', [subjectCondition('STPM', 'C', ['Matapelajaran'])]),
        ]);

        expect($result->status)->toBe(RequirementStatus::Unknown);
    });

    it('returns unknown when fewer subjects are listed than are needed', function () {
        $result = checkEligibility(stpmApplicant(), [], [
            subjectCondition('STPM', 'C', ['Mathematics T'], 2, 'subject_group_requirement'),
        ]);

        expect($result->status)->toBe(RequirementStatus::Unknown);
    });

    it('does not let unknown results hide an unmet requirement', function () {
        $result = checkEligibility(stpmApplicant(['cgpa' => null]), [
            requirementSection('SPM', [subjectCondition('SPM', 'A', ['Sejarah'])]),
        ], [
            ['condition_type' => 'cgpa_requirement', 'condition_group' => 'Khas', 'condition_operator' => '>=', 'condition_value' => 2.5],
        ]);

        expect($result->status)->toBe(RequirementStatus::NotMet);
    });

    it('keeps unreadable wording as notes without changing the result', function () {
        $result = checkEligibility(stpmApplicant(), [
            requirementSection('UMUM', [['condition_type' => 'unparsed_text', 'text' => 'Lulus Ujian DAN / ATAU Temu Duga.']]),
        ]);

        expect($result->status)->toBe(RequirementStatus::Met)
            ->and($result->notes)->toBe(['Lulus Ujian DAN / ATAU Temu Duga.']);
    });

    it('returns unknown when no requirements are listed', function () {
        expect(checkEligibility(stpmApplicant(), [])->status)->toBe(RequirementStatus::Unknown);
    });
});

describe('other qualifications', function () {
    it('checks matriculation and foundation subject grades', function () {
        $applicant = fn (string $grade): ApplicantResults => stpmApplicant([
            'applicant_category' => 'matriculation_or_foundation_science',
            'stpm_subjects' => null,
            'matriculation_subjects' => [['subject' => 'Mathematics', 'grade' => $grade]],
        ]);
        $specific = [subjectCondition('MATRIKULASI', 'B', ['Mathematics'])];

        expect(checkEligibility($applicant('A-'), [], $specific)->status)->toBe(RequirementStatus::Met)
            ->and(checkEligibility($applicant('C+'), [], $specific)->status)->toBe(RequirementStatus::NotMet);
    });

    it('reads the level named in a subject, and skips it for applicants without that qualification', function () {
        $specific = [subjectCondition('Khas', 'B', ['Mathematics Di Peringkat Matrikulasi'])];
        $matriculationApplicant = stpmApplicant([
            'applicant_category' => 'matriculation_engineering',
            'matriculation_subjects' => [['subject' => 'Mathematics', 'grade' => 'A']],
        ]);

        expect(checkEligibility($matriculationApplicant, [], $specific)->status)->toBe(RequirementStatus::Met)
            ->and(checkEligibility(stpmApplicant(), [], $specific)->conditions)->toBe([]);
    });

    it('checks "Khas" subjects against the applicant\'s own qualification when they are its subjects', function () {
        $applicant = stpmApplicant([
            'applicant_category' => 'a_level',
            'stpm_subjects' => null,
            'equivalent_subjects' => [['subject' => 'Further Mathematics', 'grade' => 'A*']],
        ]);

        expect(checkEligibility($applicant, [], [subjectCondition('Khas', 'B', ['Further Mathematics'])])->status)->toBe(RequirementStatus::Met);
    });

    it('asks diploma applicants for diploma subject grades they have not added', function () {
        $result = checkEligibility(stpmApplicant(['applicant_category' => 'diploma_public_university']), [], [
            subjectCondition('DIPLOMA', 'B-', ['Mathematics']),
        ]);

        expect($result->status)->toBe(RequirementStatus::Unknown)
            ->and($result->uncheckedConditions()[0]->reason)->toBe('Add your diploma subject grades to check this.');
    });

    it('splits subjects written without separators', function () {
        $applicant = stpmApplicant([
            'applicant_category' => 'foundation_engineering',
            'matriculation_subjects' => [['subject' => 'Basic Engineering', 'grade' => 'B']],
        ]);

        $result = checkEligibility($applicant, [], [
            subjectCondition('ASASI', 'C', ['Physics Chemistry Biology Basic Engineering Computer Science']),
        ]);

        expect($result->status)->toBe(RequirementStatus::Met);
    });
});

describe('wording and experience', function () {
    it('checks a maximum CGPA', function () {
        $maximum = [['condition_type' => 'cgpa_requirement', 'condition_group' => 'Khas', 'condition_operator' => '<=', 'condition_value' => 2.99]];

        expect(checkEligibility(stpmApplicant(['cgpa' => 2.5]), [], $maximum)->status)->toBe(RequirementStatus::Met)
            ->and(checkEligibility(stpmApplicant(['cgpa' => 3.2]), [], $maximum)->status)->toBe(RequirementStatus::NotMet);
    });

    it('leaves work experience to the applicant only in the set of requirements that asks for it', function () {
        $cgpa = fn (float $value): array => ['condition_type' => 'cgpa_requirement', 'condition_group' => 'Khas', 'condition_operator' => '>=', 'condition_value' => $value];
        $alternatives = [
            'condition_type' => 'alternative_requirements',
            'alternatives' => [
                [$cgpa(3.0)],
                [$cgpa(2.5), ['condition_type' => 'experience_requirement', 'condition_group' => 'Khas', 'condition_value' => 2, 'text' => 'Dua (2) Tahun Pengalaman Bekerja']],
            ],
        ];

        expect(checkEligibility(stpmApplicant(['cgpa' => 3.2]), [], [$alternatives])->status)->toBe(RequirementStatus::Met)
            ->and(checkEligibility(stpmApplicant(['cgpa' => 2.7]), [], [$alternatives])->status)->toBe(RequirementStatus::Unknown)
            ->and(checkEligibility(stpmApplicant(['cgpa' => 2.4]), [], [$alternatives])->status)->toBe(RequirementStatus::NotMet);
    });

    it('leaves sets of requirements made only of wording to the applicant', function () {
        $alternatives = [
            'condition_type' => 'alternative_requirements',
            'alternatives' => [
                [['condition_type' => 'unparsed_text', 'text' => 'Diploma In Music, Malaysian Institute Of Art']],
                [['condition_type' => 'unparsed_text', 'text' => 'Diploma Muzik, Universiti Teknologi Mara']],
            ],
        ];

        $result = checkEligibility(stpmApplicant(), [], [$alternatives]);

        expect($result->status)->toBe(RequirementStatus::Unknown)
            ->and(collect($result->conditions)->pluck('description')->all())->toBe(['Diploma In Music, Malaysian Institute Of Art', 'Diploma Muzik, Universiti Teknologi Mara'])
            ->and($result->notes)->toBe([]);
    });

    it('accepts an IELTS or TOEFL iBT score listed beside a MUET band in its place', function (array $profile, RequirementStatus $status) {
        $result = checkEligibility(stpmApplicant(['muet_band' => '2.0', ...$profile]), [], [
            ['condition_type' => 'muet_requirement', 'condition_operator' => '>=', 'condition_value' => 3.0],
            ['condition_type' => 'english_test_requirement', 'text' => 'Mendapat Sekurangnya BAND 4.5 (Academic) Dalam Ielts ; ATAU'],
            ['condition_type' => 'english_test_requirement', 'text' => 'Mendapat Sekurangnya Skor 33 Mata Dalam Toefl Ibt (Centre-Based) ;'],
        ]);

        expect($result->status)->toBe($status)
            ->and($result->notes)->toBe([]);
    })->with([
        'a high enough MUET band' => [['muet_band' => '3.0'], RequirementStatus::Met],
        'a high enough IELTS band' => [['ielts_band' => '5.0'], RequirementStatus::Met],
        'a high enough TOEFL iBT score' => [['toefl_ibt_score' => 40], RequirementStatus::Met],
        'a low IELTS band' => [['ielts_band' => '4.0'], RequirementStatus::NotMet],
        'no IELTS or TOEFL' => [[], RequirementStatus::NotMet],
    ]);

    it('words each English test as an option of its own', function () {
        $result = checkEligibility(stpmApplicant(['muet_band' => '2.0']), [], [[
            'condition_type' => 'alternative_requirements',
            'alternatives' => [
                [['condition_type' => 'muet_requirement', 'condition_operator' => '>=', 'condition_value' => 3.0]],
                [['condition_type' => 'english_test_requirement', 'text' => 'Mendapat Sekurang-Kurangnya BAND 5.5 (Academic) Dalam Ielts.']],
                [['condition_type' => 'english_test_requirement', 'text' => 'Mendapat Sekurang-Kurangnya 550 Mata (Pbt) / 213 (Cbt) / 80 Mata (Ibt) Dalam Toefl']],
            ],
        ]]);

        expect(collect($result->conditions)->pluck('description')->all())->toBe([
            'MUET Band 3.0 or higher',
            'IELTS band 5.5 or higher',
            'TOEFL iBT score 80 or higher',
        ])->and($result->conditions[1]->reason)->toBe("You haven't added an IELTS or TOEFL iBT score.");
    });

    it('asks for a requirement listed inside the higher MUET band option whichever band the applicant has', function () {
        $physicsAndMathematics = subjectCondition('MATRIKULASI', 'B', [['Physics', 'Basic Engineering'], 'Mathematics'], 2, 'subject_group_requirement');
        $applicant = stpmApplicant([
            'applicant_category' => 'matriculation_or_foundation_science',
            'stpm_subjects' => null,
            'matriculation_subjects' => [['subject' => 'Physics', 'grade' => 'C'], ['subject' => 'Mathematics', 'grade' => 'A']],
            'muet_band' => '2.0',
            'spm_subjects' => [['subject' => 'Bahasa Inggeris', 'grade' => 'C']],
        ]);

        $result = checkEligibility($applicant, [], [[
            'condition_type' => 'alternative_requirements',
            'alternatives' => [
                [['condition_type' => 'muet_requirement', 'condition_operator' => '>=', 'condition_value' => 3], $physicsAndMathematics],
                [['condition_type' => 'muet_requirement', 'condition_operator' => '>=', 'condition_value' => 2], subjectCondition('SPM', 'C', ['Bahasa Inggeris'])],
            ],
        ]]);

        expect($result->status)->toBe(RequirementStatus::NotMet)
            ->and($result->conditions[0]->description)->toBe('Grade B or better in 2 of these matriculation or foundation subjects: Physics / Basic Engineering; Mathematics')
            ->and($result->conditions[0]->status)->toBe(RequirementStatus::NotMet)
            ->and($result->conditions[0]->option)->toBeNull()
            ->and(collect($result->conditions)->slice(1)->pluck('description')->all())->toBe(['MUET Band 2.0 or higher', 'Grade C or better in SPM Bahasa Inggeris']);
    });

    it('reads subjects after "ATAU" as another way to meet the route\'s last subjects, not as a route of their own', function (float $cgpa, RequirementStatus $status) {
        $applicant = stpmApplicant(['cgpa' => $cgpa, 'spm_subjects' => [['subject' => 'Matematik', 'grade' => 'B'], ['subject' => 'Fizik', 'grade' => 'C']]]);

        $result = checkEligibility($applicant, [], [[
            'condition_type' => 'alternative_requirements',
            'alternatives' => [
                [['condition_type' => 'cgpa_requirement', 'condition_group' => 'Khas', 'condition_operator' => '>=', 'condition_value' => 2.5]],
                [
                    ['condition_type' => 'unparsed_text', 'text' => 'Memiliki Diploma Dalam Bidang Sains DAN Teknologi (Tahap 4, Mqf) Dengan'],
                    ['condition_type' => 'cgpa_requirement', 'condition_group' => 'Khas', 'condition_operator' => '>=', 'condition_value' => 2.75],
                    subjectCondition('SPM', 'C', ['Matematik Tambahan']),
                ],
                [subjectCondition('SPM', 'C', ['Matematik']), subjectCondition('SPM', 'C', ['Fizik', 'Kimia'])],
            ],
        ]]);

        expect($result->status)->toBe($status);
    })->with([
        'neither route' => [2.4, RequirementStatus::NotMet],
        'second route, with the other subjects' => [2.8, RequirementStatus::Met],
    ]);

    it('completes a line e-Panduan breaks after "Dengan" with the line that follows', function () {
        $requirement = new EligibilityRequirement([
            'level' => 'stpm',
            'specific_conditions_content' => ['Memiliki Diploma Komputeran (Tahap 4, Mqf) ATAU Yang Setara Dengan', 'Mendapat Sekurang-Kurangnya PNGK 2.50;'],
            'specific_conditions_logic' => [
                ['condition_type' => 'unparsed_text', 'text' => 'Memiliki Diploma Komputeran (Tahap 4, Mqf) ATAU Yang Setara Dengan'],
                ['condition_type' => 'cgpa_requirement', 'condition_group' => 'Khas', 'condition_operator' => '>=', 'condition_value' => 3.0],
            ],
        ]);

        $result = app(EligibilityChecker::class)->checkRequirement($requirement, stpmApplicant());

        expect($result->notes)->toBe([])
            ->and(array_map(fn ($condition) => $condition->description, $result->conditions))
            ->toContain('Memiliki Diploma Komputeran (Tahap 4, Mqf) ATAU Yang Setara Dengan Mendapat Sekurang-Kurangnya PNGK 2.50');
    });

    it('leaves a CGPA out of a completed line when that CGPA is checked on its own', function (array $content, string $note) {
        $requirement = new EligibilityRequirement([
            'level' => 'stpm',
            'specific_conditions_content' => $content,
            'specific_conditions_logic' => [
                ['condition_type' => 'unparsed_text', 'text' => $content[0]],
                ['condition_type' => 'cgpa_requirement', 'condition_group' => 'Khas', 'condition_operator' => '>=', 'condition_value' => 2.5],
            ],
        ]);

        $result = app(EligibilityChecker::class)->checkRequirement($requirement, stpmApplicant(['cgpa' => 3.0]));

        expect($result->notes)->toBe([])
            ->and(array_map(fn ($condition) => $condition->description, $result->conditions))->toContain($note);
    })->with([
        'nothing after the CGPA' => [
            ['Memiliki Diploma Komputeran (Tahap 4, Mqf) ATAU Yang Setara Dengan', 'Mendapat Sekurang-Kurangnya PNGK 2.50;'],
            'Memiliki Diploma Komputeran (Tahap 4, Mqf) ATAU Yang Setara',
        ],
        'another route after the CGPA' => [
            ['Memiliki Diploma Komputeran Dengan', 'Mendapat Sekurang-Kurangnya CGPA / PNGK 2.50 ATAU Kelulusan Lain Yang Diiktiraf'],
            'Memiliki Diploma Komputeran ATAU Kelulusan Lain Yang Diiktiraf',
        ],
    ]);

    it('shows a diploma in a suitable field in English once its checked CGPA is left out', function () {
        $content = ['Memiliki Diploma Dalam Bidang Yang Sesuai Dengan', 'Mendapat Sekurang-Kurangnya PNGK 3.00 ATAU Kelayakan Lain Yang Diiktiraf Oleh Senat Upm;'];
        $requirement = new EligibilityRequirement([
            'level' => 'stpm',
            'specific_conditions_content' => $content,
            'specific_conditions_logic' => [
                ['condition_type' => 'unparsed_text', 'text' => $content[0]],
                ['condition_type' => 'cgpa_requirement', 'condition_group' => 'Khas', 'condition_operator' => '>=', 'condition_value' => 3.0],
            ],
        ]);

        $result = app(EligibilityChecker::class)->checkRequirement($requirement, stpmApplicant(['applicant_category' => 'diploma_public_university', 'cgpa' => 3.2]));

        expect($result->notes)->toBe([])
            ->and($result->status)->toBe(RequirementStatus::Unknown)
            ->and(array_map(fn ($condition) => $condition->description, $result->conditions))
            ->toContain("A diploma in a suitable field, or another qualification recognised by UPM's Senate");
    });

    it('keeps a choice between English options that each ask for their own SPM English grade', function () {
        $result = checkEligibility(stpmApplicant(['muet_band' => '3.0', 'spm_subjects' => [['subject' => 'Bahasa Inggeris', 'grade' => 'A']]]), [], [[
            'condition_type' => 'alternative_requirements',
            'alternatives' => [
                [subjectCondition('SPM', 'B', ['Bahasa Inggeris']), ['condition_type' => 'muet_requirement', 'condition_operator' => '>=', 'condition_value' => 4]],
                [subjectCondition('SPM', 'A', ['Bahasa Inggeris']), ['condition_type' => 'muet_requirement', 'condition_operator' => '>=', 'condition_value' => 3]],
            ],
        ]]);

        expect($result->status)->toBe(RequirementStatus::Met);
    });

    it('asks for the first route\'s requirements only on that route when they are listed before the choice', function () {
        $result = checkEligibility(stpmApplicant(['cgpa' => 2.8, 'muet_band' => '3.0']), [], [
            ['condition_type' => 'unparsed_text', 'text' => 'Lepasan Diploma Memiliki Diploma Dalam Bidang Sains'],
            ['condition_type' => 'cgpa_requirement', 'condition_group' => 'Khas', 'condition_operator' => '>=', 'condition_value' => 3],
            [
                'condition_type' => 'alternative_requirements',
                'alternatives' => [
                    [['condition_type' => 'muet_requirement', 'condition_operator' => '>=', 'condition_value' => 3]],
                    [
                        ['condition_type' => 'unparsed_text', 'text' => 'Lepasan Asasipintar UKM Ambilan Komersial'],
                        ['condition_type' => 'cgpa_requirement', 'condition_group' => 'Khas', 'condition_operator' => '>=', 'condition_value' => 2.75],
                        ['condition_type' => 'muet_requirement', 'condition_operator' => '>=', 'condition_value' => 3],
                    ],
                ],
            ],
        ]);

        // Neither route is met for sure: the first asks for a CGPA of 3.00, and the second for wording the site can't check
        $route = fn (int $option): array => collect($result->conditions)->where('option', $option)->pluck('description')->values()->all();

        expect($result->status)->toBe(RequirementStatus::Unknown)
            ->and($route(1))->toBe(['A CGPA of 3.00 or higher', 'MUET Band 3.0 or higher', 'Lepasan Diploma Memiliki Diploma Dalam Bidang Sains'])
            ->and($route(2))->toBe(['A CGPA of 2.75 or higher', 'MUET Band 3.0 or higher', 'Lepasan Asasipintar UKM Ambilan Komersial'])
            ->and($result->notes)->toBe([]);
    });

    it('keeps an English test without a MUET band beside it as a note, since MUET is checked', function () {
        $result = checkEligibility(stpmApplicant(), [], [
            ['condition_type' => 'english_test_requirement', 'text' => 'Mendapat Sekurangnya BAND 5.5 Dalam Ielts'],
        ]);

        expect($result->status)->toBe(RequirementStatus::Met)
            ->and($result->notes)->toBe(['Mendapat Sekurangnya BAND 5.5 Dalam Ielts']);
    });

    it('checks requirements e-Panduan only gives in words', function (string|array $condition, array $profile, RequirementStatus $status, string $description, ?string $reason) {
        $result = checkEligibility(stpmApplicant($profile), [], [$condition]);

        expect($result->status)->toBe($status)
            ->and($result->conditions[0]->description)->toBe($description)
            ->and($result->conditions[0]->reason)->toBe($reason);
    })->with([
        'descent, of that descent' => ['Berketurunan Melayu, Anak Negeri Sabah, Anak Negeri Sarawak DAN Orang Asli Sahaja.', ['eligible_descent' => 'yes'], RequirementStatus::Met, 'Bumiputera', 'You said you are.'],
        'descent, not of that descent' => [['condition_type' => 'unparsed_text', 'text' => 'Catatan: Terbuka Kepada Keturunan Melayu, Anak Negeri Sabah, Anak Negeri Sarawak DAN Orang Asli Sahaja'], ['eligible_descent' => 'no'], RequirementStatus::NotMet, 'Bumiputera', "You said you aren't."],
        'descent, not answered' => ['Berketurunan Melayu, Anak Negeri Sabah, Anak Negeri Sarawak DAN Orang Asli Sahaja.', [], RequirementStatus::Unknown, 'Bumiputera', 'Answer the Bumiputera question in your results to check this.'],
        'colour blindness test, not colour blind' => ['Ujian Rabun / Buta Warna', ['colour_blind' => 'no'], RequirementStatus::Met, 'Not colour blind', "You said you aren't colour blind."],
        'colour blindness, colour blind' => ['Calon Tidak Buta Warna', ['colour_blind' => 'yes'], RequirementStatus::NotMet, 'Not colour blind', 'You said you are colour blind.'],
        'music grade, high enough' => ['Sijil The Associated Board Of The Royal Schools Of Music (Abrsm) Sekurang-Kurangnya Gred 5;', ['music_grade' => '6'], RequirementStatus::Met, 'ABRSM or Trinity College London music Grade 5 or higher', 'Your music grade: Grade 6.'],
        'music grade, none' => ['Sijil Trinity College London (Tcl) Sekurang-Kurangnya Gred 5;', [], RequirementStatus::NotMet, 'ABRSM or Trinity College London music Grade 5 or higher', "You haven't added a music grade."],
        'music grade, equivalent accepted' => ['Lulus Associated Board Of The Royal Schools Of Music (Abrsm) Gred 5 (Teori DAN Praktikal) ATAU Setaraf Dengannya', ['music_grade' => '3'], RequirementStatus::Unknown, 'ABRSM or Trinity College London music Grade 5 or higher', 'Your music grade: Grade 3. The course also accepts an equivalent. Check it with the university.'],
        'music diploma' => ['Ltcl / Atcl Diploma Dari Trinity College London', ['music_grade' => 'diploma'], RequirementStatus::Met, 'An ABRSM or Trinity College London music diploma', 'You have a music diploma.'],
        'music diploma with only a grade' => ['Ltcl / Atcl Diploma Dari Trinity College London', ['music_grade' => '8'], RequirementStatus::NotMet, 'An ABRSM or Trinity College London music diploma', 'Your music grade is Grade 8, not a diploma.'],
        'music diploma not added' => ['Ltcl / Atcl Diploma Dari Trinity College London', [], RequirementStatus::NotMet, 'An ABRSM or Trinity College London music diploma', "You haven't added a music diploma."],
        'Quran, memorised' => ['Memiliki Sijil /Perakuan Hafazan Daripada Majlis Peperiksaan (Mpm) ATAU Menghafaz Sekurang-Kurangnya 30 Juzuk Al-Quran', ['quran_juz' => 30], RequirementStatus::Met, 'Memorised 30 juz of the Quran', 'You have memorised 30 juz. The university checks your tahfiz certificate.'],
        'Quran, not enough' => ["Memiliki Sijil Tahfiz Al-Qur'An 30 Juzuk", ['quran_juz' => 10], RequirementStatus::NotMet, 'Memorised 30 juz of the Quran', 'You have memorised 10 juz.'],
        'age, written only in words' => ['Pemohon Hendaklah Berusia 18 Tahun DAN Ke Atas Semasa Permohonan.', ['age' => 17], RequirementStatus::NotMet, 'Aged 18 or older', 'You are 17.'],
        'age in years and months' => ['Berumur 17 Tahun 12 Bulan Keatas.', ['age' => 18], RequirementStatus::Met, 'Aged 18 or older', 'You are 18.'],
        'age, at least' => ['Calon Hendaklah Sekurang-Kurangnya Berumur 18 Tahun Semasa Permohonan.', ['age' => 17], RequirementStatus::NotMet, 'Aged 18 or older', 'You are 17.'],
        'work experience' => ['Mempunyai Tiga (3) Tahun Pengalaman Bekerja Dalam Bidang Berkaitan.', [], RequirementStatus::Unknown, '3 years of work experience in a related field', "This site doesn't check work experience. Check with the university whether your work counts."],
        'teaching experience' => ['Pengalaman Mengajar Sekurang-Kurangnya Tiga (3) Tahun;', [], RequirementStatus::Unknown, '3 years of work experience in a related field', "This site doesn't check work experience. Check with the university whether your work counts."],
        'gender, other' => ['Jantina: Lelaki', ['gender' => 'female'], RequirementStatus::NotMet, 'Open to men only', null],
        'gender, same' => ['Jantina: Lelaki', ['gender' => 'male'], RequirementStatus::Met, 'Open to men only', null],
    ]);

    it('does not take a college named Trinity College for a music board', function () {
        $note = 'Lulus Trinity College Foundation Studies Dengan Purata 70%';
        $result = checkEligibility(stpmApplicant(), [], [$note]);

        expect($result->status)->toBe(RequirementStatus::Unknown)
            ->and($result->conditions)->toHaveCount(1)
            ->and($result->conditions[0]->description)->toBe($note)
            ->and($result->conditions[0]->canBeChecked)->toBeFalse()
            ->and($result->notes)->toBe([]);
    });

    it('reads the music grade from the clause that names the board', function () {
        $note = 'Minimum Sijil Gred 7 Yamaha; Minimum Sijil Gred 5 The Associated Board Of The Royal Schools Of Music (Abrsm)';
        $result = checkEligibility(stpmApplicant(['music_grade' => '5']), [], [$note]);

        expect($result->status)->toBe(RequirementStatus::Met)
            ->and($result->conditions[0]->description)->toBe('ABRSM or Trinity College London music Grade 5 or higher');
    });

    it('keeps only the part of a note that is not checked', function () {
        $result = checkEligibility(stpmApplicant(['eligible_descent' => 'yes']), [], [
            'Temu Bual Berketurunan Melayu, Anak Negeri Sabah, Anak Negeri Sarawak DAN Orang Asli Sahaja.',
        ]);

        expect($result->status)->toBe(RequirementStatus::Met)
            ->and($result->conditions[0]->description)->toBe('Bumiputera')
            ->and($result->notes)->toBe(['Temu Bual']);
    });

    it('reads a diploma from a music exam board as a music diploma', function () {
        $result = checkEligibility(stpmApplicant(['music_grade' => 'diploma']), [], [
            'Mempunyai Tiga (3) Tahun Pengalaman Bekerja DAN Memiliki Sekurang-Kurangnya Satu (1) Diploma Daripada Lembaga Peperiksaan Muzik Seperti Berikut',
        ]);

        $musicDiploma = collect($result->conditions)->firstWhere('description', 'An ABRSM or Trinity College London music diploma');

        expect($musicDiploma)->not->toBeNull()
            ->and($musicDiploma->status)->toBe(RequirementStatus::Met);
    });

    it('checks a music grade that a prerequisite course can replace', function (?string $musicGrade, RequirementStatus $status) {
        $result = checkEligibility(stpmApplicant(['music_grade' => $musicGrade]), [], [
            'Lulus Associated Board Of The Royal Schools Of Music (Abrsm) Gred 5 (Teori DAN Praktikal) ATAU Setaraf Dengannya ATAU Perlu Mengikuti Kursus Prasyarat Yang Ditetapkan.',
        ]);

        expect($result->status)->toBe($status)
            ->and($result->conditions[0]->description)->toBe('ABRSM music Grade 5 or higher (theory and practical), or an equivalent, or the prerequisite course the university sets')
            ->and($result->conditions[0]->canBeChecked)->toBeTrue()
            ->and($result->notes)->toBe([]);
    })->with([
        'Grade 5' => ['5', RequirementStatus::Met],
        'Grade 3, who may take the course instead' => ['3', RequirementStatus::Unknown],
        'no grade' => [null, RequirementStatus::Unknown],
    ]);

    it('leaves out a "Catatan" or "Nota" heading with nothing after it', function (string $heading) {
        $result = checkEligibility(stpmApplicant(), [], [$heading, 'Calon Perlu Lulus Ujian Saringan Hiv.']);

        expect(collect($result->conditions)->pluck('description')->all())->toBe(['Calon Perlu Lulus Ujian Saringan Hiv.'])
            ->and($result->notes)->toBe([]);
    })->with(['Catatan', 'Nota', 'Catatan:', 'Syarat Am Program']);

    it('leaves out the "Catatan:" or "Nota:" a note starts with', function () {
        $result = checkEligibility(stpmApplicant(), [], ['Nota: Calon Tidak Mempunyai Ketidakupayaan Penglihatan.']);

        expect(collect($result->conditions)->pluck('description')->all())->toBe(['Calon Tidak Mempunyai Ketidakupayaan Penglihatan.']);
    });

    it('leaves a music grade inside what an audition covers as a note', function () {
        $note = "Catatan: Lulus Uji Bakat DAN Temu Duga Yang Ditetapkan Oleh Jabatan Muzik Merangkumi\nKemahiran Mendengar;\nLulus Associated Board Of The Royal Schools Of Music (Abrsm) Gred 5 (Teori DAN Praktikal) ATAU Setaraf Dengannya ATAU Perlu Mengikuti Kursus Prasyarat Yang Ditetapkan";
        $result = checkEligibility(stpmApplicant(), [], [$note]);

        expect($result->conditions)->toBe([])
            ->and($result->notes)->toBe([substr($note, strlen('Catatan: '))]);
    });

    it('takes a diploma holder to hold a diploma or an equivalent qualification', function (string $category, RequirementStatus $status, bool $canBeChecked) {
        $result = checkEligibility(stpmApplicant(['applicant_category' => $category, 'stpm_subjects' => null, 'music_grade' => '5']), [], [
            'Memiliki Diploma ATAU Kelulusan Lain Yang Diiktiraf Setaraf Dengannya Oleh Kerajaan Malaysia DAN Diluluskan Oleh Senat Upm. DAN Lulus Associated Board Of The Royal Schools Of Music (Abrsm) Gred 5 (Teori DAN Praktikal) ATAU Setaraf Dengannya ATAU Perlu Mengikuti Kursus Prasyarat Yang Ditetapkan;',
        ]);
        $diploma = $result->conditions[0];

        expect($diploma->description)->toBe("A diploma, or an equivalent qualification recognised by the Malaysian government and approved by the university's Senate")
            ->and($diploma->status)->toBe($status)
            ->and($diploma->canBeChecked)->toBe($canBeChecked)
            ->and($result->conditions[1]->status)->toBe(RequirementStatus::Met)
            ->and($result->notes)->toBe([]);
    })->with([
        'a diploma holder' => ['diploma_public_university', RequirementStatus::Met, true],
        'a matriculation leaver' => ['matriculation_or_foundation_not_current_year', RequirementStatus::Unknown, false],
    ]);

    it('shows an option that can\'t be checked in e-Panduan\'s words, with its list one item a line', function () {
        $heading = 'Memiliki Kelulusan Salah Satu (1) Diploma Daripada Institusi Berikut Yang Diluluskan Oleh Senat Upm';
        $requirement = new EligibilityRequirement([
            'level' => 'stpm',
            'specific_conditions_content' => [
                'Sijil Trinity College London (Tcl) Sekurang-Kurangnya Gred 5;',
                'ATAU',
                $heading,
                'Diploma In Music, Malaysian Institute Of Art;',
                'Diploma Muzik, Universiti Teknologi Mara (UITM);',
            ],
            'specific_conditions_logic' => [[
                'condition_type' => 'alternative_requirements',
                'alternatives' => [
                    ['Sijil Trinity College London (Tcl) Sekurang-Kurangnya Gred 5;'],
                    [
                        ['condition_type' => 'unparsed_text', 'text' => $heading],
                        ['condition_type' => 'unparsed_text', 'text' => 'Diploma In Music, Malaysian Institute Of Art;'],
                        ['condition_type' => 'unparsed_text', 'text' => 'Diploma Muzik, Universiti Teknologi Mara (UITM);'],
                    ],
                ],
            ]],
        ]);

        $result = app(EligibilityChecker::class)->checkRequirement($requirement, stpmApplicant(['music_grade' => '3']));
        $option = collect($result->conditions)->firstWhere('option', 2);

        expect($result->status)->toBe(RequirementStatus::Unknown)
            ->and($option->description)->toBe($heading."\nDiploma In Music, Malaysian Institute Of Art;\nDiploma Muzik, Universiti Teknologi Mara (UITM);")
            ->and($option->reason)->toBe("This site can't check this.")
            ->and($option->canBeChecked)->toBeFalse()
            ->and($result->notes)->toBe([]);
    });

    it('lists the music exam boards and leaves out of the wording what is checked', function () {
        $heading = 'Mempunyai Tiga (3) Tahun Pengalaman Bekerja DAN Memiliki Sekurang-Kurangnya Satu (1) Diploma Daripada Lembaga Peperiksaan Muzik Seperti Berikut';
        $requirement = new EligibilityRequirement([
            'level' => 'stpm',
            'specific_conditions_content' => [$heading, 'Associated Board Of The Royal Schools Of Music (Abrsm);', 'Yamaha Music;', 'ATAU', 'Lulus Ujian.'],
            'specific_conditions_logic' => [[
                'condition_type' => 'alternative_requirements',
                'alternatives' => [
                    [['condition_type' => 'unparsed_text', 'text' => $heading], ['condition_type' => 'experience_requirement', 'condition_value' => 3, 'text' => $heading]],
                    [['condition_type' => 'unparsed_text', 'text' => 'Lulus Ujian.']],
                ],
            ]],
        ]);

        $result = app(EligibilityChecker::class)->checkRequirement($requirement, stpmApplicant(['music_grade' => 'diploma']));

        $boardDiploma = collect($result->conditions)->firstWhere('description', "A music diploma from one of these exam boards\nAssociated Board Of The Royal Schools Of Music (Abrsm)\nYamaha Music");

        // The form doesn't ask which board a diploma is from, so even a diploma is left for the applicant to check
        expect($result->status)->toBe(RequirementStatus::Unknown)
            ->and(collect($result->conditions)->pluck('description')->unique()->values()->all())->toBe([
                '3 years of work experience in a related field',
                "A music diploma from one of these exam boards\nAssociated Board Of The Royal Schools Of Music (Abrsm)\nYamaha Music",
                'Lulus Ujian.',
            ])
            ->and($boardDiploma->status)->toBe(RequirementStatus::Unknown)
            ->and($boardDiploma->canBeChecked)->toBeFalse()
            ->and($result->notes)->toBe([]);
    });

    it('leaves out general requirements that only say the applicant holds their qualification', function () {
        $result = checkEligibility(stpmApplicant(['applicant_category' => 'diploma_public_university', 'stpm_subjects' => null]), [
            ['condition_group' => 'DIPLOMA UNIVERSITI AWAM / POLITEKNIK / KOLEJ KOMUNITI', 'conditions' => [
                ['condition_type' => 'unparsed_text', 'text' => 'Mempunyai Kelulusan Diploma IPTA / ILKA / IPTS ATAU Kelulusan Lain Yang Diiktiraf Setaraf Dengannya Oleh Kerajaan Malaysia DAN Diluluskan Oleh Senat Universiti (Syarat Khas Program Mengikut Jenis Program Yang Ditawarkan Di UA).'],
            ]],
            ['condition_group' => 'Logic', 'conditions' => 'AND'],
            ['condition_group' => 'UMUM', 'conditions' => [
                ['condition_type' => 'unparsed_text', 'text' => 'Diluluskan Oleh Senat UA.'],
                ['condition_type' => 'unparsed_text', 'text' => 'Lulus Ujian DAN / ATAU Temu Duga Yang Ditetapkan.'],
            ]],
        ]);

        expect($result->notes)->toBe(['Lulus Ujian DAN / ATAU Temu Duga Yang Ditetapkan.']);
    });

    it('checks a line that only states what a UPU applicant already has', function (string $line, string $category, RequirementStatus $status, string $description, string $reason) {
        $result = checkEligibility(stpmApplicant(['applicant_category' => $category]), [], [$line]);

        expect($result->conditions)->toHaveCount(1)
            ->and($result->conditions[0]->status)->toBe($status)
            ->and($result->conditions[0]->description)->toBe($description)
            ->and($result->conditions[0]->reason)->toBe($reason)
            ->and($result->conditions[0]->canBeChecked)->toBe($status === RequirementStatus::Met)
            ->and($result->notes)->toBe([]);
    })->with([
        'citizenship' => ['Warganegara Malaysia Yang Mempunyai No. Kad Pengenalan / My Kad.', 'spm_current_year', RequirementStatus::Met, 'A Malaysian citizen with a MyKad', 'UPU only takes applications from Malaysian citizens.'],
        'another qualification' => ['Mempunyai Kelulusan Lain Yang Diiktiraf Setaraf Dengannya DAN Diperakukan Oleh Senat Universiti.', 'stpm_not_current_year', RequirementStatus::Met, "Your qualification, or another one the university's Senate recognises as equal", "You're applying with STPM (previous years)."],
        'recognised diploma, as a diploma holder' => ['Memiliki Diploma Dari Institusi Yang Diiktiraf Oleh Kerajaan Malaysia DAN Senat Universiti', 'dkm_dlkm', RequirementStatus::Met, "A diploma from an institution recognised by the Malaysian government and the university's Senate", "You're applying with a diploma."],
        'recognised diploma, without one' => ['Memiliki Diploma Dari Intitusi Yang Diiktiraf Oleh Kerajaan Malaysia DAN Senat Universiti', 'a_level', RequirementStatus::Unknown, "A diploma from an institution recognised by the Malaysian government and the university's Senate", "You're applying with A-Level. Check with the university whether this course accepts it."],
        'A-Level' => ['Memiliki Kelayakan GCE A-Level Yang Diiktiraf;', 'a_level', RequirementStatus::Met, 'A recognised GCE A-Level', "You're applying with A-Level results."],
        'general requirements' => ['Memenuhi Syarat Am Universiti', 'stpm_not_current_year', RequirementStatus::Met, "The university's general entry requirements", "They're checked with the rest of this course's requirements."],
        'sat SPM' => ['Pemohon Mestilah Menduduki Sijil Pelajaran Malaysia (SPM) DAN Boleh Membaca, Menulis DAN Mengira Dalam Bahasa Melayu ATAU Bahasa Inggeris.', 'spm_not_current_year', RequirementStatus::Met, 'Sat SPM, and can read, write and count', "You're applying with SPM results."],
    ]);

    it('leaves a line asking for a particular kind of diploma to the applicant', function (string $line, string $category, string $description, string $reason) {
        $result = checkEligibility(stpmApplicant(['applicant_category' => $category]), [], [
            ['condition_type' => 'unparsed_text', 'text' => $line],
            ['condition_type' => 'cgpa_requirement', 'condition_group' => 'Khas', 'condition_operator' => '>=', 'condition_value' => 2.0],
        ]);

        $diploma = array_find($result->conditions, fn ($condition) => $condition->description === $description);

        expect($result->status)->toBe(RequirementStatus::Unknown)
            ->and($result->notes)->toBe([])
            ->and($diploma)->not->toBeNull()
            ->and($diploma->reason)->toBe($reason)
            ->and($diploma->canBeChecked)->toBeFalse();
    })->with([
        'a named field' => ['Memiliki Diploma Dalam Bidang Pengajian Islam ATAU Setaraf;', 'diploma_public_university', 'Memiliki Diploma Dalam Bidang Pengajian Islam ATAU Setaraf', "This site doesn't ask what your diploma is in or where it's from. Check with the university."],
        'a kind of diploma' => ['Lulus Diploma Kejuruteraan Dalam Bidang Berkaitan Dari Institusi-Institusi Yang Diiktiraf', 'dkm_dlkm', 'Lulus Diploma Kejuruteraan Dalam Bidang Berkaitan Dari Institusi-Institusi Yang Diiktiraf', "This site doesn't ask what your diploma is in or where it's from. Check with the university."],
        'marked as a note' => ['Catatan: Memiliki Diploma Komputeran (Tahap 4, Mqf) ATAU Yang Setara.', 'diploma_public_university', 'Memiliki Diploma Komputeran (Tahap 4, Mqf) ATAU Yang Setara', "This site doesn't ask what your diploma is in or where it's from. Check with the university."],
        'without a diploma' => ['Memiliki Diploma Dalam Bidang Pengajian Islam ATAU Setaraf', 'stpm_not_current_year', 'Memiliki Diploma Dalam Bidang Pengajian Islam ATAU Setaraf', "You're applying with STPM (previous years). Check with the university whether this course accepts it."],
    ]);

    it('still checks a line that accepts any diploma', function () {
        $result = checkEligibility(stpmApplicant(['applicant_category' => 'diploma_public_university']), [], [
            'Memiliki Diploma ATAU Kelulusan Lain Yang Diiktiraf Setaraf Dengannya Oleh Kerajaan Malaysia DAN Diluluskan Oleh Senat Upm.',
        ]);

        expect($result->status)->toBe(RequirementStatus::Met);
    });

    it('leaves the field of a qualification to the applicant, in English', function (string $line, string $category, string $description, string $reason) {
        $result = checkEligibility(stpmApplicant(['applicant_category' => $category]), [], [$line]);

        expect($result->status)->toBe(RequirementStatus::Unknown)
            ->and($result->conditions)->toHaveCount(1)
            ->and($result->conditions[0]->description)->toBe($description)
            ->and($result->conditions[0]->reason)->toBe($reason)
            ->and($result->conditions[0]->canBeChecked)->toBeFalse()
            ->and($result->notes)->toBe([]);
    })->with([
        'suitable diploma, as a diploma holder' => ['Memiliki Diploma Dalam Bidang Yang Sesuai ATAU Kelayakan Lain Yang Diiktiraf Oleh Senat Upm;', 'diploma_public_university', "A diploma in a suitable field, or another qualification recognised by UPM's Senate", "This site doesn't ask what your diploma is in, so it can't tell whether it's related. Check with the university."],
        'suitable diploma or another qualification' => ['Memiliki Diploma Dalam Bidang Yang Sesuai ATAU Kelayakan Lain Yang Diiktiraf Oleh Senat Upm;', 'matriculation_or_foundation_not_current_year', "A diploma in a suitable field, or another qualification recognised by UPM's Senate", "You're applying with Matriculation / Foundation (previous years). Check with the university whether this course accepts it."],
        'related qualification' => ['Memiliki Kelulusan Berkaitan Dari Institusi Pengajian Tinggi Yang Diiktiraf Setaraf Dengannya Oleh Senat Upnm.', 'a_level', "A related qualification from a higher education institution the university's Senate recognises", "You're applying with A-Level. Check with the university whether this course accepts it."],
        'related diploma' => ['Memiliki Diploma Dalam Bidang Yang Berkaitan', 'diploma_public_university', 'A diploma in a related field', "This site doesn't ask what your diploma is in, so it can't tell whether it's related. Check with the university."],
        'suitable diploma' => ['Memiliki Diploma Dalam Bidang Yang Sesuai', 'dkm_dlkm', 'A diploma in a related field', "This site doesn't ask what your diploma is in, so it can't tell whether it's related. Check with the university."],
        'related to the course' => ['Memiliki Diploma Dalam Bidang Yang Berkaitan Dengan Kursus Yang Dipohon;', 'diploma_public_university', 'A diploma in a field related to the course', "This site doesn't ask what your diploma is in, so it can't tell whether it's related. Check with the university."],
        'from a polytechnic' => ['Memiliki Diploma Dalam Bidang Yang Berkaitan Dari Politeknik', 'diploma_public_university', 'A diploma in a related field from a polytechnic', "This site doesn't ask what your diploma is in, so it can't tell whether it's related. Check with the university."],
        'from a public university' => ['Memiliki Diploma Dalam Bidang Yang Berkaitan Dari Universiti Awam ( UA )', 'diploma_public_university', 'A diploma in a related field from a public university', "This site doesn't ask what your diploma is in, so it can't tell whether it's related. Check with the university."],
        'from a private institution' => ['Memiliki Diploma Dalam Bidang Yang Berkaitan Dari Institusi Pengajian Tinggi Swasta (IPTS)', 'diploma_public_university', 'A diploma in a related field from a private higher education institution', "This site doesn't ask what your diploma is in, so it can't tell whether it's related. Check with the university."],
        'from UTM' => ['Memiliki Diploma Dalam Bidang Yang Berkaitan Dari Utm/ Ipt/ Setaraf', 'diploma_public_university', 'A diploma in a related field from UTM or another higher education institution, or an equivalent', "This site doesn't ask what your diploma is in, so it can't tell whether it's related. Check with the university."],
        'without a diploma' => ['Memiliki Diploma Dalam Bidang Yang Berkaitan', 'spm_current_year', 'A diploma in a related field', "You're applying with SPM (current year). Check with the university whether this course accepts it."],
    ]);

    it('checks a qualification statement the collectors marked as a senate approval', function () {
        $result = checkEligibility(stpmApplicant(['applicant_category' => 'stpm_not_current_year']), [], [
            ['condition_type' => 'senate_approval_requirement', 'with_diploma' => false, 'text' => 'Mempunyai Kelulusan Lain Yang Diiktiraf Setaraf Dengannya DAN Diperakukan Oleh Senat Universiti.'],
        ]);

        expect($result->conditions)->toHaveCount(1)
            ->and($result->conditions[0]->status)->toBe(RequirementStatus::Met)
            ->and($result->notes)->toBe([]);
    });

    it('keeps a requirement e-Panduan joined to a checked one with "DAN"', function () {
        $line = 'Mendapat Sekurang-Kurangnya BAND 2.0 Dalam Malaysian University English Test (MUET) Untuk Peperiksaan Yang Bermula Sesi 1 Tahun 2021. DAN Lulus Ujian Psikologi DAN Temu Duga Yang Ditetapkan.';
        $requirement = new EligibilityRequirement([
            'level' => 'stpm',
            'specific_conditions_content' => [$line],
            'specific_conditions_logic' => [['condition_type' => 'muet_requirement', 'condition_operator' => '>=', 'condition_value' => 2]],
        ]);

        $result = app(EligibilityChecker::class)->checkRequirement($requirement, stpmApplicant());

        // Like the interviews e-Panduan lists on their own, it is shown for the applicant to check
        expect($result->status)->toBe(RequirementStatus::Met)
            ->and($result->conditions[0]->description)->toBe('MUET Band 2.0 or higher')
            ->and($result->notes)->toBe(['Lulus Ujian Psikologi DAN Temu Duga Yang Ditetapkan.']);
    });

    it('checks an SPM subject grade e-Panduan joined to a checked one with "DAN"', function (string $clause, array $spmSubjects, RequirementStatus $status, string $description) {
        $requirement = new EligibilityRequirement([
            'level' => 'stpm',
            'specific_conditions_content' => ["Mendapat Sekurang-Kurangnya Kepujian Gred C Dalam Mata Pelajaran Mathematics DAN {$clause}"],
            'specific_conditions_logic' => [['condition_type' => 'subject_requirement', 'condition_group' => 'SPM', 'condition_count' => 1, 'minimum_grade' => 'C', 'subject_list' => ['Mathematics']]],
        ]);

        $result = app(EligibilityChecker::class)->checkRequirement($requirement, stpmApplicant(['spm_subjects' => $spmSubjects]));
        $subject = $result->conditions[1];

        expect($subject->description)->toBe($description)
            ->and($subject->status)->toBe($status)
            ->and($subject->canBeChecked)->toBeTrue()
            ->and($result->notes)->toBe([]);
    })->with([
        'a grade, met' => [
            'Lulus Gred E Dalam Mata Pelajaran Bahasa Inggeris Di Peringkat SPM',
            [['subject' => 'Matematik', 'grade' => 'A'], ['subject' => 'Bahasa Inggeris', 'grade' => 'D']],
            RequirementStatus::Met,
            'Grade E or better in SPM Bahasa Inggeris',
        ],
        'a grade, not taken' => [
            'Mendapat Sekurangnya-Kurangnya Gred B Dalam Mata Pelajaran Bahasa Inggeris Di Peringkat SPM',
            [['subject' => 'Matematik', 'grade' => 'A']],
            RequirementStatus::NotMet,
            'Grade B or better in SPM Bahasa Inggeris',
        ],
        'a pass in one of three' => [
            'Lulus Matematik / Fizik / Sains Pada Peringkat Sijil Pelajaran Malaysia (SPM)',
            [['subject' => 'Matematik', 'grade' => 'A'], ['subject' => 'Sains', 'grade' => 'E']],
            RequirementStatus::Met,
            'Grade E or better in one SPM subject: Matematik / Fizik / Sains',
        ],
        'a pass from a year on' => [
            'Lulus Dalam Mata Pelajaran Sejarah (SPM 2013 DAN Ke Atas)',
            [['subject' => 'Matematik', 'grade' => 'A']],
            RequirementStatus::NotMet,
            'Grade E or better in SPM Sejarah',
        ],
    ]);

    it('leaves a joined subject grade it cannot read for the applicant to check', function () {
        $requirement = new EligibilityRequirement([
            'level' => 'stpm',
            'specific_conditions_content' => ['Mendapat Sekurang-Kurangnya PNGK 3.00 DAN Gred C Dalam Satu (1) Mata Pelajaran Di Peringkat STPM'],
            'specific_conditions_logic' => [['condition_type' => 'cgpa_requirement', 'condition_group' => 'STPM', 'condition_operator' => '>=', 'condition_value' => 3]],
        ]);

        $result = app(EligibilityChecker::class)->checkRequirement($requirement, stpmApplicant());
        $subject = $result->conditions[1];

        expect($result->status)->toBe(RequirementStatus::Unknown)
            ->and($subject->description)->toBe('Gred C Dalam Satu (1) Mata Pelajaran Di Peringkat STPM')
            ->and($subject->status)->toBe(RequirementStatus::Unknown)
            ->and($subject->canBeChecked)->toBeFalse();
    });

    it('leaves a joined requirement alone when the rules already have it', function (array $logic) {
        $requirement = new EligibilityRequirement([
            'level' => 'stpm',
            'specific_conditions_content' => ['Mendapat Sekurang-Kurangnya Gred C Dalam Mata Pelajaran Matematik DAN Gred B Dalam Mata Pelajaran Fizik Pada Peringkat SPM.'],
            'specific_conditions_logic' => $logic,
        ]);

        $result = app(EligibilityChecker::class)->checkRequirement($requirement, stpmApplicant());

        expect($result->notes)->toBe([]);
    })->with([
        'as a subject rule' => [[
            ['condition_type' => 'subject_requirement', 'condition_group' => 'SPM', 'condition_count' => 1, 'minimum_grade' => 'C', 'subject_list' => ['Matematik']],
            ['condition_type' => 'subject_requirement', 'condition_group' => 'SPM', 'condition_count' => 1, 'minimum_grade' => 'B', 'subject_list' => ['Physics']],
        ]],
    ]);

    it('keeps "DAN" inside a name or a list as part of the same requirement', function () {
        $line = 'Lulus Mata Pelajaran Pendidikan Al-Quran DAN Al-Sunnah Pada Peringkat SPM.';
        $requirement = new EligibilityRequirement([
            'level' => 'stpm',
            'specific_conditions_content' => [$line],
            'specific_conditions_logic' => [['condition_type' => 'unparsed_text', 'text' => $line]],
        ]);

        expect(collect(app(EligibilityChecker::class)->checkRequirement($requirement, stpmApplicant())->conditions)->pluck('description')->all())->toBe([$line]);
    });

    it('leaves a diploma line with a CGPA in it to the applicant, not as a recognised diploma', function () {
        $line = 'Memiliki Diploma Dari Institusi Pendidikan Yang Diiktiraf Oleh Senat Universiti Malaysia Sabah Dengan Mendapat Sekurang-Kurangnya PNGK 3.00.';
        $result = checkEligibility(stpmApplicant(['applicant_category' => 'diploma_public_university']), [], [$line]);

        expect($result->status)->toBe(RequirementStatus::Unknown)
            ->and($result->conditions)->toHaveCount(1)
            ->and($result->conditions[0]->description)->toBe(rtrim($line, '.'))
            ->and($result->notes)->toBe([]);
    });

    it('names the field of work experience when e-Panduan gives one', function () {
        $result = checkEligibility(stpmApplicant(), [], [
            'Mempunyai Lima (5) Tahun Pengalaman Bekerja Dalam Industri Muzik DAN Mempunyai Pencapaian Profesional Yang Cemerlang',
        ]);

        expect(collect($result->conditions)->pluck('description')->all())->toBe(['5 years of work experience in the music industry', 'Mempunyai Pencapaian Profesional Yang Cemerlang']);
    });

    it('takes the lowest of several music qualifications a course lists', function () {
        $result = checkEligibility(stpmApplicant(['music_grade' => '5']), [], [[
            'condition_type' => 'alternative_requirements',
            'alternatives' => [
                ['Gred 5 Associated Board Of The Royal School Of Music, London (Abrsm) / Trinity College London'],
                ['Ltcl / Atcl Diploma Dari Trinity College London'],
            ],
        ]]);

        expect($result->status)->toBe(RequirementStatus::Met);
    });

    it('drops a note that only says what is checked', function (string $note) {
        $result = checkEligibility(stpmApplicant(['eligible_descent' => 'yes', 'colour_blind' => 'no']), [], [$note]);

        expect($result->status)->toBe(RequirementStatus::Met)
            ->and($result->notes)->toBe([]);
    })->with([
        'Berketurunan Melayu, Anak Negeri Sabah, Anak Negeri Sarawak DAN Orang Asli Sahaja. Berketurunan Melayu, Anak Negeri Sabah, Anak Negeri Sarawak DAN Orang Asli Sahaja.',
        'Catatan: Terbuka Kepada Keturunan Melayu, Anak Negeri Sabah, Anak Negeri Sarawak DAN Orang Asli Sahaja',
        'Ujian Rabun / Buta Warna',
    ]);

    it('leaves the rest of a line that says more than what is checked to the applicant', function () {
        $note = 'Calon Tidak Buta Warna DAN Tidak Sengau DAN Tidak Gayat Tinggi (Vertigo)';
        $result = checkEligibility(stpmApplicant(['colour_blind' => 'no']), [], [$note]);

        expect($result->status)->toBe(RequirementStatus::Unknown)
            ->and(collect($result->conditions)->map(fn (ConditionResult $condition): array => [$condition->status, $condition->description])->all())->toBe([
                [RequirementStatus::Met, 'Not colour blind'],
                [RequirementStatus::Unknown, $note],
            ]);
    });

    it('leaves a course with a requirement the site can\'t read to the applicant, even when everything checked is met', function (string $line) {
        $result = checkEligibility(stpmApplicant(), [], [
            ['condition_type' => 'muet_requirement', 'condition_operator' => '>=', 'condition_value' => 2.0],
            $line,
        ]);

        expect($result->status)->toBe(RequirementStatus::Unknown)
            ->and($result->conditions[1]->description)->toBe($line)
            ->and($result->conditions[1]->reason)->toBe("This site can't check this.")
            ->and($result->conditions[1]->canBeChecked)->toBeFalse()
            ->and($result->notes)->toBe([]);
    })->with([
        'a disability' => 'Calon Tidak Mempunyai Ketidakupayaan Fizikal Yang Menyukarkan Kerja-Kerja Amali.',
        'a health check' => 'Calon Mesti Disahkan Sihat Dari Segi Perubatan Oleh Pengamal Perubatan Berdaftar;',
        'a subject the collectors couldn\'t read' => 'Mendapat Sekurang-Kurangnya Gred C (Kepujian) Bagi Mata Pelajaran Fizik Pada Peringkat SPM',
        'a subject list item' => 'Physics',
        'marital status' => 'Taraf Perkahwinan: Bujang',
        'a test beside a subject' => 'Lulus Ujian DAN / ATAU Temu Duga Yang Ditetapkan Oleh Fakulti. Calon Lepasan Aliran Perakaunan (Matrikulasi KPM).',
    ]);

    it('still counts a course as one the applicant qualifies for when the rest is an interview, a test or information', function (string $line) {
        $result = checkEligibility(stpmApplicant(), [], [
            ['condition_type' => 'muet_requirement', 'condition_operator' => '>=', 'condition_value' => 2.0],
            $line,
        ]);

        expect($result->status)->toBe(RequirementStatus::Met)
            ->and($result->notes)->toBe([$line]);
    })->with([
        'an interview or test' => 'Lulus Ujian DAN / ATAU Temu Duga Yang Ditetapkan Oleh Universiti Awam.',
        'MEdSI' => 'Melepasi Tahap Minimum Ujian Malaysian Educators Selection Inventory (Medsi) Yang Ditetapkan Oleh Universiti Awam.',
        'a drawing test' => 'Ujian Melukis',
        'no promise of a teaching post' => 'Program Ini Merupakan Program Ijazah Sarjana Muda Pendidikan Yang Tiada Jaminan Untuk Lantikan Sebagai Pegawai Perkhidmatan Pendidikan (Guru).',
        'where the course runs' => 'Penempatan: Kampus Serdang, Selangor',
        'a course to take after entering' => 'Calon Yang Tidak Mengambil Physics Pada Peringkat Matrikulasi / Asasi Dikehendaki Mengambil Mata Pelajaran Basic Physics Di Universiti',
        'a preference' => '* Keutamaan Diberikan Kepada Calon Yang Lulus Dalam Mata Pelajaran Pendidikan Seni Visual ATAU Reka Cipta Pada Peringkat SPM.',
        'documents to send' => 'Menyertakan Transkrip Akademik Semester Satu (1) Hingga Semester Akhir.',
        'the Senate\'s other conditions' => 'Syarat-Syarat Lain Yang Ditetapkan Oleh Senat Dari Semasa Ke Semasa.',
        'an English test in place of MUET' => 'Mendapat Sekurang-Kurangnya Ielts BAND 4.0 ATAU Toefl Ibt Skor 31 ATAU Cefr Skor A2.',
    ]);

    it('takes the next set of requirements when the first leaves a requirement to the applicant', function () {
        $result = checkEligibility(stpmApplicant(), [], [[
            'condition_type' => 'alternative_requirements',
            'alternatives' => [
                [
                    ['condition_type' => 'muet_requirement', 'condition_operator' => '>=', 'condition_value' => 2.0],
                    ['condition_type' => 'unparsed_text', 'text' => 'Calon Tidak Mempunyai Ketidakupayaan Penglihatan.'],
                ],
                [['condition_type' => 'muet_requirement', 'condition_operator' => '>=', 'condition_value' => 3.0]],
            ],
        ]]);

        expect($result->status)->toBe(RequirementStatus::Met)
            ->and(collect($result->conditions)->pluck('description')->all())->toBe(['MUET Band 3.0 or higher']);
    });
});

describe('Pra-U results', function () {
    it('checks Pra-U subject requirements against a sports school applicant\'s Pra-U results', function () {
        $applicant = stpmApplicant([
            'applicant_category' => 'sports_school',
            'stpm_subjects' => null,
            'prau_subjects' => [['subject' => 'Sains Sukan', 'grade' => 'B'], ['subject' => 'Pengkomputeran', 'grade' => 'C']],
        ]);
        $twoOf = [subjectCondition('PRA-U', 'C+', ['Sains Sukan', 'Bahasa Inggeris', 'Pengkomputeran'], 2, 'subject_group_requirement')];

        $result = checkEligibility($applicant, [], $twoOf);

        expect($result->status)->toBe(RequirementStatus::NotMet)
            ->and($result->conditions[0]->description)->toContain('Pra-U (Sekolah Sukan Malaysia)')
            ->and(checkEligibility($applicant, [], [subjectCondition('PRA-U', 'C', ['Sains Sukan', 'Pengkomputeran'], 2, 'subject_group_requirement')])->status)->toBe(RequirementStatus::Met);
    });

    it('counts the Pra-U as the equivalent qualification in the general requirements', function () {
        $result = checkEligibility(stpmApplicant(['applicant_category' => 'sports_school', 'stpm_subjects' => null]), [
            requirementSection('STPM', [['condition_type' => 'cgpa_requirement', 'condition_group' => 'STPM', 'condition_operator' => '>=', 'condition_value' => 2.0]]),
            ['condition_group' => 'Logic', 'conditions' => 'OR'],
            requirementSection('KELAYAKAN SETARAF', [['condition_type' => 'cgpa_requirement', 'condition_group' => 'KELAYAKAN SETARAF', 'condition_operator' => '>=', 'condition_value' => 2.0]]),
        ]);

        expect($result->status)->toBe(RequirementStatus::Met);
    });

    it('asks for Pra-U results when a sports school applicant has not added them', function () {
        $result = checkEligibility(stpmApplicant(['applicant_category' => 'sports_school']), [], [subjectCondition('PRA-U', 'C', ['Sains Sukan'])]);

        expect($result->status)->toBe(RequirementStatus::Unknown)
            ->and($result->conditions[0]->reason)->toBe('Add your Pra-U (Sekolah Sukan Malaysia) subject grades to check this.');
    });
});

describe('other details', function () {
    it('leaves work experience for the applicant to check themselves', function () {
        $experience = [['condition_type' => 'experience_requirement', 'condition_group' => 'Khas', 'condition_value' => 2, 'text' => 'Dua (2) Tahun Pengalaman Kerja']];

        $result = checkEligibility(stpmApplicant(), [], $experience);

        expect($result->status)->toBe(RequirementStatus::Unknown)
            ->and($result->conditions[0]->description)->toBe('2 years of work experience in a related field')
            ->and($result->conditions[0]->reason)->toBe("This site doesn't check work experience. Check with the university whether your work counts.")
            ->and($result->conditions[0]->canBeChecked)->toBeFalse();
    });

    it('leaves the diploma fields a course accepts to the applicant', function () {
        $result = checkEligibility(stpmApplicant(), [], [['condition_type' => 'diploma_field_requirement', 'condition_group' => 'Khas', 'fields' => ['Seni Reka', 'Multimedia']]]);

        expect($result->status)->toBe(RequirementStatus::Unknown)
            ->and($result->conditions[0]->description)->toBe('Diploma in one of these fields: Seni Reka; Multimedia')
            ->and($result->conditions[0]->reason)->toBe("This site doesn't ask what your diploma is in. Check with the university.");
    });

    it('explains that e-Panduan does not name the subject', function () {
        $result = checkEligibility(stpmApplicant(), [], [subjectCondition('STPM', 'C', ['Matapelajaran'])]);

        expect($result->status)->toBe(RequirementStatus::Unknown)
            ->and($result->conditions[0]->description)->toBe('Grade C or better in one STPM subject')
            ->and($result->conditions[0]->reason)->toBe("e-Panduan doesn't name the subject. Check with the university which subject it means.");
    });

    it('explains that the university decides which subjects belong to a subject group', function () {
        $condition = subjectCondition('SPM', 'B', ['Bahasa Inggeris', 'Matematik', 'Sains / Teknikal / Vokasional'], 3, 'subject_group_requirement');

        $result = checkEligibility(stpmApplicant(), [], [$condition]);

        expect($result->status)->toBe(RequirementStatus::Unknown)
            ->and($result->conditions[0]->reason)->toBe("The site can't tell which subjects belong to Sains, Teknikal or Vokasional. The university decides this.");
    });

    it('leaves a choice of a diploma in a related field to the university', function (string $category, RequirementStatus $status, string $description, string $reason) {
        $alternatives = [
            'condition_type' => 'alternative_requirements',
            'alternatives' => [
                [['condition_type' => 'related_diploma_requirement', 'text' => 'Memiliki Diploma Dalam Bidang Yang Berkaitan Dengan Kursus Yang Dipohon;']],
                [subjectCondition('STPM', 'A', ['Biology'])],
            ],
        ];

        $result = checkEligibility(stpmApplicant(['applicant_category' => $category]), [], [$alternatives]);

        expect($result->conditions[0]->status)->toBe($status)
            ->and($result->conditions[0]->description)->toBe($description)
            ->and($result->conditions[0]->reason)->toBe($reason);
    })->with([
        'without a diploma' => ['sports_school', RequirementStatus::Unknown, 'A diploma in a field related to the course', "You're applying with Sports School (Sekolah Sukan). Check with the university whether this course accepts it."],
        'with a diploma' => ['diploma_public_university', RequirementStatus::Unknown, 'A diploma in a field related to the course', "This site doesn't ask what your diploma is in, so it can't tell whether it's related. Check with the university."],
    ]);

    it('leaves a choice the Senate decides on to the university', function () {
        $senateApproval = ['condition_type' => 'senate_approval_requirement', 'with_diploma' => false, 'text' => 'Mempunyai Kelulusan Lain ... Senat Universiti.'];
        $alternatives = [
            'condition_type' => 'alternative_requirements',
            'alternatives' => [[$senateApproval], [subjectCondition('STPM', 'A', ['Biology'])]],
        ];

        $result = checkEligibility(stpmApplicant(), [], [$alternatives]);

        expect($result->status)->toBe(RequirementStatus::Unknown)
            ->and($result->conditions[0]->description)->toBe("Another qualification recognised by the university's Senate")
            ->and($result->conditions[0]->reason)->toBe("The university's Senate decides whether your qualification counts. Check with the university.");
    });

    it('shows a Senate-approved qualification as a note beside other requirements', function () {
        $result = checkEligibility(stpmApplicant(), [], [
            ['condition_type' => 'senate_approval_requirement', 'with_diploma' => false, 'text' => 'Mempunyai Kelulusan Lain ... Senat Universiti.'],
            ['condition_type' => 'muet_requirement', 'condition_operator' => '>=', 'condition_value' => 2.0],
        ]);

        expect($result->status)->toBe(RequirementStatus::Met);
    });

    it('checks age limits', function (array $limit, int $age, RequirementStatus $status) {
        $condition = [['condition_type' => 'age_requirement', 'text' => 'Berumur ...', ...$limit]];

        expect(checkEligibility(stpmApplicant(['age' => $age]), [], $condition)->status)->toBe($status);
    })->with([
        'at the maximum' => [['maximum_age' => 30], 30, RequirementStatus::Met],
        'over the maximum' => [['maximum_age' => 30], 31, RequirementStatus::NotMet],
        'under the minimum' => [['minimum_age' => 21], 19, RequirementStatus::NotMet],
        'inside a range' => [['minimum_age' => 17, 'maximum_age' => 20], 18, RequirementStatus::Met],
        'outside a range' => [['minimum_age' => 17, 'maximum_age' => 20], 21, RequirementStatus::NotMet],
    ]);

    it('asks for the age when it is blank', function () {
        $result = checkEligibility(stpmApplicant(), [], [['condition_type' => 'age_requirement', 'maximum_age' => 30, 'text' => 'Berumur 30 Tahun Kebawah']]);

        expect($result->status)->toBe(RequirementStatus::Unknown)
            ->and($result->conditions[0]->description)->toBe('Aged 30 or younger')
            ->and($result->conditions[0]->reason)->toBe('Add your age to check this.');
    });

    it('checks the sports level an applicant has represented', function () {
        $district = [['condition_type' => 'sports_requirement', 'minimum_level' => 'district', 'former_roles_accepted' => false, 'text' => 'Mewakili Daerah']];

        expect(checkEligibility(stpmApplicant(['sports_level' => 'state']), [], $district)->status)->toBe(RequirementStatus::Met)
            ->and(checkEligibility(stpmApplicant(['sports_level' => 'school']), [], $district)->status)->toBe(RequirementStatus::NotMet)
            ->and(checkEligibility(stpmApplicant(), [], $district)->status)->toBe(RequirementStatus::Unknown)
            ->and(checkEligibility(stpmApplicant(), [], $district)->conditions[0]->description)->toBe('Represented district level or higher in sports');
    });

    it('leaves a lower sports level unknown when former athletes, referees and team managers are accepted', function () {
        $state = [['condition_type' => 'sports_requirement', 'minimum_level' => 'state', 'former_roles_accepted' => true, 'text' => 'Mewakili Negeri ATAU Sebagai Bekas Atlet / Pengadil / Pengurus Pasukan']];

        $result = checkEligibility(stpmApplicant(['sports_level' => 'district']), [], $state);

        expect($result->status)->toBe(RequirementStatus::Unknown)
            ->and($result->conditions[0]->reason)->toContain('Former athletes, referees and team managers may also qualify.');
    });

    it('leaves a specialisation subject grade for matriculation applicants to check with the university', function () {
        $specialisation = [['condition_type' => 'specialisation_requirement', 'condition_group' => 'MATRIKULASI', 'minimum_grade' => 'C']];
        $result = checkEligibility(stpmApplicant(['applicant_category' => 'matriculation_or_foundation_science', 'matriculation_subjects' => [['subject' => 'Mathematics', 'grade' => 'A']]]), [], $specialisation);

        expect($result->status)->toBe(RequirementStatus::Unknown)
            ->and($result->conditions[0]->description)->toBe('Grade C or better in your matriculation or foundation specialisation subjects')
            ->and($result->conditions[0]->reason)->toContain('ask the university whether your grades meet this')
            ->and(checkEligibility(stpmApplicant(), [], $specialisation)->conditions)->toBe([]);
    });
});

describe('MUET', function () {
    it('does not meet a MUET requirement without MUET', function () {
        $result = checkEligibility(stpmApplicant(['muet_band' => 'none']), [], [['condition_type' => 'muet_requirement', 'condition_operator' => '>=', 'condition_value' => 1.0]]);

        expect($result->status)->toBe(RequirementStatus::NotMet)
            ->and($result->conditions[0]->reason)->toBe("You haven't taken MUET.");
    });

    it('exempts polytechnic and community college diploma graduates from MUET when the course says so', function (string $category, string $wording, RequirementStatus $status, string $reason) {
        $requirement = new EligibilityRequirement([
            'level' => 'stpm',
            'specific_conditions_content' => [$wording],
            'specific_conditions_logic' => [['condition_type' => 'muet_requirement', 'condition_operator' => '>=', 'condition_value' => 2.0]],
        ]);

        $result = app(EligibilityChecker::class)->checkRequirement($requirement, stpmApplicant(['applicant_category' => $category, 'muet_band' => 'none']));

        expect($result->status)->toBe($status)
            ->and($result->conditions[0]->description)->toBe('MUET Band 2.0 or higher')
            ->and($result->conditions[0]->reason)->toBe($reason);
    })->with([
        'polytechnic diploma, exempted' => [
            'diploma_polytechnic_or_community_college',
            'Mendapat Sekurang-Kurangnya BAND 2.0 Dalam Malaysian University English Test (MUET) Mengikut Tempoh Sah Laku Pada Tarikh Permohonan (Pengecualian MUET Sebagai Syarat Permohonan Kemasukan Ke Malaysia Technical University Network (Mtun) Kepada Lepasan Diploma Politeknik DAN Kolej Komuniti).',
            RequirementStatus::Met,
            "Polytechnic and community college diploma graduates don't need MUET for this course.",
        ],
        'public university diploma, not exempted' => [
            'diploma_public_university',
            'Mendapat Sekurang-Kurangnya BAND 2.0 Dalam Malaysian University English Test (MUET) Mengikut Tempoh Sah Laku Pada Tarikh Permohonan (Pengecualian MUET Sebagai Syarat Permohonan Kemasukan Ke Malaysia Technical University Network (Mtun) Kepada Lepasan Diploma Politeknik DAN Kolej Komuniti).',
            RequirementStatus::NotMet,
            "You haven't taken MUET.",
        ],
        'polytechnic diploma, no exemption listed' => [
            'diploma_polytechnic_or_community_college',
            'Mendapat Sekurang-Kurangnya BAND 2.0 Dalam Malaysian University English Test (MUET).',
            RequirementStatus::NotMet,
            "You haven't taken MUET.",
        ],
    ]);

    it('accepts the lower band only from students sponsored by the National Sports Council (MSN)', function (string $band, RequirementStatus $status, array $descriptions) {
        $requirement = new EligibilityRequirement([
            'level' => 'stpm',
            'specific_conditions_content' => [
                'Mendapat Sekurang-Kurangnya BAND 3.0 Dalam Malaysian University English Test (MUET) Untuk Peperiksaan Yang Bermula Sesi 1 Tahun 2021. Bagi Pelajar Tajaan Msn',
                'Mendapat Sekurang-Kurangnya BAND 2.0 Dalam Malaysian University English Test (MUET) Untuk Peperiksaan Yang Bermula Sesi 1 Tahun 2021.',
            ],
            'specific_conditions_logic' => [
                ['condition_type' => 'muet_requirement', 'condition_operator' => '>=', 'condition_value' => 3],
                ['condition_type' => 'muet_requirement', 'condition_operator' => '>=', 'condition_value' => 2],
            ],
        ]);

        $result = app(EligibilityChecker::class)->checkRequirement($requirement, stpmApplicant(['muet_band' => $band]));

        expect($result->status)->toBe($status)
            ->and(collect($result->conditions)->pluck('description')->all())->toBe($descriptions);
    })->with([
        'higher band' => ['3.5', RequirementStatus::Met, ['MUET Band 3.0 or higher']],
        'lower band' => ['2.5', RequirementStatus::Unknown, ['MUET Band 3.0 or higher', 'MUET Band 2.0 or higher', 'Sponsored by the National Sports Council (MSN)']],
        'below both' => ['1.5', RequirementStatus::NotMet, ['MUET Band 3.0 or higher', 'MUET Band 2.0 or higher', 'Sponsored by the National Sports Council (MSN)']],
    ]);
});

describe('subject names', function () {
    it('matches other spellings of the same subject', function (string $level, string $requirementName) {
        $group = $level === 'stpm' ? 'STPM' : 'SPM';
        $applicant = stpmApplicant([
            'spm_subjects' => [['subject' => "Pendidikan Syari'ah Islamiah", 'grade' => 'A'], ['subject' => 'Bahasa Melayu', 'grade' => 'A']],
            'stpm_subjects' => [['subject' => 'Mathematics M', 'grade' => 'A']],
        ]);

        $result = checkEligibility($applicant, [requirementSection($group, [subjectCondition($group, 'C', [$requirementName])])]);

        expect($result->status)->toBe(RequirementStatus::Met);
    })->with([
        'different capitals' => ['spm', "Pendidikan Syari'Ah Islamiah"],
        'Bahasa Malaysia' => ['spm', 'Bahasa Malaysia'],
        'brackets' => ['stpm', 'Mathematics (M)'],
        'Malay name' => ['stpm', 'Matematik M'],
        'level suffix' => ['stpm', 'Mathematics M Di Peringkat STPM'],
    ]);
});

/**
 * A course whose only specific requirements are the given lines, unread by the collectors.
 *
 * @param  list<string>  $lines
 */
function lineRequirement(array $lines): EligibilityRequirement
{
    return new EligibilityRequirement([
        'level' => 'stpm',
        'specific_conditions_content' => $lines,
        'specific_conditions_logic' => array_map(fn (string $line): array => ['condition_type' => 'unparsed_text', 'text' => $line], $lines),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function qualificationApplicant(string $category, array $overrides = []): ApplicantResults
{
    return stpmApplicant(array_merge(['applicant_category' => $category, 'stpm_subjects' => null, 'cgpa' => null], $overrides));
}

describe('A-Level, IB and AUSMAT results', function () {
    it('checks IB grades at Higher or Standard Level', function (array $subjects, RequirementStatus $status) {
        $requirement = lineRequirement([
            'Mendapat Sekurang-Kurangnya Gred 5 Di Peringkat Higher Level (Hl) ATAU Gred 6 Di Peringkat Standard Level (Sl) Dalam Mata Pelajaran Berikut',
            'Mathematics;',
            'Physics',
        ]);

        $result = app(EligibilityChecker::class)->checkRequirement($requirement, qualificationApplicant('international_baccalaureate_diploma', ['ib_subjects' => $subjects]));

        expect($result->status)->toBe($status)
            ->and($result->conditions[0]->description)->toBe('Grade 5 or better at Higher Level (HL), or 6 at Standard Level (SL), in each of these IB subjects: Mathematics; Physics')
            ->and($result->notes)->toBe([]);
    })->with([
        '5 at HL and 6 at SL' => [[['subject' => 'Mathematics', 'level' => 'HL', 'grade' => '5'], ['subject' => 'Physics', 'level' => 'SL', 'grade' => '6']], RequirementStatus::Met],
        '5 at SL' => [[['subject' => 'Mathematics', 'level' => 'HL', 'grade' => '7'], ['subject' => 'Physics', 'level' => 'SL', 'grade' => '5']], RequirementStatus::NotMet],
        'no IB results' => [[], RequirementStatus::Unknown],
    ]);

    it('counts a subject once across the parts of a list', function (array $subjects, RequirementStatus $status) {
        $requirement = lineRequirement([
            'Memiliki Kelayakan International Baccalaureate Diploma (Ib) Dengan Mendapat Gred 4 Mata Pelajaran Peringkat Hl/Sl Dalam Mana-Mana Dua (2) Mata Pelajaran Berikut;',
            'Physics ; DAN Mana-Mana Satu (1) Dari Mata Pelajaran Berikut',
            'Mathematics',
            'Chemistry',
        ]);

        $result = app(EligibilityChecker::class)->checkRequirement($requirement, qualificationApplicant('international_baccalaureate_diploma', ['ib_subjects' => $subjects]));

        expect($result->status)->toBe($status)
            ->and(collect($result->conditions)->pluck('description')->all())->toBe([
                'Grade 4 or better in IB Physics',
                'Grade 4 or better in one of these IB subjects: Mathematics; Chemistry',
            ]);
    })->with([
        'Physics and Chemistry' => [[['subject' => 'Physics', 'level' => 'SL', 'grade' => '4'], ['subject' => 'Chemistry', 'level' => 'HL', 'grade' => '5']], RequirementStatus::Met],
        'Physics only' => [[['subject' => 'Physics', 'level' => 'SL', 'grade' => '7']], RequirementStatus::NotMet],
    ]);

    it('takes IB Mathematics Analysis and Approaches as Mathematics', function () {
        $requirement = lineRequirement(['Mendapat Sekurang-Kurangnya Gred 4 Dalam Mata Pelajaran Mathematics.']);

        $result = app(EligibilityChecker::class)->checkRequirement($requirement, qualificationApplicant('international_baccalaureate_diploma', [
            'ib_subjects' => [['subject' => 'Mathematics Analysis and Approaches', 'level' => 'SL', 'grade' => '5']],
        ]));

        expect($result->status)->toBe(RequirementStatus::Met)
            ->and($result->conditions[0]->description)->toBe('Grade 4 or better in IB Mathematics');
    });

    it('takes AUSMAT Mathematical Methods as Mathematics', function () {
        $result = checkEligibility(
            qualificationApplicant('ausmat_sam_cpu', ['equivalent_subjects' => [['subject' => 'Mathematical Methods', 'grade' => 'B']]]),
            [],
            [subjectCondition('Kelayakan Setaraf', 'C', ['Mathematics'])],
        );

        expect($result->status)->toBe(RequirementStatus::Met);
    });

    it('checks the IB total', function (?int $points, RequirementStatus $status) {
        $requirement = lineRequirement(['Lulus Peperiksaan International Baccalaureate (Ib) Dengan', 'Mendapat Sekurang-Kurangnya Markah 32']);

        $result = app(EligibilityChecker::class)->checkRequirement($requirement, qualificationApplicant('international_baccalaureate_diploma', ['ib_points' => $points]));

        expect($result->status)->toBe($status)
            ->and($result->conditions[0]->description)->toBe('At least 32 points in the IB Diploma');
    })->with([
        'above' => [33, RequirementStatus::Met],
        'below' => [30, RequirementStatus::NotMet],
        'not given' => [null, RequirementStatus::Unknown],
    ]);

    it('leaves a letter grade for IB results to the applicant, in English', function () {
        $requirement = lineRequirement(['Mendapat Sekurang-Kurangnya Gred C Dalam Mata Pelajaran Berikut', 'Mathematics', 'Chemistry / Physics / Biology']);

        $result = app(EligibilityChecker::class)->checkRequirement($requirement, qualificationApplicant('international_baccalaureate_diploma', ['ib_subjects' => [['subject' => 'Mathematics', 'level' => 'HL', 'grade' => '7']]]));

        expect($result->status)->toBe(RequirementStatus::Unknown)
            ->and($result->conditions[0]->description)->toBe('Grade C or better in each of these IB subjects: Mathematics; Chemistry / Physics / Biology')
            ->and($result->conditions[0]->canBeChecked)->toBeFalse();
    });

    it('leaves a list to the applicant when a subject the form lacks could meet it', function () {
        $requirement = lineRequirement([
            'Memiliki Kelayakan International Baccalaureate Diploma (Ib) Dengan Sekurang-Kurangnya Mendapat Gred 4 Mata Pelajaran Peringkat Hl Dalam Mata Pelajaran Berikut',
            'Mathematics/ Physics/ Accounting DAN Mana-Mana Satu (1) Daripada Mata Pelajaran Lain.',
        ]);

        $result = app(EligibilityChecker::class)->checkRequirement($requirement, qualificationApplicant('international_baccalaureate_diploma', ['ib_subjects' => [
            ['subject' => 'History', 'level' => 'HL', 'grade' => '6'],
            ['subject' => 'Physics', 'level' => 'SL', 'grade' => '6'],
        ]]));

        expect($result->status)->toBe(RequirementStatus::Unknown)
            ->and($result->conditions[0]->canBeChecked)->toBeFalse()
            ->and($result->conditions[0]->reason)->toContain('Accounting');
    });

    it('checks A-Level grade sets across the subjects', function (array $grades, RequirementStatus $status) {
        $requirement = lineRequirement([
            'Lulus A-Level Dengan Mendapat Gred Bbb, Abc ATAU Aac Dalam Tiga (3) Mata Pelajaran Berikut',
            'Biology',
            'Chemistry',
            'Physics / Mathematics',
        ]);
        $subjects = array_map(fn (string $subject, string $grade): array => ['subject' => $subject, 'grade' => $grade], ['Biology', 'Chemistry', 'Mathematics'], $grades);

        $result = app(EligibilityChecker::class)->checkRequirement($requirement, qualificationApplicant('a_level', ['equivalent_subjects' => $subjects]));

        expect($result->status)->toBe($status)
            ->and($result->conditions[0]->description)->toBe('Grades BBB, ABC or AAC in each of these A-Level subjects: Biology; Chemistry; Physics / Mathematics');
    })->with([
        'AAC' => [['C', 'A', 'A'], RequirementStatus::Met],
        'ABC' => [['B', 'C', 'A'], RequirementStatus::Met],
        'BBC' => [['B', 'B', 'C'], RequirementStatus::NotMet],
    ]);

    it('checks the AUSMAT aggregate and ATAR', function (string $line, array $scores, RequirementStatus $status, string $description) {
        $result = app(EligibilityChecker::class)->checkRequirement(lineRequirement([$line]), qualificationApplicant('ausmat_sam_cpu', $scores));

        expect($result->status)->toBe($status)
            ->and($result->conditions[0]->description)->toBe($description);
    })->with([
        'aggregate met' => ['Lulus AUSMAT/Sam Dengan Memperolehi Minimum Agregat 68', ['ausmat_aggregate' => 70], RequirementStatus::Met, 'An AUSMAT aggregate of at least 68'],
        'aggregate not given' => ['Lulus AUSMAT/Sam Dengan Memperolehi Minimum Agregat 68', [], RequirementStatus::Unknown, 'An AUSMAT aggregate of at least 68'],
        'ATAR not met' => ['Mendapat Sekurang-Kurangnya Skor 80 Mata Dalam Australian Tertiary Admissions Rank (Atar) ;', ['atar' => 79.5], RequirementStatus::NotMet, 'An ATAR of at least 80'],
    ]);
});

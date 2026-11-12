<?php

use App\Http\Controllers\AcademicProfileController;

/**
 * @return array<string, mixed>
 */
function spmProfilePayload(array $overrides = []): array
{
    return array_merge([
        'applicant_category' => 'spm_current_year',
        'gender' => 'female',
        'age' => '18',
        'eligible_descent' => 'yes',
        'colour_blind' => 'no',
        'spm_stream' => 'stem_a',
        'spm_subjects' => [
            ['subject' => 'Bahasa Melayu', 'grade' => 'A'],
            ['subject' => 'Sejarah', 'grade' => 'B+'],
        ],
    ], $overrides);
}

/**
 * @return array<string, mixed>
 */
function stpmProfilePayload(array $overrides = []): array
{
    return array_merge([
        'applicant_category' => 'stpm_science_current_year',
        'gender' => 'male',
        'age' => '20',
        'eligible_descent' => 'yes',
        'colour_blind' => 'no',
        'spm_subjects' => [
            ['subject' => 'Bahasa Melayu', 'grade' => 'A'],
            ['subject' => 'Sejarah', 'grade' => 'B'],
        ],
        'stpm_subjects' => [
            ['subject' => 'Pengajian Am', 'grade' => 'A-'],
            ['subject' => 'Physics', 'grade' => 'B+'],
        ],
        'cgpa' => '3.67',
        'muet_band' => '4.0',
    ], $overrides);
}

describe('create', function () {
    it('renders the academic profile form', function () {
        $this->get(route('academic-profile.create'))
            ->assertOk()
            ->assertSee('Add your results')
            ->assertSee('What are you applying with?')
            ->assertSee('SPM (current year)');
    });

    it('redirects to the edit form when a profile already exists', function () {
        $this->post(route('academic-profile.store'), spmProfilePayload());

        $this->get(route('academic-profile.create'))
            ->assertRedirect(route('academic-profile.edit'));
    });
});

describe('store', function () {
    it('saves an SPM profile in the session and shows it', function () {
        $this->post(route('academic-profile.store'), spmProfilePayload())
            ->assertRedirect(route('academic-profile.show'))
            ->assertSessionHas('status', 'Your results have been saved.')
            ->assertSessionHas(AcademicProfileController::SESSION_KEY, function (array $profile): bool {
                return $profile['applicant_category'] === 'spm_current_year'
                    && $profile['gender'] === 'female'
                    && $profile['spm_stream'] === 'stem_a'
                    && $profile['spm_subjects'] === [
                        ['subject' => 'Bahasa Melayu', 'grade' => 'A'],
                        ['subject' => 'Sejarah', 'grade' => 'B+'],
                    ]
                    && $profile['stpm_subjects'] === null
                    && $profile['cgpa'] === null
                    && $profile['muet_band'] === null;
            });
    });

    it('saves an STPM profile with its STPM results, CGPA and MUET band', function () {
        $this->post(route('academic-profile.store'), stpmProfilePayload())
            ->assertRedirect(route('academic-profile.show'))
            ->assertSessionHas(AcademicProfileController::SESSION_KEY, function (array $profile): bool {
                return $profile['stpm_subjects'] === [
                    ['subject' => 'Pengajian Am', 'grade' => 'A-'],
                    ['subject' => 'Physics', 'grade' => 'B+'],
                ]
                    && $profile['cgpa'] === 3.67
                    && $profile['muet_band'] === '4.0'
                    && $profile['spm_stream'] === null;
            });
    });

    it('does not save fields that do not apply to the applicant category', function () {
        $this->post(route('academic-profile.store'), spmProfilePayload([
            'cgpa' => '3.90',
            'muet_band' => '5.0',
            'stam_grade' => 'Mumtaz',
            'stpm_subjects' => [['subject' => 'Physics', 'grade' => 'A']],
        ]))->assertSessionHasNoErrors();

        $profile = session(AcademicProfileController::SESSION_KEY);

        expect($profile['cgpa'])->toBeNull()
            ->and($profile['muet_band'])->toBeNull()
            ->and($profile['stam_grade'])->toBeNull()
            ->and($profile['stpm_subjects'])->toBeNull();
    });

    it('ignores subject rows left completely empty', function () {
        $this->post(route('academic-profile.store'), spmProfilePayload([
            'spm_subjects' => [
                ['subject' => 'Bahasa Melayu', 'grade' => 'A'],
                ['subject' => '', 'grade' => ''],
            ],
        ]))->assertSessionHasNoErrors();

        expect(session(AcademicProfileController::SESSION_KEY)['spm_subjects'])
            ->toBe([['subject' => 'Bahasa Melayu', 'grade' => 'A']]);
    });

    it('accepts an A-Level profile without a CGPA', function () {
        $this->post(route('academic-profile.store'), stpmProfilePayload([
            'applicant_category' => 'a_level',
            'stpm_subjects' => null,
            'equivalent_subjects' => [['subject' => 'Mathematics', 'grade' => 'A*']],
            'cgpa' => '',
        ]))->assertSessionHasNoErrors();

        expect(session(AcademicProfileController::SESSION_KEY)['cgpa'])->toBeNull();
    });

    it('rejects an empty form', function () {
        $this->post(route('academic-profile.store'), [])
            ->assertSessionHasErrors([
                'applicant_category' => 'Choose what you are applying with.',
                'gender' => 'Choose your gender.',
                'age' => 'Enter your age.',
                'eligible_descent' => 'Choose your Bumiputera status.',
                'colour_blind' => 'Choose your colour vision.',
                'spm_subjects' => 'Add at least one SPM subject.',
            ])
            ->assertSessionMissing(AcademicProfileController::SESSION_KEY);
    });

    it('requires STPM results, CGPA and MUET band from STPM applicants', function () {
        $this->post(route('academic-profile.store'), stpmProfilePayload([
            'stpm_subjects' => [],
            'cgpa' => '',
            'muet_band' => '',
        ]))->assertSessionHasErrors([
            'stpm_subjects' => 'Add at least one STPM subject.',
            'cgpa' => 'Enter your CGPA.',
            'muet_band' => 'Choose your MUET band.',
        ]);
    });

    it('requires a STAM grade from STAM applicants', function () {
        $this->post(route('academic-profile.store'), spmProfilePayload([
            'applicant_category' => 'stam_current_year',
            'muet_band' => '3.0',
        ]))->assertSessionHasErrors(['stam_grade' => 'Choose your STAM grade.']);
    });

    it('saves matriculation results from matriculation applicants', function () {
        $this->post(route('academic-profile.store'), stpmProfilePayload([
            'applicant_category' => 'matriculation_or_foundation_science',
            'stpm_subjects' => null,
            'matriculation_subjects' => [
                ['subject' => 'Mathematics', 'grade' => 'A-'],
                ['subject' => 'Chemistry', 'grade' => 'B+'],
            ],
        ]))->assertSessionHasNoErrors();

        expect(session(AcademicProfileController::SESSION_KEY)['matriculation_subjects'])
            ->toBe([['subject' => 'Mathematics', 'grade' => 'A-'], ['subject' => 'Chemistry', 'grade' => 'B+']]);
    });

    it('requires matriculation results from matriculation applicants', function () {
        $this->post(route('academic-profile.store'), stpmProfilePayload([
            'applicant_category' => 'matriculation_engineering',
            'stpm_subjects' => null,
        ]))->assertSessionHasErrors(['matriculation_subjects' => 'Add at least one matriculation or foundation subject.']);
    });

    it('lets diploma applicants leave out diploma subject grades', function () {
        $this->post(route('academic-profile.store'), stpmProfilePayload([
            'applicant_category' => 'diploma_public_university',
            'stpm_subjects' => null,
        ]))->assertSessionHasNoErrors();

        expect(session(AcademicProfileController::SESSION_KEY)['diploma_subjects'])->toBeNull();
    });

    it('requires subject results from A-Level, IB and AUSMAT applicants', function () {
        $this->post(route('academic-profile.store'), stpmProfilePayload([
            'applicant_category' => 'ausmat_sam_cpu',
            'stpm_subjects' => null,
            'equivalent_subjects' => [['subject' => 'Mathematical Methods', 'grade' => 'B+']],
        ]))->assertSessionHasErrors('equivalent_subjects.0.grade');
    });

    it('saves IB subjects with their level, and the IB total', function () {
        $this->post(route('academic-profile.store'), stpmProfilePayload([
            'applicant_category' => 'international_baccalaureate_diploma',
            'stpm_subjects' => null,
            'cgpa' => '',
            'ib_subjects' => [
                ['subject' => 'Mathematics Analysis and Approaches', 'level' => 'HL', 'grade' => '6'],
                ['subject' => '', 'level' => '', 'grade' => ''],
                ['subject' => 'Physics', 'level' => 'SL', 'grade' => '5'],
            ],
            'ib_points' => '34',
            'equivalent_subjects' => [['subject' => 'Mathematics', 'grade' => 'A']],
            'atar' => '90',
        ]))->assertSessionHasNoErrors();

        $profile = session(AcademicProfileController::SESSION_KEY);

        expect($profile['ib_subjects'])->toBe([
            ['subject' => 'Mathematics Analysis and Approaches', 'level' => 'HL', 'grade' => '6'],
            ['subject' => 'Physics', 'level' => 'SL', 'grade' => '5'],
        ])
            ->and($profile['ib_points'])->toBe(34)
            ->and($profile['equivalent_subjects'])->toBeNull()
            ->and($profile['atar'])->toBeNull();
    });

    it('rejects an IB subject without a level, or with a letter grade', function (array $row, string $error) {
        $this->post(route('academic-profile.store'), stpmProfilePayload([
            'applicant_category' => 'international_baccalaureate_diploma',
            'stpm_subjects' => null,
            'ib_subjects' => [$row],
        ]))->assertSessionHasErrors($error);
    })->with([
        'no level' => [['subject' => 'Physics', 'level' => '', 'grade' => '5'], 'ib_subjects.0.level'],
        'letter grade' => [['subject' => 'Physics', 'level' => 'HL', 'grade' => 'A'], 'ib_subjects.0.grade'],
    ]);

    it('saves the ATAR and aggregate for AUSMAT applicants', function () {
        $this->post(route('academic-profile.store'), stpmProfilePayload([
            'applicant_category' => 'ausmat_sam_cpu',
            'stpm_subjects' => null,
            'equivalent_subjects' => [['subject' => 'Mathematical Methods', 'grade' => 'B']],
            'atar' => '85.5',
            'ausmat_aggregate' => '72',
        ]))->assertSessionHasNoErrors();

        $profile = session(AcademicProfileController::SESSION_KEY);

        expect($profile['atar'])->toBe(85.5)
            ->and($profile['ausmat_aggregate'])->toBe(72);
    });

    it('rejects an ATAR above 99.95', function () {
        $this->post(route('academic-profile.store'), stpmProfilePayload([
            'applicant_category' => 'ausmat_sam_cpu',
            'stpm_subjects' => null,
            'equivalent_subjects' => [['subject' => 'Mathematical Methods', 'grade' => 'B']],
            'atar' => '99.99',
        ]))->assertSessionHasErrors(['atar' => 'Your ATAR must be between 0 and 99.95.']);
    });

    it('saves Pra-U results instead of STPM results from sports school applicants', function () {
        $this->post(route('academic-profile.store'), stpmProfilePayload([
            'applicant_category' => 'sports_school',
            'prau_subjects' => [['subject' => 'Sains Sukan', 'grade' => 'B+']],
        ]))->assertSessionHasNoErrors();

        $profile = session(AcademicProfileController::SESSION_KEY);

        expect($profile['prau_subjects'])->toBe([['subject' => 'Sains Sukan', 'grade' => 'B+']])
            ->and($profile['stpm_subjects'])->toBeNull();
    });

    it('requires Pra-U results from sports school applicants', function () {
        $this->post(route('academic-profile.store'), stpmProfilePayload([
            'applicant_category' => 'sports_school',
        ]))->assertSessionHasErrors(['prau_subjects' => 'Add at least one Pra-U subject.']);
    });

    it('rejects the same SPM subject entered twice', function () {
        $this->post(route('academic-profile.store'), spmProfilePayload([
            'spm_subjects' => [
                ['subject' => 'Sejarah', 'grade' => 'A'],
                ['subject' => 'Sejarah', 'grade' => 'B'],
            ],
        ]))->assertSessionHasErrors(['spm_subjects.0.subject' => 'You have added the same SPM subject more than once.']);
    });

    it('rejects a subject row without a grade', function () {
        $this->post(route('academic-profile.store'), spmProfilePayload([
            'spm_subjects' => [['subject' => 'Matematik', 'grade' => '']],
        ]))->assertSessionHasErrors(['spm_subjects.0.grade' => 'Choose a grade for every SPM subject.']);
    });

    it('rejects a subject that is not on the SPM subject list', function () {
        $this->post(route('academic-profile.store'), spmProfilePayload([
            'spm_subjects' => [['subject' => 'Astrology', 'grade' => 'A']],
        ]))->assertSessionHasErrors(['spm_subjects.0.subject' => 'Choose your SPM subjects from the list.']);
    });

    it('rejects an invalid CGPA', function (string $cgpa, string $message) {
        $this->post(route('academic-profile.store'), stpmProfilePayload(['cgpa' => $cgpa]))
            ->assertSessionHasErrors(['cgpa' => $message]);
    })->with([
        'above 4.00' => ['4.10', 'Your CGPA must be between 0.00 and 4.00.'],
        'more than 2 decimal places' => ['3.456', 'Use no more than 2 decimal places for your CGPA.'],
    ]);

    it('saves the age and sports level, and not work experience, which the form no longer asks for', function () {
        $this->post(route('academic-profile.store'), stpmProfilePayload([
            'age' => '24',
            'work_experience_years' => '3',
            'sports_level' => 'state',
        ]))->assertSessionHasNoErrors();

        $profile = session(AcademicProfileController::SESSION_KEY);

        expect($profile['age'])->toBe(24)
            ->and($profile['sports_level'])->toBe('state')
            ->and($profile)->not->toHaveKey('work_experience_years');
    });

    it('saves a profile with the other details left blank', function () {
        $this->post(route('academic-profile.store'), stpmProfilePayload([
            'sports_level' => '',
            'music_grade' => '',
        ]))->assertSessionHasNoErrors();

        $profile = session(AcademicProfileController::SESSION_KEY);

        expect($profile['sports_level'])->toBeNull()
            ->and($profile['music_grade'])->toBeNull();
    });

    it('rejects invalid other details', function (string $field, string $value, string $message) {
        $this->post(route('academic-profile.store'), stpmProfilePayload([$field => $value]))
            ->assertSessionHasErrors([$field => $message]);
    })->with([
        'an age under 15' => ['age', '12', 'Your age must be between 15 and 70.'],
        'an age that is not a whole number' => ['age', '19.5', 'Enter your age as a whole number, like 19.'],
        'negative Quran memorisation' => ['quran_juz', '-1', 'The number of juz must be between 0 and 30.'],
        'a sports level not on the list' => ['sports_level', 'olympic', 'Choose your sports level from the list.'],
        'a Bumiputera answer that is not yes or no' => ['eligible_descent', 'maybe', 'Choose your Bumiputera status from the list.'],
        'a colour blindness answer that is not yes or no' => ['colour_blind', 'maybe', 'Choose your colour vision from the list.'],
        'an IELTS band not on the list' => ['ielts_band', '9.5', 'Choose your IELTS band from the list.'],
        'a TOEFL iBT score above 120' => ['toefl_ibt_score', '121', 'Your TOEFL iBT score must be between 0 and 120.'],
        'a music grade not on the list' => ['music_grade', '9', 'Choose your music grade from the list.'],
        'more than 30 juz' => ['quran_juz', '31', 'The number of juz must be between 0 and 30.'],
    ]);

    it('saves that the applicant has not taken MUET', function () {
        $this->post(route('academic-profile.store'), stpmProfilePayload(['muet_band' => 'none']))->assertSessionHasNoErrors();

        expect(session(AcademicProfileController::SESSION_KEY)['muet_band'])->toBe('none');

        $this->get(route('academic-profile.show'))->assertOk()->assertSee("Haven't taken MUET");
    });

    it('saves the descent, colour blindness, English test, music grade and Quran memorisation', function () {
        $this->post(route('academic-profile.store'), stpmProfilePayload([
            'eligible_descent' => 'yes',
            'colour_blind' => 'no',
            'ielts_band' => '6.5',
            'toefl_ibt_score' => '90',
            'music_grade' => '5',
            'quran_juz' => '30',
        ]))->assertSessionHasNoErrors();

        $profile = session(AcademicProfileController::SESSION_KEY);

        expect($profile['eligible_descent'])->toBe('yes')
            ->and($profile['colour_blind'])->toBe('no')
            ->and($profile['ielts_band'])->toBe('6.5')
            ->and($profile['toefl_ibt_score'])->toBe(90)
            ->and($profile['music_grade'])->toBe('5')
            ->and($profile['quran_juz'])->toBe(30);

        $this->get(route('academic-profile.show'))
            ->assertOk()
            ->assertSee('Bumiputera')
            ->assertSee('Band 6.5')
            ->assertSee('Grade 5')
            ->assertSee('30 juz');
    });

    it('ignores a specialisation grade, which is no longer asked for', function () {
        $this->post(route('academic-profile.store'), stpmProfilePayload([
            'applicant_category' => 'matriculation_or_foundation_science',
            'stpm_subjects' => null,
            'matriculation_subjects' => [['subject' => 'Mathematics', 'grade' => 'A-']],
            'specialisation_grade' => 'B+',
        ]))->assertSessionHasNoErrors();

        expect(session(AcademicProfileController::SESSION_KEY))->not->toHaveKey('specialisation_grade');
    });
});

describe('show', function () {
    it('redirects to the form when no profile has been saved', function () {
        $this->get(route('academic-profile.show'))
            ->assertRedirect(route('academic-profile.create'));
    });

    it('displays the saved profile', function () {
        $this->post(route('academic-profile.store'), stpmProfilePayload());

        $this->get(route('academic-profile.show'))
            ->assertOk()
            ->assertSee('STPM Science (current year)')
            ->assertSee('Pengajian Am')
            ->assertSee('3.67')
            ->assertSee('Band 4.0');
    });

    it('displays the other details when they are given', function () {
        $this->post(route('academic-profile.store'), stpmProfilePayload([
            'age' => '24',
            'sports_level' => 'district',
        ]));

        $this->get(route('academic-profile.show'))
            ->assertOk()
            ->assertSee('24')
            ->assertSee('District')
            ->assertDontSee('daerah');
    });
});

describe('edit', function () {
    it('redirects to the form when no profile has been saved', function () {
        $this->get(route('academic-profile.edit'))
            ->assertRedirect(route('academic-profile.create'));
    });

    it('fills the form with the saved profile', function () {
        $this->post(route('academic-profile.store'), stpmProfilePayload());

        $this->get(route('academic-profile.edit'))
            ->assertOk()
            ->assertSee('Edit your results')
            ->assertSee('value="3.67"', false);
    });
});

describe('update', function () {
    it('replaces the saved profile', function () {
        $this->post(route('academic-profile.store'), spmProfilePayload());

        $this->put(route('academic-profile.update'), spmProfilePayload(['gender' => 'male']))
            ->assertRedirect(route('academic-profile.show'))
            ->assertSessionHas('status', 'Your changes have been saved.');

        expect(session(AcademicProfileController::SESSION_KEY)['gender'])->toBe('male');
    });
});

describe('destroy', function () {
    it('clears the saved profile', function () {
        $this->post(route('academic-profile.store'), spmProfilePayload());

        $this->delete(route('academic-profile.destroy'))
            ->assertRedirect(route('home'))
            ->assertSessionHas('status', 'Your results have been deleted.')
            ->assertSessionMissing(AcademicProfileController::SESSION_KEY);
    });
});

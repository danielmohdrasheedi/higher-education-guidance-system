<?php

namespace App\Http\Controllers;

use App\ApplicantCategory;
use App\Http\Requests\SaveAcademicProfileRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * FR01 Academic Profile Input.
 *
 * The profile is kept in the visitor's session only and is never written to
 * a permanent table (NFR05). It is removed when the session expires or when
 * the visitor clears it.
 */
class AcademicProfileController extends Controller
{
    public const SESSION_KEY = 'academic_profile';

    public function create(Request $request): View|RedirectResponse
    {
        if ($request->session()->has(self::SESSION_KEY)) {
            return redirect()->route('academic-profile.edit');
        }

        return $this->form(null);
    }

    public function store(SaveAcademicProfileRequest $request): RedirectResponse
    {
        $request->session()->put(self::SESSION_KEY, $request->profile());

        return redirect()
            ->route('academic-profile.show')
            ->with('status', 'Your results have been saved.');
    }

    public function show(Request $request): View|RedirectResponse
    {
        $profile = $request->session()->get(self::SESSION_KEY);

        if ($profile === null) {
            return redirect()->route('academic-profile.create');
        }

        return view('academic-profile.show', [
            'profile' => $profile,
            'category' => ApplicantCategory::from($profile['applicant_category']),
        ]);
    }

    public function edit(Request $request): View|RedirectResponse
    {
        $profile = $request->session()->get(self::SESSION_KEY);

        if ($profile === null) {
            return redirect()->route('academic-profile.create');
        }

        return $this->form($profile);
    }

    public function update(SaveAcademicProfileRequest $request): RedirectResponse
    {
        $request->session()->put(self::SESSION_KEY, $request->profile());

        return redirect()
            ->route('academic-profile.show')
            ->with('status', 'Your changes have been saved.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->forget([self::SESSION_KEY, FavouriteController::SESSION_KEY]);

        return redirect()
            ->route('home')
            ->with('status', 'Your results have been deleted.');
    }

    /**
     * @param  array<string, mixed>|null  $profile
     */
    private function form(?array $profile): View
    {
        $defaultSpmSubjects = array_map(
            fn (string $subject): array => ['subject' => $subject, 'grade' => ''],
            config('academic.spm_default_subjects'),
        );

        return view('academic-profile.form', [
            'profile' => $profile,
            'categoryGroups' => ApplicantCategory::grouped(),
            'spmSubjectRows' => old('spm_subjects', $profile['spm_subjects'] ?? $defaultSpmSubjects),
            'stpmSubjectRows' => old('stpm_subjects', $profile['stpm_subjects'] ?? [['subject' => 'Pengajian Am', 'grade' => '']]),
            'matriculationSubjectRows' => old('matriculation_subjects', $profile['matriculation_subjects'] ?? [['subject' => '', 'grade' => '']]),
            'diplomaSubjectRows' => old('diploma_subjects', $profile['diploma_subjects'] ?? []),
            'equivalentSubjectRows' => old('equivalent_subjects', $profile['equivalent_subjects'] ?? [['subject' => '', 'grade' => '']]),
            'prauSubjectRows' => old('prau_subjects', $profile['prau_subjects'] ?? [['subject' => '', 'grade' => '']]),
            'ibSubjectRows' => old('ib_subjects', $profile['ib_subjects'] ?? [['subject' => '', 'level' => '', 'grade' => '']]),
        ]);
    }
}

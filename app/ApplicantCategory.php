<?php

namespace App;

/**
 * Applicant categories offered by e-Panduan UPUOnline.
 *
 * Each value matches the column name used in the scraped programme lists
 * (upuonline_guide_spm_data.csv and upuonline_guide_stpm_data.csv), and
 * code() returns the category code used in e-Panduan URLs.
 */
enum ApplicantCategory: string
{
    case SpmCurrentYear = 'spm_current_year';
    case SpmNotCurrentYear = 'spm_not_current_year';
    case StpmScienceCurrentYear = 'stpm_science_current_year';
    case StpmArtsCurrentYear = 'stpm_arts_current_year';
    case StpmNotCurrentYear = 'stpm_not_current_year';
    case SportsSchool = 'sports_school';
    case MatriculationAccounting = 'matriculation_accounting';
    case MatriculationOrFoundationScience = 'matriculation_or_foundation_science';
    case MatriculationEngineering = 'matriculation_engineering';
    case FoundationTesl = 'foundation_tesl';
    case FoundationLaw = 'foundation_law';
    case FoundationEngineering = 'foundation_engineering';
    case FoundationSocialScience = 'foundation_social_science';
    case FoundationTvetPolytechnic = 'foundation_tvet_polytechnic';
    case MatriculationOrFoundationNotCurrentYear = 'matriculation_or_foundation_not_current_year';
    case FoundationIpts = 'foundation_ipts';
    case StamCurrentYear = 'stam_current_year';
    case StamNot20222021 = 'stam_not_2022_2021';
    case DiplomaPublicUniversity = 'diploma_public_university';
    case DiplomaPolytechnicOrCommunityCollege = 'diploma_polytechnic_or_community_college';
    case DkmDlkm = 'dkm_dlkm';
    case Dvm = 'dvm';
    case DiplomaIlkaIptsOverseasOrOthers = 'diploma_ilka_ipts_overseas_or_others';
    case ALevel = 'a_level';
    case InternationalBaccalaureateDiploma = 'international_baccalaureate_diploma';
    case AusmatSamCpu = 'ausmat_sam_cpu';

    /**
     * The category code used by e-Panduan, e.g. "A" or "G1".
     */
    public function code(): string
    {
        return match ($this) {
            self::SpmCurrentYear => 'A',
            self::SpmNotCurrentYear => 'B',
            self::StpmScienceCurrentYear => 'S',
            self::StpmArtsCurrentYear => 'A',
            self::StpmNotCurrentYear => 'F4',
            self::SportsSchool => 'F3',
            self::MatriculationAccounting => 'P',
            self::MatriculationOrFoundationScience => 'N',
            self::MatriculationEngineering => 'J',
            self::FoundationTesl => 'L',
            self::FoundationLaw => 'U',
            self::FoundationEngineering => 'K',
            self::FoundationSocialScience => 'M',
            self::FoundationTvetPolytechnic => 'V',
            self::MatriculationOrFoundationNotCurrentYear => 'F5',
            self::FoundationIpts => 'F8',
            self::StamCurrentYear => 'T',
            self::StamNot20222021 => 'F6',
            self::DiplomaPublicUniversity => 'G1',
            self::DiplomaPolytechnicOrCommunityCollege => 'G2',
            self::DkmDlkm => 'E1',
            self::Dvm => 'E2',
            self::DiplomaIlkaIptsOverseasOrOthers => 'E3',
            self::ALevel => 'F1',
            self::InternationalBaccalaureateDiploma => 'F2',
            self::AusmatSamCpu => 'F7',
        };
    }

    /**
     * The e-Panduan search level: "spm" for SPM leavers, "stpm" for STPM and equivalent.
     */
    public function level(): string
    {
        return $this->isSpm() ? 'spm' : 'stpm';
    }

    public function label(): string
    {
        return match ($this) {
            self::SpmCurrentYear => 'SPM (current year)',
            self::SpmNotCurrentYear => 'SPM (previous years)',
            self::StpmScienceCurrentYear => 'STPM Science (current year)',
            self::StpmArtsCurrentYear => 'STPM Arts (current year)',
            self::StpmNotCurrentYear => 'STPM (previous years)',
            self::SportsSchool => 'Sports School (Sekolah Sukan)',
            self::MatriculationAccounting => 'Matriculation (Accounting)',
            self::MatriculationOrFoundationScience => 'Matriculation / Foundation (Science)',
            self::MatriculationEngineering => 'Matriculation (Engineering)',
            self::FoundationTesl => 'Foundation (TESL)',
            self::FoundationLaw => 'Foundation (Law)',
            self::FoundationEngineering => 'Foundation (Engineering)',
            self::FoundationSocialScience => 'Foundation (Social Science)',
            self::FoundationTvetPolytechnic => 'Foundation (TVET Polytechnic)',
            self::MatriculationOrFoundationNotCurrentYear => 'Matriculation / Foundation (previous years)',
            self::FoundationIpts => 'Foundation (private institution)',
            self::StamCurrentYear => 'STAM (current year)',
            self::StamNot20222021 => 'STAM (other years)',
            self::DiplomaPublicUniversity => 'Diploma (public university)',
            self::DiplomaPolytechnicOrCommunityCollege => 'Diploma (polytechnic / community college)',
            self::DkmDlkm => 'DKM / DLKM',
            self::Dvm => 'Diploma Vokasional Malaysia (DVM)',
            self::DiplomaIlkaIptsOverseasOrOthers => 'Diploma (ILKA / private / overseas / other)',
            self::ALevel => 'A-Level',
            self::InternationalBaccalaureateDiploma => 'International Baccalaureate Diploma (IBD)',
            self::AusmatSamCpu => 'AUSMAT / SAM / CPU',
        };
    }

    /**
     * The heading the category is listed under on the form.
     */
    public function group(): string
    {
        return match ($this) {
            self::SpmCurrentYear, self::SpmNotCurrentYear => 'SPM',
            self::StpmScienceCurrentYear, self::StpmArtsCurrentYear, self::StpmNotCurrentYear, self::SportsSchool => 'STPM',
            self::StamCurrentYear, self::StamNot20222021 => 'STAM',
            self::DiplomaPublicUniversity, self::DiplomaPolytechnicOrCommunityCollege, self::DkmDlkm, self::Dvm,
            self::DiplomaIlkaIptsOverseasOrOthers => 'Diploma',
            self::ALevel, self::InternationalBaccalaureateDiploma, self::AusmatSamCpu => 'Other qualifications',
            default => 'Matriculation / Foundation',
        };
    }

    public function isSpm(): bool
    {
        return in_array($this, [self::SpmCurrentYear, self::SpmNotCurrentYear], true);
    }

    public function requiresSpmStream(): bool
    {
        return $this->isSpm();
    }

    /**
     * Sports school applicants give their Pra-U (Sekolah Sukan Malaysia) results instead.
     */
    public function requiresStpmResults(): bool
    {
        return $this->group() === 'STPM' && $this !== self::SportsSchool;
    }

    public function requiresPrauResults(): bool
    {
        return $this === self::SportsSchool;
    }

    public function requiresMatriculationResults(): bool
    {
        return $this->group() === 'Matriculation / Foundation';
    }

    /**
     * Diploma holders may add diploma subject grades; some courses ask for them.
     */
    public function acceptsDiplomaResults(): bool
    {
        return $this->group() === 'Diploma';
    }

    /**
     * A-Level and AUSMAT applicants give their subjects with letter grades.
     */
    public function requiresEquivalentResults(): bool
    {
        return $this === self::ALevel || $this === self::AusmatSamCpu;
    }

    /**
     * IB applicants give their subjects with the level taken (HL or SL) and a grade from 7 to 1.
     */
    public function requiresIbResults(): bool
    {
        return $this === self::InternationalBaccalaureateDiploma;
    }

    /**
     * AUSMAT applicants may give their ATAR and aggregate, which some courses ask for.
     */
    public function acceptsAusmatScores(): bool
    {
        return $this === self::AusmatSamCpu;
    }

    /**
     * Whether e-Panduan gives courses an average merit score for this category. It gives none for diploma holders,
     * applicants from previous years, sports school, private foundation and other qualifications.
     */
    public function hasMeritScore(): bool
    {
        return in_array($this, [
            self::SpmCurrentYear, self::SpmNotCurrentYear, self::StpmScienceCurrentYear, self::StpmArtsCurrentYear,
            self::MatriculationAccounting, self::MatriculationOrFoundationScience, self::MatriculationEngineering,
            self::FoundationTesl, self::FoundationLaw, self::FoundationEngineering, self::FoundationSocialScience,
            self::FoundationTvetPolytechnic, self::StamCurrentYear,
        ], true);
    }

    public function requiresStamGrade(): bool
    {
        return $this->group() === 'STAM';
    }

    public function requiresMuet(): bool
    {
        return ! $this->isSpm();
    }

    /**
     * STPM, matriculation, foundation and diploma applicants must give their CGPA.
     */
    public function requiresCgpa(): bool
    {
        return in_array($this->group(), ['STPM', 'Matriculation / Foundation', 'Diploma'], true);
    }

    /**
     * Applicants with other qualifications may give an equivalent CGPA if they have one.
     */
    public function acceptsCgpa(): bool
    {
        return $this->requiresCgpa() || $this->group() === 'Other qualifications';
    }

    /**
     * The form sections this category needs, used to show and hide sections on the page.
     *
     * @return list<string>
     */
    public function sections(): array
    {
        return array_keys(array_filter([
            'spm-stream' => $this->requiresSpmStream(),
            'stpm' => $this->requiresStpmResults(),
            'matriculation' => $this->requiresMatriculationResults(),
            'diploma' => $this->acceptsDiplomaResults(),
            'equivalent' => $this->requiresEquivalentResults(),
            'ib' => $this->requiresIbResults(),
            'ausmat' => $this->acceptsAusmatScores(),
            'prau' => $this->requiresPrauResults(),
            'cgpa' => $this->acceptsCgpa(),
            'muet' => $this->requiresMuet(),
            'stam' => $this->requiresStamGrade(),
        ]));
    }

    /**
     * All categories grouped by their form heading, in display order.
     *
     * @return array<string, list<self>>
     */
    public static function grouped(): array
    {
        $groups = [];

        foreach (self::cases() as $category) {
            $groups[$category->group()][] = $category;
        }

        return $groups;
    }
}

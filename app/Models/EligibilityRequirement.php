<?php

namespace App\Models;

use Database\Factories\EligibilityRequirementFactory;
use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;

/**
 * The entry requirements of one programme for one applicant category (Eligibility Requirement entity).
 *
 * The *_logic fields hold the structured rules produced by the e-Panduan collectors,
 * and the *_content fields hold the original wording shown on e-Panduan.
 *
 * @property string $programme_code
 * @property string $level
 * @property string $target_category The e-Panduan category code, e.g. "S" or "G1".
 * @property bool $is_offered
 * @property list<array<string, mixed>> $general_conditions_logic
 * @property list<array<string, mixed>|string> $specific_conditions_logic
 * @property list<mixed> $general_conditions_content
 * @property list<mixed> $specific_conditions_content
 */
#[Connection('mongodb')]
#[Table('eligibility_requirements')]
#[Fillable([
    'programme_code',
    'level',
    'target_category',
    'is_offered',
    'general_conditions_content',
    'general_conditions_logic',
    'specific_conditions_content',
    'specific_conditions_logic',
])]
class EligibilityRequirement extends Model
{
    /** @use HasFactory<EligibilityRequirementFactory> */
    use HasFactory;

    /**
     * Words after "Ujian" that mean it isn't a test the university sets: English tests the applicant
     * already holds (IELTS, TOEFL), medical checks, and MUET's "Tarikh Keputusan Ujian Dikeluarkan"
     * (the date the results were released).
     */
    private const NOT_ADMISSION_TESTS = 'dikeluar|toefl|ielts|kemahiran\s+bahasa|bahasa\s+inggeris|rabun|buta|kesihatan|pendengaran|saringan\s+hiv|komputer|internet';

    /**
     * @return BelongsTo<Programme, $this>
     */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class, 'programme_code', 'programme_code');
    }

    /**
     * Whether the requirements mention an interview ("Temu Duga", "Temuduga" or "Temu Bual") or a test
     * the university sets ("Ujian", e.g. "Lulus Ujian DAN / ATAU Temu Duga Yang Ditetapkan.", "Ujian Lukisan"
     * or the MEdSI test). A note that no interview is needed, e.g. "(Tidak Perlu Temu Duga)", doesn't count.
     */
    public function mentionsInterviewOrTest(): bool
    {
        $mentionsInterviewOrTest = false;
        $requirements = $this->requirementTexts();

        array_walk_recursive($requirements, function (mixed $value) use (&$mentionsInterviewOrTest): void {
            if (! $mentionsInterviewOrTest && is_string($value)) {
                $text = preg_replace('/tidak\s+perlu\s+temu\s*-?\s*duga/iu', '', $value);
                $mentionsInterviewOrTest = preg_match('/temu\s*-?\s*(duga|bual)|\bujian\b(?!\s+('.self::NOT_ADMISSION_TESTS.'))/iu', $text) === 1;
            }
        });

        return $mentionsInterviewOrTest;
    }

    /**
     * Whether the wording exempts polytechnic and community college diploma graduates from MUET, e.g.
     * "Mendapat Sekurang-Kurangnya BAND 2.0 Dalam ... (MUET) ... (Pengecualian MUET Sebagai Syarat Permohonan
     * Kemasukan Ke Malaysia Technical University Network (Mtun) Kepada Lepasan Diploma Politeknik DAN Kolej Komuniti)."
     */
    public function exemptsPolytechnicDiplomasFromMuet(): bool
    {
        $isExempt = false;
        $content = [$this->general_conditions_content ?? [], $this->specific_conditions_content ?? []];

        array_walk_recursive($content, function (mixed $value) use (&$isExempt): void {
            $isExempt = $isExempt || (is_string($value) && preg_match('/pengecualian\s+muet\b.*politeknik/iu', $value) === 1);
        });

        return $isExempt;
    }

    /**
     * Whether the wording accepts a lower MUET band from students sponsored by the National Sports Council (MSN), e.g.
     * "Mendapat Sekurang-Kurangnya BAND 3.0 Dalam ... (MUET) ... Bagi Pelajar Tajaan Msn", "Mendapat Sekurang-Kurangnya BAND 2.0 ...".
     */
    public function lowersMuetForMsnSponsoredStudents(): bool
    {
        $isLowered = false;
        $content = [$this->general_conditions_content ?? [], $this->specific_conditions_content ?? []];

        array_walk_recursive($content, function (mixed $value) use (&$isLowered): void {
            $isLowered = $isLowered || (is_string($value) && preg_match('/\bmuet\b.*tajaan\s+msn\b/iu', $value) === 1);
        });

        return $isLowered;
    }

    /**
     * The original wording and the structured rules, which keep any text the collectors couldn't read.
     *
     * @return list<mixed>
     */
    private function requirementTexts(): array
    {
        return [
            $this->general_conditions_content ?? [],
            $this->specific_conditions_content ?? [],
            $this->general_conditions_logic ?? [],
            $this->specific_conditions_logic ?? [],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_offered' => 'boolean',
        ];
    }
}

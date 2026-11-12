<?php

namespace App\Models;

use App\ApplicantCategory;
use Database\Factories\HistoricalTrendFactory;
use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;

/**
 * Past UPUOnline admission figures for one programme and one group of applicants (Historical Trend entity).
 *
 * UPUOnline splits the figures by applicant type, academic group, SPM stream and gender,
 * so each programme has one record for each group of applicants.
 *
 * The choice figures cover all the sessions together.
 *
 * @property string $programme_code
 * @property string $level "spm" or "stpm"
 * @property string|null $applicant_type "STPM/Matrikulasi/Asasi" or "STAM" for STPM-level programmes.
 * @property string $group "Science", "Arts" or "STAM"
 * @property string|null $stream The SPM stream, e.g. "STEM A", for SPM-level programmes.
 * @property string $gender "Female" or "Male"
 * @property float $average_merit_score
 * @property array<string, int> $session_applications Applications by session, e.g. ["2024/2025" => 120].
 * @property array<string, int> $session_offers Offers by session.
 * @property int $first_choice_applications
 * @property int $first_choice_offers
 * @property int $lower_choice_applications
 * @property int $lower_choice_offers
 */
#[Connection('mongodb')]
#[Table('historical_trends')]
#[Fillable([
    'programme_code',
    'level',
    'applicant_type',
    'group',
    'stream',
    'gender',
    'average_merit_score',
    'session_applications',
    'session_offers',
    'first_choice_applications',
    'first_choice_offers',
    'lower_choice_applications',
    'lower_choice_offers',
])]
class HistoricalTrend extends Model
{
    /** @use HasFactory<HistoricalTrendFactory> */
    use HasFactory;

    public const STPM_APPLICANTS = 'STPM/Matrikulasi/Asasi';

    public const STAM_APPLICANTS = 'STAM';

    /**
     * The applicant type whose figures apply to the category: STAM figures for STAM
     * applicants and STPM figures for the other STPM-level categories. SPM-level
     * figures are not split by applicant type, so this is null for SPM leavers.
     */
    public static function applicantTypeFor(ApplicantCategory $category): ?string
    {
        if ($category->isSpm()) {
            return null;
        }

        return $category->requiresStamGrade() ? self::STAM_APPLICANTS : self::STPM_APPLICANTS;
    }

    /**
     * @return BelongsTo<Programme, $this>
     */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class, 'programme_code', 'programme_code');
    }

    /**
     * The group of applicants the figures are for, e.g. "Science · STEM A · Female".
     */
    public function segmentLabel(): string
    {
        return implode(' · ', array_filter([$this->group === 'STAM' ? null : $this->group, $this->stream, $this->gender]));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'average_merit_score' => 'float',
            'first_choice_applications' => 'integer',
            'first_choice_offers' => 'integer',
            'lower_choice_applications' => 'integer',
            'lower_choice_offers' => 'integer',
        ];
    }
}

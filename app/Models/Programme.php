<?php

namespace App\Models;

use App\ApplicantCategory;
use App\StudyLevel;
use Database\Factories\ProgrammeFactory;
use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\HasMany;

/**
 * A university programme listed in e-Panduan UPUOnline (Institution Programme entity).
 *
 * @property string $programme_code
 * @property string $programme_name
 * @property string $institution_name
 * @property string $level "spm" or "stpm", the e-Panduan search level the programme is listed under.
 * @property list<array{campus: string, state: string}>|null $campuses Where the programme is offered, e.g. "Kampus Jasin" in Melaka.
 * @property array<string, float>|null $average_merit_scores The average merit score e-Panduan gives for each applicant category code, e.g. ['S' => 99.43].
 */
#[Connection('mongodb')]
#[Table('programmes')]
#[Fillable(['programme_code', 'programme_name', 'institution_name', 'level', 'campuses', 'average_merit_scores'])]
class Programme extends Model
{
    /** @use HasFactory<ProgrammeFactory> */
    use HasFactory;

    public const PORTAL_URL = 'https://online.mohe.gov.my/epanduan';

    /**
     * @return HasMany<EligibilityRequirement, $this>
     */
    public function requirements(): HasMany
    {
        return $this->hasMany(EligibilityRequirement::class, 'programme_code', 'programme_code');
    }

    public function studyLevel(): StudyLevel
    {
        return StudyLevel::fromProgramme($this->programme_name, $this->institution_name);
    }

    /**
     * The states the programme is offered in, without repeats.
     *
     * @return list<string>
     */
    public function states(): array
    {
        return array_values(array_unique(array_filter(array_column($this->campuses ?? [], 'state'))));
    }

    /**
     * The campuses as one line, e.g. "Kampus Jasin, Melaka · Kampus Shah Alam, Selangor".
     */
    public function locationLabel(): ?string
    {
        $places = array_map(
            fn (array $campus): string => implode(', ', array_filter([$campus['campus'] ?? '', $campus['state'] ?? ''])),
            $this->campuses ?? [],
        );

        return $places === [] ? null : implode(' · ', $places);
    }

    /**
     * The average merit score e-Panduan gives for the applicant category, out of 100, or null when it gives none.
     */
    public function averageMeritScore(ApplicantCategory $category): ?float
    {
        return $this->average_merit_scores[$category->code()] ?? null;
    }

    /**
     * The programme's page on e-Panduan for the given applicant category.
     */
    public function officialPortalLink(ApplicantCategory $category): string
    {
        return sprintf(
            '%s/carianNamaProgram/%s/%s/%s/%s',
            self::PORTAL_URL,
            substr($this->programme_code, 0, 2),
            $this->programme_code,
            $category->code(),
            $this->level,
        );
    }
}

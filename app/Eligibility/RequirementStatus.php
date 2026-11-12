<?php

namespace App\Eligibility;

/**
 * The outcome of checking a requirement against an applicant's results.
 *
 * Unknown means the requirement could not be checked, for example because it
 * needs results the applicant has not given or because e-Panduan's wording
 * could not be read in full.
 */
enum RequirementStatus: string
{
    case Met = 'met';
    case NotMet = 'not_met';
    case Unknown = 'unknown';

    /**
     * Combine statuses that must all be met.
     */
    public static function all(self ...$statuses): self
    {
        if (in_array(self::NotMet, $statuses, true)) {
            return self::NotMet;
        }

        return in_array(self::Unknown, $statuses, true) ? self::Unknown : self::Met;
    }

    /**
     * Combine statuses where meeting any one is enough.
     */
    public static function any(self ...$statuses): self
    {
        if (in_array(self::Met, $statuses, true)) {
            return self::Met;
        }

        return in_array(self::Unknown, $statuses, true) ? self::Unknown : self::NotMet;
    }

    public static function fromBoolean(bool $isMet): self
    {
        return $isMet ? self::Met : self::NotMet;
    }

    /**
     * The status of one requirement, e.g. "Met".
     */
    public function label(): string
    {
        return match ($this) {
            self::Met => 'Met',
            self::NotMet => 'Not met',
            self::Unknown => 'Check yourself',
        };
    }

    /**
     * The status of a whole course, e.g. "You qualify".
     */
    public function courseLabel(): string
    {
        return match ($this) {
            self::Met => 'You qualify',
            self::NotMet => "You don't qualify",
            self::Unknown => 'Check the rest yourself',
        };
    }
}

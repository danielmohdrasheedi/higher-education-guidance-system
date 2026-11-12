<?php

namespace App\Trends;

use App\ApplicantCategory;
use App\Models\Programme;

/**
 * The average merit score e-Panduan gives a course for one applicant category, out of 100.
 *
 * e-Panduan gives no score to some categories, such as diploma holders, and shows "Tiada" (none) for some courses.
 */
final readonly class MeritScore
{
    public function __construct(public ApplicantCategory $category, public ?float $score) {}

    public static function for(Programme $programme, ApplicantCategory $category): self
    {
        return new self($category, $category->hasMeritScore() ? $programme->averageMeritScore($category) : null);
    }

    /**
     * The score with two decimal places, e.g. "99.43", or a dash when there is none.
     */
    public function value(): string
    {
        return $this->score === null ? '–' : number_format($this->score, 2);
    }

    /**
     * What the score means, or why there is none.
     */
    public function note(): string
    {
        return match (true) {
            ! $this->category->hasMeritScore() => "e-Panduan doesn't give a merit score for {$this->category->label()} applicants.",
            $this->score === null => 'e-Panduan has no merit score for this course.',
            $this->score >= 90 => 'Out of 100, which is very high. Strong results are needed.',
            $this->score >= 80 => 'Out of 100, which is high.',
            default => 'Out of 100.',
        };
    }
}

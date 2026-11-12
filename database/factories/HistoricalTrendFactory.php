<?php

namespace Database\Factories;

use App\Models\HistoricalTrend;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HistoricalTrend>
 */
class HistoricalTrendFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'programme_code' => 'U'.fake()->randomLetter().fake()->numerify('6######'),
            'level' => 'stpm',
            'applicant_type' => HistoricalTrend::STPM_APPLICANTS,
            'group' => 'Science',
            'stream' => null,
            'gender' => fake()->randomElement(['Female', 'Male']),
            'average_merit_score' => fake()->randomFloat(2, 60, 100),
            'session_applications' => ['2024/2025' => 400, '2025/2026' => 500],
            'session_offers' => ['2024/2025' => 40, '2025/2026' => 50],
            'first_choice_applications' => 100,
            'first_choice_offers' => 30,
            'lower_choice_applications' => 800,
            'lower_choice_offers' => 60,
        ];
    }

    /**
     * Figures for SPM leavers in one SPM stream.
     */
    public function spm(string $stream = 'STEM A'): static
    {
        return $this->state(fn (array $attributes): array => [
            'level' => 'spm',
            'applicant_type' => null,
            'stream' => $stream,
        ]);
    }

    /**
     * Figures for STAM applicants.
     */
    public function stam(): static
    {
        return $this->state(fn (array $attributes): array => [
            'applicant_type' => HistoricalTrend::STAM_APPLICANTS,
            'group' => 'STAM',
        ]);
    }
}

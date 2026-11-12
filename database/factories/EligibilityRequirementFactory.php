<?php

namespace Database\Factories;

use App\Models\EligibilityRequirement;
use App\Models\Programme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EligibilityRequirement>
 */
class EligibilityRequirementFactory extends Factory
{
    /**
     * Define the model's default state: the usual STPM Science requirements.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'programme_code' => fn (array $attributes): string => Programme::factory()->create(['level' => $attributes['level']])->programme_code,
            'level' => 'stpm',
            'target_category' => 'S',
            'is_offered' => true,
            'general_conditions_content' => [],
            'general_conditions_logic' => [
                [
                    'condition_group' => 'SPM',
                    'conditions' => [
                        ['condition_type' => 'subject_requirement', 'condition_group' => 'SPM', 'condition_count' => 1, 'minimum_grade' => 'C', 'subject_list' => ['Bahasa Melayu', 'Bahasa Melayu (Kertas Julai)']],
                        ['condition_type' => 'subject_requirement', 'condition_group' => 'SPM', 'condition_count' => 1, 'minimum_grade' => 'E', 'subject_list' => ['Sejarah']],
                    ],
                ],
                ['condition_group' => 'Logic', 'conditions' => 'AND'],
                [
                    'condition_group' => 'STPM',
                    'conditions' => [
                        ['condition_type' => 'cgpa_requirement', 'condition_group' => 'STPM', 'condition_operator' => '>=', 'condition_value' => 2.0],
                        ['condition_type' => 'subject_requirement', 'condition_group' => 'STPM', 'condition_count' => 1, 'minimum_grade' => 'C', 'subject_list' => ['Pengajian Am']],
                        ['condition_type' => 'any_subject_requirement', 'condition_group' => 'STPM', 'condition_count' => 2, 'minimum_grade' => 'C', 'subject_list' => 'ANY'],
                    ],
                ],
                ['condition_group' => 'Logic', 'conditions' => 'AND'],
                [
                    'condition_group' => 'MUET',
                    'conditions' => [
                        ['condition_type' => 'muet_requirement', 'condition_operator' => '>=', 'condition_value' => 1.0],
                    ],
                ],
            ],
            'specific_conditions_content' => [],
            'specific_conditions_logic' => [],
        ];
    }

    /**
     * Add specific (programme) requirements.
     *
     * @param  list<array<string, mixed>>  $conditions
     */
    public function withSpecificConditions(array $conditions): static
    {
        return $this->state(fn (array $attributes): array => [
            'specific_conditions_logic' => $conditions,
        ]);
    }

    public function notOffered(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_offered' => false,
        ]);
    }
}

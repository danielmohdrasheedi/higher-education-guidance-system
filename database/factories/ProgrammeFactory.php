<?php

namespace Database\Factories;

use App\Models\Programme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Programme>
 */
class ProgrammeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'programme_code' => 'U'.fake()->randomLetter().fake()->unique()->numerify('6######'),
            'programme_name' => 'Bacelor '.fake()->words(2, true).' Dengan Kepujian',
            'institution_name' => 'Universiti '.fake()->lastName(),
            'level' => 'stpm',
        ];
    }

    /**
     * A programme listed for SPM leavers, such as an asasi (foundation) programme.
     */
    public function spm(): static
    {
        return $this->state(fn (array $attributes): array => [
            'programme_name' => 'Asasi '.fake()->word(),
            'level' => 'spm',
        ]);
    }
}

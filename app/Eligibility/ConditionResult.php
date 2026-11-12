<?php

namespace App\Eligibility;

/**
 * The outcome of one requirement, worded for the applicant.
 */
final readonly class ConditionResult
{
    /**
     * @param  string  $description  What the requirement asks for, e.g. "Grade C or better in SPM Matematik".
     * @param  string|null  $reason  What the applicant has, e.g. "Your grade is D.".
     * @param  int|null  $option  When the requirement is part of a choice between options, which option it belongs to (1, 2, ...).
     * @param  int|null  $optionCount  How many options the choice has.
     * @param  bool  $canBeChecked  Whether the site can check it from the applicant's results, given the details it asks for. False for
     *                              things only the university can judge, such as Senate approval, or wording the site can't read.
     */
    public function __construct(
        public RequirementStatus $status,
        public string $description,
        public ?string $reason = null,
        public ?int $option = null,
        public ?int $optionCount = null,
        public bool $canBeChecked = true,
    ) {}

    /**
     * Group the conditions for display: each choice between options becomes one item holding
     * its options, and every other condition stays an item of its own. A new choice starts
     * when the option number goes back down, e.g. from option 2 to option 1.
     *
     * @param  list<ConditionResult>  $conditions
     * @return list<array{condition: ConditionResult}|array{options: array<int, list<ConditionResult>>}>
     */
    public static function grouped(array $conditions): array
    {
        $items = [];
        $previousOption = null;

        foreach ($conditions as $condition) {
            if ($condition->option === null) {
                $items[] = ['condition' => $condition];
                $previousOption = null;

                continue;
            }

            if ($previousOption === null || $condition->option < $previousOption) {
                $items[] = ['options' => []];
            }

            $items[array_key_last($items)]['options'][$condition->option][] = $condition;
            $previousOption = $condition->option;
        }

        return $items;
    }
}

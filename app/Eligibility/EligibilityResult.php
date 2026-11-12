<?php

namespace App\Eligibility;

/**
 * The outcome of checking one programme's entry requirements.
 */
final readonly class EligibilityResult
{
    /**
     * @param  list<ConditionResult>  $conditions  Every requirement that was checked.
     * @param  list<string>  $notes  Requirements that cannot be checked automatically, in e-Panduan's wording.
     */
    public function __construct(
        public RequirementStatus $status,
        public array $conditions,
        public array $notes = [],
    ) {}

    /**
     * @return list<ConditionResult>
     */
    public function unmetConditions(): array
    {
        return $this->conditionsWithStatus(RequirementStatus::NotMet);
    }

    /**
     * @return list<ConditionResult>
     */
    public function uncheckedConditions(): array
    {
        return $this->conditionsWithStatus(RequirementStatus::Unknown);
    }

    /**
     * The requirements the site checks against the applicant's results, without repeats. A choice between
     * options stays whole here when any of its requirements can be checked.
     *
     * @return list<ConditionResult>
     */
    public function checkableConditions(): array
    {
        return $this->conditionsWhereCheckable(true);
    }

    /**
     * The requirements the site can't check, such as Senate approval or wording it can't read, without repeats.
     *
     * @return list<ConditionResult>
     */
    public function uncheckableConditions(): array
    {
        return $this->conditionsWhereCheckable(false);
    }

    /**
     * @return list<ConditionResult>
     */
    private function conditionsWhereCheckable(bool $isCheckable): array
    {
        $conditions = [];

        foreach (ConditionResult::grouped($this->uniqueConditions()) as $item) {
            $itemConditions = isset($item['condition']) ? [$item['condition']] : array_merge(...array_values($item['options']));
            $hasCheckable = array_filter($itemConditions, fn (ConditionResult $condition): bool => $condition->canBeChecked) !== [];

            if ($hasCheckable === $isCheckable) {
                array_push($conditions, ...$itemConditions);
            }
        }

        return $conditions;
    }

    /**
     * Every condition, without repeats (e-Panduan sometimes lists a requirement twice).
     *
     * @return list<ConditionResult>
     */
    public function uniqueConditions(): array
    {
        $conditions = [];

        foreach ($this->conditions as $condition) {
            $conditions[$condition->status->value.'|'.$condition->option.'|'.$condition->description.'|'.$condition->reason] = $condition;
        }

        return array_values($conditions);
    }

    /**
     * Conditions with the given status, without repeats.
     *
     * @return list<ConditionResult>
     */
    private function conditionsWithStatus(RequirementStatus $status): array
    {
        return array_values(array_filter(
            $this->uniqueConditions(),
            fn (ConditionResult $condition): bool => $condition->status === $status,
        ));
    }
}

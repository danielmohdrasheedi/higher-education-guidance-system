<?php

namespace App\Http\Requests;

use App\Http\Controllers\CourseComparisonController;
use App\Http\Controllers\FavouriteController;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FR05 Single-Page Programme Comparison: the favourite courses chosen to compare.
 */
class CompareCoursesRequest extends FormRequest
{
    /**
     * Mistakes are shown on the favourites page, where the courses are chosen.
     *
     * @var string
     */
    protected $redirectRoute = 'favourites.index';

    /**
     * Comparing is open to every visitor with favourites.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'courses' => ['required', 'array', 'min:2', 'max:'.CourseComparisonController::MAXIMUM_COMPARED],
            'courses.*' => ['string', 'distinct', Rule::in(FavouriteController::favouriteCodes($this))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'courses.required' => 'Choose at least 2 courses to compare.',
            'courses.min' => 'Choose at least 2 courses to compare.',
            'courses.max' => 'You can compare up to :max courses at a time.',
            'courses.*.in' => 'Only favourite courses can be compared.',
            'courses.*.distinct' => 'Choose each course only once.',
        ];
    }

    /**
     * The programme codes chosen, in the order they appear on the favourites page.
     *
     * @return list<string>
     */
    public function programmeCodes(): array
    {
        return array_values(array_intersect(FavouriteController::favouriteCodes($this), $this->validated('courses')));
    }
}

<?php

use App\Eligibility\AlternativeCourses;
use App\Http\Controllers\AcademicProfileController;
use App\Http\Controllers\CourseComparisonController;
use App\Http\Controllers\EligibilityController;
use App\Http\Controllers\FavouriteController;
use App\Http\Controllers\HistoricalTrendController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

Route::singleton('academic-profile', AcademicProfileController::class)
    ->creatable()
    ->destroyable();

Route::get('courses', [EligibilityController::class, 'index'])->name('eligibility.index');
Route::get('courses/{programme:programme_code}/requirements', [EligibilityController::class, 'requirements'])->name('eligibility.requirements');
Route::get('courses/{programme:programme_code}/similar/{list}', [EligibilityController::class, 'similarCourses'])
    ->whereIn('list', array_keys(AlternativeCourses::LISTS))
    ->name('eligibility.similar');

Route::get('courses/{programme:programme_code}/trends', [HistoricalTrendController::class, 'show'])->name('trends.show');

Route::get('favourites', [FavouriteController::class, 'index'])->name('favourites.index');
Route::get('favourites/compare', [CourseComparisonController::class, 'show'])->name('favourites.compare');
Route::post('favourites/{programme:programme_code}', [FavouriteController::class, 'store'])->name('favourites.store');
Route::delete('favourites/{programme:programme_code}', [FavouriteController::class, 'destroy'])->name('favourites.destroy');

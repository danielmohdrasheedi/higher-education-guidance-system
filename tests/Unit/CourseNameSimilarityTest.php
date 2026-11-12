<?php

use App\Eligibility\AlternativeCourses;
use App\Eligibility\CourseNameSimilarity;

function courseNameSimilarity(): CourseNameSimilarity
{
    return new CourseNameSimilarity([
        'Sarjana Muda Sains Komputer Dengan Kepujian',
        'Bachelor Of Computer Science',
        'Sarjana Muda Sains Biokimia',
        'Sarjana Muda Sains Kimia',
        'Sarjana Muda Kejururawatan',
        'Sarjana Muda Kejuruteraan Awam',
        'Bacelor Ekonomi Dengan Kepujian',
        'Asasi Undang-Undang (Uitm)',
        'Diploma Sains Komputer (Kampus Bintulu, Sarawak)',
    ]);
}

it('keeps only the words that say what a course is about, in Malay', function (string $name, array $expected) {
    expect(CourseNameSimilarity::words($name))->toBe($expected);
})->with([
    'malay' => ['Ijazah Sarjana Muda Sains Komputer (Kepintaran Buatan) Dengan Kepujian', ['sains', 'komputer', 'kepintaran', 'buatan']],
    'english' => ['Bachelor Of Computer Science', ['komputer', 'sains']],
    'repeated word' => ['Asasi Undang-Undang (Uitm)', ['undang', 'uitm']],
    'campus' => ['Diploma Sains Komputer (Kampus Bintulu, Sarawak)', ['sains', 'komputer']],
    'university' => ['Asasi Kejuruteraan & Teknologi - Universiti Teknologi Malaysia', ['kejuruteraan', 'teknologi']],
]);

it('finds names that mean the same course alike', function () {
    $similarity = courseNameSimilarity();

    expect($similarity->between('Sarjana Muda Sains Komputer Dengan Kepujian', 'Diploma Sains Komputer (Kampus Bintulu, Sarawak)'))->toEqualWithDelta(1.0, 0.0001)
        ->and($similarity->between('Sarjana Muda Sains Komputer Dengan Kepujian', 'Bachelor Of Computer Science'))->toBeGreaterThan(0.5);
});

it('finds courses that share a root more alike than unrelated ones', function () {
    $similarity = courseNameSimilarity();

    expect($similarity->between('Sarjana Muda Sains Biokimia', 'Sarjana Muda Sains Kimia'))
        ->toBeGreaterThan($similarity->between('Sarjana Muda Sains Biokimia', 'Sarjana Muda Sains Komputer Dengan Kepujian'));
});

it('keeps unrelated courses below the similarity needed to suggest one', function (string $first, string $second) {
    expect(courseNameSimilarity()->between($first, $second))->toBeLessThan(AlternativeCourses::MINIMUM_SIMILARITY);
})->with([
    'nursing and engineering' => ['Sarjana Muda Kejururawatan', 'Sarjana Muda Kejuruteraan Awam'],
    'economics and law' => ['Bacelor Ekonomi Dengan Kepujian', 'Asasi Undang-Undang (Uitm)'],
]);

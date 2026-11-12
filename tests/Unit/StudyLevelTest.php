<?php

use App\StudyLevel;

it('works out the study level from the programme name', function (string $programmeName, string $institutionName, StudyLevel $expected) {
    expect(StudyLevel::fromProgramme($programmeName, $institutionName))->toBe($expected);
})->with([
    'asasi' => ['Asasi Sains Fizikal', 'Universiti Putra Malaysia', StudyLevel::Foundation],
    'program asasi' => ['Program Asasi Pengurusan', 'Universiti Utara Malaysia', StudyLevel::Foundation],
    'asasipintar' => ['Asasipintar Ukm', 'Universiti Kebangsaan Malaysia', StudyLevel::Foundation],
    'tamhidi' => ['Tamhidi Sains Perubatan', 'Universiti Sains Islam Malaysia', StudyLevel::Foundation],
    'matriculation' => ['Jurusan Sains (2 Semester)', 'Kolej Matrikulasi', StudyLevel::Matriculation],
    'sijil' => ['Sijil Teknologi Elektrik', 'Kolej Komuniti', StudyLevel::Certificate],
    'certificate' => ['Certificate In Hotel Operations', 'Politeknik', StudyLevel::Certificate],
    'diploma' => ['Diploma Kejuruteraan Mekanikal', 'Politeknik', StudyLevel::Diploma],
    'sarjana muda' => ['Sarjana Muda Sains Komputer Dengan Kepujian', 'Universiti Malaya', StudyLevel::Degree],
    'bacelor' => ['Bacelor Ekonomi Dengan Kepujian', 'Universiti Putra Malaysia', StudyLevel::Degree],
    'doktor perubatan' => ['Ijazah Doktor Perubatan', 'Universiti Malaysia Sabah', StudyLevel::Degree],
]);

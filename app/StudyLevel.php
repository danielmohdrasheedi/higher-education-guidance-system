<?php

namespace App;

/**
 * The kind of qualification a programme leads to, worked out from its name
 * because e-Panduan's programme lists don't give it separately.
 */
enum StudyLevel: string
{
    case Foundation = 'foundation';
    case Matriculation = 'matriculation';
    case Certificate = 'certificate';
    case Diploma = 'diploma';
    case Degree = 'degree';

    public static function fromProgramme(string $programmeName, string $institutionName): self
    {
        return match (true) {
            preg_match('/^(program\s+)?(asasi|tamhidi|foundation)/iu', $programmeName) === 1 => self::Foundation,
            preg_match('/^jurusan\b/iu', $programmeName) === 1 || $institutionName === 'Kolej Matrikulasi' => self::Matriculation,
            preg_match('/^(sijil|certificate)\b/iu', $programmeName) === 1 => self::Certificate,
            preg_match('/^diploma\b/iu', $programmeName) === 1 => self::Diploma,
            default => self::Degree,
        };
    }
}

<?php

namespace App\Eligibility;

use Illuminate\Support\Str;

/**
 * Matches subject names written in different ways in e-Panduan, e.g.
 * "Pendidikan Syari'Ah Islamiah" and "Pendidikan Syari'ah Islamiah", or
 * "Mathematics (M)" and "Mathematics M".
 */
final class SubjectName
{
    /**
     * A comparable key for a subject name at the given level ("spm" or "stpm").
     */
    public static function key(string $name, string $level): string
    {
        $key = Str::of($name)
            ->lower()
            ->replace(['&', "'", '’'], [' dan ', '', ''])
            ->replaceMatches('/\band\b/u', 'dan')
            ->replaceMatches('/\b((di|pada) )?peringkat (spm|stpm|matrikulasi|asasi)\b|\bmulai tahun \d{4}\b/u', ' ')
            ->replaceMatches('/[^\p{L}\p{N}]+/u', ' ')
            ->replaceMatches('/ \d+$/u', '')
            ->squish()
            ->value();

        return config("academic.subject_aliases.{$level}.{$key}", $key);
    }

    /**
     * Split an e-Panduan subject entry such as "Fizik / Kimia ATAU Biologi" into its alternatives.
     *
     * @return list<string>
     */
    public static function alternatives(string $entry): array
    {
        $entry = preg_replace('/\s+((di|pada)\s*)?peringkat\b.*$/iu', '', $entry) ?? $entry;
        $entry = preg_replace('/\s*[(.;]?\s*\*?\s*\b(calon|dan\s+(melepasi|lulus|calon))\b.*$/iu', '', $entry) ?? $entry;
        $alternatives = preg_split('/\s*\/\s*|\s+atau\s+/iu', $entry) ?: [];

        return array_values(array_filter(
            array_map(self::tidy(...), $alternatives),
            fn (string $alternative): bool => $alternative !== '' && ! self::isQualificationName($alternative),
        ));
    }

    /**
     * Words such as "Asasi" or "Setaraf" that e-Panduan sometimes lists among
     * the subjects, e.g. "Mathematics Di Peringkat Matrikulasi / Asasi".
     */
    private static function isQualificationName(string $name): bool
    {
        return preg_match('/^\(?(asasi|matrikulasi|kategori matrikulasi|setaraf)\b(?!\s+kimia)/iu', $name) === 1;
    }

    /**
     * Tidy e-Panduan's capitalisation and leftover joining words, e.g.
     * "Pendidikan Al-Quran DAN Al-Sunnah" or "Pendidikan Syari'Ah Islamiah".
     */
    private static function tidy(string $name): string
    {
        $name = preg_replace('/\s+(dan|atau|serta)$/iu', '', trim($name)) ?? $name;
        $name = preg_replace('/\bDAN\b/u', 'dan', $name) ?? $name;
        $name = preg_replace_callback("/(?<=\p{L})(['’])(\p{Lu})/u", fn (array $matches): string => $matches[1].mb_strtolower($matches[2]), $name) ?? $name;

        return trim($name, " \t\n\r.;:,");
    }
}

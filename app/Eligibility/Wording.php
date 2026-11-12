<?php

namespace App\Eligibility;

/**
 * Shows a line of e-Panduan's Malay in English when lang/en.json has it. A line is looked up without its ending
 * full stop or semicolon, so "Lulus Temu Duga." and "Lulus Temu Duga;" share one translation. A translation may
 * hold "\n" to split a long line into a first line and a list.
 */
final class Wording
{
    /**
     * The line in English, without its ending punctuation, or the line as it is when there's no translation.
     */
    public static function inEnglish(string $line): string
    {
        $key = self::withoutEnding($line);
        $english = __($key);

        return $english === $key ? $key : self::withoutEnding($english);
    }

    /**
     * A line as one sentence: its first line in English and a full stop, or a colon when a list follows.
     */
    public static function sentence(string $line, bool $hasList): string
    {
        return self::withoutEnding($line).($hasList ? ':' : '.');
    }

    private static function withoutEnding(string $line): string
    {
        return rtrim(trim($line), ' .;:');
    }
}

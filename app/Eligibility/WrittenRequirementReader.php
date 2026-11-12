<?php

namespace App\Eligibility;

/**
 * Reads requirements that e-Panduan only gives in words, which the collectors keep as text,
 * and turns them into rules the eligibility checker can check against the profile:
 *
 * - descent: "Berketurunan Melayu, Anak Negeri Sabah, Anak Negeri Sarawak DAN Orang Asli Sahaja"
 * - colour vision: "Ujian Rabun / Buta Warna", "Calon Tidak Buta Warna"
 * - music grade: "Sijil The Associated Board Of The Royal Schools Of Music (Abrsm) Sekurang-Kurangnya Gred 5"
 * - Quran memorisation: "Menghafaz Sekurang-Kurangnya 30 Juzuk Al-Quran"
 * - age: "Pemohon Hendaklah Berusia 18 Tahun DAN Ke Atas", "Calon Hendaklah Sekurang-Kurangnya Berumur 18 Tahun"
 * - work experience: "Mempunyai Tiga (3) Tahun Pengalaman Bekerja", "Pengalaman Mengajar Sekurang-Kurangnya Tiga (3) Tahun"
 * - gender: "Jantina: Lelaki"
 */
final class WrittenRequirementReader
{
    private const DESCENT = '/keturunan\s+melayu|anak\s+negeri\s+(sabah|sarawak)/iu';

    private const COLOUR_BLINDNESS = '/buta\s*warna|rabun\s*warna/iu';

    /**
     * Only "Trinity College London": "Trinity College Foundation Studies" is a foundation programme, not a music board.
     */
    private const MUSIC = '/abrsm|royal\s+schools?\s+of\s+music|trinity\s+college\s+london|\btcl\b|lembaga\s+peperiksaan\s+muzik/iu';

    /**
     * A diploma from a music exam board, e.g. "Satu (1) Diploma Daripada Lembaga Peperiksaan Muzik Seperti Berikut".
     */
    private const MUSIC_BOARD_DIPLOMA = '/\b(ltcl|atcl|dipabrsm)\b|diploma\s+daripada\s+lembaga\s+peperiksaan\s+muzik/iu';

    /**
     * A music grade that can be replaced by a prerequisite course, e.g. "Lulus ABRSM Gred 5 ... ATAU Perlu
     * Mengikuti Kursus Prasyarat Yang Ditetapkan". A high enough grade meets it; otherwise the course may.
     */
    private const MUSIC_OR_PREREQUISITE_COURSE = '/kursus\s+prasyarat/iu';

    /**
     * The same grade or prerequisite course after another sentence of a note, taken out because it is checked.
     * Not when it is a line of a list, such as what an audition covers.
     */
    private const MUSIC_OR_PREREQUISITE_COURSE_PHRASE = '/(?:^|(?<=\.))\s*(?:dan\s+)?lulus\s+associated\s+board\s+of\s+the\s+royal\s+schools?\s+of\s+music\b[^;\n]*?kursus\s+prasyarat\s+yang\s+ditetapkan\s*[;.]?/iu';

    /**
     * A diploma, or a qualification recognised as equal to one, e.g. "Memiliki Diploma ATAU Kelulusan Lain Yang
     * Diiktiraf Setaraf Dengannya Oleh Kerajaan Malaysia DAN Diluluskan Oleh Senat Upm.". Diploma holders meet it.
     */
    /**
     * A line asking for a diploma, e.g. "Memiliki Diploma ...", "Lulus Diploma ..." or "Mempunyai Sekurang-Kurangnya Diploma ...".
     */
    private const DIPLOMA_KIND = '/^(?:(?:catatan|nota)\s*:\s*)?(?:memiliki|mempunyai|lulus|berkelulusan)\s+(?:sekurang-?kurangnya\s+)?(?:sijil\s+)?diploma\b/iu';

    private const DIPLOMA_OR_EQUIVALENT_PHRASE = '/memiliki\s+diploma\s+atau\s+kelulusan\s+lain\s+yang\s+diiktiraf\s+setaraf\s+dengannya\s+oleh\s+kerajaan\s+malaysia\s+dan\s+diluluskan\s+oleh\s+senat\s+\w+\s*[.;]?/iu';

    /**
     * Whole lines that only state what an applicant applying through UPU already has, such as citizenship or the
     * qualification they apply with, e.g. "Warganegara Malaysia Yang Mempunyai No. Kad Pengenalan / My Kad.".
     * Each is checked against the applicant's category. Lines that also give a CGPA or a field are left alone.
     *
     * @var array<string, string>
     */
    private const QUALIFICATION_STATEMENTS = [
        'citizenship' => '/^warganegara\s+malaysia\s+yang\s+mempunyai\s+no\.?\s*kad\s+pengenalan\s*\/\s*my\s*kad\.?$/iu',
        'equivalent_qualification' => '/^mempunyai\s+kelulusan\s+lain\s+yang\s+diiktiraf\s+setaraf\s+dengannya\s+dan\s+diperakukan\s+oleh\s+senat\s+universiti\.?$/iu',
        'recognised_diploma' => '/^memiliki\s+diploma\s+dari\s+ins?titusi\s+(pendidikan\s+)?yang\s+diiktiraf\s+oleh\s+kerajaan\s+malaysia\s+dan\s+senat\s+universiti\.?$/iu',
        'suitable_diploma_or_qualification' => '/^memiliki\s+diploma\s+dalam\s+bidang\s+yang\s+sesuai\s+atau\s+kelayakan\s+lain\s+yang\s+diiktiraf\s+oleh\s+senat\s+upm\s*[.;]?$/iu',
        'related_qualification' => '/^memiliki\s+kelulusan\s+berkaitan\s+dari\s+institusi\s+pengajian\s+tinggi\s+yang\s+diiktiraf\s+setaraf\s+dengannya\s+oleh\s+senat\s+\p{L}+\.?$/iu',
        'related_diploma' => '/^memiliki\s+diploma\s+dalam\s+bidang\s+(yang\s+)?(berkaitan|sesuai)\s*[.;]?$/iu',
        'related_diploma_for_course' => '/^memiliki\s+diploma\s+dalam\s+bidang\s+(yang\s+)?berkaitan\s+dengan\s+(program|kursus)\s+yang\s+dipohon\s*[.;]?$/iu',
        'related_diploma_from_polytechnic' => '/^memiliki\s+diploma\s+dalam\s+bidang\s+(yang\s+)?berkaitan\s+dari\s+politeknik\s*[.;]?$/iu',
        'related_diploma_from_public_university' => '/^memiliki\s+diploma\s+dalam\s+bidang\s+(yang\s+)?berkaitan\s+dari\s+universiti\s+awam\s*(\(\s*ua\s*\))?\s*[.;]?$/iu',
        'related_diploma_from_private_institution' => '/^memiliki\s+diploma\s+dalam\s+bidang\s+(yang\s+)?berkaitan\s+dari\s+institu(si|t)\s+pengajian\s+tinggi\s+swasta\s*(\(\s*ipts\s*\))?\s*[.;]?$/iu',
        'related_diploma_from_utm' => '/^memiliki\s+diploma\s+dalam\s+bidang\s+(yang\s+)?berkaitan\s+dari\s+utm\s*\/\s*ipt\s*\/\s*setaraf\s*[.;]?$/iu',
        'a_level' => '/^memiliki\s+kelayakan\s+gce\s+a-level\s+yang\s+diiktiraf\s*[.;]?$/iu',
        'general_requirements' => '/^memenuhi\s+syarat\s+am\s+universiti\.?$/iu',
        'sat_spm' => '/^pemohon\s+mestilah\s+menduduki\s+sijil\s+pelajaran\s+malaysia\s+\(spm\)\s+dan\s+boleh\s+membaca,?\s+menulis\s+dan\s+mengira(\s+dalam\s+bahasa\s+melayu\s+atau\s+bahasa\s+inggeris)?\.?$/iu',
    ];

    /**
     * A heading with nothing after it, such as "Catatan" before a list of notes. It says nothing on its own.
     */
    private const LONE_HEADING = '/^(catatan|nota|syarat\s+am\s+program)\s*:?\s*$/iu';

    private const QURAN = '/hafazan|menghafaz|juzuk/iu';

    private const AGE = '/\bber(umur|usia)\b/iu';

    /**
     * A number of years of work or teaching experience, e.g. "Tiga (3) Tahun Pengalaman Bekerja" or
     * "Pengalaman Mengajar Sekurang-Kurangnya Tiga (3) Tahun". A note about experience without a number
     * of years, such as "Bagi Calon Yang Mempunyai Pengalaman Kerja", isn't a requirement.
     */
    private const EXPERIENCE = '/(\d{1,2})\)?\s*tahun\s+pengalaman\s+(?:bekerja|kerja|mengajar)|pengalaman\s+(?:bekerja|kerja|mengajar)\s+(?:[^.;]*?\s)?sekurang-kurangnya\s+(?:[a-z]+\s*)?\(?(\d{1,2})\)?\s*tahun/iu';

    /**
     * The work experience phrase inside a longer note, e.g. "Mempunyai Lima (5) Tahun Pengalaman Bekerja Dalam Industri
     * Muzik DAN". It is taken out of the note because it is checked.
     */
    private const EXPERIENCE_PHRASE = '/mempunyai\s+(?:\p{L}+\s+)?\(?\d{1,2}\)?\s*tahun\s+pengalaman\s+(?:bekerja|kerja|mengajar)(?:\s+dalam\s+(?:industri|bidang)(?:\s+(?!dan\b)\p{L}+)+)?(?:\s+dan\s+|\s*;\s*|\s*$)/iu';

    /**
     * The field of the work experience, e.g. "Industri Muzik" in "Pengalaman Bekerja Dalam Industri Muzik DAN".
     */
    private const EXPERIENCE_FIELD = '/pengalaman\s+(?:bekerja|kerja|mengajar)\s+dalam\s+((?:industri|bidang)(?:\s+(?!dan\b)\p{L}+)+)/iu';

    /**
     * A diploma from a music exam board with the boards listed under it, taken out of a note because it is checked.
     */
    private const MUSIC_BOARD_DIPLOMA_PHRASE = '/(?:dan\s+)?memiliki\s+sekurang-kurangnya\s+satu\s+\(1\)\s+diploma\s+daripada\s+lembaga\s+peperiksaan\s+muzik\s+seperti\s+berikut\s*:?(?:\n.*)*$/iu';

    private const GENDER = '/jantina\s*:?\s*(lelaki|perempuan)/iu';

    /**
     * The descent requirement inside a longer note. It is taken out of the note because it is checked.
     */
    private const DESCENT_PHRASE = '/(terbuka\s+kepada\s+)?(ber)?keturunan\s+melayu,?\s+anak\s+negeri\s+sabah,?\s+anak\s+negeri\s+sarawak,?\s+dan\s+orang\s+asli(\s+sahaja)?\.?/iu';

    /**
     * Whole sentences that only state a requirement checked from the profile, so they don't need
     * to be shown again in e-Panduan's words.
     *
     * @var list<string>
     */
    private const CHECKED_SENTENCES = [
        '/^(terbuka\s+kepada\s+)?(ber)?keturunan\s+melayu,?\s+anak\s+negeri\s+sabah,?\s+anak\s+negeri\s+sarawak,?\s+dan\s+orang\s+asli(\s+sahaja)?$/iu',
        '/^(ujian\s+rabun\s*\/\s*buta\s+warna|calon\s+tidak\s+buta\s+warna)$/iu',
        '/^jantina\s*:?\s*(lelaki|perempuan)$/iu',
        '/^ber(umur|usia)\s+\d{2}\s*tahun(\s+\d{1,2}\s*bulan)?\s*(dan\s+)?ke\s*(atas|bawah)$/iu',
    ];

    /**
     * The rules written in a condition's words, in the same shape as the collectors' rules,
     * e.g. ['condition_type' => 'music_grade_requirement', 'minimum_rank' => 5].
     *
     * @param  array<string, mixed>|string  $condition
     * @return list<array<string, mixed>>
     */
    public function read(array|string $condition): array
    {
        $type = is_array($condition) ? ($condition['condition_type'] ?? null) : null;

        // A choice between options is read option by option
        if ($type === 'alternative_requirements') {
            return [];
        }

        $text = implode(' ', $this->texts($condition));
        $rules = [];

        if (preg_match(self::DESCENT, $text) === 1) {
            $rules[] = ['condition_type' => 'descent_requirement'];
        }

        if (preg_match(self::COLOUR_BLINDNESS, $text) === 1) {
            $rules[] = ['condition_type' => 'colour_vision_requirement'];
        }

        // Only a requirement given in words alone; the collectors read the rest, e.g. the CGPA after it
        if (($type === null || $type === 'unparsed_text') && preg_match(self::DIPLOMA_OR_EQUIVALENT_PHRASE, $text) === 1) {
            $rules[] = ['condition_type' => 'diploma_or_equivalent_requirement'];
        }

        // The line itself, without the collectors' type in front
        $ownText = is_string($condition) ? $condition : (string) ($condition['text'] ?? '');
        $statement = $this->qualificationStatement($ownText);

        // A statement may come as text or as a note the collectors marked, e.g. a senate approval
        if (in_array($type, [null, 'unparsed_text', 'senate_approval_requirement', 'related_diploma_requirement'], true) && $statement !== null) {
            $rules[] = ['condition_type' => 'qualification_statement', 'statement' => $statement];
        }

        if (in_array($type, [null, 'unparsed_text'], true) && $this->isDiplomaKindLine($ownText)) {
            $rules[] = ['condition_type' => 'diploma_kind_requirement', 'text' => rtrim((string) preg_replace('/^(catatan|nota)\s*:\s*/iu', '', trim($ownText)), ' ;.')];
        }

        // A grade or prerequisite course listed as something an audition covers ("Merangkumi") isn't a requirement
        $isAuditionTopic = preg_match(self::MUSIC_OR_PREREQUISITE_COURSE, $text) === 1
            && preg_match(self::MUSIC, (string) preg_replace('/merangkumi.*$/isu', '', $text)) !== 1;

        if (preg_match(self::MUSIC, $text) === 1 && ! $isAuditionTopic) {
            $rules[] = $this->musicGrade($text);
        }

        if (preg_match(self::QURAN, $text) === 1) {
            $rules[] = $this->quranMemorisation($text);
        }

        // Age limits the collectors already read are checked as they are
        if ($type !== 'age_requirement' && preg_match(self::AGE, $text) === 1) {
            $age = $this->age($text);

            if ($age !== null) {
                $rules[] = $age;
            }
        }

        // Work experience the collectors already read is checked as it is
        if ($type !== 'experience_requirement' && preg_match(self::EXPERIENCE, $text, $matches) === 1) {
            $rules[] = ['condition_type' => 'experience_requirement', 'condition_value' => (int) ($matches[1] !== '' ? $matches[1] : $matches[2]), 'text' => $text];
        }

        if (preg_match(self::GENDER, $text, $matches) === 1) {
            $rules[] = ['condition_type' => 'gender_requirement', 'gender' => mb_strtolower($matches[1]) === 'lelaki' ? 'male' : 'female'];
        }

        return $rules;
    }

    /**
     * A note with the parts checked from the profile taken out, or null when nothing else is left. E.g.
     * "Temu Bual Berketurunan Melayu, Anak Negeri Sabah, Anak Negeri Sarawak DAN Orang Asli Sahaja." becomes "Temu Bual".
     */
    public function withoutCheckedText(string $note): ?string
    {
        if ($this->isCheckedInFull($note) || $this->qualificationStatement($note) !== null || $this->isDiplomaKindLine($note) || $this->isMusicOrQuranLine($note)) {
            return null;
        }

        $remaining = (string) preg_replace([self::DESCENT_PHRASE, self::EXPERIENCE_PHRASE, self::MUSIC_BOARD_DIPLOMA_PHRASE, self::MUSIC_OR_PREREQUISITE_COURSE_PHRASE, self::DIPLOMA_OR_EQUIVALENT_PHRASE], '', $note);
        $remaining = trim((string) preg_replace(['/[ \t]{2,}/u', '/^\s*(dan|;)\s+/iu'], [' ', ''], $remaining));
        $remaining = trim((string) preg_replace(self::LONE_HEADING, '', $remaining));
        // "Catatan:" or "Nota:" only marks a note, which it already is
        $remaining = trim((string) preg_replace('/^(catatan|nota)\s*:\s*/iu', '', $remaining));

        return $remaining === '' || $this->isCheckedInFull($remaining) ? null : $remaining;
    }

    /**
     * Whether a line is read as a music grade or Quran memorisation, e.g. "Minimum Sijil Gred 7 Yamaha; Minimum Sijil
     * Gred 5 The Associated Board Of The Royal Schools Of Music (Abrsm)". The rule read from it covers the whole line.
     */
    private function isMusicOrQuranLine(string $note): bool
    {
        return array_filter(
            $this->read($note),
            fn (array $rule): bool => in_array($rule['condition_type'], ['music_grade_requirement', 'quran_memorisation_requirement'], true),
        ) !== [];
    }

    /**
     * Whether a line asks for a particular kind of diploma, e.g. "Memiliki Diploma Dalam Bidang Pengajian Islam ATAU
     * Setaraf" or "Lulus Diploma Kejuruteraan Dalam Bidang Berkaitan". The form doesn't ask what an applicant's diploma
     * is in or where it's from, so the line is a requirement the applicant checks. A line that accepts any diploma, or
     * one of the QUALIFICATION_STATEMENTS, is checked on its own.
     */
    public function isDiplomaKindLine(string $text): bool
    {
        return preg_match(self::DIPLOMA_KIND, trim($text)) === 1
            && preg_match(self::DIPLOMA_OR_EQUIVALENT_PHRASE, $text) !== 1
            && $this->qualificationStatement($text) === null;
    }

    /**
     * Which of the QUALIFICATION_STATEMENTS a whole line is, e.g. "citizenship", or null when it is none of them.
     */
    public function qualificationStatement(string $text): ?string
    {
        foreach (self::QUALIFICATION_STATEMENTS as $statement => $pattern) {
            if (preg_match($pattern, trim($text)) === 1) {
                return $statement;
            }
        }

        return null;
    }

    /**
     * The field a work experience requirement names, e.g. "Industri Muzik", or null when it names none or only
     * "a related field" ("Bidang Berkaitan").
     */
    public function experienceField(string $text): ?string
    {
        if (preg_match(self::EXPERIENCE_FIELD, $text, $matches) !== 1 || preg_match('/berkaitan|\byang\b|sesuai/iu', $matches[1]) === 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Whether a note only states requirements that are checked from the profile, e.g.
     * "Berketurunan Melayu, Anak Negeri Sabah, Anak Negeri Sarawak DAN Orang Asli Sahaja.".
     */
    public function isCheckedInFull(string $note): bool
    {
        $sentences = preg_split('/[.;]/u', (string) preg_replace('/^\s*(catatan|nota)\s*:\s*/iu', '', $note));
        $hasSentence = false;

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);

            if ($sentence === '') {
                continue;
            }

            $hasSentence = true;

            if (array_filter(self::CHECKED_SENTENCES, fn (string $pattern): bool => preg_match($pattern, $sentence) === 1) === []) {
                return false;
            }
        }

        return $hasSentence;
    }

    /**
     * Combine rules of the same kind read from one list of requirements. A course that lists
     * several music qualifications, such as "ABRSM Gred 5" and "Trinity College London Gred 5",
     * accepts any of them, so the lowest is kept.
     *
     * @param  list<array<string, mixed>>  $rules
     * @return list<array<string, mixed>>
     */
    public function combine(array $rules): array
    {
        $combined = [];

        foreach ($rules as $rule) {
            $type = $rule['condition_type'];
            $key = match ($type) {
                'age_requirement' => $type.'|'.($rule['minimum_age'] ?? '').'|'.($rule['maximum_age'] ?? ''),
                'experience_requirement' => $type.'|'.$rule['condition_value'],
                'qualification_statement' => $type.'|'.$rule['statement'],
                'diploma_kind_requirement' => $type.'|'.$rule['text'],
                default => $type,
            };

            if (! isset($combined[$key])) {
                $combined[$key] = $rule;

                continue;
            }

            $combined[$key] = match ($type) {
                'music_grade_requirement' => [
                    ...$combined[$key],
                    'minimum_rank' => min($combined[$key]['minimum_rank'], $rule['minimum_rank']),
                    'accepts_equivalent' => $combined[$key]['accepts_equivalent'] || $rule['accepts_equivalent'],
                ],
                'quran_memorisation_requirement' => [
                    ...$combined[$key],
                    'minimum_juz' => min($combined[$key]['minimum_juz'], $rule['minimum_juz']),
                    'needs_certificate' => $combined[$key]['needs_certificate'] && $rule['needs_certificate'],
                ],
                default => $combined[$key],
            };
        }

        return array_values($combined);
    }

    /**
     * "Gred 5" sets the lowest grade. A diploma such as "Ltcl / Atcl Diploma Dari Trinity College London"
     * ranks above Grade 8. "Setaraf" or "Setara" means an equivalent is also accepted, and so does a diploma
     * from any music exam board, since boards such as Yamaha are not on the form. Only a grade written
     * beside ABRSM or Trinity counts, so "Gred 7 Yamaha; Minimum Sijil Gred 5 The Associated Board ..." is Grade 5.
     *
     * @return array<string, mixed>
     */
    private function musicGrade(string $text): array
    {
        $rank = null;

        if (preg_match(self::MUSIC_BOARD_DIPLOMA, $text) === 1) {
            $rank = count(config('academic.music_grades'));
        }

        foreach (preg_split('/[;:]/u', $text) as $clause) {
            if (preg_match(self::MUSIC, $clause) === 1 && preg_match('/gred\s*(\d)\b/iu', $clause, $matches) === 1) {
                $rank = $rank === null ? (int) $matches[1] : min($rank, (int) $matches[1]);
            }
        }

        // The exam boards listed under "Diploma Daripada Lembaga Peperiksaan Muzik Seperti Berikut", one a line
        $lines = explode("\n", $text);
        $boards = preg_match(self::MUSIC_BOARD_DIPLOMA_PHRASE, $text) === 1 ? array_slice($lines, 1) : [];

        return [
            'condition_type' => 'music_grade_requirement',
            'minimum_rank' => $rank,
            'accepts_equivalent' => preg_match('/setara|lembaga\s+peperiksaan\s+muzik/iu', $text) === 1,
            'prerequisite_course' => preg_match(self::MUSIC_OR_PREREQUISITE_COURSE, $text) === 1,
            'boards' => array_values(array_filter(array_map(fn (string $board): string => trim($board, " ;\t"), $boards))),
        ];
    }

    /**
     * "Menghafaz Sekurang-Kurangnya 30 Juzuk" sets the number of juz, 30 when none is given.
     * "Sijil" or "Perakuan" means a certificate of memorisation is asked for.
     *
     * @return array<string, mixed>
     */
    private function quranMemorisation(string $text): array
    {
        return [
            'condition_type' => 'quran_memorisation_requirement',
            'minimum_juz' => preg_match('/(\d+)\s*juzuk/iu', $text, $matches) === 1 ? min((int) $matches[1], 30) : 30,
            'needs_certificate' => preg_match('/sijil|perakuan/iu', $text) === 1,
        ];
    }

    /**
     * "Berusia 18 Tahun DAN Ke Atas", "Berumur 30 Tahun Kebawah" or "Berumur Diantara 17 Tahun Hingga 20 Tahun".
     * "17 Tahun 12 Bulan" is read as 18.
     *
     * @return array<string, mixed>|null
     */
    private function age(string $text): ?array
    {
        if (preg_match('/(\d{2})\s*tahun\s*hingga\s*(\d{2})\s*tahun/iu', $text, $matches) === 1) {
            return ['condition_type' => 'age_requirement', 'minimum_age' => (int) $matches[1], 'maximum_age' => (int) $matches[2]];
        }

        if (preg_match('/(\d{2})\s*tahun\s*(?:(\d{1,2})\s*bulan\s*)?(?:dan\s*)?ke\s*atas/iu', $text, $matches) === 1) {
            return ['condition_type' => 'age_requirement', 'minimum_age' => (int) $matches[1] + ((int) ($matches[2] ?? 0) >= 12 ? 1 : 0)];
        }

        if (preg_match('/(\d{2})\s*tahun\s*ke\s*bawah/iu', $text, $matches) === 1) {
            return ['condition_type' => 'age_requirement', 'maximum_age' => (int) $matches[1]];
        }

        // "Sekurang-Kurangnya Berumur 18 Tahun" or "Berumur Sekurang-Kurangnya 18 Tahun"
        if (preg_match('/sekurang-kurangnya\s+ber(?:umur|usia)\s+(\d{2})\s*tahun|ber(?:umur|usia)\s+sekurang-kurangnya\s+(\d{2})\s*tahun/iu', $text, $matches) === 1) {
            return ['condition_type' => 'age_requirement', 'minimum_age' => (int) ($matches[1] !== '' ? $matches[1] : $matches[2])];
        }

        return null;
    }

    /**
     * Every piece of text in a condition, e.g. its "text" and its subject list.
     *
     * @param  array<string, mixed>|string  $condition
     * @return list<string>
     */
    private function texts(array|string $condition): array
    {
        if (is_string($condition)) {
            return [$condition];
        }

        $texts = [];

        array_walk_recursive($condition, function (mixed $value) use (&$texts): void {
            if (is_string($value)) {
                $texts[] = $value;
            }
        });

        return $texts;
    }
}

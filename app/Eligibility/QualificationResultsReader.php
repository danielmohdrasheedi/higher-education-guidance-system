<?php

namespace App\Eligibility;

/**
 * Reads the A-Level, IB and AUSMAT requirements e-Panduan writes over several lines, e.g. "Mendapat Sekurang-Kurangnya
 * Gred C (Markah", "Pada Peringkat A-Level Dalam Mata Pelajaran Berikut", "Mathematics, DAN", "Mana-Mana Dua (2) Daripada
 * Mata Pelajaran Di Bawah", "Physics", "Chemistry". The collectors keep each line as wording of its own, so the lines are
 * joined back into whole requirements here: Grade C in Mathematics, and in two of Physics and Chemistry.
 *
 * A requirement read in full becomes a rule the checker checks against the applicant's results. One that is only partly
 * understood, such as an IB requirement given as a letter grade, becomes a single requirement in plain English for the
 * applicant to check. Lines about anything else, such as SPM grades or an interview, are left as they are.
 */
final class QualificationResultsReader
{
    /**
     * The qualifications read here, as named to the applicant.
     *
     * @var array<string, string>
     */
    public const NAMES = ['a_level' => 'A-Level', 'ib' => 'IB', 'ausmat' => 'AUSMAT'];

    /**
     * Where each qualification's subject grades are kept in the applicant's results.
     *
     * @var array<string, string>
     */
    public const SUBJECT_LEVELS = ['a_level' => 'equivalent', 'ib' => 'ib', 'ausmat' => 'equivalent'];

    /**
     * Words naming a qualification in a line.
     *
     * @var array<string, string>
     */
    private const QUALIFICATION_WORDS = [
        'ib' => '/international\s+baccalaureate|\bibd?\b|\b(high|higher|standard)\s+level\b|\b(hl|sl)\b/iu',
        'a_level' => '/\ba\s*-?\s*level|\ba[”"“]\s*levels?|\bgce\b/iu',
        'ausmat' => '/\bausmat\b|australian\s+matriculation|\batar\b|\bagregat\b/iu',
        'other' => '/\bspm\b|\bstpm\b|sijil\s+pelajaran|o\s*level|\bigcse\b|matrikulasi|\basasi\b|\bpra[-\s]?u\b|\bmuet\b|\bband\b|\bielts\b|\btoefl\b|\bpte\b|pearson|\bcefr\b|linguaskill|\boet\b|\bpngk\b|\bcgpa\b/iu',
    ];

    /**
     * Words that start a new requirement, so a list of subjects ends before a line starting with one of them.
     */
    private const SENTENCE_START = '/^(mendapat|memiliki|mempunyai|memenuhi|melepasi|lulus|calon|nota|catatan|sekurang|band|tidak|apa-apa|untuk|program|syarat|pada\s+peringkat|dalam\s+\p{L}+\s+\(\d\))/iu';

    /**
     * Words that can't be part of a subject's name, so a line with them isn't a subject in a list.
     */
    private const NOT_A_SUBJECT = '/\(\d\)|\bperingkat\b|\b(hl|sl)\b|\b(melepasi|lulus|temu\s*duga|temuduga|syarat|mendapat|memiliki|spm|stpm|muet|ujian|tahun|gred|sijil)\b/iu';

    /**
     * Lines that only say the applicant holds the qualification, e.g. "Lulus Peperiksaan GCE A-Level Dengan" or
     * "Memiliki Kelayakan GCE A-Level Yang Diiktiraf;". The applicant's category already says this.
     */
    private const HOLDS_QUALIFICATION = '/^(?:(?:lulus|memiliki|mempunyai)\s+(?:(?:peperiksaan|pengajian|kelayakan|kelulusan)\s+)?(?:(?:pada\s+)?peringkat\s+)?(?:diploma\s+)?(?:gce\s+)?(?:a\s*-?\s*level|a[”"“]\s*levels|international\s+baccalaureate(?:\s+diploma)?(?:\s*\(ibd?\))?|ibd|australian\s+matriculation\s+program(?:me)?\s*\(ausmat\)|ausmat)(?:\s+yang\s+diiktiraf)?(?:\s+dengan)?|ausmat)$/iu';

    /**
     * A grade or IB score, e.g. "Gred C", "Gred B-", "Gred A*" or "Skor 4".
     */
    private const GRADE = '/\b(?:gred|skor)\s+(a\*|[a-e][+-]?|[1-7])(?=[\s(;:,.]|$)/iu';

    /**
     * Sets of grades, any of which is enough, e.g. "Gred Bbb, Abc ATAU Aac".
     */
    private const GRADE_SETS = '/\bgred\s+([a-e]{3}(?:\s*(?:[,\/]|atau|dan)\s*[a-e]{3})+)\b/iu';

    /**
     * A count of subjects, e.g. "Mana-Mana Dua (2)", "Dalam Tiga (3)" or "Dua (2) Mana-Mana".
     */
    private const COUNT = '/\b(?:\p{L}+\s+)?\((\d)\)\s*(?:mana-?mana\s+|daripada\s+|dari\s+)?(?:mana-?mana\s+)?mata\s+pelajaran|\bmana-?mana\s+(\d)\s+mata\s+pelajaran/iu';

    /**
     * A line opening another set of subjects inside a list, e.g. "Mana-Mana Dua (2) Daripada Mata Pelajaran Di Bawah",
     * possibly after the last subject of the set before it: "Mathematics DAN Mana-Mana Satu (1) Dari Mata Pelajaran
     * Berikut".
     */
    private const LIST_COUNT = '/^(?:(.*?)[\s,;]*\bdan\s+)?(?:mana-?mana|salah)\s+(?:\p{L}+\s+)?\((\d)\)\s+(?:daripada\s+|dari\s+)?mata\s+pelajaran\s+(?:di\s+bawah|berikut)$/iu';

    /**
     * A line asking for subjects not counted yet, e.g. "Salah Satu (1) Daripada Mana-Mana Mata Pelajaran Di Peringkat
     * GCE A-Level" or "Mana-Mana Subjek Yang Belum Diambil Kira".
     */
    private const OTHER_SUBJECTS = '/^(?:salah\s+satu|mana-?mana(?:\s+\p{L}+)?)\s*(?:\((\d)\)\s*)?(?:daripada\s+)?(?:mana-?mana\s+)?(?:mata\s+pelajaran|subjek)(?:\s+(?:lain|yang\s+belum\s+diambil\s+kira))?(?:\s+(?:di|pada)\s+peringkat\s+.+)?$/iu';

    /**
     * Another requirement written after the last subject of a list, e.g. "Chemistry / Physics DAN Calon Yang Tidak
     * Mengambil ..." or "Computing DAN Melepasi Tahap Minimum Ujian ...".
     */
    private const TRAILING_NOTE = '/[\s;,]*\bdan\s+(?:calon|melepasi|lulus|memiliki|mendapat|et\s*\/\s*seta)\b.*$/iu';

    /**
     * The subjects the form offers for each kind of results, as the form names them, by SubjectName key.
     *
     * @var array<string, array<string, string>>
     */
    private array $knownSubjects = [];

    /**
     * The rules read from a requirement's logic, for an applicant with the given qualification.
     *
     * @param  list<array<string, mixed>|string>  $logic
     * @return list<array<string, mixed>|string>
     */
    public function read(array $logic, string $qualification): array
    {
        $read = [];
        $run = [];

        foreach ($logic as $item) {
            if ($this->lineOf($item) !== null) {
                $run[] = $item;

                continue;
            }

            array_push($read, ...$this->readRun($run, $qualification));
            $run = [];

            if (is_array($item) && ($item['condition_type'] ?? null) === 'alternative_requirements' && is_array($item['alternatives'] ?? null)) {
                $item['alternatives'] = array_map(fn (array $option): array => $this->read($option, $qualification), $item['alternatives']);
            }

            $read[] = $item;
        }

        return [...$read, ...$this->readRun($run, $qualification)];
    }

    /**
     * Whether a subject is offered on the form for a qualification's results.
     */
    public function isKnownSubject(string $subject, string $qualification): bool
    {
        return $this->formName($subject, $qualification) !== null;
    }

    /**
     * The subjects that meet a subject in a requirement, as SubjectName keys. Since 2019 IB Mathematics is taken as
     * Analysis and Approaches or Applications and Interpretation, and AUSMAT has no course named Mathematics, only
     * Mathematical Methods and Specialist Mathematics, so these meet a requirement for Mathematics.
     *
     * @return list<string>
     */
    public function meetingSubjects(string $subject, string $qualification): array
    {
        $level = self::SUBJECT_LEVELS[$qualification];
        $key = SubjectName::key($subject, $level);
        $alsoMeeting = match ($qualification) {
            'ib' => ['Mathematics Analysis and Approaches', 'Mathematics Applications and Interpretation'],
            'ausmat' => ['Mathematical Methods', 'Specialist Mathematics'],
            default => [],
        };

        if ($alsoMeeting === [] || $key !== SubjectName::key('Mathematics', $level)) {
            return [$key];
        }

        return [$key, ...array_map(fn (string $name): string => SubjectName::key($name, $level), $alsoMeeting)];
    }

    /**
     * A subject's name as the form offers it, e.g. "Mathematics" for "Matematik", or null when the form doesn't offer it.
     */
    private function formName(string $subject, string $qualification): ?string
    {
        $level = self::SUBJECT_LEVELS[$qualification];

        if (! isset($this->knownSubjects[$level])) {
            $this->knownSubjects[$level] = [];

            foreach (config("academic.{$level}_subjects", []) as $name) {
                $this->knownSubjects[$level][SubjectName::key($name, $level)] = $name;
            }
        }

        return $this->knownSubjects[$level][SubjectName::key($subject, $level)] ?? null;
    }

    /**
     * A subject requirement in plain English, e.g. "Grade C or better in A-Level Mathematics" or "Grade 5 or better at
     * Higher Level (HL), or 6 at Standard Level (SL), in 2 of these IB subjects: Physics; Chemistry; Biology".
     *
     * @param  array<string, mixed>  $rule
     */
    public static function describe(array $rule): string
    {
        $name = self::NAMES[$rule['qualification']];
        $count = (int) $rule['condition_count'];
        $grade = "Grade {$rule['minimum_grade']} or better";

        if ($rule['grade_sets'] !== null) {
            $sets = $rule['grade_sets'];
            $last = array_pop($sets);
            $grade = 'Grades '.($sets === [] ? $last : implode(', ', $sets)." or {$last}");
        } elseif ($rule['standard_level_grade'] !== null) {
            $grade = "Grade {$rule['minimum_grade']} or better at Higher Level (HL), or {$rule['standard_level_grade']} at Standard Level (SL),";
        } elseif ($rule['ib_level'] === 'HL') {
            $grade .= ' at Higher Level (HL)';
        } elseif ($rule['ib_level'] === 'SL') {
            $grade .= ' at Standard Level (SL) or higher';
        }

        if ($rule['subjects'] === null) {
            $other = $rule['other_subjects'] ? 'other ' : '';

            return $count === 1 ? "{$grade} in 1 {$other}{$name} subject" : "{$grade} in any {$count} {$other}{$name} subjects";
        }

        $entries = array_map(fn (array $alternatives): string => implode(' / ', $alternatives), $rule['subjects']);

        if ($count === count($entries)) {
            if ($count === 1) {
                return str_contains($entries[0], ' / ') ? "{$grade} in one {$name} subject: {$entries[0]}" : "{$grade} in {$name} {$entries[0]}";
            }

            return "{$grade} in each of these {$name} subjects: ".implode('; ', $entries);
        }

        return $count === 1
            ? "{$grade} in one of these {$name} subjects: ".implode('; ', $entries)
            : "{$grade} in {$count} of these {$name} subjects: ".implode('; ', $entries);
    }

    /**
     * The wording of a line left unread by the collectors, or null for a rule.
     */
    private function lineOf(mixed $item): ?string
    {
        if (is_string($item)) {
            return $item;
        }

        return is_array($item) && ($item['condition_type'] ?? null) === 'unparsed_text' && is_string($item['text'] ?? null) ? $item['text'] : null;
    }

    /**
     * Reads lines of wording that follow each other.
     *
     * @param  list<array<string, mixed>|string>  $items
     * @return list<array<string, mixed>|string>
     */
    private function readRun(array $items, string $qualification): array
    {
        // Several qualifications listed one after another, e.g. "AUSMAT ...", "B. Sam/ Sace ...", "C. Adfp ...", are
        // routes to choose from, which the lines alone don't show, so they are left as e-Panduan words them
        if ($items === [] || $this->listsSeveralRoutes($items)) {
            return $items;
        }

        $items = $this->withRequirementsSplit($items);
        $lines = array_map(fn (array|string $item): string => $this->tidy((string) $this->lineOf($item)), $items);
        $read = [];
        $index = 0;

        while ($index < count($lines)) {
            $line = $lines[$index];
            $next = $lines[$index + 1] ?? null;
            $isFragment = preg_match('/^(?:mendapat\s+)?sekurang-?\s*kurangnya(?:\s+skor)?$/iu', $line) === 1;

            // "Mendapat Sekurang-Kurangnya" or "Gred C (Markah" end mid-sentence, and the next line finishes them
            if ($next !== null && ($isFragment || preg_match('/\(markah$/iu', $line) === 1) && $this->continuesSentence($next)) {
                $lines[$index + 1] = trim(preg_replace('/\s*\(markah$/iu', '', $line).' '.$next);
                $items[$index + 1] = $this->joined($items[$index], $items[$index + 1]);
                $index++;

                continue;
            }

            // A lone "Mendapat Sekurang-Kurangnya" before a MUET band the collectors read on its own says nothing more
            if ($isFragment) {
                $index++;

                continue;
            }

            if (! $this->mentions($line, $qualification)) {
                $read[] = $items[$index];
                $index++;

                continue;
            }

            if (preg_match(self::HOLDS_QUALIFICATION, $line) === 1) {
                $index++;

                continue;
            }

            $score = $this->scoreRule($line, $qualification);

            if ($score !== null) {
                $read[] = $score + ['source_lines' => [$this->lineOf($items[$index])]];
                $index++;

                continue;
            }

            $consumed = 0;
            $rules = $this->subjectRules($lines, $index, $qualification, $consumed);

            if ($rules === null) {
                $read[] = $items[$index];
                $index++;

                continue;
            }

            $sources = array_map($this->lineOf(...), array_slice($items, $index, $consumed));
            array_push($read, ...array_map(fn (array $rule): array => $rule + ['source_lines' => $sources], $rules));

            // A subject line ending in a note, e.g. "Chemistry / Physics / Biology DAN Calon Yang Tidak Mengambil ...
            // Perlu", keeps the note as e-Panduan words it
            foreach (array_slice($items, $index + 1, $consumed - 1) as $item) {
                if (preg_match(self::TRAILING_NOTE, (string) $this->lineOf($item)) === 1) {
                    $read[] = $item;
                }
            }

            $index += $consumed;
        }

        return $read;
    }

    /**
     * Lines holding the end of one requirement and the start of the next, e.g. "Mana-Mana Satu (1) Mata Pelajaran Yang
     * Belum Diambil Kira DAN Memiliki Kelayakan GCE A Level Dengan ... Gred Bbb ...", split into a line for each.
     *
     * @param  list<array<string, mixed>|string>  $items
     * @return list<array<string, mixed>|string>
     */
    private function withRequirementsSplit(array $items): array
    {
        $split = [];

        foreach ($items as $item) {
            if (preg_match('/^(.+)\s+dan\s+((?:memiliki|lulus)\s+(?!.*\bata?r\b).*\bgred\b.*)$/iu', (string) $this->lineOf($item), $parts) === 1) {
                array_push($split, ['condition_type' => 'unparsed_text', 'text' => $parts[1]], ['condition_type' => 'unparsed_text', 'text' => $parts[2]]);

                continue;
            }

            $split[] = $item;
        }

        return $split;
    }

    /**
     * @param  list<array<string, mixed>|string>  $items
     */
    private function listsSeveralRoutes(array $items): bool
    {
        $text = implode("\n", array_map(fn (array|string $item): string => (string) $this->lineOf($item), $items));

        return preg_match('/\b(?:b|c)\.\s*(?:sam|adfp)\b|\bperingkat\s+(?:sam|adfp)\b|canadian/iu', $text) === 1;
    }

    /**
     * The line that finishes a broken sentence, e.g. "Pada Peringkat A-Level Dalam Mata Pelajaran Berikut".
     */
    private function continuesSentence(string $line): bool
    {
        return preg_match('/^(?:gred|skor|markah|tahap|pada\s+peringkat|di\s+peringkat|dalam|bagi|\d)/iu', $line) === 1;
    }

    /**
     * The first item with the text of both, for a sentence e-Panduan broke over two lines.
     *
     * @param  array<string, mixed>|string  $first
     * @param  array<string, mixed>|string  $second
     * @return array<string, mixed>
     */
    private function joined(array|string $first, array|string $second): array
    {
        return ['condition_type' => 'unparsed_text', 'text' => trim($this->lineOf($first).' '.$this->lineOf($second))];
    }

    /**
     * Whether a line is about the applicant's qualification: when it names it and no other kind of result, such as
     * SPM, or when it names no qualification at all.
     */
    private function mentions(string $line, string $qualification): bool
    {
        $named = [];

        foreach (self::QUALIFICATION_WORDS as $name => $pattern) {
            if (preg_match($pattern, $line) === 1) {
                $named[$name] = true;
            }
        }

        // "Lulus AUSMAT/Sam" names the South Australian Matriculation beside AUSMAT, and both are this category's
        if ($qualification === 'ausmat' && preg_match('/\bsam\b/iu', $line) === 1) {
            $named['ausmat'] = true;
        }

        return $named === [] || (isset($named[$qualification]) && ! isset($named['other']));
    }

    /**
     * A total score: IB points, an AUSMAT ATAR or aggregate, or an A-Level score the site can't work out.
     *
     * @return array<string, mixed>|null
     */
    private function scoreRule(string $line, string $qualification): ?array
    {
        // "Skor Minimum 26/42 Daripada Enam (6) Mata Pelajaran Iaitu ...": the subjects only say what the points are made of
        if ($qualification === 'ib' && preg_match('/\bskor\s+minimum\s+(\d{2})\s*\/\s*4[25]\b/iu', $line, $matches) === 1) {
            return ['condition_type' => 'qualification_score_requirement', 'measure' => 'ib_points', 'minimum' => (float) $matches[1]];
        }

        if (preg_match('/mata\s+pelajaran|\bgred\b|\bband\b/iu', $line) === 1) {
            return null;
        }

        if ($qualification === 'ausmat') {
            if (preg_match('/\bagregat\s+(\d{2})\b/iu', $line, $matches) === 1) {
                return ['condition_type' => 'qualification_score_requirement', 'measure' => 'ausmat_aggregate', 'minimum' => (float) $matches[1]];
            }

            if (preg_match('/(\d{2}(?:\.\d{1,2})?)\s*%?\s*(?:mata|markah)?\s*(?:dalam\s+)?(?:australian\s+tertiary\s+admissions?\s+rank\s*)?\(?\bat(?:a)?r\b|\bat(?:a)?r\)?\s*(\d{2}(?:\.\d{1,2})?)/iu', $line, $matches) === 1) {
                return ['condition_type' => 'qualification_score_requirement', 'measure' => 'atar', 'minimum' => (float) ($matches[1] !== '' ? $matches[1] : $matches[2])];
            }

            return null;
        }

        if ($qualification === 'ib') {
            if (preg_match('/\b(?:skor|markah|mata)\s+(?:minimum\s+)?(\d{2})\b|\b(\d{2})\s+mata\b/iu', $line, $matches) !== 1) {
                return null;
            }

            $points = (int) ($matches[1] !== '' ? $matches[1] : $matches[2]);

            return $points <= 45
                ? ['condition_type' => 'qualification_score_requirement', 'measure' => 'ib_points', 'minimum' => (float) $points]
                : null;
        }

        if (preg_match('/\b(skor|tahap)\s+(\d{1,2})\b/iu', $line, $matches) === 1) {
            return [
                'condition_type' => 'qualification_wording',
                'description' => mb_strtolower($matches[1]) === 'tahap' ? "At least level {$matches[2]} at A-Level" : "An A-Level score of at least {$matches[2]}",
                'reason' => "e-Panduan doesn't say how this is worked out from your grades. Check with the university.",
            ];
        }

        return null;
    }

    /**
     * The subject rules starting at a line, e.g. "Mendapat Sekurang-Kurangnya Gred C Dalam Setiap Mata Pelajaran
     * Berikut" with the subject lines after it, or null when the line isn't a subject grade requirement.
     *
     * @param  list<string>  $lines
     * @param  int  $consumed  Set to the number of lines read.
     * @return list<array<string, mixed>>|null
     */
    private function subjectRules(array $lines, int $start, string $qualification, int &$consumed): ?array
    {
        $line = $lines[$start];
        $consumed = 1;

        // "Mendapat Sekurang-Kurangnya 2C Pada Peringkat A-Level": a grade in any two subjects
        if (preg_match('/^(?:mendapat\s+)?sekurang-?\s*kurangnya\s+(\d)\s*([a-e])(?:\s+(?:pada|di)\s+peringkat\s+.+)?$/iu', $line, $matches) === 1) {
            return [$this->subjectRule($qualification, mb_strtoupper($matches[2]), null, null, null, (int) $matches[1], null)];
        }

        // A heading broken before the level, e.g. "... Dalam Tiga (3) Mata Pelajaran Di", "Peringkat Hl"
        while (isset($lines[$start + $consumed]) && preg_match('/^(?:(?:di|pada)\s+)?peringkat\b|^\(?(hl|sl)\)?(\s*\/\s*\(?(hl|sl)\)?)?$/iu', $lines[$start + $consumed]) === 1) {
            $line .= ' '.$lines[$start + $consumed];
            $consumed++;
        }

        // "... Dalam Tiga (3) Mata Pelajaran. DAN i.": the "i." starts the next requirement, on the line after
        $line = (string) preg_replace('/[.;]\s*dan\s+[ivx]+\.?$/iu', '.', $line);

        $gradeSets = null;

        if (preg_match(self::GRADE_SETS, $line, $setsMatch) === 1) {
            preg_match_all('/[a-e]{3}/iu', $setsMatch[1], $setLetters);
            $gradeSets = array_map(mb_strtoupper(...), $setLetters[0]);
            $grade = substr($gradeSets[0], -1);
        } elseif (preg_match(self::GRADE, $line, $gradeMatch) === 1) {
            $grade = mb_strtoupper($gradeMatch[1]);
        } else {
            return null;
        }

        if (preg_match('/mata\s+pelajaran/iu', $line) !== 1) {
            return null;
        }

        $standardGrade = null;
        $ibLevel = null;
        $namesHigher = preg_match('/\bhigh(?:er)?\s+level\b|\bhl\b/iu', $line) === 1;
        $namesStandard = preg_match('/\bstandard\s+level\b|\bsl\b/iu', $line) === 1;

        if (preg_match('/gred\s+(\d)\s+di\s+peringkat\s+higher\s+level\s*\(hl\)\s+atau\s+gred\s+(\d)\s+di\s+peringkat\s+standard\s+level/iu', $line, $levels) === 1) {
            $grade = $levels[1];
            $standardGrade = $levels[2];
        } elseif ($namesHigher && ! $namesStandard) {
            $ibLevel = 'HL';
        } elseif ($namesStandard && ! $namesHigher) {
            $ibLevel = 'SL';
        }

        // "Skor 4 Bagi Mana-Mana Dua (2) Mata Pelajaran Di Peringkat High Level (Hl) DAN Satu (1) Mata Pelajaran Di
        // Peringkat Standard Level (Sl)": two subjects at HL and one more at SL
        if (preg_match('/\((\d)\)\s+mata\s+pelajaran\s+di\s+peringkat\s+high(?:er)?\s+level\s*\(hl\)\s+dan\s+\p{L}+\s+\((\d)\)\s+mata\s+pelajaran\s+di\s+peringkat\s+standard\s+level\s*\(sl\)\s*\.?$/iu', $line, $split) === 1) {
            return [
                $this->subjectRule($qualification, $grade, null, null, 'HL', (int) $split[1], null),
                $this->subjectRule($qualification, $grade, null, null, null, (int) $split[2], null, otherSubjects: true),
            ];
        }

        $count = preg_match(self::COUNT, $line, $countMatch) === 1 ? (int) ($countMatch[1] !== '' ? $countMatch[1] : $countMatch[2]) : null;
        $isEach = preg_match('/\bsetiap\b/iu', $line) === 1;
        $listFollows = preg_match('/\bberikut\b\s*[:;.\-]*\s*$/iu', $line) === 1;
        $asksOtherSubjects = preg_match('/mata\s+pelajaran\s+lain\s*[.;]?$/iu', $line) === 1;
        $inline = $this->inlineSubjects($line);
        $nextLine = $lines[$start + $consumed] ?? '';

        // "Dalam Mana-Mana Mata Pelajaran" with the subjects on the lines after it, though it doesn't say "Berikut"
        if (! $listFollows && $inline === null && ! str_ends_with($line, '.') && $this->isSubjectLine($nextLine)) {
            $listFollows = true;
        }

        // A sentence with more after the subjects can't be read in full
        if (! $listFollows && ! $asksOtherSubjects && $inline === null && preg_match('/mata\s+pelajaran\s*(?:(?:di|pada)\s+peringkat\s+[^.;]+)?[.;:]?\s*$/iu', $line) !== 1) {
            return null;
        }

        $sets = [];

        if ($listFollows) {
            // "Dalam Mana-Mana Mata Pelajaran" without a count asks for any one of the subjects listed
            $isAnyOne = $count === null && preg_match('/\bmana-?mana\s+mata\s+pelajaran\b/iu', $line) === 1;
            $set = ['count' => $isAnyOne ? 1 : $count, 'each' => $isEach || ($count === null && ! $isAnyOne), 'entries' => []];

            for ($next = $start + $consumed; $next < count($lines); $next++) {
                $item = $lines[$next];

                if (preg_match(self::LIST_COUNT, $item, $listCount) === 1) {
                    if (trim($listCount[1]) !== '') {
                        $set['entries'][] = $this->subjectEntry($listCount[1]);
                    }

                    if ($set['entries'] !== []) {
                        $sets[] = $set;
                    }

                    $set = ['count' => (int) $listCount[2], 'each' => false, 'entries' => []];
                    $consumed++;

                    continue;
                }

                // "Accounting / Economics DAN Mana-Mana Satu (1) Daripada Mata Pelajaran Lain."
                if (preg_match('/^(.+?)[\s,;]*\bdan\s+(.+)$/iu', $item, $parts) === 1 && preg_match(self::OTHER_SUBJECTS, rtrim($parts[2], ' .;'), $other) === 1) {
                    $set['entries'][] = $this->subjectEntry($parts[1]);
                    $item = rtrim($parts[2], ' .;');
                }

                if (preg_match(self::OTHER_SUBJECTS, rtrim($item, ' .;'), $other) === 1) {
                    if ($set['entries'] !== []) {
                        $sets[] = $set;
                    }

                    $set = ['count' => null, 'each' => true, 'entries' => []];
                    $sets[] = ['other' => max(1, (int) ($other[1] ?? 1))];
                    $consumed++;

                    continue;
                }

                if (! $this->isSubjectLine($item)) {
                    break;
                }

                $set['entries'][] = $this->subjectEntry($item);
                $consumed++;
            }

            if ($set['entries'] !== []) {
                $sets[] = $set;
            }
        } elseif ($inline !== null) {
            $isAllOf = count($inline) > 1 && preg_match('/\s+dan\s+/iu', $this->inlineText($line)) === 1;
            $entries = $isAllOf ? array_map(fn (string $subject): array => [$subject], $inline) : [$inline];
            $sets[] = ['count' => $count ?? ($isAllOf ? count($entries) : 1), 'each' => $isAllOf, 'entries' => $entries];
        } else {
            $sets[] = ['other' => $count ?? 1, 'first' => ! $asksOtherSubjects];
        }

        $rules = $this->rulesFor($sets, $qualification, $grade, $gradeSets, $standardGrade, $ibLevel);
        $isLetter = preg_match('/^(a\*|[a-e][+-]?)$/iu', $grade) === 1;
        $name = self::NAMES[$qualification];
        $subjectCount = array_sum(array_map(fn (array $rule): int => $rule['condition_count'], $rules ?? []));

        $reason = match (true) {
            $qualification === 'ib' && $isLetter => "e-Panduan gives a letter grade here, which IB results don't use. Check with the university.",
            $qualification !== 'ib' && ! $isLetter => "e-Panduan gives this grade as a number, which {$name} results don't use. Check with the university.",
            $rules === null || $rules === [] || ($gradeSets !== null && strlen($gradeSets[0]) !== $subjectCount) => "This site couldn't read this requirement in full. Check it with the university.",
            $this->hasNoKnownSubject($rules, $qualification) => "This list has no subject the form offers, so this site can't check it. Check it with the university.",
            default => null,
        };

        if ($reason === null) {
            return $rules;
        }

        $description = $rules === null || $rules === []
            ? self::describe($this->subjectRule($qualification, $grade, $gradeSets, $standardGrade, $ibLevel, $count ?? 1, null))
            : implode('; and ', array_map(fn (array $rule, int $index): string => $index === 0 ? self::describe($rule) : lcfirst(self::describe($rule)), $rules, array_keys($rules)));

        return [['condition_type' => 'qualification_wording', 'description' => $description, 'reason' => $reason]];
    }

    /**
     * The subject rules for the sets of subjects read from a requirement, or null when the counts don't add up. A set
     * asking for more subjects than it lists, e.g. "Dalam Dua (2) Mata Pelajaran Berikut" over "Physics DAN Mana-Mana
     * Satu (1) Dari Mata Pelajaran Berikut", asks for all of them, and the rest come from the sets after it or, when
     * there are none, from any other subjects.
     *
     * @param  list<array<string, mixed>>  $sets
     * @param  list<string>|null  $gradeSets
     * @return list<array<string, mixed>>|null
     */
    private function rulesFor(array $sets, string $qualification, string $grade, ?array $gradeSets, ?string $standardGrade, ?string $ibLevel): ?array
    {
        $sets = array_map(fn (array $set): array => isset($set['entries'])
            ? [...$set, 'entries' => array_map(fn (array $entry): array => $this->withSubjectsSplit($entry, $qualification), $set['entries'])]
            : $set, $sets);
        $counts = array_map(fn (array $set): int => isset($set['other']) ? $set['other'] : $this->countAndEntries($set)[0], $sets);
        $rules = [];

        foreach ($sets as $position => $set) {
            if (isset($set['other'])) {
                $rules[] = $this->subjectRule($qualification, $grade, $gradeSets, $standardGrade, $ibLevel, $set['other'], null, otherSubjects: ! ($set['first'] ?? false));

                continue;
            }

            [$count, $entries] = $this->countAndEntries($set);

            if ($count <= count($entries)) {
                $rules[] = $this->subjectRule($qualification, $grade, $gradeSets, $standardGrade, $ibLevel, $count, $entries);

                continue;
            }

            $more = $count - count($entries) - array_sum(array_slice($counts, $position + 1));

            if ($more < 0) {
                return null;
            }

            $rules[] = $this->subjectRule($qualification, $grade, $gradeSets, $standardGrade, $ibLevel, count($entries), $entries);

            if ($more > 0) {
                $rules[] = $this->subjectRule($qualification, $grade, $gradeSets, $standardGrade, $ibLevel, $more, null, otherSubjects: true);
            }
        }

        return $rules;
    }

    /**
     * Whether a rule lists subjects of which the form offers none, so there is nothing to check.
     *
     * @param  list<array<string, mixed>>  $rules
     */
    private function hasNoKnownSubject(array $rules, string $qualification): bool
    {
        foreach ($rules as $rule) {
            $subjects = array_merge(...($rule['subjects'] ?? [[]]));

            if ($rule['subjects'] !== null && array_filter($subjects, fn (string $subject): bool => $this->isKnownSubject($subject, $qualification)) === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * How many of a set's subjects are asked for, and the subjects, each a list of alternatives. "Dalam Dua (2) Mata
     * Pelajaran Berikut" with one line "Biology / Chemistry / Physics" asks for two of those three.
     *
     * @param  array{count: int|null, each: bool, entries: list<list<string>>}  $set
     * @return array{int, list<list<string>>}
     */
    private function countAndEntries(array $set): array
    {
        $entries = $set['entries'];
        $count = $set['each'] ? count($entries) : (int) $set['count'];

        if (! $set['each'] && $count > 1 && count($entries) === 1) {
            $entries = array_map(fn (string $subject): array => [$subject], $entries[0]);
        }

        return [$count, $entries];
    }

    /**
     * Whether a line in a list is a subject entry, e.g. "Physics / Mathematics" or "Mathematics, DAN".
     */
    private function isSubjectLine(string $line): bool
    {
        return $line !== ''
            && preg_match(self::SENTENCE_START, $line) !== 1
            && preg_match(self::OTHER_SUBJECTS, $line) !== 1
            && preg_match(self::NOT_A_SUBJECT, (string) preg_replace(self::TRAILING_NOTE, '', $line)) !== 1
            && $this->subjectEntry($line) !== [];
    }

    /**
     * Subjects named in the line itself, e.g. "Gred B Dalam Mata Pelajaran Mathematical Methods DAN Physics Di
     * Peringkat AUSMAT", or null when none are named.
     *
     * @return list<string>|null
     */
    private function inlineSubjects(string $line): ?array
    {
        $text = $this->inlineText($line);

        if ($text === '' || preg_match('/^(berikut|lain|yang|di\s+bawah)\b/iu', $text) === 1 || preg_match(self::NOT_A_SUBJECT, $text) === 1) {
            return null;
        }

        $subjects = [];

        foreach (preg_split('/\s+dan\s+/iu', $text) ?: [] as $part) {
            array_push($subjects, ...$this->subjectEntry($part));
        }

        return $subjects === [] ? null : $subjects;
    }

    private function inlineText(string $line): string
    {
        if (preg_match('/mata\s+pelajaran\s*(?:berikut\s*)?[:]?\s*([^.;]*)/iu', $line, $matches) !== 1) {
            return '';
        }

        $text = (string) preg_replace('/\s*(?:di|pada)\s+peringkat\b.*$/iu', '', $matches[1]);

        return trim($text, " \t.;:-");
    }

    /**
     * The alternatives in a subject line, e.g. "Mathematics / Further Mathematics" or "Science (Physics / Chemistry /
     * Biology)", without the "DAN" or note that may follow.
     *
     * @return list<string>
     */
    private function subjectEntry(string $line): array
    {
        $line = (string) preg_replace(self::TRAILING_NOTE, '', $line);
        $line = (string) preg_replace('/[\s,;.]*\bdan\s*$/iu', '', $line);
        $line = (string) preg_replace('/^science\s*\((.+)\)$/iu', '$1', trim($line, " \t.;:,-·"));

        return SubjectName::alternatives($line);
    }

    /**
     * Subjects written without separators, e.g. "Economics Business Management Accounting", split into the subjects
     * the form offers, with each subject named as the form names it. A name that can't be split this way is kept as it is.
     *
     * @param  list<string>  $entry
     * @return list<string>
     */
    private function withSubjectsSplit(array $entry, string $qualification): array
    {
        $split = [];

        foreach ($entry as $subject) {
            $formName = $this->formName($subject, $qualification);

            if ($formName !== null) {
                $split[] = $formName;

                continue;
            }

            $words = preg_split('/\s+/u', trim($subject)) ?: [];
            $parts = [];
            $position = 0;
            $unmatched = [];

            while ($position < count($words)) {
                $match = null;

                for ($length = min(6, count($words) - $position); $length > 0; $length--) {
                    $phrase = implode(' ', array_slice($words, $position, $length));

                    if ($this->isKnownSubject($phrase, $qualification)) {
                        $match = [(string) $this->formName($phrase, $qualification), $length];

                        break;
                    }
                }

                // A word the form doesn't offer, e.g. "Accounting" for IB, is kept as a name of its own
                if ($match === null) {
                    $unmatched[] = $words[$position];
                    $position++;

                    continue;
                }

                if ($unmatched !== []) {
                    $parts[] = implode(' ', $unmatched);
                    $unmatched = [];
                }

                $parts[] = $match[0];
                $position += $match[1];
            }

            if ($unmatched !== []) {
                $parts[] = implode(' ', $unmatched);
            }

            $formNames = array_filter($parts, fn (string $part): bool => $this->isKnownSubject($part, $qualification));
            array_push($split, ...(count($formNames) > 1 ? $parts : [$subject]));
        }

        return $split;
    }

    /**
     * @param  list<string>|null  $gradeSets  Sets of grades, any of which is enough, e.g. ["BBB", "ABC", "AAC"].
     * @param  list<list<string>>|null  $entries  The subjects asked for, each a list of alternatives, or null for any subjects.
     * @return array<string, mixed>
     */
    private function subjectRule(string $qualification, string $grade, ?array $gradeSets, ?string $standardGrade, ?string $ibLevel, int $count, ?array $entries, bool $otherSubjects = false): array
    {
        return [
            'condition_type' => 'qualification_subject_requirement',
            'qualification' => $qualification,
            'minimum_grade' => $grade,
            'grade_sets' => $gradeSets,
            'standard_level_grade' => $standardGrade,
            'ib_level' => $ibLevel,
            'condition_count' => max(1, $count),
            'subjects' => $entries,
            'other_subjects' => $otherSubjects,
        ];
    }

    /**
     * e-Panduan's spacing slips, e.g. "Gre d B", "p Ada Peringkat" or "Higher Leve l", put right.
     */
    private function tidy(string $line): string
    {
        $line = (string) preg_replace(['/\bgre\s+d\b/iu', '/\bp\s+ada\b/iu', '/\bleve\s+l\b/iu', '/\bdiperingkat\b/iu', '/\s+/u'], ['Gred', 'Pada', 'Level', 'Di Peringkat', ' '], $line);

        return trim($line, " \t;");
    }
}

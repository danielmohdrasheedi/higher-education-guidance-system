<?php

namespace App\Eligibility;

use App\ApplicantCategory;
use App\Models\EligibilityRequirement;
use Illuminate\Support\Str;

/**
 * Checks an applicant's results against a programme's general and specific
 * entry requirements (FR02).
 *
 * The rules come from the e-Panduan collectors. General requirements are a
 * list of sections (SPM, STPM, MUET and so on) joined by "AND" / "OR" logic
 * entries, read from left to right. Specific requirements are a flat list
 * that must all be met. Wording the collectors could not turn into rules is
 * returned as notes for the applicant to check themselves.
 */
class EligibilityChecker
{
    /**
     * Requirement section headings mapped to the qualification they are written for.
     * Sections not listed here, such as "UMUM" and "MUET", apply to everyone.
     *
     * @var array<string, string>
     */
    private const SECTION_QUALIFICATIONS = [
        'SPM' => 'spm',
        'STPM' => 'stpm',
        'MATRIK / ASASI' => 'matriculation',
        'MATRIKULASI' => 'matriculation',
        'ASASI' => 'matriculation',
        'STAM' => 'stam',
        'KELAYAKAN SETARAF' => 'equivalent',
        'PRA-U' => 'prau',
    ];

    /**
     * @var array<string, string>
     */
    private const QUALIFICATION_NAMES = [
        'spm' => 'SPM',
        'stpm' => 'STPM',
        'matriculation' => 'matriculation or foundation',
        'stam' => 'STAM',
        'diploma' => 'diploma',
        'equivalent' => 'A-Level, IB, AUSMAT or another equivalent qualification',
        'prau' => 'Pra-U (Sekolah Sukan Malaysia)',
    ];

    /**
     * Condition types shown as notes instead of being checked. IELTS and TOEFL scores
     * stand in for MUET for applicants without it, and the form always asks for MUET.
     * A qualification the Senate decides on, or a diploma in a related field, sits beside requirements
     * for qualifications the course already accepts, so it is only checked when it is a choice of its own.
     *
     * @var list<string|null>
     */
    private const NOTE_TYPES = [null, 'unparsed_text', 'english_test_requirement', 'senate_approval_requirement', 'related_diploma_requirement'];

    /**
     * Names of subject groups written where a subject is expected, such as "Sains / Teknikal / Vokasional".
     * Each covers many subjects, and only the university knows which ones count.
     *
     * @var list<string>
     */
    private const SUBJECT_GROUP_NAMES = ['teknikal', 'vokasional'];

    /**
     * Where e-Panduan joins two requirements in one line, e.g. the "DAN" in "... Ujian Dikeluarkan. DAN Lulus Ujian
     * Psikologi". Only a "DAN" before a word that starts a requirement counts, so "Al-Quran DAN Al-Sunnah" or
     * "Anak Negeri Sarawak DAN Orang Asli" stay whole.
     */
    private const CLAUSE_BREAK = '/\s*[.;]?\s+DAN\s+(?=(?:Lulus|Melepasi|Mendapat|Memiliki|Mempunyai|Menyertakan|Cemerlang|Telah|Perlu|Calon|Kemasukan|Gred|Temu|Berkemahiran|Uji|Menduduki|Disahkan|Bebas|Tidak)\b)/u';

    /**
     * Words that mark a clause as a subject grade, e.g. "Gred B Dalam Mata Pelajaran Fizik Pada Peringkat SPM".
     */
    /**
     * A CGPA at the start of a line, e.g. "Mendapat Sekurang-Kurangnya PNGK 3.00", "Mendapat Sekurang-Kurangnya Cpa / PNGK
     * 2.50" or "Mendapat Sekurang-Kurangnya PNGK 2.75/Markah 80% Ke Atas", with the value captured.
     */
    private const CGPA_PHRASE = '/^mendapat\s+sekurang-?kurangnya\s+(?:purata\s+nilai\s+gred\s+kumulatif\s+)?\(?(?:pngk|cgpa|cpa)\)?(?:\s*\/\s*(?:pngk|cgpa|cpa))?\s*(\d\.\d{1,2})(?:\s*\/\s*markah\s+\d+\s*%\s*ke\s+atas)?/iu';

    private const SUBJECT_CLAUSE = '/\bgred\s+[a-e][+-]?(?=\s|$)|mata\s*pelajaran|peringkat\s+(spm|stpm)|sijil\s+pelajaran\s+malaysia/iu';

    /**
     * Subject keys offered on the academic profile form, by level.
     *
     * @var array<string, array<string, true>>
     */
    private array $knownSubjects = [];

    public function __construct(
        private readonly WrittenRequirementReader $writtenRequirements = new WrittenRequirementReader,
        private readonly QualificationResultsReader $qualificationResults = new QualificationResultsReader,
        private readonly RequirementNotes $requirementNotes = new RequirementNotes,
    ) {}

    public function checkRequirement(EligibilityRequirement $requirement, ApplicantResults $applicant): EligibilityResult
    {
        $generalLogic = $requirement->general_conditions_logic ?? [];
        $specificLogic = $requirement->specific_conditions_logic ?? [];

        if ($applicant->category === ApplicantCategory::DiplomaPolytechnicOrCommunityCollege && $requirement->exemptsPolytechnicDiplomasFromMuet()) {
            $generalLogic = $this->withMuetExempted($generalLogic);
            $specificLogic = $this->withMuetExempted($specificLogic);
        }

        $qualification = $this->resultsQualification($applicant->category);

        if ($qualification !== null) {
            $specificLogic = $this->qualificationResults->read($specificLogic, $qualification);
        }

        $specificLogic = $this->withLinesCompleted($specificLogic, $requirement->specific_conditions_content ?? []);
        $specificLogic = $this->withListsGathered($specificLogic, $requirement->specific_conditions_content ?? []);
        $specificLogic = $this->withLeftOutClausesKept($specificLogic, $requirement->specific_conditions_content ?? []);

        if ($requirement->lowersMuetForMsnSponsoredStudents()) {
            $specificLogic = $this->withMsnSponsoredBand($specificLogic);
        }

        return $this->check($generalLogic, $specificLogic, $applicant, $requirement->level);
    }

    /**
     * The qualification whose requirements the QualificationResultsReader reads for an applicant, or null for one
     * whose requirements the collectors already read.
     */
    private function resultsQualification(ApplicantCategory $category): ?string
    {
        return match ($category) {
            ApplicantCategory::ALevel => 'a_level',
            ApplicantCategory::InternationalBaccalaureateDiploma => 'ib',
            ApplicantCategory::AusmatSamCpu => 'ausmat',
            default => null,
        };
    }

    /**
     * Marks every MUET requirement as exempted, for a polytechnic or community college diploma graduate
     * applying to a course that exempts them from MUET.
     *
     * @template TLogic of array
     *
     * @param  TLogic  $logic
     * @return TLogic
     */
    private function withMuetExempted(array $logic): array
    {
        if (($logic['condition_type'] ?? null) === 'muet_requirement') {
            $logic['condition_type'] = 'muet_exemption';
        }

        return array_map(fn (mixed $value): mixed => is_array($value) ? $this->withMuetExempted($value) : $value, $logic);
    }

    /**
     * e-Panduan joins some requirements into one line with "DAN", e.g. "Mendapat Sekurang-Kurangnya BAND 2.0 Dalam
     * Malaysian University English Test (MUET) ... Dikeluarkan. DAN Lulus Ujian Psikologi DAN Temu Duga Yang
     * Ditetapkan.", and the rules read from that line keep only the first requirement, the MUET band. A requirement
     * left out of the rules this way is added as wording, so the applicant is told to check it instead of it being
     * lost. A clause counts as read when its words are in the rules, or, for a subject grade, when its subjects are.
     *
     * @param  list<array<string, mixed>|string>  $logic
     * @param  array<int, mixed>  $content
     * @return list<array<string, mixed>|string>
     */
    private function withLeftOutClausesKept(array $logic, array $content): array
    {
        $texts = [];
        $subjects = [];

        array_walk_recursive($logic, function (mixed $value) use (&$texts): void {
            if (is_string($value)) {
                $texts[] = $this->comparableText($value);
            }
        });

        $collectSubjects = function (mixed $node) use (&$collectSubjects, &$subjects): void {
            if (! is_array($node)) {
                return;
            }

            foreach ((array) ($node['subject_list'] ?? []) as $subject) {
                if (is_string($subject)) {
                    $subjects[SubjectName::key($subject, 'spm')] = true;
                    $subjects[SubjectName::key($subject, 'stpm')] = true;
                }
            }

            array_map($collectSubjects, $node);
        };
        $collectSubjects($logic);

        foreach ($content as $line) {
            $line = trim(is_array($line) ? (string) ($line['condition'] ?? '') : (string) $line);
            $leftOut = [];

            foreach (array_slice(preg_split(self::CLAUSE_BREAK, $line) ?: [], 1) as $clause) {
                $clause = trim($clause, " \t.;");

                if ($this->isClauseInRules($clause, $texts, $subjects)) {
                    $this->keepClauses($logic, $leftOut);

                    continue;
                }

                $leftOut[] = $clause;
            }

            $this->keepClauses($logic, $leftOut);
        }

        return $logic;
    }

    /**
     * Adds clauses left out of the rules and empties the list. A subject grade becomes a requirement of its own, so
     * the course isn't shown as one the applicant qualifies for without it: checked when it is read in full, and
     * left for the applicant to check otherwise. Other clauses, such as an interview, are joined back with "DAN"
     * into one line of wording, like the notes e-Panduan gives on their own.
     *
     * @param  list<array<string, mixed>|string>  $logic
     * @param  list<string>  $clauses
     */
    private function keepClauses(array &$logic, array &$clauses): void
    {
        $wording = [];

        foreach ([...$clauses, null] as $clause) {
            if ($clause !== null && ! $this->isSubjectClause($clause)) {
                $wording[] = $clause;

                continue;
            }

            if ($wording !== []) {
                $logic[] = ['condition_type' => 'unparsed_text', 'text' => implode(' DAN ', $wording).'.'];
                $wording = [];
            }

            if ($clause !== null) {
                array_push($logic, ...($this->spmSubjectRules($clause) ?? [['condition_type' => 'unread_subject_requirement', 'text' => $clause]]));
            }
        }

        $clauses = [];
    }

    /**
     * The subject rules in a clause that asks for SPM grades in named subjects, e.g. "Lulus Gred E Dalam Mata Pelajaran
     * Bahasa Inggeris Pada Peringkat SPM", "Lulus Matematik / Fizik / Sains Pada Peringkat SPM" (one of the three) or
     * "Lulus Gred E Additional Mathematics DAN Bahasa Inggeris" (both). "Gred B" sets the grade, "Kepujian" means C
     * and "Lulus" alone means E. Null when the clause asks for anything else, such as a number of unnamed subjects,
     * a list on the lines after it, or a subject not on the form, so it is left for the applicant to check.
     *
     * @return list<array<string, mixed>>|null
     */
    private function spmSubjectRules(string $clause): ?array
    {
        if (preg_match('/\bspm\b|sijil\s+pelajaran\s+malaysia/iu', $clause) !== 1
            || preg_match('/\b(berikut|mana-mana|satu|dua|tiga|lima|amali|stpm|matrikulasi|asasi|a\s*-?\s*level|ausmat)\b|\(\d\)/iu', $clause) === 1) {
            return null;
        }

        $grade = match (true) {
            preg_match('/\bgred\s+([a-e][+-]?)(?=\s|$)/iu', $clause, $matches) === 1 => mb_strtoupper($matches[1]),
            preg_match('/\bkepujian\b/iu', $clause) === 1 => 'C',
            preg_match('/\blulus\b/iu', $clause) === 1 => 'E',
            default => null,
        };

        if ($grade === null) {
            return null;
        }

        $named = (string) preg_replace(
            '/^(mendapat\s+)?(sekurang\S*\s+)?(lulus\s+spm(\s*\/\s*setaraf)?\s+dengan\s+)?(mendapat\s+)?(lulus\s+)?(kepujian\s+)?(gred\s+[a-e][+-]?\s+)?(\(kepujian\)\s+)?((dalam|bagi)\s+)?(mata\s*pelajaran\s+)?/iu',
            '',
            trim($clause),
        );
        $named = trim((string) preg_replace('/\s*\(spm\b.*$|\s+((pada|di)\s*peringkat|dalam\s+sijil)\b.*$|\s+atau\s+setara.*$/iu', '', $named));
        $rules = [];

        foreach (preg_split('/\s+dan\s+/iu', $named) ?: [] as $part) {
            $subjects = SubjectName::alternatives($part);

            if ($subjects === [] || ! array_all($subjects, fn (string $subject): bool => $this->isKnownSubject(SubjectName::key($subject, 'spm'), 'spm'))) {
                return null;
            }

            $rules[] = ['condition_type' => 'subject_requirement', 'condition_group' => 'SPM', 'condition_count' => 1, 'minimum_grade' => $grade, 'subject_list' => $subjects];
        }

        return $rules === [] ? null : $rules;
    }

    /**
     * @param  list<string>  $texts  The rules' words, made comparable.
     * @param  array<string, true>  $subjects  The subjects the rules name, by SubjectName::key().
     */
    private function isClauseInRules(string $clause, array $texts, array $subjects): bool
    {
        $words = $this->comparableText($clause);

        if (str_word_count($words) < 2) {
            return true;
        }

        $start = mb_substr($words, 0, 40);

        foreach ($texts as $text) {
            if (str_contains($text, $start)) {
                return true;
            }
        }

        if (! $this->isSubjectClause($clause)) {
            return false;
        }

        // A heading such as "... Dalam Mata Pelajaran Berikut" has its subjects on the lines after it, which are
        // read on their own; only subjects written after its colon are looked for here
        if (preg_match('/\bberikut\b/iu', $clause) === 1) {
            if (preg_match('/:\s*(?:[a-z]\)\s*)?(.+)$/iu', $clause, $listed) !== 1) {
                return true;
            }

            $clause = $listed[1];
        }

        $named = (string) preg_replace(
            '/^(mendapat\s+sekurang-?kurangnya(-kurangnya)?\s+)?(lulus\s+)?(kepujian\s+)?(gred\s+[a-e][+-]?\s+)?(\(kepujian\)\s+)?((dalam|bagi)\s+)?(mata\s*pelajaran\s+)?/iu',
            '',
            $clause,
        );
        $named = (string) preg_replace('/\s+((pada|di)\s*peringkat|dalam\s+sijil)\b.*$/iu', '', $named);
        $names = SubjectName::alternatives($named);

        return $names !== [] && array_all($names, fn (string $name): bool => isset($subjects[SubjectName::key($name, 'spm')]));
    }

    /**
     * Whether a clause asks for a subject grade, e.g. "Gred B Dalam Mata Pelajaran Fizik Pada Peringkat SPM". A clause
     * that only gives priority to a subject, or names a course taken at the university, doesn't.
     */
    private function isSubjectClause(string $clause): bool
    {
        return preg_match(self::SUBJECT_CLAUSE, $clause) === 1 && preg_match('/\bkeutamaan\b|\bdi\s+universiti\b/iu', $clause) !== 1;
    }

    /**
     * Words only, in lower case, so the same text compares equal however it is punctuated.
     */
    private function comparableText(string $text): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text)));
    }

    /**
     * e-Panduan breaks some sentences over two lines, e.g. "Memiliki Diploma Komputeran (Tahap 4, Mqf) ATAU Yang Setara
     * Dengan" and "Mendapat Sekurang-Kurangnya PNGK 2.50;". The second line is checked, so the first would be shown as a
     * note that stops mid-sentence. A line ending in "Dengan" (with) gets the next line of e-Panduan's wording added.
     * A CGPA in that next line that is already checked on its own is left out, so "Memiliki Diploma Dalam Bidang Yang
     * Sesuai Dengan" and "Mendapat Sekurang-Kurangnya PNGK 3.00 ATAU Kelayakan Lain Yang Diiktiraf Oleh Senat Upm" become
     * "Memiliki Diploma Dalam Bidang Yang Sesuai ATAU Kelayakan Lain Yang Diiktiraf Oleh Senat Upm".
     *
     * @template TLogic of array
     *
     * @param  TLogic  $logic
     * @param  array<int, mixed>  $content
     * @return TLogic
     */
    private function withLinesCompleted(array $logic, array $content): array
    {
        $lines = array_values(array_map(
            fn (mixed $line): string => trim(is_array($line) ? (string) ($line['condition'] ?? '') : (string) $line),
            $content,
        ));

        $checkedCgpas = [];
        $gatherCgpas = function (mixed $value) use (&$gatherCgpas, &$checkedCgpas): void {
            if (! is_array($value)) {
                return;
            }

            if (($value['condition_type'] ?? null) === 'cgpa_requirement' && is_numeric($value['condition_value'] ?? null)) {
                $checkedCgpas[] = number_format((float) $value['condition_value'], 2);
            }

            array_walk($value, $gatherCgpas);
        };
        $gatherCgpas($logic);

        $complete = function (mixed $value) use (&$complete, $lines, $checkedCgpas): mixed {
            if (! is_array($value)) {
                return $value;
            }

            if (($value['condition_type'] ?? null) === 'unparsed_text' && is_string($value['text'] ?? null) && preg_match('/\bdengan$/iu', trim($value['text'])) === 1) {
                $index = array_search(trim($value['text']), $lines, true);
                $next = $index === false ? null : ($lines[$index + 1] ?? null);

                if ($next !== null && $next !== '' && preg_match('/^atau$/iu', $next) !== 1) {
                    // Only the requirement that ends the sentence; others joined after it with "DAN" are kept on their own
                    $next = rtrim((preg_split(self::CLAUSE_BREAK, $next) ?: [$next])[0], ' ;');
                    $isCgpaChecked = preg_match(self::CGPA_PHRASE, $next, $matches) === 1
                        && in_array(number_format((float) $matches[1], 2), $checkedCgpas, true);

                    if (! $isCgpaChecked) {
                        $value['text'] = trim($value['text']).' '.$next;

                        return $value;
                    }

                    $rest = trim((string) preg_replace(self::CGPA_PHRASE, '', $next, 1), " ;.\t");
                    $value['text'] = $rest === '' || preg_match('/^(atau|dalam|dari|daripada)\b/iu', $rest) === 1
                        ? trim((string) preg_replace('/\s+dengan$/iu', '', trim($value['text'])).' '.$rest)
                        : trim($value['text']).' '.$rest;
                }

                return $value;
            }

            return array_map($complete, $value);
        };

        return $complete($logic);
    }

    /**
     * e-Panduan writes a list as a line naming it, e.g. "Memiliki ... Diploma Daripada Lembaga Peperiksaan Muzik Seperti
     * Berikut", then one line an item: "Associated Board Of The Royal Schools Of Music (Abrsm);", "Trinity Guildhall;".
     * The items are kept with the line naming them, one a line, so they are shown as a list. Only items the site
     * doesn't check are gathered, the ones kept as wording or left out of the rules altogether, and the list ends
     * at "ATAU" or at a MUET band or CGPA.
     *
     * @template TLogic of array
     *
     * @param  TLogic  $logic
     * @param  array<int, mixed>  $content
     * @return TLogic
     */
    private function withListsGathered(array $logic, array $content): array
    {
        $lines = array_values(array_map(
            fn (mixed $line): string => trim(is_array($line) ? (string) ($line['condition'] ?? '') : (string) $line),
            $content,
        ));

        $wording = [];
        array_walk_recursive($logic, function (mixed $value, int|string $key) use (&$wording): void {
            if ($key === 'text' && is_string($value)) {
                $wording[trim($value)] = true;
            }
        });
        // The rules without their wording, to tell whether a line was turned into a rule that is checked
        $withoutWording = function (mixed $value) use (&$withoutWording): mixed {
            return is_array($value) ? array_map($withoutWording, array_diff_key($value, ['text' => true])) : $value;
        };
        $rules = mb_strtolower((string) json_encode($withoutWording($logic), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $isUnchecked = fn (string $line): bool => isset($wording[$line]) || ! str_contains($rules, mb_strtolower(rtrim($line, ' ;.')));

        $gathered = [];

        $gather = function (mixed $value) use (&$gather, &$gathered, $lines, $isUnchecked): mixed {
            if (! is_array($value)) {
                return $value;
            }

            $text = is_string($value['text'] ?? null) ? trim($value['text']) : null;

            if ($text !== null && ! str_contains($text, "\n") && preg_match('/\bberikut\b|\bmerangkumi\s*:?$/iu', $text) === 1) {
                $index = array_search($text, $lines, true);
                $items = [];

                for ($next = $index === false ? count($lines) : $index + 1; $next < count($lines); $next++) {
                    $line = $lines[$next];

                    if ($line === '' || preg_match('/^atau$|\bband\s+\d|\bpngk\b/iu', $line) === 1 || ! $isUnchecked($line)) {
                        break;
                    }

                    $items[] = $line;
                }

                if ($items !== []) {
                    $value['text'] = $text."\n".implode("\n", $items);
                    $gathered += array_fill_keys($items, true);
                }
            }

            return array_map($gather, $value);
        };

        $logic = $gather($logic);

        // The gathered items are no longer listed on their own
        $withoutGathered = function (mixed $value) use (&$withoutGathered, $gathered): mixed {
            if (! is_array($value)) {
                return $value;
            }

            if (array_is_list($value)) {
                $value = array_values(array_filter($value, function (mixed $item) use ($gathered): bool {
                    $text = is_string($item) ? $item : (is_array($item) && ($item['condition_type'] ?? null) === 'unparsed_text' ? ($item['text'] ?? null) : null);

                    return ! is_string($text) || ! isset($gathered[trim($text)]);
                }));
            }

            return array_map($withoutGathered, $value);
        };

        return $withoutGathered($logic);
    }

    /**
     * Turns "MUET Band 3.0" and "MUET Band 2.0" listed one after the other into a choice between the higher band and the
     * lower band for students sponsored by the National Sports Council (MSN). Otherwise both bands would be asked for.
     *
     * @param  list<array<string, mixed>|string>  $logic
     * @return list<array<string, mixed>|string>
     */
    private function withMsnSponsoredBand(array $logic): array
    {
        $muetConditions = array_filter($logic, fn (array|string $condition): bool => is_array($condition) && ($condition['condition_type'] ?? null) === 'muet_requirement');
        $bands = array_map(fn (array $condition): float => (float) ($condition['condition_value'] ?? 0), $muetConditions);

        if (count(array_unique($bands)) !== 2) {
            return $logic;
        }

        $higherKey = array_search(max($bands), $bands, true);
        $lowerKey = array_search(min($bands), $bands, true);
        $rewritten = [];

        foreach ($logic as $key => $condition) {
            if ($key === $higherKey) {
                $rewritten[] = [
                    'condition_type' => 'alternative_requirements',
                    'alternatives' => [
                        [$condition],
                        [$logic[$lowerKey], ['condition_type' => 'msn_sponsorship_requirement']],
                    ],
                ];
            } elseif ($key !== $lowerKey) {
                $rewritten[] = $condition;
            }
        }

        return $rewritten;
    }

    /**
     * @param  list<array<string, mixed>>  $generalLogic
     * @param  list<array<string, mixed>|string>  $specificLogic
     * @param  string  $programmeLevel  "spm" or "stpm", the e-Panduan level the programme is listed under.
     */
    public function check(array $generalLogic, array $specificLogic, ApplicantResults $applicant, string $programmeLevel): EligibilityResult
    {
        if ($generalLogic === [] && $specificLogic === []) {
            return new EligibilityResult(RequirementStatus::Unknown, [
                new ConditionResult(RequirementStatus::Unknown, 'Entry requirements', 'No entry requirements are listed on e-Panduan.', canBeChecked: false),
            ]);
        }

        $general = $this->checkGeneralLogic($generalLogic, $applicant, $programmeLevel);
        $specific = $this->checkConditions($specificLogic, $applicant, $programmeLevel, null, skipOtherQualifications: true);
        $notes = array_values(array_unique([...$general->notes, ...$specific->notes]));
        $unchecked = array_values(array_filter($notes, $this->requirementNotes->isRequirement(...)));

        // Wording the site can't check that asks for something, e.g. "Calon Tidak Buta Warna", is a requirement
        // left for the applicant, so the course isn't one they're known to qualify for
        return new EligibilityResult(
            RequirementStatus::all($general->status, $specific->status, ...($unchecked === [] ? [] : [RequirementStatus::Unknown])),
            [...$general->conditions, ...$specific->conditions, ...$this->wordingConditions($unchecked)],
            array_values(array_diff($notes, $unchecked)),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    private function checkGeneralLogic(array $sections, ApplicantResults $applicant, string $programmeLevel): EligibilityResult
    {
        $combined = null;
        $operator = 'AND';

        foreach ($sections as $section) {
            $group = (string) ($section['condition_group'] ?? '');

            if ($group === 'Logic') {
                $operator = strtoupper((string) $section['conditions']);

                continue;
            }

            $conditions = is_array($section['conditions'] ?? null) ? array_values(array_filter($section['conditions'], fn (mixed $condition): bool => ! $this->isQualificationStatement($condition))) : [];
            $result = $this->checkSection($group, $conditions, $applicant, $programmeLevel);
            $combined = $combined === null ? $result : $this->combine($combined, $result, $operator);
            $operator = 'AND';
        }

        return $combined ?? new EligibilityResult(RequirementStatus::Met, []);
    }

    /**
     * Whether a general requirement only says the applicant holds the qualification they apply with, e.g. "Mempunyai
     * Kelulusan Diploma IPTA / ILKA / IPTS ATAU Kelulusan Lain Yang Diiktiraf Setaraf ..." for diploma holders, or
     * "Diluluskan Oleh Senat UA." for everyone. The applicant's category already says this, so it isn't shown.
     */
    private function isQualificationStatement(mixed $condition): bool
    {
        $text = is_string($condition) ? $condition : (is_array($condition) && ($condition['condition_type'] ?? null) === 'unparsed_text' ? ($condition['text'] ?? null) : null);

        return is_string($text) && preg_match(
            '/^(mempunyai\s+kelulusan\s+diploma\b|lulusan\s+pra-u\b|diluluskan\s+oleh\s+senat\s+ua\.?$|diploma\s+vokasional\s+malaysia\s+\(dvm\))/iu',
            trim($text),
        ) === 1;
    }

    /**
     * @param  list<array<string, mixed>|string>  $conditions
     */
    private function checkSection(string $group, array $conditions, ApplicantResults $applicant, string $programmeLevel): EligibilityResult
    {
        $qualification = $this->qualificationForGroup($group);

        if ($qualification !== null && ! $applicant->holds($qualification)) {
            return new EligibilityResult(RequirementStatus::NotMet, [
                new ConditionResult(RequirementStatus::NotMet, $this->qualificationDescription($qualification), "You haven't added this qualification."),
            ]);
        }

        return $this->checkConditions($conditions, $applicant, $programmeLevel, $qualification);
    }

    /**
     * Check a list of conditions that must all be met.
     *
     * @param  list<array<string, mixed>|string>  $conditions
     * @param  string|null  $sectionQualification  The qualification of the section the conditions belong to, if any.
     * @param  bool  $skipOtherQualifications  Skip conditions written for a qualification the applicant does not hold.
     */
    private function checkConditions(
        array $conditions,
        ApplicantResults $applicant,
        string $programmeLevel,
        ?string $sectionQualification,
        bool $skipOtherQualifications = false,
    ): EligibilityResult {
        $results = [];
        $statuses = [];
        $notes = [];
        $countedSubjects = [];
        $writtenRules = [];

        $conditions = $this->withSubjectChoicesInsideRoute($conditions);
        $conditions = $this->withFirstRouteInsideChoice($conditions);
        $conditions = $this->withSharedRequirementsOutsideMuetChoice($conditions);

        foreach ($this->withEnglishTestsBesideMuet($conditions) as $condition) {
            array_push($writtenRules, ...$this->writtenRequirements->read($condition));

            if (is_string($condition)) {
                $note = $this->writtenRequirements->withoutCheckedText($condition);

                if ($note !== null) {
                    $notes[] = $note;
                }

                continue;
            }

            $type = $condition['condition_type'] ?? null;

            if (in_array($type, self::NOTE_TYPES, true)) {
                $note = isset($condition['text']) ? $this->writtenRequirements->withoutCheckedText((string) $condition['text']) : null;

                if ($note !== null) {
                    $notes[] = $note;
                }

                continue;
            }

            if ($type === 'alternative_requirements') {
                $outcome = $this->checkAlternatives($condition['alternatives'] ?? [], $applicant, $programmeLevel, $sectionQualification, $skipOtherQualifications);
                $statuses[] = $outcome->status;
                array_push($results, ...$outcome->conditions);
                array_push($notes, ...$outcome->notes);

                continue;
            }

            $qualification = $this->conditionQualification($condition, $sectionQualification);

            if ($skipOtherQualifications && $qualification !== null && ! $applicant->holds($qualification)) {
                continue;
            }

            $results[] = $result = match ($type) {
                'cgpa_requirement' => $this->checkCgpa($condition, $applicant),
                'experience_requirement' => $this->checkExperience($condition),
                'diploma_field_requirement' => $this->checkDiplomaField($condition),
                'age_requirement' => $this->checkAge($condition, $applicant),
                'sports_requirement' => $this->checkSports($condition, $applicant),
                'specialisation_requirement' => $this->checkSpecialisation($condition, $qualification),
                'muet_requirement' => $this->checkMuet($condition, $applicant),
                'muet_exemption' => new ConditionResult(
                    RequirementStatus::Met,
                    sprintf('MUET Band %.1f or higher', (float) $condition['condition_value']),
                    "Polytechnic and community college diploma graduates don't need MUET for this course.",
                ),
                'msn_sponsorship_requirement' => new ConditionResult(
                    RequirementStatus::Unknown,
                    'Sponsored by the National Sports Council (MSN)',
                    'Only MSN-sponsored students can enter with this lower band. Your results don\'t show sponsorship, so check it with the university.', canBeChecked: false,
                ),
                'stam_requirement' => $this->checkStam($condition, $applicant),
                'english_test_score_requirement' => $this->checkEnglishTestScore($condition, $applicant),
                'subject_requirement', 'subject_group_requirement', 'any_subject_requirement' => $this->checkSubjects(
                    $condition,
                    $applicant,
                    $this->subjectLevel($condition, $qualification, $programmeLevel, $applicant),
                    $countedSubjects,
                ),
                'qualification_subject_requirement' => $this->checkQualificationSubjects($condition, $applicant, $countedSubjects),
                'qualification_score_requirement' => $this->checkQualificationScore($condition, $applicant),
                'qualification_wording' => new ConditionResult(RequirementStatus::Unknown, (string) $condition['description'], (string) $condition['reason'], canBeChecked: false),
                'unread_subject_requirement' => new ConditionResult(
                    RequirementStatus::Unknown,
                    (string) $condition['text'],
                    "This site couldn't read this subject requirement, so it isn't checked against your results.",
                    canBeChecked: false,
                ),
                default => new ConditionResult(RequirementStatus::Unknown, 'Other requirement', 'This requirement could not be read.', canBeChecked: false),
            };
            $statuses[] = $result->status;
        }

        foreach ($this->writtenRequirements->combine($writtenRules) as $rule) {
            $results[] = $result = $this->checkWrittenRule($rule, $applicant);
            $statuses[] = $result->status;
        }

        return new EligibilityResult(RequirementStatus::all(...$statuses), $results, $notes);
    }

    /**
     * Check a requirement read from e-Panduan's words by the WrittenRequirementReader.
     *
     * @param  array<string, mixed>  $rule
     */
    private function checkWrittenRule(array $rule, ApplicantResults $applicant): ConditionResult
    {
        return match ($rule['condition_type']) {
            'descent_requirement' => $this->checkDescent($applicant),
            'colour_vision_requirement' => $this->checkColourVision($applicant),
            'music_grade_requirement' => $this->checkMusicGrade($rule, $applicant),
            'quran_memorisation_requirement' => $this->checkQuranMemorisation($rule, $applicant),
            'age_requirement' => $this->checkAge($rule, $applicant),
            'experience_requirement' => $this->checkExperience($rule),
            'diploma_or_equivalent_requirement' => $this->checkDiplomaOrEquivalent($applicant),
            'qualification_statement' => $this->checkQualificationStatement($rule['statement'], $applicant),
            'diploma_kind_requirement' => new ConditionResult(
                RequirementStatus::Unknown,
                (string) $rule['text'],
                $applicant->category->acceptsDiplomaResults()
                    ? "This site doesn't ask what your diploma is in or where it's from. Check with the university."
                    : $this->fieldReason($applicant),
                canBeChecked: false,
            ),
            'gender_requirement' => new ConditionResult(
                RequirementStatus::fromBoolean($applicant->gender === $rule['gender']),
                $rule['gender'] === 'male' ? 'Open to men only' : 'Open to women only',
            ),
        };
    }

    /**
     * An "ATAU" between subject requirements inside one route, e.g. "Diploma in Science and Technology", "CGPA 2.75",
     * "SPM Matematik Tambahan C", "ATAU", "SPM Matematik C and one science subject C", is read as a choice between
     * the whole route and the subjects alone. The subjects after "ATAU" are another way to meet the route's last
     * subject requirements, so they become a choice inside that route.
     *
     * @param  list<array<string, mixed>|string>  $conditions
     * @return list<array<string, mixed>|string>
     */
    private function withSubjectChoicesInsideRoute(array $conditions): array
    {
        $isSubject = fn (mixed $item): bool => is_array($item) && in_array($item['condition_type'] ?? null, ['subject_requirement', 'subject_group_requirement', 'any_subject_requirement'], true);
        $isSubjectChoice = fn (mixed $item): bool => is_array($item) && ($item['subject_choice'] ?? false) === true;

        $rewritten = [];

        foreach ($conditions as $condition) {
            if (! is_array($condition) || ($condition['condition_type'] ?? null) !== 'alternative_requirements') {
                $rewritten[] = $condition;

                continue;
            }

            $options = [];

            foreach ($condition['alternatives'] ?? [] as $option) {
                $previous = $options === [] ? null : array_key_last($options);
                $isSubjectsOnly = $option !== [] && array_filter($option, fn (mixed $item): bool => ! $isSubject($item)) === [];

                if ($previous === null || ! $isSubjectsOnly) {
                    $options[] = $option;

                    continue;
                }

                $route = $options[$previous];
                $last = end($route);

                // A further "ATAU" adds another way to the choice made just before
                if ($isSubjectChoice($last)) {
                    $route[array_key_last($route)]['alternatives'][] = $option;
                    $options[$previous] = $route;

                    continue;
                }

                $trailing = [];

                while ($route !== [] && $isSubject(end($route))) {
                    array_unshift($trailing, array_pop($route));
                }

                // The previous option is subjects only too, so this is a choice between whole options as it is
                if ($trailing === [] || $route === []) {
                    $options[] = $option;

                    continue;
                }

                $route[] = ['condition_type' => 'alternative_requirements', 'subject_choice' => true, 'alternatives' => [$trailing, $option]];
                $options[$previous] = $route;
            }

            // Only one route is left, so it isn't a choice any more
            if (count($options) === 1) {
                array_push($rewritten, ...$options[0]);

                continue;
            }

            $rewritten[] = [...$condition, 'alternatives' => $options];
        }

        return $rewritten;
    }

    /**
     * Some courses offer two routes that each end with the same MUET band, e.g. "Grade C in three science subjects",
     * "MUET Band 2.0", "ATAU", "CGPA 2.00 in engineering", "Grade C in three SPM subjects", "MUET Band 2.0". The first
     * route's requirements can end up before the choice, leaving only its MUET band inside it, so they would be asked
     * of every applicant. When the first option is that lone MUET band and the second option ends with the same band,
     * the requirements listed just before the choice are moved into the first option.
     *
     * @param  list<array<string, mixed>|string>  $conditions
     * @return list<array<string, mixed>|string>
     */
    private function withFirstRouteInsideChoice(array $conditions): array
    {
        $isMuet = fn (mixed $item): bool => is_array($item) && ($item['condition_type'] ?? null) === 'muet_requirement';
        $rewritten = [];

        foreach ($conditions as $condition) {
            $options = is_array($condition) && ($condition['condition_type'] ?? null) === 'alternative_requirements' ? ($condition['alternatives'] ?? []) : [];

            if (count($options) !== 2) {
                $rewritten[] = $condition;

                continue;
            }

            [$first, $second] = $options;
            $lastOfSecond = end($second);
            $firstIsLoneBand = count($first) === 1 && $isMuet($first[0]);
            $secondIsRoute = count($second) > 1 && $isMuet($lastOfSecond) && (float) ($lastOfSecond['condition_value'] ?? -1) === (float) ($first[0]['condition_value'] ?? -2);

            $before = [];

            while ($firstIsLoneBand && $secondIsRoute && $rewritten !== [] && is_array(end($rewritten)) && ! in_array(end($rewritten)['condition_type'] ?? null, ['alternative_requirements', 'muet_requirement'], true)) {
                array_unshift($before, array_pop($rewritten));
            }

            $rewritten[] = $before === [] ? $condition : [...$condition, 'alternatives' => [[...$before, ...$first], $second]];
        }

        return $rewritten;
    }

    /**
     * The usual English choice is "MUET Band 3.0 ATAU MUET Band 2.0 and SPM Bahasa Inggeris grade C". When e-Panduan
     * lists another requirement between the higher band and "ATAU", e.g. "Band 3.0", "Grade B in Mathematics and
     * Physics", "ATAU", "Band 2.0", "SPM English C", that requirement ends up in the first option only. It applies
     * whichever band the applicant has, as the same course states for STPM applicants, so it is moved out of the choice.
     *
     * @param  list<array<string, mixed>|string>  $conditions
     * @return list<array<string, mixed>|string>
     */
    private function withSharedRequirementsOutsideMuetChoice(array $conditions): array
    {
        $muetBand = function (array $option): ?float {
            foreach ($option as $item) {
                if (is_array($item) && ($item['condition_type'] ?? null) === 'muet_requirement' && isset($item['condition_value'])) {
                    return (float) $item['condition_value'];
                }
            }

            return null;
        };
        $isSpmEnglish = fn (array|string $item): bool => is_array($item)
            && ($item['condition_type'] ?? null) === 'subject_requirement'
            && ($item['condition_group'] ?? null) === 'SPM'
            && is_array($item['subject_list'] ?? null)
            && array_filter($item['subject_list'], fn (mixed $subject): bool => ! is_string($subject) || ! preg_match('/^bahasa inggeris\b/iu', $subject)) === [];

        $rewritten = [];

        foreach ($conditions as $condition) {
            $options = is_array($condition) && ($condition['condition_type'] ?? null) === 'alternative_requirements' ? ($condition['alternatives'] ?? []) : [];

            if (count($options) !== 2) {
                $rewritten[] = $condition;

                continue;
            }

            [$higher, $lower] = $options;
            $higherBand = $muetBand($higher);
            $lowerBand = $muetBand($lower);
            $lowerIsBandAndEnglish = count($lower) === 2 && array_filter($lower, $isSpmEnglish) !== [];

            $higherHasOnlyBandBesideOthers = array_filter($higher, $isSpmEnglish) === [];

            if ($higherBand === null || $lowerBand === null || $higherBand <= $lowerBand || ! $lowerIsBandAndEnglish || ! $higherHasOnlyBandBesideOthers || count($higher) < 2) {
                $rewritten[] = $condition;

                continue;
            }

            $higherMuet = array_values(array_filter($higher, fn (array|string $item): bool => is_array($item) && ($item['condition_type'] ?? null) === 'muet_requirement'));
            $shared = array_values(array_filter($higher, fn (array|string $item): bool => ! is_array($item) || ($item['condition_type'] ?? null) !== 'muet_requirement'));

            array_push($rewritten, ...$shared);
            $rewritten[] = [...$condition, 'alternatives' => [$higherMuet, $lower]];
        }

        return $rewritten;
    }

    /**
     * IELTS and TOEFL scores listed beside a MUET band are other ways to meet the English requirement,
     * e.g. "MUET Band 3.0", "Mendapat Sekurangnya BAND 4.5 (Academic) Dalam Ielts ; ATAU",
     * "Mendapat Sekurangnya Skor 33 Mata Dalam Toefl Ibt". They are turned into a choice between
     * options, the MUET band first. Without a MUET band beside them they stay notes, since the form
     * always asks for MUET.
     *
     * @param  list<array<string, mixed>|string>  $conditions
     * @return list<array<string, mixed>|string>
     */
    private function withEnglishTestsBesideMuet(array $conditions): array
    {
        $isEnglishTest = fn (array|string $condition): bool => is_array($condition) && ($condition['condition_type'] ?? null) === 'english_test_requirement';
        $isMuet = fn (array|string $condition): bool => is_array($condition) && ($condition['condition_type'] ?? null) === 'muet_requirement';
        $asScore = fn (array $condition): array => ['condition_type' => 'english_test_score_requirement', 'text' => $condition['text'] ?? ''];

        $englishTests = array_values(array_filter($conditions, $isEnglishTest));

        if ($englishTests !== [] && array_filter($conditions, $isMuet) !== []) {
            $choice = null;
            $rewritten = [];

            foreach ($conditions as $condition) {
                if ($isEnglishTest($condition)) {
                    continue;
                }

                if ($isMuet($condition) && $choice === null) {
                    $rewritten[] = $choice = [
                        'condition_type' => 'alternative_requirements',
                        'alternatives' => [[$condition], ...array_map(fn (array $test): array => [$asScore($test)], $englishTests)],
                    ];

                    continue;
                }

                $rewritten[] = $condition;
            }

            return $rewritten;
        }

        return array_map(function (array|string $condition) use ($isEnglishTest, $isMuet, $asScore): array|string {
            if (! is_array($condition) || ($condition['condition_type'] ?? null) !== 'alternative_requirements') {
                return $condition;
            }

            $alternatives = $condition['alternatives'] ?? [];
            $hasMuetOption = array_filter($alternatives, fn (array $option): bool => array_filter($option, $isMuet) !== []) !== [];

            if (! $hasMuetOption) {
                return $condition;
            }

            $condition['alternatives'] = array_map(
                fn (array $option): array => array_map(fn (array|string $item): array|string => $isEnglishTest($item) ? $asScore($item) : $item, $option),
                $alternatives,
            );

            return $condition;
        }, $conditions);
    }

    /**
     * Check sets of requirements joined by "ATAU" (or), where meeting one set is enough.
     *
     * A set made only of requirements for a qualification the applicant does not hold
     * is not open to them, and a set with nothing that can be checked is unknown.
     * When no set has anything that can be checked, such as a list of accepted
     * diplomas, the sets are shown as notes like any other wording that is not checked.
     *
     * @param  list<list<array<string, mixed>|string>>  $alternatives
     */
    private function checkAlternatives(
        array $alternatives,
        ApplicantResults $applicant,
        string $programmeLevel,
        ?string $sectionQualification,
        bool $skipOtherQualifications,
    ): EligibilityResult {
        $statuses = [];
        $conditions = [];
        $notes = [];

        if (! array_filter($alternatives, $this->hasCheckableCondition(...))) {
            return $this->checkConditions(array_merge(...$alternatives ?: [[]]), $applicant, $programmeLevel, $sectionQualification, $skipOtherQualifications);
        }

        foreach ($alternatives as $index => $alternative) {
            $outcome = $this->checkAlternative($alternative, $applicant, $programmeLevel, $sectionQualification, $skipOtherQualifications);

            if ($outcome->status === RequirementStatus::Met) {
                return $outcome;
            }

            $statuses[] = $outcome->status;
            array_push($notes, ...$outcome->notes);
            array_push($conditions, ...array_map(
                // A choice inside an option keeps its own option numbers, so this option is named in the wording instead
                fn (ConditionResult $result): ConditionResult => $result->option === null
                    ? new ConditionResult($result->status, $result->description, $result->reason, $index + 1, count($alternatives), $result->canBeChecked)
                    : new ConditionResult($result->status, sprintf('Option %d of %d: %s', $index + 1, count($alternatives), $result->description), $result->reason, $result->option, $result->optionCount, $result->canBeChecked),
                $outcome->conditions,
            ));
        }

        return new EligibilityResult($statuses === [] ? RequirementStatus::Unknown : RequirementStatus::any(...$statuses), $conditions, $notes);
    }

    /**
     * Requirements of an option the site can't check, each in e-Panduan's words.
     *
     * @param  list<string>  $notes
     * @return list<ConditionResult>
     */
    private function wordingConditions(array $notes): array
    {
        return array_map(
            fn (string $note): ConditionResult => new ConditionResult(RequirementStatus::Unknown, $note, "This site can't check this.", canBeChecked: false),
            array_values(array_unique($notes)),
        );
    }

    /**
     * @param  list<array<string, mixed>|string>  $conditions
     */
    private function hasCheckableCondition(array $conditions): bool
    {
        foreach ($conditions as $condition) {
            if (is_array($condition) && ! in_array($condition['condition_type'] ?? null, self::NOTE_TYPES, true)) {
                return true;
            }

            if ($this->writtenRequirements->read($condition) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>|string>  $conditions
     */
    private function checkAlternative(
        array $conditions,
        ApplicantResults $applicant,
        string $programmeLevel,
        ?string $sectionQualification,
        bool $skipOtherQualifications,
    ): EligibilityResult {
        $checkable = array_filter(
            $conditions,
            fn (array|string $condition): bool => is_array($condition) && ! in_array($condition['condition_type'] ?? null, self::NOTE_TYPES, true),
        );
        $qualifications = array_map(
            fn (array $condition): ?string => $this->conditionQualification($condition, $sectionQualification),
            $checkable,
        );
        $heldQualifications = array_filter($qualifications, fn (?string $qualification): bool => $qualification === null || $applicant->holds($qualification));

        if ($skipOtherQualifications && $checkable !== [] && $heldQualifications === []) {
            $qualification = (string) reset($qualifications);

            return new EligibilityResult(RequirementStatus::NotMet, [
                new ConditionResult(RequirementStatus::NotMet, $this->qualificationDescription($qualification), "You haven't added this qualification."),
            ]);
        }

        $outcome = $this->checkConditions($conditions, $applicant, $programmeLevel, $sectionQualification, $skipOtherQualifications);

        if ($outcome->conditions === []) {
            $senateApproval = array_find(
                $conditions,
                fn (array|string $condition): bool => is_array($condition) && ($condition['condition_type'] ?? null) === 'senate_approval_requirement',
            );

            if ($senateApproval !== null) {
                return new EligibilityResult(RequirementStatus::Unknown, [$this->checkSenateApproval($senateApproval)]);
            }

            $relatedDiploma = array_find(
                $conditions,
                fn (array|string $condition): bool => is_array($condition) && ($condition['condition_type'] ?? null) === 'related_diploma_requirement',
            );

            if ($relatedDiploma !== null) {
                $result = $this->checkRelatedDiploma($applicant);

                return new EligibilityResult($result->status, [$result]);
            }

            if ($outcome->notes === []) {
                return new EligibilityResult(RequirementStatus::Unknown, [
                    new ConditionResult(RequirementStatus::Unknown, 'These requirements', 'This option could not be read. Check it on e-Panduan.', canBeChecked: false),
                ]);
            }

            return new EligibilityResult(RequirementStatus::Unknown, $this->wordingConditions($outcome->notes));
        }

        // What this option asks for beyond its checked requirements is shown within the option. A requirement among it
        // is left for the applicant, so meeting the rest isn't enough to qualify through this option
        $status = array_filter($outcome->notes, $this->requirementNotes->isRequirement(...)) === []
            ? $outcome->status
            : RequirementStatus::all($outcome->status, RequirementStatus::Unknown);

        return new EligibilityResult($status, [...$outcome->conditions, ...$this->wordingConditions($outcome->notes)]);
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private function checkCgpa(array $condition, ApplicantResults $applicant): ConditionResult
    {
        $value = (float) $condition['condition_value'];
        $isMaximum = ($condition['condition_operator'] ?? '>=') === '<=';
        $description = sprintf($isMaximum ? 'A CGPA of %.2f or lower' : 'A CGPA of %.2f or higher', $value);

        if ($applicant->cgpa === null) {
            return new ConditionResult(RequirementStatus::Unknown, $description, 'Add your CGPA to check this.');
        }

        return new ConditionResult(
            RequirementStatus::fromBoolean($isMaximum ? $applicant->cgpa <= $value : $applicant->cgpa >= $value),
            $description,
            sprintf('Your CGPA is %.2f.', $applicant->cgpa),
        );
    }

    /**
     * Check an IB, ATAR or AUSMAT aggregate score read by the QualificationResultsReader.
     *
     * @param  array<string, mixed>  $condition
     */
    private function checkQualificationScore(array $condition, ApplicantResults $applicant): ConditionResult
    {
        $minimum = $this->scoreText((float) $condition['minimum']);

        [$description, $score, $missing, $yours] = match ($condition['measure']) {
            'ib_points' => ["At least {$minimum} points in the IB Diploma", $applicant->ibPoints, 'Add your IB total points to check this.', 'Your IB total is %s points.'],
            'atar' => ["An ATAR of at least {$minimum}", $applicant->atar, 'Add your ATAR to check this.', 'Your ATAR is %s.'],
            default => ["An AUSMAT aggregate of at least {$minimum}", $applicant->ausmatAggregate, 'Add your AUSMAT aggregate to check this.', 'Your aggregate is %s.'],
        };

        if ($score === null) {
            return new ConditionResult(RequirementStatus::Unknown, $description, $missing);
        }

        return new ConditionResult(
            RequirementStatus::fromBoolean($score >= (float) $condition['minimum']),
            $description,
            sprintf($yours, $this->scoreText((float) $score)),
        );
    }

    /**
     * A score without trailing zeros, e.g. "85" or "85.5".
     */
    private function scoreText(float $score): string
    {
        return rtrim(rtrim(number_format($score, 2, '.', ''), '0'), '.');
    }

    /**
     * Check an A-Level, IB or AUSMAT subject requirement read by the QualificationResultsReader. Each subject counts
     * once: towards one entry of the list, and not again for a later part of the same requirement or for "other
     * subjects". A subject the form doesn't offer, such as IB Accounting, may be one the applicant took, so a
     * requirement that could be met through one is left for the applicant to check.
     *
     * @param  array<string, mixed>  $rule
     * @param  array<string, array<string, true>>  $countedSubjects  Subjects already counted, by level and by requirement.
     */
    private function checkQualificationSubjects(array $rule, ApplicantResults $applicant, array &$countedSubjects): ConditionResult
    {
        $qualification = $rule['qualification'];
        $level = QualificationResultsReader::SUBJECT_LEVELS[$qualification];
        $name = QualificationResultsReader::NAMES[$qualification];
        $description = QualificationResultsReader::describe($rule);
        $count = (int) $rule['condition_count'];
        $grades = $applicant->grades($level);

        if ($grades === []) {
            return new ConditionResult(RequirementStatus::Unknown, $description, "Add your {$name} subject grades to check this.");
        }

        $requirementKey = 'requirement:'.md5((string) json_encode($rule['source_lines'] ?? []));
        $countedSubjects[$level] ??= [];
        $countedSubjects[$requirementKey] ??= [];
        $available = array_diff_key($grades, $countedSubjects[$requirementKey], $rule['other_subjects'] ? $countedSubjects[$level] : []);
        $gradeSets = $rule['grade_sets'];

        // With grade sets such as "BBB, ABC or AAC" any subject can take any of the grades, so the grades are checked together
        $meetsGradeSets = fn (array $keys): bool => $gradeSets === null
            || $this->meetsGradeSet(array_map(fn (string $key): string => $available[$key]['grade'], $keys), $gradeSets);
        $unknownSubjects = [];
        $groups = [];

        foreach ($rule['subjects'] ?? array_fill(0, $count, null) as $alternatives) {
            $keys = [];
            $hasUnknownSubject = false;

            foreach ($alternatives ?? array_keys($available) as $subject) {
                if ($alternatives !== null && ! $this->qualificationResults->isKnownSubject($subject, $qualification)) {
                    $unknownSubjects[$subject] = true;
                    $hasUnknownSubject = true;
                }

                foreach ($alternatives === null ? [$subject] : $this->qualificationResults->meetingSubjects($subject, $qualification) as $key) {
                    if (isset($available[$key]) && ! in_array($key, $keys, true) && ($gradeSets !== null || $this->meetsQualificationGrade($available[$key], $rule))) {
                        $keys[] = $key;
                    }
                }
            }

            $groups[] = ['keys' => $keys, 'hasUnknownSubject' => $hasUnknownSubject];
        }

        $keyGroups = array_column($groups, 'keys');
        $used = $this->subjectAssignment($keyGroups, $count, $meetsGradeSets);

        if ($used !== null) {
            $countedSubjects[$level] += array_fill_keys($used, true);
            $countedSubjects[$requirementKey] += array_fill_keys($used, true);
            $found = array_map(fn (string $key): string => $this->qualificationGradeText($available[$key]), $used);

            return new ConditionResult(RequirementStatus::Met, $description, (count($found) > 1 ? 'Your grades: ' : 'Your grade: ').implode(', ', $found).'.');
        }

        // How many of the subjects asked for the applicant has, for the reason shown
        $metCount = 0;

        for ($needed = $count - 1; $needed > 0 && $gradeSets === null; $needed--) {
            if ($this->subjectAssignment($keyGroups, $needed, fn (array $keys): bool => true) !== null) {
                $metCount = $needed;

                break;
            }
        }

        $entriesWithUnknownSubject = count(array_filter($groups, fn (array $group): bool => $group['hasUnknownSubject']));

        if ($unknownSubjects !== [] && $metCount + $entriesWithUnknownSubject >= $count) {
            return new ConditionResult(
                RequirementStatus::Unknown,
                $description,
                "The form doesn't offer ".implode(', ', array_keys($unknownSubjects)).", so this site can't check every subject in this list. Check it with the university.",
                canBeChecked: false,
            );
        }

        return new ConditionResult(RequirementStatus::NotMet, $description, $this->qualificationSubjectReason($rule, $grades, $count, $metCount));
    }

    /**
     * Distinct subjects, one from each of $needed groups, that the check accepts together, or null when there are none.
     *
     * @param  list<list<string>>  $groups  The subject keys each entry of the list can be met by.
     * @param  callable(list<string>): bool  $acceptsTogether
     * @param  list<string>  $chosen
     * @return list<string>|null
     */
    private function subjectAssignment(array $groups, int $needed, callable $acceptsTogether, array $chosen = [], int $from = 0): ?array
    {
        if ($needed === 0) {
            return $acceptsTogether($chosen) ? $chosen : null;
        }

        for ($group = $from; $group <= count($groups) - $needed; $group++) {
            foreach ($groups[$group] as $key) {
                if (in_array($key, $chosen, true)) {
                    continue;
                }

                $assignment = $this->subjectAssignment($groups, $needed - 1, $acceptsTogether, [...$chosen, $key], $group + 1);

                if ($assignment !== null) {
                    return $assignment;
                }
            }
        }

        return null;
    }

    /**
     * Whether letter grades match one of the sets of grades, e.g. A, A, B against "AAC": the best grade must be at
     * least the set's best, the next at least the set's next, and so on.
     *
     * @param  list<string>  $grades
     * @param  list<string>  $gradeSets
     */
    private function meetsGradeSet(array $grades, array $gradeSets): bool
    {
        usort($grades, fn (string $first, string $second): int => ($this->gradeRank($first) ?? PHP_INT_MAX) <=> ($this->gradeRank($second) ?? PHP_INT_MAX));

        foreach ($gradeSets as $set) {
            $setGrades = str_split($set);

            if (count($setGrades) === count($grades) && array_all($setGrades, fn (string $grade, int $index): bool => $this->meetsGrade($grades[$index], $grade))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a subject's grade meets a rule. An IB grade must be taken at Higher Level when the rule asks for it, and
     * may have to meet a different grade at Standard Level.
     *
     * @param  array{subject: string, grade: string, level?: string}  $row
     * @param  array<string, mixed>  $rule
     */
    private function meetsQualificationGrade(array $row, array $rule): bool
    {
        if ($rule['qualification'] !== 'ib') {
            return $this->meetsGrade($row['grade'], (string) $rule['minimum_grade']);
        }

        $isHigherLevel = ($row['level'] ?? null) === 'HL';

        if ($rule['standard_level_grade'] !== null) {
            return (int) $row['grade'] >= (int) ($isHigherLevel ? $rule['minimum_grade'] : $rule['standard_level_grade']);
        }

        return ($rule['ib_level'] !== 'HL' || $isHigherLevel) && (int) $row['grade'] >= (int) $rule['minimum_grade'];
    }

    /**
     * @param  array{subject: string, grade: string, level?: string}  $row
     */
    private function qualificationGradeText(array $row): string
    {
        return isset($row['level']) ? "{$row['subject']}: {$row['grade']} ({$row['level']})" : "{$row['subject']}: {$row['grade']}";
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, array{subject: string, grade: string, level?: string}>  $grades
     */
    private function qualificationSubjectReason(array $rule, array $grades, int $count, int $metCount): string
    {
        if ($count > 1 || $rule['subjects'] === null) {
            return "You have {$metCount} of {$count}.";
        }

        $subjects = array_merge(...$rule['subjects']);
        $found = [];

        foreach ($subjects as $subject) {
            foreach ($this->qualificationResults->meetingSubjects($subject, $rule['qualification']) as $key) {
                if (isset($grades[$key])) {
                    $found[$key] = $this->qualificationGradeText($grades[$key]);
                }
            }
        }

        if ($found === []) {
            return count($subjects) > 1 ? 'None of these subjects is in your results.' : "This subject isn't in your results.";
        }

        return (count($found) > 1 ? 'Your grades: ' : 'Your grade: ').implode(', ', $found).'.';
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private function checkMuet(array $condition, ApplicantResults $applicant): ConditionResult
    {
        $minimum = (float) $condition['condition_value'];
        $description = sprintf('MUET Band %.1f or higher', $minimum);

        if ($applicant->hasNoMuet()) {
            return new ConditionResult(RequirementStatus::NotMet, $description, "You haven't taken MUET.");
        }

        if ($applicant->muetScore() === null) {
            return new ConditionResult(RequirementStatus::Unknown, $description, 'Add your MUET band to check this.');
        }

        return new ConditionResult(
            RequirementStatus::fromBoolean($applicant->muetScore() >= $minimum),
            $description,
            "Your MUET band is {$applicant->muetBand}.",
        );
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private function checkStam(array $condition, ApplicantResults $applicant): ConditionResult
    {
        $grades = array_map('mb_strtolower', config('academic.stam_grades'));
        $minimum = mb_strtolower(trim((string) $condition['condition_value']));
        $minimumRank = array_search($minimum, $grades, true);
        $description = 'A STAM grade of '.ucwords($minimum).' or better';

        if ($minimumRank === false) {
            return new ConditionResult(RequirementStatus::Unknown, $description, 'This requirement could not be read in full.', canBeChecked: false);
        }

        if ($applicant->stamGrade === null) {
            return new ConditionResult(RequirementStatus::Unknown, $description, 'Add your STAM grade to check this.');
        }

        $applicantRank = array_search(mb_strtolower($applicant->stamGrade), $grades, true);

        return new ConditionResult(
            RequirementStatus::fromBoolean($applicantRank !== false && $applicantRank <= $minimumRank),
            $description,
            "Your STAM grade is {$applicant->stamGrade}.",
        );
    }

    /**
     * Years of work experience, e.g. "Dua (2) Tahun Pengalaman Kerja Dalam Bidang Berkaitan". The form doesn't ask for
     * work experience, and only the university can judge which work counts, so the applicant checks it themselves.
     *
     * @param  array<string, mixed>  $condition
     */
    private function checkExperience(array $condition): ConditionResult
    {
        if (! isset($condition['condition_value'])) {
            return new ConditionResult(RequirementStatus::Unknown, 'Work experience', 'This requirement could not be read in full. Check it on e-Panduan.', canBeChecked: false);
        }

        $years = (int) $condition['condition_value'];
        $field = $this->writtenRequirements->experienceField((string) ($condition['text'] ?? ''));
        $description = sprintf('%s of work experience in %s', trans_choice(':count year|:count years', $years), $field === null ? 'a related field' : __($field));

        return new ConditionResult(
            RequirementStatus::Unknown,
            $description,
            'This site doesn\'t check work experience. Check with the university whether your work counts.',
            canBeChecked: false,
        );
    }

    /**
     * The field of the applicant's diploma, e.g. "PNGK 2.50 Dalam Mana-Mana Satu (1) Bidang Berikut: Seni Reka, Multimedia".
     * The form doesn't ask what a diploma is in, so the field is left to the applicant.
     *
     * @param  array<string, mixed>  $condition
     */
    private function checkDiplomaField(array $condition): ConditionResult
    {
        $fields = array_values(array_filter(array_map('trim', (array) ($condition['fields'] ?? []))));

        if ($fields === []) {
            return new ConditionResult(RequirementStatus::Unknown, 'Diploma field', 'This requirement could not be read in full. Check it on e-Panduan.', canBeChecked: false);
        }

        return new ConditionResult(
            RequirementStatus::Unknown,
            'Diploma in one of these fields: '.implode('; ', $fields),
            "This site doesn't ask what your diploma is in. Check with the university.",
            canBeChecked: false,
        );
    }

    /**
     * A choice the university's Senate decides on, e.g. "Mempunyai Kelulusan Lain Yang Diiktiraf Setaraf
     * Dengannya DAN Diperakukan Oleh Senat Universiti", so it can only be answered by the university.
     *
     * @param  array<string, mixed>  $condition
     */
    private function checkSenateApproval(array $condition): ConditionResult
    {
        $description = ($condition['with_diploma'] ?? false)
            ? "A diploma in a suitable field, or another qualification recognised by the university's Senate"
            : "Another qualification recognised by the university's Senate";

        return new ConditionResult(
            RequirementStatus::Unknown,
            $description,
            "The university's Senate decides whether your qualification counts. Check with the university.", canBeChecked: false,
        );
    }

    /**
     * A choice asking only for "Diploma Dalam Bidang Yang Berkaitan Dengan Kursus Yang Dipohon".
     * The form doesn't ask what a diploma is in, so the field is left to the applicant, as is whether
     * another qualification is on par with a diploma.
     */
    private function checkRelatedDiploma(ApplicantResults $applicant): ConditionResult
    {
        return new ConditionResult(RequirementStatus::Unknown, 'A diploma in a field related to the course', $this->fieldReason($applicant), canBeChecked: false);
    }

    /**
     * Why a qualification's field is left to the applicant. The form doesn't ask what a diploma is in.
     */
    private function fieldReason(ApplicantResults $applicant): string
    {
        if ($applicant->category->acceptsDiplomaResults()) {
            return "This site doesn't ask what your diploma is in, so it can't tell whether it's related. Check with the university.";
        }

        return "You're applying with {$applicant->category->label()}. Check with the university whether this course accepts it.";
    }

    /**
     * An age limit, e.g. "Berumur 30 Tahun Kebawah" or "Berumur Diantara 17 Tahun Hingga 20 Tahun".
     *
     * @param  array<string, mixed>  $condition
     */
    private function checkAge(array $condition, ApplicantResults $applicant): ConditionResult
    {
        $minimum = isset($condition['minimum_age']) ? (float) $condition['minimum_age'] : null;
        $maximum = isset($condition['maximum_age']) ? (float) $condition['maximum_age'] : null;
        $format = fn (float $age): string => rtrim(rtrim(number_format($age, 1), '0'), '.');

        $description = match (true) {
            $minimum !== null && $maximum !== null => "Aged {$format($minimum)} to {$format($maximum)}",
            $maximum !== null => "Aged {$format($maximum)} or younger",
            $minimum !== null => "Aged {$format($minimum)} or older",
            default => 'An age limit',
        };

        if ($minimum === null && $maximum === null) {
            return new ConditionResult(RequirementStatus::Unknown, $description, 'This requirement could not be read in full. Check it on e-Panduan.', canBeChecked: false);
        }

        if ($applicant->age === null) {
            return new ConditionResult(RequirementStatus::Unknown, $description, 'Add your age to check this.');
        }

        return new ConditionResult(
            RequirementStatus::fromBoolean(($minimum === null || $applicant->age >= $minimum) && ($maximum === null || $applicant->age <= $maximum)),
            $description,
            "You are {$applicant->age}.",
        );
    }

    /**
     * "Berketurunan Melayu, Anak Negeri Sabah, Anak Negeri Sarawak DAN Orang Asli Sahaja".
     */
    private function checkDescent(ApplicantResults $applicant): ConditionResult
    {
        $description = 'Bumiputera';

        return match ($applicant->isOfEligibleDescent) {
            null => new ConditionResult(RequirementStatus::Unknown, $description, 'Answer the Bumiputera question in your results to check this.'),
            true => new ConditionResult(RequirementStatus::Met, $description, 'You said you are.'),
            false => new ConditionResult(RequirementStatus::NotMet, $description, "You said you aren't."),
        };
    }

    /**
     * "Ujian Rabun / Buta Warna" or "Calon Tidak Buta Warna".
     */
    private function checkColourVision(ApplicantResults $applicant): ConditionResult
    {
        $description = 'Not colour blind';

        return match ($applicant->isColourBlind) {
            null => new ConditionResult(RequirementStatus::Unknown, $description, 'Answer the colour blindness question in your results to check this.'),
            false => new ConditionResult(RequirementStatus::Met, $description, "You said you aren't colour blind."),
            true => new ConditionResult(RequirementStatus::NotMet, $description, 'You said you are colour blind.'),
        };
    }

    /**
     * "Memiliki Diploma ATAU Kelulusan Lain Yang Diiktiraf Setaraf Dengannya Oleh Kerajaan Malaysia DAN Diluluskan
     * Oleh Senat Upm." A diploma holder is taken to hold a recognised diploma. Whether another qualification
     * counts as equal to one is the university's call.
     */
    private function checkDiplomaOrEquivalent(ApplicantResults $applicant): ConditionResult
    {
        $description = "A diploma, or an equivalent qualification recognised by the Malaysian government and approved by the university's Senate";

        if ($applicant->category->acceptsDiplomaResults()) {
            return new ConditionResult(RequirementStatus::Met, $description, "You're applying with a diploma.");
        }

        return new ConditionResult(RequirementStatus::Unknown, $description, 'Check with the university whether your qualification counts as equal to a diploma.', canBeChecked: false);
    }

    /**
     * The requirement to hold a qualification, e.g. "An STPM qualification" or "An A-Level, IB, AUSMAT or another
     * equivalent qualification".
     */
    private function qualificationDescription(string $qualification): string
    {
        $name = self::QUALIFICATION_NAMES[$qualification];
        $article = in_array($qualification, ['spm', 'stpm', 'equivalent'], true) ? 'An' : 'A';

        return $qualification === 'equivalent' ? "{$article} {$name}" : "{$article} {$name} qualification";
    }

    /**
     * A line that only states what an applicant applying through UPU already has, read by
     * WrittenRequirementReader::qualificationStatement(), e.g. "citizenship". UPU only takes Malaysian citizens, and
     * the applicant's category says which qualification they apply with.
     */
    private function checkQualificationStatement(string $statement, ApplicantResults $applicant): ConditionResult
    {
        $category = $applicant->category;
        $applyingWith = "You're applying with {$category->label()}.";
        $holdsDiploma = $category->acceptsDiplomaResults();

        $fieldDescription = match ($statement) {
            'suitable_diploma_or_qualification' => "A diploma in a suitable field, or another qualification recognised by UPM's Senate",
            'related_qualification' => "A related qualification from a higher education institution the university's Senate recognises",
            'related_diploma' => 'A diploma in a related field',
            'related_diploma_for_course' => 'A diploma in a field related to the course',
            'related_diploma_from_polytechnic' => 'A diploma in a related field from a polytechnic',
            'related_diploma_from_public_university' => 'A diploma in a related field from a public university',
            'related_diploma_from_private_institution' => 'A diploma in a related field from a private higher education institution',
            'related_diploma_from_utm' => 'A diploma in a related field from UTM or another higher education institution, or an equivalent',
            default => null,
        };

        // The form doesn't ask what an applicant's qualification is in, so whether it's related is left to them
        if ($fieldDescription !== null) {
            return new ConditionResult(RequirementStatus::Unknown, $fieldDescription, $this->fieldReason($applicant), canBeChecked: false);
        }

        [$description, $isMet, $metReason] = match ($statement) {
            'citizenship' => ['A Malaysian citizen with a MyKad', true, 'UPU only takes applications from Malaysian citizens.'],
            'equivalent_qualification' => ["Your qualification, or another one the university's Senate recognises as equal", true, $applyingWith],
            'recognised_diploma' => ["A diploma from an institution recognised by the Malaysian government and the university's Senate", $holdsDiploma, "You're applying with a diploma."],
            'a_level' => ['A recognised GCE A-Level', $category === ApplicantCategory::ALevel, "You're applying with A-Level results."],
            'general_requirements' => ["The university's general entry requirements", true, "They're checked with the rest of this course's requirements."],
            'sat_spm' => ['Sat SPM, and can read, write and count', $category->isSpm(), "You're applying with SPM results."],
        };

        if ($isMet) {
            return new ConditionResult(RequirementStatus::Met, $description, $metReason);
        }

        return new ConditionResult(RequirementStatus::Unknown, $description, $applyingWith.' Check with the university whether this course accepts it.', canBeChecked: false);
    }

    /**
     * A music grade from ABRSM or Trinity College London, e.g. "Sekurang-Kurangnya Gred 5".
     *
     * @param  array<string, mixed>  $rule
     */
    private function checkMusicGrade(array $rule, ApplicantResults $applicant): ConditionResult
    {
        $grades = config('academic.music_grades');
        $minimumRank = $rule['minimum_rank'];

        if ($minimumRank === null) {
            return new ConditionResult(RequirementStatus::Unknown, 'A music qualification', 'This requirement could not be read in full. Check it on e-Panduan.', canBeChecked: false);
        }

        $boards = $rule['boards'] ?? [];
        $takesPrerequisiteCourse = $rule['prerequisite_course'] ?? false;
        $description = match (true) {
            $boards !== [] => "A music diploma from one of these exam boards\n".implode("\n", $boards),
            $takesPrerequisiteCourse => "ABRSM music Grade {$minimumRank} or higher (theory and practical), or an equivalent, or the prerequisite course the university sets",
            $minimumRank > 8 => 'An ABRSM or Trinity College London music diploma',
            default => "ABRSM or Trinity College London music Grade {$minimumRank} or higher",
        };

        // The form doesn't ask which exam board a diploma is from, so a list of boards is left to the applicant
        if ($boards !== []) {
            return new ConditionResult(RequirementStatus::Unknown, $description, "This site doesn't ask which exam board your music diploma is from.", canBeChecked: false);
        }

        // A diploma ranks above Grade 8, so a rule that asks for one is about the diploma, not a grade
        $asksForDiploma = $minimumRank > 8;

        if ($applicant->musicGradeRank() >= $minimumRank) {
            return new ConditionResult(RequirementStatus::Met, $description, $asksForDiploma ? 'You have a music diploma.' : 'Your music grade: '.$grades[$applicant->musicGrade].'.');
        }

        $reason = match (true) {
            $asksForDiploma && $applicant->musicGrade === null => "You haven't added a music diploma.",
            $asksForDiploma => "Your music grade is {$grades[$applicant->musicGrade]}, not a diploma.",
            $applicant->musicGrade === null => "You haven't added a music grade.",
            default => 'Your music grade: '.$grades[$applicant->musicGrade].'.',
        };

        if ($takesPrerequisiteCourse) {
            return new ConditionResult(RequirementStatus::Unknown, $description, $reason.' You can still meet this by taking the prerequisite course. Check it with the university.');
        }

        if ($rule['accepts_equivalent']) {
            return new ConditionResult(RequirementStatus::Unknown, $description, $reason.' The course also accepts an equivalent. Check it with the university.');
        }

        return new ConditionResult(RequirementStatus::NotMet, $description, $reason);
    }

    /**
     * "Menghafaz Sekurang-Kurangnya 30 Juzuk Al-Quran" or "Memiliki Sijil Tahfiz Al-Qur'An 30 Juzuk".
     *
     * @param  array<string, mixed>  $rule
     */
    private function checkQuranMemorisation(array $rule, ApplicantResults $applicant): ConditionResult
    {
        $description = "Memorised {$rule['minimum_juz']} juz of the Quran";

        if ($applicant->quranJuzMemorised === null) {
            return new ConditionResult(RequirementStatus::NotMet, $description, "You haven't added how many juz you have memorised.");
        }

        $reason = 'You have memorised '.trans_choice(':count juz|:count juz', $applicant->quranJuzMemorised).'.';

        if ($applicant->quranJuzMemorised < $rule['minimum_juz']) {
            return new ConditionResult(RequirementStatus::NotMet, $description, $reason);
        }

        return new ConditionResult(
            RequirementStatus::Met,
            $description,
            $rule['needs_certificate'] ? $reason.' The university checks your tahfiz certificate.' : $reason,
        );
    }

    /**
     * An IELTS band or TOEFL iBT score listed beside a MUET band, e.g. "Mendapat Sekurangnya BAND 4.5
     * (Academic) Dalam Ielts", "Skor 33 Mata Dalam Toefl Ibt" or "80 Mata (Ibt) Dalam Toefl".
     * Other tests, such as TOEFL PBT or CEFR, are checked by the university.
     *
     * @param  array<string, mixed>  $condition
     */
    private function checkEnglishTestScore(array $condition, ApplicantResults $applicant): ConditionResult
    {
        $text = (string) ($condition['text'] ?? '');
        $ieltsBand = null;
        $toeflScore = null;

        foreach (preg_split('/;|\bATAU\b/u', $text) as $clause) {
            if ($ieltsBand === null && preg_match('/ielts/iu', $clause) === 1 && preg_match('/\b([1-9](?:\.[05])?)\b/u', $clause, $matches) === 1) {
                $ieltsBand = (float) $matches[1];
            }

            if ($toeflScore === null && preg_match('/(\d{2,3})\s*(?:mata\s*)?(?:\(ibt\)|dalam\s+toefl\s+ibt)|toefl\s+ibt\s+skor\s+(\d{2,3})/iu', $clause, $matches) === 1) {
                $toeflScore = (int) ($matches[1] !== '' ? $matches[1] : $matches[2]);
            }
        }

        $tests = array_filter([
            $ieltsBand === null ? null : sprintf('IELTS band %.1f', $ieltsBand),
            $toeflScore === null ? null : "TOEFL iBT score {$toeflScore}",
        ]);

        if ($tests === []) {
            return new ConditionResult(RequirementStatus::Unknown, 'An English test listed on e-Panduan', 'This test can only be checked by the university.', canBeChecked: false);
        }

        $description = implode(' or ', $tests).' or higher';
        $isMet = ($ieltsBand !== null && $applicant->ieltsBand !== null && $applicant->ieltsBand >= $ieltsBand)
            || ($toeflScore !== null && $applicant->toeflIbtScore !== null && $applicant->toeflIbtScore >= $toeflScore);

        $scores = array_filter([
            $applicant->ieltsBand === null ? null : sprintf('IELTS band %.1f', $applicant->ieltsBand),
            $applicant->toeflIbtScore === null ? null : "TOEFL iBT score {$applicant->toeflIbtScore}",
        ]);

        return new ConditionResult(
            RequirementStatus::fromBoolean($isMet),
            $description,
            $scores === [] ? "You haven't added an IELTS or TOEFL iBT score." : 'Yours: '.implode(', ', $scores).'.',
        );
    }

    /**
     * The level an applicant has represented in sports, e.g. "Sekurang-Kurangnya Mewakili Daerah".
     * Some courses also accept former athletes, referees and team managers.
     *
     * @param  array<string, mixed>  $condition
     */
    private function checkSports(array $condition, ApplicantResults $applicant): ConditionResult
    {
        $levels = config('academic.sports_levels');
        $minimumLevel = (string) ($condition['minimum_level'] ?? '');
        $minimumRank = ApplicantResults::sportsLevelRank($minimumLevel);

        if ($minimumRank === null) {
            return new ConditionResult(RequirementStatus::Unknown, 'Sports involvement', 'This requirement could not be read in full. Check it on e-Panduan.', canBeChecked: false);
        }

        $description = $minimumLevel === 'school'
            ? 'Active involvement in sports'
            : 'Represented '.Str::before(Str::lower($levels[$minimumLevel]), ' (').' level or higher in sports';

        if ($applicant->sportsRank() === null) {
            return new ConditionResult(RequirementStatus::Unknown, $description, 'Add the highest level you have represented in sports to check this.');
        }

        $reason = 'Your highest level: '.$levels[$applicant->sportsLevel].'.';

        if ($applicant->sportsRank() >= $minimumRank) {
            return new ConditionResult(RequirementStatus::Met, $description, $reason);
        }

        if ($condition['former_roles_accepted'] ?? false) {
            return new ConditionResult(RequirementStatus::Unknown, $description, $reason.' Former athletes, referees and team managers may also qualify. Check it with the university.');
        }

        return new ConditionResult(RequirementStatus::NotMet, $description, $reason);
    }

    /**
     * A grade in the specialisation (major) subjects of a matriculation or foundation programme.
     * Which subjects count as specialisation subjects differs between programmes and isn't part of
     * the saved results, so this is always left for the applicant to check with the university.
     *
     * @param  array<string, mixed>  $condition
     */
    private function checkSpecialisation(array $condition, ?string $qualification): ConditionResult
    {
        $minimumGrade = strtoupper((string) ($condition['minimum_grade'] ?? ''));
        $levelName = self::QUALIFICATION_NAMES[$qualification ?? 'matriculation'] ?? 'matriculation or foundation';
        $description = $this->gradeRank($minimumGrade) === null
            ? "A grade in your {$levelName} specialisation subjects"
            : "Grade {$minimumGrade} or better in your {$levelName} specialisation subjects";

        return new ConditionResult(
            RequirementStatus::Unknown,
            $description,
            "Specialisation subjects are the main subjects of your programme, and which ones count differs between programmes. Your results don't show which of your subjects they are, so ask the university whether your grades meet this.", canBeChecked: false,
        );
    }

    /**
     * Check a requirement for grades in particular subjects.
     *
     * - subject_requirement: the listed subjects are alternatives; condition_count of them must meet the grade.
     * - subject_group_requirement: condition_count of the listed entries must each be met by one subject,
     *   where an entry such as "Sejarah / Geografi" is met by either subject.
     * - any_subject_requirement: condition_count subjects that have not been counted by an earlier
     *   condition in the same list must meet the grade.
     *
     * @param  array<string, mixed>  $condition
     * @param  array<string, array<string, true>>  $countedSubjects  Subjects already counted in this list, by level.
     */
    private function checkSubjects(array $condition, ApplicantResults $applicant, string $level, array &$countedSubjects): ConditionResult
    {
        $type = $condition['condition_type'];
        $count = max(1, (int) ($condition['condition_count'] ?? 1));
        $minimumGrade = strtoupper(trim((string) ($condition['minimum_grade'] ?? '')));
        $entries = $this->subjectEntries($condition);

        $genderRequirement = $this->genderRequirement($entries, $applicant);

        if ($genderRequirement !== null) {
            return $genderRequirement;
        }

        $levelName = $level === 'equivalent' ? $this->equivalentName($applicant) : (self::QUALIFICATION_NAMES[$level] ?? $level);
        $gradePhrase = "Grade {$minimumGrade} or better";

        // IB results are graded 7 to 1, so a letter grade for them can't be checked
        if ($level === 'equivalent' && $applicant->holds('ib') && $this->gradeRank($minimumGrade) !== null) {
            return new ConditionResult(
                RequirementStatus::Unknown,
                $this->subjectDescription($type, $count, $gradePhrase, $levelName, $entries),
                "e-Panduan gives a letter grade here, which IB results don't use. Check with the university.", canBeChecked: false,
            );
        }

        if ($this->gradeRank($minimumGrade) === null) {
            return new ConditionResult(RequirementStatus::Unknown, "Subject grades ({$levelName})", 'This requirement could not be read in full.', canBeChecked: false);
        }

        // "Gred C Bagi Matapelajaran": e-Panduan leaves the subject name out
        if ($entries !== [] && array_all($entries, fn (string $entry): bool => preg_match('/^mata\s*pelajaran$/iu', trim($entry)) === 1)) {
            return new ConditionResult(
                RequirementStatus::Unknown,
                $count === 1 ? "{$gradePhrase} in one {$levelName} subject" : "{$gradePhrase} in {$count} {$levelName} subjects",
                "e-Panduan doesn't name the subject. Check with the university which subject it means.", canBeChecked: false,
            );
        }

        if (! in_array($level, ApplicantResults::SUBJECT_LEVELS, true)) {
            return new ConditionResult(
                RequirementStatus::Unknown,
                $this->subjectDescription($type, $count, $gradePhrase, $levelName, $entries),
                ucfirst($levelName).' subject grades cannot be checked yet.', canBeChecked: false,
            );
        }

        if ($applicant->grades($level) === []) {
            return new ConditionResult(
                RequirementStatus::Unknown,
                $this->subjectDescription($type, $count, $gradePhrase, $levelName, $entries),
                "Add your {$levelName} subject grades to check this.",
            );
        }

        // "Grade C in 2 subjects: Mathematics T / Chemistry / Physics" lists the choices as one entry
        if ($type === 'subject_group_requirement' && $count > count($entries)
            && count(array_merge(...array_map(fn (string $entry): array => $this->subjectAlternatives($entry, $level), $entries ?: ['']))) > $count) {
            $type = 'subject_requirement';
        }

        $grades = $applicant->grades($level);
        $countedSubjects[$level] ??= [];

        if ($type === 'any_subject_requirement') {
            return $this->checkAnySubjects($condition, $grades, $level, $count, $minimumGrade, $gradePhrase, $levelName, $countedSubjects[$level]);
        }

        $description = $this->subjectDescription($type, $count, $gradePhrase, $levelName, $entries);
        $groups = $type === 'subject_group_requirement'
            ? array_map(fn (string $entry): array => $this->subjectAlternatives($entry, $level), $entries)
            : array_map(fn (string $alternative): array => [$alternative], array_merge(...array_map(fn (string $entry): array => $this->subjectAlternatives($entry, $level), $entries ?: [''])));

        $metGroups = 0;
        $usedSubjects = [];
        $hasUnreadableSubject = false;

        foreach ($groups as $alternatives) {
            // Only the subjects needed are counted, so later "other subjects" conditions can use the rest
            if ($metGroups >= $count) {
                break;
            }

            foreach ($alternatives as $subject) {
                $key = SubjectName::key($subject, $level);

                if (! $this->isKnownSubject($key, $level)) {
                    $hasUnreadableSubject = true;

                    continue;
                }

                $meetingKeys = $level === 'equivalent' && $applicant->category === ApplicantCategory::AusmatSamCpu
                    ? $this->qualificationResults->meetingSubjects($subject, 'ausmat')
                    : [$key];
                $meetingKey = array_find($meetingKeys, fn (string $candidate): bool => isset($grades[$candidate]) && ! isset($usedSubjects[$candidate]) && $this->meetsGrade($grades[$candidate]['grade'], $minimumGrade));

                if ($meetingKey !== null) {
                    $usedSubjects[$meetingKey] = true;
                    $metGroups++;

                    break;
                }
            }
        }

        if ($metGroups >= $count) {
            $countedSubjects[$level] += $usedSubjects;

            return new ConditionResult(RequirementStatus::Met, $description, $this->subjectReason($type, $groups, $grades, $level, $count, $metGroups));
        }

        $subjectGroupChoice = $this->subjectGroupChoice($groups);

        if ($subjectGroupChoice !== null) {
            return new ConditionResult(
                RequirementStatus::Unknown,
                $description,
                "The site can't tell which subjects belong to {$subjectGroupChoice}. The university decides this.", canBeChecked: false,
            );
        }

        if ($hasUnreadableSubject || count($groups) < $count) {
            return new ConditionResult(RequirementStatus::Unknown, $description, 'This requirement could not be read in full. Check it on e-Panduan.', canBeChecked: false);
        }

        return new ConditionResult(RequirementStatus::NotMet, $description, $this->subjectReason($type, $groups, $grades, $level, $count, $metGroups));
    }

    /**
     * The name of the applicant's qualification for subjects e-Panduan lists as "Kelayakan Setaraf".
     */
    private function equivalentName(ApplicantResults $applicant): string
    {
        return match ($applicant->category) {
            ApplicantCategory::ALevel => 'A-Level',
            ApplicantCategory::InternationalBaccalaureateDiploma => 'IB',
            ApplicantCategory::AusmatSamCpu => 'AUSMAT',
            default => 'A-Level, IB or AUSMAT',
        };
    }

    /**
     * The choice that names a subject group, e.g. "Sains, Teknikal or Vokasional", or null when there is none.
     *
     * @param  list<list<string>>  $groups
     */
    private function subjectGroupChoice(array $groups): ?string
    {
        foreach ($groups as $alternatives) {
            $namesGroup = array_filter($alternatives, fn (string $subject): bool => in_array(mb_strtolower(trim($subject)), self::SUBJECT_GROUP_NAMES, true));

            if ($namesGroup !== []) {
                $last = array_pop($alternatives);

                return $alternatives === [] ? $last : implode(', ', $alternatives)." or {$last}";
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, array{subject: string, grade: string}>  $grades
     * @param  array<string, true>  $countedSubjects
     */
    private function checkAnySubjects(
        array $condition,
        array $grades,
        string $level,
        int $count,
        string $minimumGrade,
        string $gradePhrase,
        string $levelName,
        array &$countedSubjects,
    ): ConditionResult {
        $excluded = [];

        foreach ((array) ($condition['excluded_subjects'] ?? []) as $subject) {
            foreach ($this->subjectAlternatives((string) $subject, $level) as $alternative) {
                $excluded[SubjectName::key($alternative, $level)] = true;
            }
        }

        $qualifying = array_filter(
            $grades,
            fn (array $row, string $key): bool => ! isset($countedSubjects[$key])
                && ! isset($excluded[$key])
                && $this->meetsGrade($row['grade'], $minimumGrade),
            ARRAY_FILTER_USE_BOTH,
        );

        $isFirstCondition = $countedSubjects === [];
        $description = sprintf(
            '%s in %s %s %s',
            $gradePhrase,
            $count,
            $isFirstCondition ? '' : 'other',
            $count === 1 ? "{$levelName} subject" : "{$levelName} subjects",
        );

        if (count($qualifying) >= $count) {
            $countedSubjects += array_fill_keys(array_slice(array_keys($qualifying), 0, $count), true);
        }

        return new ConditionResult(
            RequirementStatus::fromBoolean(count($qualifying) >= $count),
            preg_replace('/\s+/', ' ', $description),
            sprintf('You have %d.', count($qualifying)),
        );
    }

    /**
     * The entries of a condition's subject list, one per choice. The collectors give
     * each subject its own entry, and a choice between subjects is a list, e.g.
     * ["Matematik", "Matematik Tambahan"], which becomes "Matematik / Matematik Tambahan".
     * A descent condition such as "Berketurunan Melayu ... Sahaja" collected into the list
     * is not a subject, so it is left out.
     *
     * @param  array<string, mixed>  $condition
     * @return list<string>
     */
    private function subjectEntries(array $condition): array
    {
        if (! is_array($condition['subject_list'] ?? null)) {
            return [];
        }

        $entries = array_map(
            fn (mixed $entry): string => is_array($entry) ? implode(' / ', array_map('strval', $entry)) : (string) $entry,
            $condition['subject_list'],
        );

        return array_values(array_filter($entries, fn (string $entry): bool => preg_match('/^\s*berketurunan\b/iu', $entry) !== 1));
    }

    /**
     * Some requirements were collected as a subject list such as ["Jantina: Lelaki"].
     *
     * @param  list<string>  $entries
     */
    private function genderRequirement(array $entries, ApplicantResults $applicant): ?ConditionResult
    {
        foreach ($entries as $entry) {
            if (preg_match('/jantina\s*:?\s*(lelaki|perempuan)/iu', $entry, $matches)) {
                $requiredGender = mb_strtolower($matches[1]) === 'lelaki' ? 'male' : 'female';

                return new ConditionResult(
                    RequirementStatus::fromBoolean($applicant->gender === $requiredGender),
                    $requiredGender === 'male' ? 'Open to men only' : 'Open to women only',
                );
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $entries
     */
    private function subjectDescription(string $type, int $count, string $gradePhrase, string $levelName, array $entries): string
    {
        $entryNames = array_values(array_filter(array_map(
            fn (string $entry): string => implode(' / ', SubjectName::alternatives($entry)),
            $entries,
        )));

        if ($entryNames === []) {
            return $count === 1
                ? "{$gradePhrase} in one {$levelName} subject listed on e-Panduan"
                : "{$gradePhrase} in {$count} {$levelName} subjects listed on e-Panduan";
        }

        if ($type === 'subject_group_requirement' && $count > 1 && count($entryNames) > 1) {
            return "{$gradePhrase} in {$count} of these {$levelName} subjects: ".implode('; ', $entryNames);
        }

        // Choices between subjects are separated by "/", e.g. "one SPM subject: Fizik / Kimia"
        $subjects = implode(' / ', $entryNames);

        if ($count > 1) {
            return "{$gradePhrase} in {$count} of these {$levelName} subjects: {$subjects}";
        }

        return str_contains($subjects, ' / ')
            ? "{$gradePhrase} in one {$levelName} subject: {$subjects}"
            : "{$gradePhrase} in {$levelName} {$subjects}";
    }

    /**
     * @param  list<list<string>>  $groups
     * @param  array<string, array{subject: string, grade: string}>  $grades
     */
    private function subjectReason(string $type, array $groups, array $grades, string $level, int $count, int $metGroups): string
    {
        if ($count > 1) {
            return "You have {$metGroups} of {$count}.";
        }

        $found = [];
        $subjects = array_merge(...$groups ?: [[]]);

        foreach ($subjects as $subject) {
            $key = SubjectName::key($subject, $level);

            if (isset($grades[$key])) {
                $found[] = "{$grades[$key]['subject']}: {$grades[$key]['grade']}";
            }
        }

        if ($found === []) {
            return count($subjects) > 1 ? 'None of these subjects is in your results.' : "This subject isn't in your results.";
        }

        return (count($found) > 1 ? 'Your grades: ' : 'Your grade: ').implode(', ', $found).'.';
    }

    /**
     * Which level's grades a subject condition refers to.
     *
     * "Khas" (specific) conditions that do not name a level refer to the applicant's own
     * qualification when every subject named is one of its subjects. Otherwise, for
     * STPM-level programmes they refer to STPM subjects when every subject named is an
     * STPM subject, and SPM subjects otherwise.
     *
     * @param  array<string, mixed>  $condition
     */
    private function subjectLevel(array $condition, ?string $qualification, string $programmeLevel, ApplicantResults $applicant): string
    {
        if ($qualification !== null) {
            return $qualification;
        }

        if ($programmeLevel === 'spm' || ! is_array($condition['subject_list'] ?? null)) {
            return 'spm';
        }

        foreach (['matriculation', 'diploma', 'equivalent'] as $ownLevel) {
            if ($applicant->holds($ownLevel) && $this->namesOnlyKnownSubjects($this->subjectEntries($condition), $ownLevel)) {
                return $ownLevel;
            }
        }

        return $this->namesOnlyKnownSubjects($this->subjectEntries($condition), 'stpm') ? 'stpm' : 'spm';
    }

    /**
     * @param  list<mixed>  $entries
     */
    private function namesOnlyKnownSubjects(array $entries, string $level): bool
    {
        foreach ($entries as $entry) {
            foreach ($this->subjectAlternatives((string) $entry, $level) as $subject) {
                if (! $this->isKnownSubject(SubjectName::key($subject, $level), $level)) {
                    return false;
                }
            }
        }

        return $entries !== [];
    }

    /**
     * The alternatives in a subject entry. An entry such as "Physics Chemistry Biology",
     * written without separators, is split into the known subjects it is made of.
     *
     * @return list<string>
     */
    private function subjectAlternatives(string $entry, string $level): array
    {
        $alternatives = [];

        foreach (SubjectName::alternatives($entry) as $alternative) {
            $key = SubjectName::key($alternative, $level);
            $parts = $this->isKnownSubject($key, $level) ? null : $this->knownSubjectsIn($key, $level);
            array_push($alternatives, ...($parts ?? [$alternative]));
        }

        return $alternatives;
    }

    /**
     * Split a subject key made of known subject names, e.g. "physics chemistry biology",
     * into those names. A few unknown words, such as a misspelt subject, are skipped, but
     * null is returned when more than a quarter of the words are not part of a known name.
     *
     * @return list<string>|null
     */
    private function knownSubjectsIn(string $key, string $level): ?array
    {
        $words = explode(' ', $key);
        $subjects = [];
        $position = 0;
        $matchedWords = 0;
        $unmatchedWords = 0;

        while ($position < count($words)) {
            if (in_array($words[$position], ['dan', 'atau', 'or', 'and', 'serta'], true)) {
                $position++;

                continue;
            }

            $match = null;

            for ($length = min(8, count($words) - $position); $length > 0; $length--) {
                $phrase = implode(' ', array_slice($words, $position, $length));

                if ($this->isKnownSubject(SubjectName::key($phrase, $level), $level)) {
                    $match = [$phrase, $length];

                    break;
                }
            }

            if ($match === null) {
                $unmatchedWords++;
                $position++;

                continue;
            }

            $subjects[] = $match[0];
            $position += $match[1];
            $matchedWords += $match[1];
        }

        return count($subjects) > 1 && $unmatchedWords * 3 <= $matchedWords ? $subjects : null;
    }

    /**
     * The qualification a condition is written for: its own group, the section it is in,
     * or the level named in its subjects, such as "Mathematics Di Peringkat Matrikulasi".
     *
     * @param  array<string, mixed>  $condition
     */
    private function conditionQualification(array $condition, ?string $sectionQualification): ?string
    {
        $qualification = $this->qualificationForGroup((string) ($condition['condition_group'] ?? '')) ?? $sectionQualification;

        if ($qualification !== null || ! is_array($condition['subject_list'] ?? null)) {
            return $qualification;
        }

        $text = implode(' ', $this->subjectEntries($condition));

        return match (true) {
            preg_match('/\b(matrikulasi|asasi)\b/iu', $text) === 1 => 'matriculation',
            preg_match('/\bdiploma\b/iu', $text) === 1 => 'diploma',
            preg_match('/\bpra[-\s]?u\b/iu', $text) === 1 => 'prau',
            preg_match('/\b(gce|a-level|ausmat|sam|cpu|international baccalaureate)\b/iu', $text) === 1 => 'equivalent',
            default => null,
        };
    }

    private function qualificationForGroup(string $group): ?string
    {
        $group = strtoupper(trim($group));

        if (str_starts_with($group, 'DIPLOMA')) {
            return 'diploma';
        }

        return self::SECTION_QUALIFICATIONS[$group] ?? null;
    }

    private function combine(EligibilityResult $left, EligibilityResult $right, string $operator): EligibilityResult
    {
        if ($operator === 'OR') {
            foreach ([$left, $right] as $branch) {
                if ($branch->status === RequirementStatus::Met) {
                    return $branch;
                }
            }

            $status = RequirementStatus::any($left->status, $right->status);
        } else {
            $status = RequirementStatus::all($left->status, $right->status);
        }

        return new EligibilityResult(
            $status,
            [...$left->conditions, ...$right->conditions],
            [...$left->notes, ...$right->notes],
        );
    }

    private function meetsGrade(string $grade, string $minimumGrade): bool
    {
        $rank = $this->gradeRank($grade);
        $minimumRank = $this->gradeRank($minimumGrade);

        return $rank !== null && $minimumRank !== null && $rank <= $minimumRank;
    }

    private function gradeRank(string $grade): ?int
    {
        $rank = array_search(strtoupper(trim($grade)), config('academic.grade_order'), true);

        return $rank === false ? null : $rank;
    }

    private function isKnownSubject(string $key, string $level): bool
    {
        if (! isset($this->knownSubjects[$level])) {
            $this->knownSubjects[$level] = [];

            foreach (config("academic.{$level}_subjects", []) as $subject) {
                $this->knownSubjects[$level][SubjectName::key($subject, $level)] = true;
            }
        }

        return isset($this->knownSubjects[$level][$key]);
    }
}

<?php

namespace App\Console\Commands;

use App\Models\EligibilityRequirement;
use App\Models\Programme;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use SplFileObject;

/**
 * Loads the output of the e-Panduan collectors into MongoDB.
 *
 * A copy of the collector output is kept in database/data/e-panduan, which is
 * used when no folder is given. Copy newer collector output there to update it.
 *
 * The folder must hold the collectors' "SPM" and "STPM" folders, each with the
 * programme list (upuonline_guide_{level}_data.csv) and the entry requirements
 * (upuonline_guide_{level}_all_requirements_data_progress.jsonl, one programme per line).
 * The campus of each programme comes from the campus collector's output in the
 * "Campus" folder (upuonline_guide_campus_data.json). Without it, campuses are left empty.
 * The average merit score e-Panduan gives each programme for each applicant category comes from
 * the "Merit" folder (upuonline_guide_merit_data.csv). Without it, merit scores are left empty.
 */
#[Signature('programmes:import {path? : The e-Panduan folder that holds the SPM and STPM collector output (default: database/data/e-panduan)}')]
#[Description('Replace the stored programmes and entry requirements with the e-Panduan collector output')]
class ImportProgrammes extends Command
{
    private const BATCH_SIZE = 500;

    private const LEVELS = ['spm', 'stpm'];

    public function handle(): int
    {
        $folder = rtrim((string) ($this->argument('path') ?? database_path('data/e-panduan')), '\\/');
        $files = [];

        foreach (self::LEVELS as $level) {
            $levelFolder = $folder.DIRECTORY_SEPARATOR.strtoupper($level).DIRECTORY_SEPARATOR;
            $files[$level] = [
                'programmes' => $levelFolder."upuonline_guide_{$level}_data.csv",
                'requirements' => $levelFolder."upuonline_guide_{$level}_all_requirements_data_progress.jsonl",
            ];

            foreach ($files[$level] as $file) {
                if (! is_file($file)) {
                    $this->error("Cannot find {$file}");

                    return self::FAILURE;
                }
            }
        }

        $campuses = $this->readCampuses($folder.DIRECTORY_SEPARATOR.'Campus'.DIRECTORY_SEPARATOR.'upuonline_guide_campus_data.json');
        $meritScores = $this->readMeritScores($folder.DIRECTORY_SEPARATOR.'Merit'.DIRECTORY_SEPARATOR.'upuonline_guide_merit_data.csv');

        Programme::query()->truncate();
        EligibilityRequirement::query()->truncate();

        foreach ($files as $level => $levelFiles) {
            $programmeCount = $this->importProgrammes($levelFiles['programmes'], $level, $campuses, $meritScores[$level] ?? []);
            $requirementCount = $this->importRequirements($levelFiles['requirements'], $level);

            $this->info(sprintf('%s: %d programmes, %d entry requirement records.', strtoupper($level), $programmeCount, $requirementCount));
        }

        return self::SUCCESS;
    }

    /**
     * The campuses of each programme, keyed by programme code.
     *
     * @return array<string, list<array{campus: string, state: string}>>
     */
    private function readCampuses(string $file): array
    {
        if (! is_file($file)) {
            $this->warn("Cannot find {$file}, so campuses are left empty.");

            return [];
        }

        $campuses = [];

        foreach (json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) as $document) {
            $campuses[$document['programme_code']] = array_map(
                fn (array $campus): array => ['campus' => (string) $campus['campus'], 'state' => (string) $campus['state']],
                $document['campuses'] ?? [],
            );
        }

        $this->info(sprintf('Campuses: %d programmes.', count($campuses)));

        return $campuses;
    }

    /**
     * The average merit score of each programme for each applicant category, keyed by level, programme code and
     * category code, e.g. ['stpm' => ['UP6314001' => ['S' => 99.43]]]. e-Panduan shows "Tiada" (none) for a programme
     * without a score, and shows no score at all for diploma and other categories, so those are left out.
     *
     * @return array<string, array<string, array<string, float>>>
     */
    private function readMeritScores(string $file): array
    {
        if (! is_file($file)) {
            $this->warn("Cannot find {$file}, so merit scores are left empty.");

            return [];
        }

        $handle = fopen($file, 'r');
        $header = fgetcsv($handle, escape: '');
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        $scores = [];
        $count = 0;

        while (($values = fgetcsv($handle, escape: '')) !== false) {
            if ($values === [null]) {
                continue;
            }

            $row = array_combine($header, $values);

            if (is_numeric($row['average_merit'])) {
                $scores[$row['level']][$row['programme_code']][$row['category_code']] = (float) $row['average_merit'];
                $count++;
            }
        }

        fclose($handle);

        $this->info(sprintf('Merit scores: %d.', $count));

        return $scores;
    }

    /**
     * @param  array<string, list<array{campus: string, state: string}>>  $campuses
     * @param  array<string, array<string, float>>  $meritScores  The merit scores for this level, keyed by programme code and category code.
     */
    private function importProgrammes(string $file, string $level, array $campuses, array $meritScores): int
    {
        $handle = fopen($file, 'r');
        $header = fgetcsv($handle, escape: '');
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        $rows = [];
        $count = 0;

        while (($values = fgetcsv($handle, escape: '')) !== false) {
            if ($values === [null]) {
                continue;
            }

            $row = array_combine($header, $values);
            $rows[] = [
                'programme_code' => $row['program_code'],
                'programme_name' => $row['program_name'],
                'institution_name' => $row['institution_name'],
                'level' => $level,
                'campuses' => $campuses[$row['program_code']] ?? [],
                'average_merit_scores' => $meritScores[$row['program_code']] ?? [],
            ];

            if (count($rows) === self::BATCH_SIZE) {
                $count += $this->insert(Programme::class, $rows);
            }
        }

        fclose($handle);

        return $count + $this->insert(Programme::class, $rows);
    }

    /**
     * The collectors append a line each time a programme is collected, so a
     * programme that was collected again keeps only its last line.
     */
    private function importRequirements(string $file, string $level): int
    {
        $lastLineByProgramme = [];

        foreach (new SplFileObject($file) as $lineNumber => $line) {
            if (preg_match('/^\{"programme_code":\s*"([^"]+)"/', (string) $line, $matches)) {
                $lastLineByProgramme[$matches[1]] = $lineNumber;
            }
        }

        $linesToImport = array_flip($lastLineByProgramme);
        $rows = [];
        $count = 0;

        foreach (new SplFileObject($file) as $lineNumber => $line) {
            if (! isset($linesToImport[$lineNumber])) {
                continue;
            }

            $document = json_decode((string) $line, true, flags: JSON_THROW_ON_ERROR);

            foreach ($document['programme_categories'] as $category) {
                $rows[] = [
                    'programme_code' => $document['programme_code'],
                    'level' => $level,
                    'target_category' => $category['category_code'],
                    'is_offered' => (bool) $category['is_offered'],
                    'general_conditions_content' => $category['general_conditions_content'] ?? [],
                    'general_conditions_logic' => $category['general_conditions_logic'] ?? [],
                    'specific_conditions_content' => $category['specific_conditions_content'] ?? [],
                    'specific_conditions_logic' => $category['specific_conditions_logic'] ?? [],
                ];
            }

            if (count($rows) >= self::BATCH_SIZE) {
                $count += $this->insert(EligibilityRequirement::class, $rows);
            }
        }

        return $count + $this->insert(EligibilityRequirement::class, $rows);
    }

    /**
     * Insert the rows and empty the list.
     *
     * @param  class-string<Programme|EligibilityRequirement>  $model
     * @param  list<array<string, mixed>>  $rows
     */
    private function insert(string $model, array &$rows): int
    {
        $count = count($rows);

        if ($count > 0) {
            $model::query()->insert($rows);
        }

        $rows = [];

        return $count;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\HistoricalTrend;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Loads the output of the UPUOnline admission-trend collectors into MongoDB.
 *
 * A copy of the collector output is kept in database/data/upuonline, which is
 * used when no folder is given. Copy newer collector output there to update it.
 *
 * The folder must hold upuonline_spm_admission_data.csv and upuonline_stpm_admission_data.csv.
 * Each row holds the figures for one programme and one group of applicants. The session
 * columns, e.g. applications_session_2425, are read from the header, so a file with
 * more sessions needs no change here.
 */
#[Signature('trends:import {path? : The folder that holds the UPUOnline admission-trend collector output (default: database/data/upuonline)}')]
#[Description('Replace the stored historical admission figures with the UPUOnline collector output')]
class ImportHistoricalTrends extends Command
{
    private const BATCH_SIZE = 500;

    private const LEVELS = ['spm', 'stpm'];

    public function handle(): int
    {
        $folder = rtrim((string) ($this->argument('path') ?? database_path('data/upuonline')), '\\/');
        $files = [];

        foreach (self::LEVELS as $level) {
            $files[$level] = $folder.DIRECTORY_SEPARATOR."upuonline_{$level}_admission_data.csv";

            if (! is_file($files[$level])) {
                $this->error("Cannot find {$files[$level]}");

                return self::FAILURE;
            }
        }

        HistoricalTrend::query()->truncate();

        foreach ($files as $level => $file) {
            [$recordCount, $programmeCount] = $this->importTrends($file, $level);

            $this->info(sprintf('%s: %d records for %d programmes.', strtoupper($level), $recordCount, $programmeCount));
        }

        return self::SUCCESS;
    }

    /**
     * UPUOnline lists some programmes twice, under an old and a new name, with the
     * same figures. Only the first row for each group of applicants is kept.
     *
     * @return array{int, int}
     */
    private function importTrends(string $file, string $level): array
    {
        $handle = fopen($file, 'r');
        $header = fgetcsv($handle, escape: '');
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        $sessions = $this->sessions($header);
        $seen = [];
        $programmes = [];
        $rows = [];
        $count = 0;

        while (($values = fgetcsv($handle, escape: '')) !== false) {
            if ($values === [null]) {
                continue;
            }

            $row = array_combine($header, $values);
            $trend = $this->trend($row, $level, $sessions);
            $key = implode('|', [$trend['programme_code'], $trend['applicant_type'], $trend['group'], $trend['stream'], $trend['gender']]);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $programmes[$trend['programme_code']] = true;
            $rows[] = $trend;

            if (count($rows) === self::BATCH_SIZE) {
                $count += $this->insert($rows);
            }
        }

        fclose($handle);

        return [$count + $this->insert($rows), count($programmes)];
    }

    /**
     * The sessions in the header, e.g. "2425" => "2024/2025".
     *
     * @param  list<string>  $header
     * @return array<string, string>
     */
    private function sessions(array $header): array
    {
        $sessions = [];

        foreach ($header as $column) {
            if (preg_match('/^applications_session_(\d{2})(\d{2})$/', $column, $matches)) {
                $sessions[$matches[1].$matches[2]] = "20{$matches[1]}/20{$matches[2]}";
            }
        }

        ksort($sessions);

        return $sessions;
    }

    /**
     * UPUOnline leaves a count blank when it is zero.
     *
     * @param  array<string, string>  $row
     * @param  array<string, string>  $sessions
     * @return array<string, mixed>
     */
    private function trend(array $row, string $level, array $sessions): array
    {
        $count = fn (string $column): int => (int) ($row[$column] ?? 0);
        $applications = [];
        $offers = [];

        foreach ($sessions as $column => $session) {
            $applications[$session] = $count("applications_session_{$column}");
            $offers[$session] = $count("offers_session_{$column}");
        }

        return [
            'programme_code' => $row['programme_code'],
            'level' => $level,
            'applicant_type' => $level === 'stpm' ? $row['category'] : null,
            'group' => $row['group'],
            'stream' => $level === 'spm' ? $row['stream'] : null,
            'gender' => $row['gender'],
            'average_merit_score' => (float) $row['average_merit'],
            'session_applications' => $applications,
            'session_offers' => $offers,
            'first_choice_applications' => $count('applications_choice_1'),
            'first_choice_offers' => $count('offers_choice_1'),
            'lower_choice_applications' => $count('applications_choice_2') + $count('applications_choice_3') + $count('applications_other_choices'),
            'lower_choice_offers' => $count('offers_choice_2') + $count('offers_choice_3') + $count('offers_other_choices'),
        ];
    }

    /**
     * Insert the rows and empty the list.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function insert(array &$rows): int
    {
        $count = count($rows);

        if ($count > 0) {
            HistoricalTrend::query()->insert($rows);
        }

        $rows = [];

        return $count;
    }
}

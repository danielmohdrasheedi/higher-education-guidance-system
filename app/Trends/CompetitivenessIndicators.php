<?php

namespace App\Trends;

use App\Models\HistoricalTrend;

/**
 * FR07 Historical Trend & Competitiveness Indicators.
 *
 * Adds up a programme's past UPUOnline figures for one applicant type and turns
 * them into numbers and plain sentences that show how hard it was to get an offer.
 */
final class CompetitivenessIndicators
{
    /**
     * Applications and offers by session, oldest first.
     *
     * @var array<string, array{applications: int, offers: int, offerRate: float|null}>
     */
    public readonly array $sessions;

    public readonly int $firstChoiceApplications;

    public readonly int $firstChoiceOffers;

    public readonly int $lowerChoiceApplications;

    public readonly int $lowerChoiceOffers;

    /**
     * @param  list<HistoricalTrend>  $trends  The records for one programme and one applicant type.
     */
    public function __construct(public readonly array $trends)
    {
        $sessions = [];

        foreach ($trends as $trend) {
            foreach ($trend->session_applications as $session => $applications) {
                $sessions[$session]['applications'] = ($sessions[$session]['applications'] ?? 0) + $applications;
                $sessions[$session]['offers'] = ($sessions[$session]['offers'] ?? 0) + ($trend->session_offers[$session] ?? 0);
            }
        }

        ksort($sessions);

        $this->sessions = array_map(fn (array $figures): array => $figures + [
            'offerRate' => self::percentage($figures['offers'], $figures['applications']),
        ], $sessions);

        $this->firstChoiceApplications = array_sum(array_map(fn (HistoricalTrend $trend): int => $trend->first_choice_applications, $trends));
        $this->firstChoiceOffers = array_sum(array_map(fn (HistoricalTrend $trend): int => $trend->first_choice_offers, $trends));
        $this->lowerChoiceApplications = array_sum(array_map(fn (HistoricalTrend $trend): int => $trend->lower_choice_applications, $trends));
        $this->lowerChoiceOffers = array_sum(array_map(fn (HistoricalTrend $trend): int => $trend->lower_choice_offers, $trends));
    }

    /**
     * Whether any session had applications.
     */
    public function hasFigures(): bool
    {
        return $this->latestSession() !== null;
    }

    /**
     * The latest session that had applications, e.g. "2025/2026".
     */
    public function latestSession(): ?string
    {
        $sessions = array_keys(array_filter($this->sessions, fn (array $figures): bool => $figures['applications'] > 0));

        return $sessions === [] ? null : end($sessions);
    }

    /**
     * The session before the latest one, when it had applications.
     */
    public function previousSession(): ?string
    {
        $sessions = array_keys($this->sessions);
        $index = array_search($this->latestSession(), $sessions, true);

        if ($index === false || $index === 0) {
            return null;
        }

        $previous = $sessions[$index - 1];

        return $this->sessions[$previous]['applications'] > 0 ? $previous : null;
    }

    public function applications(): int
    {
        return $this->sessions[$this->latestSession()]['applications'] ?? 0;
    }

    public function offers(): int
    {
        return $this->sessions[$this->latestSession()]['offers'] ?? 0;
    }

    /**
     * The percentage of applications in the latest session that got an offer.
     */
    public function offerRate(): ?float
    {
        return $this->sessions[$this->latestSession()]['offerRate'] ?? null;
    }

    /**
     * About how many applications there were for each offer in the latest session.
     */
    public function applicationsPerOffer(): ?int
    {
        return $this->offers() > 0 ? (int) round($this->applications() / $this->offers()) : null;
    }

    /**
     * The percentage change in applications from the previous session to the latest one.
     */
    public function applicationChange(): ?float
    {
        $previous = $this->previousSession();

        if ($previous === null) {
            return null;
        }

        $before = $this->sessions[$previous]['applications'];

        return round(($this->applications() - $before) / $before * 100, 1);
    }

    /**
     * The percentage of first-choice applications that got an offer, over all sessions.
     */
    public function firstChoiceOfferRate(): ?float
    {
        return self::percentage($this->firstChoiceOffers, $this->firstChoiceApplications);
    }

    /**
     * The percentage of second-or-lower-choice applications that got an offer, over all sessions.
     */
    public function lowerChoiceOfferRate(): ?float
    {
        return self::percentage($this->lowerChoiceOffers, $this->lowerChoiceApplications);
    }

    /**
     * Null when no session had both applications and offers.
     */
    public function competitiveness(): ?Competitiveness
    {
        return $this->offers() > 0 ? Competitiveness::fromOfferRate((float) $this->offerRate()) : null;
    }

    /**
     * The latest session's figures for each group of applicants, most applications first.
     *
     * @return list<array{label: string, applications: int, offers: int, offerRate: float|null}>
     */
    public function segments(): array
    {
        $session = $this->latestSession();

        if ($session === null) {
            return [];
        }

        $segments = array_map(fn (HistoricalTrend $trend): array => [
            'label' => $trend->segmentLabel(),
            'applications' => $trend->session_applications[$session] ?? 0,
            'offers' => $trend->session_offers[$session] ?? 0,
            'offerRate' => self::percentage($trend->session_offers[$session] ?? 0, $trend->session_applications[$session] ?? 0),
        ], $this->trends);

        usort($segments, fn (array $a, array $b): int => [$b['applications'], $a['label']] <=> [$a['applications'], $b['label']]);

        return $segments;
    }

    private static function percentage(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }
}

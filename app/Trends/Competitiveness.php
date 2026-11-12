<?php

namespace App\Trends;

/**
 * How hard it was to get an offer for a programme, judged by the share of applications that got one.
 */
enum Competitiveness: string
{
    case VeryHigh = 'very_high';
    case High = 'high';
    case Moderate = 'moderate';
    case Low = 'low';

    /**
     * @param  float  $offerRate  The percentage of applications that got an offer.
     */
    public static function fromOfferRate(float $offerRate): self
    {
        return match (true) {
            $offerRate < 5 => self::VeryHigh,
            $offerRate < 15 => self::High,
            $offerRate < 35 => self::Moderate,
            default => self::Low,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::VeryHigh => 'Very high',
            self::High => 'High',
            self::Moderate => 'Moderate',
            self::Low => 'Low',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::VeryHigh => 'Fewer than 1 in 20 applications got an offer.',
            self::High => 'Between 1 in 20 and about 1 in 7 applications got an offer.',
            self::Moderate => 'Between about 1 in 7 and 1 in 3 applications got an offer.',
            self::Low => 'More than 1 in 3 applications got an offer.',
        };
    }
}

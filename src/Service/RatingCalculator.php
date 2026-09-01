<?php

declare(strict_types=1);

namespace VRchessIndo\Service;

/**
 * Chess rating calculator. Unchanged from the legacy VRchessIndo\Logic\Rating:
 * a custom bracketed win/draw/loss point table (not fixed-K Elo), keyed off
 * the standard logistic expected-score formula.
 */
class RatingCalculator
{
    public const int WIN = 1;
    public const float DRAW = 0.5;
    public const int LOSS = 0;

    public function expectedScore(float|int $rating, float|int $opponentRating): float
    {
        return 1 / (1 + 10 ** (($opponentRating - $rating) / 400));
    }

    /**
     * @return array{old_rating: int|float, new_rating: int|float, change: int, expected: float}
     */
    public function calculate(float|int $rating, float|int $opponentRating, float|int $result): array
    {
        $expected = $this->expectedScore($rating, $opponentRating);

        if ($expected <= 0.20) {
            // Huge underdog
            $win = 60;
            $draw = 20;
            $loss = 0;
        } elseif ($expected <= 0.40) {
            $win = 45;
            $draw = 10;
            $loss = -2;
        } elseif ($expected <= 0.60) {
            $win = 30;
            $draw = 0;
            $loss = -5;
        } elseif ($expected <= 0.80) {
            $win = 20;
            $draw = -3;
            $loss = -8;
        } else {
            // Heavy favorite
            $win = 10;
            $draw = -8;
            $loss = -15;
        }

        if ($result == self::WIN) {
            $delta = $win;
        } elseif ($result == self::DRAW) {
            $delta = $draw;
        } else {
            $delta = $loss;
        }

        return [
            'old_rating' => $rating,
            'new_rating' => $rating + $delta,
            'change' => $delta,
            'expected' => round($expected * 100, 2),
        ];
    }
}

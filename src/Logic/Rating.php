<?php

namespace VRchessIndo\Logic;

/**
 * Class Rating
 * 
 * Handles chess rating calculations and expected scores.
 */
class Rating
{
    public const int WIN = 1;
    public const float DRAW = 0.5;
    public const int LOSS = 0;

    /**
     * Calculates the expected score for a player against an opponent.
     * 
     * @param float|int $rating The player's current rating.
     * @param float|int $opponentRating The opponent's current rating.
     * @return float The expected score (between 0.0 and 1.0).
     */
    public static function expectedScore($rating, $opponentRating)
    {
        return 1 / (1 + pow(10, ($opponentRating - $rating) / 400));
    }

    /**
     * Calculates the new rating and rating change after a match.
     * 
     * @param float|int $rating The player's current rating.
     * @param float|int $opponentRating The opponent's current rating.
     * @param float|int $result The match result (Rating::WIN, Rating::DRAW, Rating::LOSS).
     * @return array{change: int, expected: float, new_rating: int, old_rating: int|float}
     */
    public static function calculate($rating, $opponentRating, $result)
    {
        $expected = self::expectedScore($rating, $opponentRating);

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

        if ($result == Rating::WIN) {
            $delta = $win;
        } elseif ($result == Rating::DRAW) {
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

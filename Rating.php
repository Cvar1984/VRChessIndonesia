<?php

class Rating
{
    public const WIN = 1;
    public const DRAW = 0.5;
    public const LOSS = 0;

    /**
     * Expected score (0.0 - 1.0)
     * @param mixed $rating
     * @param mixed $opponentRating
     * @return float|int
     */
    public static function expectedScore($rating, $opponentRating)
    {
        return 1 / (1 + pow(10, ($opponentRating - $rating) / 400));
    }

    /**
     * Summary of calculate
     * @param mixed $rating
     * @param mixed $opponentRating
     * @param mixed $result
     * @return array{change: int, expected: float, new_rating: int, old_rating: mixed}
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

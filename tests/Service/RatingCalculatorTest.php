<?php

declare(strict_types=1);

namespace VRchessIndo\Tests\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VRchessIndo\Service\RatingCalculator;

/**
 * Pure math, no I/O — MatchManagerTest exercises this indirectly through a
 * couple of real match scenarios, but never systematically covers all 5
 * expected-score brackets (each with its own win/draw/loss point table) or
 * expectedScore() directly. This does.
 */
class RatingCalculatorTest extends TestCase
{
    private RatingCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new RatingCalculator();
    }

    public function testExpectedScoreIsHalfForEqualRatings(): void
    {
        self::assertEqualsWithDelta(0.5, $this->calculator->expectedScore(1000, 1000), 0.0001);
    }

    public function testExpectedScoreFavorsHigherRating(): void
    {
        $favorite = $this->calculator->expectedScore(1300, 1000);
        $underdog = $this->calculator->expectedScore(1000, 1300);

        self::assertGreaterThan(0.5, $favorite);
        self::assertLessThan(0.5, $underdog);
        // Symmetric around 0.5 — favorite's edge equals underdog's deficit.
        self::assertEqualsWithDelta(1.0, $favorite + $underdog, 0.0001);
    }

    /**
     * @return array<string, array{0: float, 1: float, 2: int, 3: int, 4: int}>
     *   [rating, opponentRating, win, draw, loss] — the bracket's point table,
     *   for a rating pair confirmed (by the standard logistic formula) to
     *   land well inside that bracket, away from the boundary.
     */
    public static function ratingBrackets(): array
    {
        return [
            'huge underdog (expected <= 0.20)' => [1000, 1300, 60, 20, 0],
            'underdog (0.20 < expected <= 0.40)' => [1000, 1100, 45, 10, -2],
            'even match (0.40 < expected <= 0.60)' => [1000, 1000, 30, 0, -5],
            'favorite (0.60 < expected <= 0.80)' => [1000, 900, 20, -3, -8],
            'heavy favorite (expected > 0.80)' => [1000, 700, 10, -8, -15],
        ];
    }

    #[DataProvider('ratingBrackets')]
    public function testCalculateAppliesCorrectBracketPointsForEachResult(
        float $rating,
        float $opponentRating,
        int $winDelta,
        int $drawDelta,
        int $lossDelta,
    ): void {
        $win = $this->calculator->calculate($rating, $opponentRating, RatingCalculator::WIN);
        self::assertSame($winDelta, $win['change']);
        self::assertSame($rating + $winDelta, $win['new_rating']);
        self::assertSame($rating, $win['old_rating']);

        $draw = $this->calculator->calculate($rating, $opponentRating, RatingCalculator::DRAW);
        self::assertSame($drawDelta, $draw['change']);
        self::assertSame($rating + $drawDelta, $draw['new_rating']);

        $loss = $this->calculator->calculate($rating, $opponentRating, RatingCalculator::LOSS);
        self::assertSame($lossDelta, $loss['change']);
        self::assertSame($rating + $lossDelta, $loss['new_rating']);
    }

    public function testCalculateExpectedFieldIsPercentageRoundedToTwoDecimals(): void
    {
        $result = $this->calculator->calculate(1000, 1000, RatingCalculator::WIN);

        self::assertSame(50.0, $result['expected']);
    }

    public function testBracketBoundariesAreInclusiveOfTheLowerWinTable(): void
    {
        // expectedScore(x, x) == exactly 0.5 — the "<= 0.60" branch, not "> 0.60".
        $atBoundary = $this->calculator->calculate(1000, 1000, RatingCalculator::WIN);
        self::assertSame(30, $atBoundary['change'], 'expected==0.5 must take the 0.40-0.60 bracket (win=30), not the next one');
    }
}

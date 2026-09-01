<?php

declare(strict_types=1);

namespace VRchessIndo\Tests\Service\Engine;

use PHPUnit\Framework\TestCase;
use VRchessIndo\Service\Engine\StockfishEngine;

/**
 * Exercises StockfishEngine against the real local binary (/usr/bin/stockfish
 * — present in this environment, and this is a pure local subprocess with no
 * external network involved, unlike VRChatClient's API calls, so there's no
 * reason to mock it). Kept to shallow depths/short movetimes throughout to
 * keep the suite fast.
 */
class StockfishEngineTest extends TestCase
{
    private const string STARTPOS = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';

    public function testAnalyzeStartingPositionReturnsABestMove(): void
    {
        $engine = new StockfishEngine('/usr/bin/stockfish', 1, 16, 1, false);
        $result = $engine->analyze(self::STARTPOS, 6);

        self::assertNotNull($result['bestmove']);
        self::assertMatchesRegularExpression('/^[a-h][1-8][a-h][1-8][qrbn]?$/', $result['bestmove']);
        self::assertSame(6, $result['depth']);
        self::assertSame('cp', $result['score_type']);
        self::assertIsInt($result['score']);
        self::assertNotEmpty($result['pv']);
    }

    public function testMovetimeModeIsRespected(): void
    {
        $engine = new StockfishEngine('/usr/bin/stockfish', 1, 16, 1, false);
        $result = $engine->analyze(self::STARTPOS, null, 100);

        self::assertNotNull($result['bestmove']);
        // Stockfish reports actual elapsed wall-clock time, not the exact
        // requested movetime — allow a small margin either side.
        self::assertEqualsWithDelta(100, $result['time'], 50);
    }

    public function testSameEngineInstanceCanAnalyzeMultiplePositionsInSequence(): void
    {
        // Mirrors legacy analyzeFens()'s batch pattern: one Stockfish process,
        // many analyze() calls, each preceded by ucinewgame internally.
        $engine = new StockfishEngine('/usr/bin/stockfish', 1, 16, 1, false);

        $first = $engine->analyze(self::STARTPOS, 6);
        $second = $engine->analyze('rnbqkbnr/pppp1ppp/8/4p3/4P3/8/PPPP1PPP/RNBQKBNR w KQkq - 0 2', 6);

        self::assertNotNull($first['bestmove']);
        self::assertNotNull($second['bestmove']);
    }

    public function testOnInfoCallbackIsInvokedDuringAnalysis(): void
    {
        $engine = new StockfishEngine('/usr/bin/stockfish', 1, 16, 1, false);

        $callCount = 0;
        $sawIncreasingDepth = false;
        $lastDepth = 0;

        $engine->analyze(self::STARTPOS, 10, null, function (array $res) use (&$callCount, &$sawIncreasingDepth, &$lastDepth): void {
            $callCount++;
            if (($res['depth'] ?? 0) > $lastDepth) {
                $sawIncreasingDepth = true;
                $lastDepth = $res['depth'];
            }
        });

        self::assertGreaterThan(0, $callCount);
        self::assertTrue($sawIncreasingDepth);
    }

    public function testMultiPvPopulatesMultipleLines(): void
    {
        $engine = new StockfishEngine('/usr/bin/stockfish', 1, 16, 3, false);
        $result = $engine->analyze(self::STARTPOS, 8);

        self::assertGreaterThanOrEqual(2, count(array_filter($result['multipv'], static fn ($line) => $line['pv'] !== [])));
    }

    public function testInvalidBinaryPathThrows(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unable to start Stockfish.');

        new StockfishEngine('/no/such/binary/anywhere', 1, 16, 1, false);
    }

    public function testDetectsCheckmatedPositionWithMateScoreType(): void
    {
        // Fool's mate's final position (Black's Qh4# already delivered) —
        // verified empirically to report score_type=mate, score=0.
        $engine = new StockfishEngine('/usr/bin/stockfish', 1, 16, 1, false);
        $result = $engine->analyze('rnb1kbnr/pppp1ppp/8/4p3/6Pq/5P2/PPPPP2P/RNBQKBNR w KQkq - 1 3', 10);

        self::assertSame('mate', $result['score_type']);
        self::assertSame(0, $result['score']);
    }

    public function testEvalBarMapsZeroToFiftyPercent(): void
    {
        self::assertSame(50.0, StockfishEngine::evalBar(0.0));
    }

    public function testEvalBarIsMonotonicallyIncreasing(): void
    {
        self::assertGreaterThan(StockfishEngine::evalBar(0.0), StockfishEngine::evalBar(200.0));
        self::assertGreaterThan(StockfishEngine::evalBar(200.0), StockfishEngine::evalBar(800.0));
        self::assertGreaterThan(StockfishEngine::evalBar(-200.0), StockfishEngine::evalBar(0.0));
    }

    public function testEvalBarApproachesButNeverReachesBounds(): void
    {
        self::assertGreaterThan(99.0, StockfishEngine::evalBar(2000.0));
        self::assertLessThan(100.0, StockfishEngine::evalBar(2000.0));
        self::assertLessThan(1.0, StockfishEngine::evalBar(-2000.0));
        self::assertGreaterThan(0.0, StockfishEngine::evalBar(-2000.0));
    }
}

<?php

declare(strict_types=1);

namespace VRchessIndo\Service\Engine;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds a fresh StockfishEngine per call. Not a shared/singleton service
 * itself — each engine owns one live subprocess with its own stdin/stdout,
 * so concurrent requests must never share one (their UCI commands and
 * output would interleave). Batch analysis reuses a single instance across
 * many analyze() calls (via `ucinewgame` between positions, same as
 * legacy's analyzeFens()) — that reuse is the caller's responsibility, this
 * factory only owns *starting* one.
 */
class StockfishEngineFactory
{
    public function __construct(
        #[Autowire(env: 'STOCKFISH_BINARY')] private readonly string $binary,
    ) {
    }

    /**
     * @throws \Exception if the Stockfish process cannot be started
     */
    public function create(int $multipv = 1, bool $chess960 = false, int $threads = 4, int $hash = 16): StockfishEngine
    {
        return new StockfishEngine($this->binary, $threads, $hash, $multipv, $chess960);
    }
}

<?php

declare(strict_types=1);

namespace VRchessIndo\Service\Engine;

use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * Wraps the Stockfish chess engine binary (UCI protocol) to evaluate
 * positions and find best moves. Ported from a raw proc_open()/fgets() pair
 * onto Symfony's Process component.
 *
 * Symfony's Process is built around "run a command, capture its output" —
 * it has no built-in fgets()-style blocking readline for a long-lived,
 * multi-round-trip REPL like UCI (write a command, read until a marker,
 * write another, read more — repeated across many analyze() calls on the
 * same engine process). readLine()/pump() below replicate that on top of
 * InputStream (writes) and getIncrementalOutput() (reads), buffering partial
 * output between polls — the same shape as the legacy fgets() loop, just
 * polling (2ms) instead of blocking on the pipe directly.
 */
class StockfishEngine
{
    private const int POLL_MICROSECONDS = 2000;

    private Process $process;
    private InputStream $input;
    private string $buffer = '';

    /**
     * @throws \Exception if the process cannot be started
     */
    public function __construct(
        string $binary = '/usr/bin/stockfish',
        int $threads = 4,
        int $hash = 512,
        int $multipv = 1,
        bool $chess960 = false,
    ) {
        $this->input = new InputStream();
        $this->process = new Process([$binary]);
        $this->process->setInput($this->input);
        $this->process->setTimeout(null);

        try {
            $this->process->start();
        } catch (\Throwable $e) {
            throw new \Exception('Unable to start Stockfish.', 0, $e);
        }

        if (!$this->process->isRunning()) {
            throw new \Exception('Unable to start Stockfish.');
        }

        // A binary that doesn't exist (or isn't executable) can still leave
        // isRunning() true momentarily right after start() — the exec
        // failure only surfaces once we actually try to talk to it, which
        // would otherwise throw the less accurate "exited unexpectedly"
        // from waitFor(). Anything going wrong during this handshake really
        // is a "couldn't start" failure from the caller's point of view.
        try {
            $this->command('uci');
            $this->waitFor('uciok');

            $this->command('setoption name Threads value ' . max(1, $threads));
            $this->command('setoption name Hash value ' . max(1, $hash));
            $this->command('setoption name MultiPV value ' . max(0, $multipv));

            if ($chess960) {
                $this->command('setoption name UCI_Chess960 value true');
            }

            $this->command('isready');
            $this->waitFor('readyok');
        } catch (\Throwable $e) {
            throw new \Exception('Unable to start Stockfish.', 0, $e);
        }
    }

    private function command(string $cmd): void
    {
        $this->input->write($cmd . "\n");
    }

    /**
     * Reads and discards lines until one contains $needle (matching the
     * legacy fgets()+strpos() loop) or the process exits without ever
     * producing it, which is treated as a hard failure — unlike the legacy
     * version, which would silently fall through and let the caller
     * continue as though initialization succeeded.
     */
    private function waitFor(string $needle): void
    {
        while (true) {
            $line = $this->readLine();
            if ($line === null) {
                throw new \Exception('Stockfish process exited unexpectedly.');
            }
            if (str_contains($line, $needle)) {
                return;
            }
        }
    }

    /**
     * Blocking (via polling) readline, or null once the process has exited
     * and no more buffered output remains.
     */
    private function readLine(): ?string
    {
        while (!str_contains($this->buffer, "\n")) {
            $chunk = $this->process->getIncrementalOutput();
            if ($chunk !== '') {
                $this->buffer .= $chunk;
                continue;
            }

            if (!$this->process->isRunning()) {
                if ($this->buffer === '') {
                    return null;
                }
                // Process exited with a trailing, unterminated line — return it.
                $line = $this->buffer;
                $this->buffer = '';
                return $line;
            }

            usleep(self::POLL_MICROSECONDS);
        }

        $pos = strpos($this->buffer, "\n");
        $line = substr($this->buffer, 0, $pos);
        $this->buffer = substr($this->buffer, $pos + 1);

        return $line;
    }

    /**
     * Converts a centipawn score to a win probability percentage (0.0 to 100.0).
     */
    public static function evalBar(float $cp): float
    {
        return 100.0 / (1.0 + exp(-$cp / 120.0));
    }

    /**
     * Analyzes a position given a FEN string.
     *
     * @param callable|null $onInfoCallback Optional callback invoked when Stockfish outputs info lines during computation.
     * @return array The analysis results including bestmove, score, and principal variation.
     */
    public function analyze(string $fen, ?int $depth = 15, ?int $movetime = null, ?callable $onInfoCallback = null): array
    {
        $this->command('ucinewgame');
        $this->command('isready');
        $this->waitFor('readyok');

        $this->command("position fen {$fen}");

        if ($movetime !== null) {
            $this->command("go movetime {$movetime}");
        } else {
            $this->command("go depth {$depth}");
        }

        $result = [
            'info' => [],
            'depth' => null,
            'seldepth' => null,
            'time' => null,
            'nodes' => null,
            'nps' => null,
            'score' => null,
            'score_type' => null,
            'eval' => null,
            'pv' => [],
            'bestmove' => null,
            'ponder' => null,
            'multipv' => [],
        ];

        while (true) {
            $line = $this->readLine();
            if ($line === null) {
                break;
            }
            $line = trim($line);

            if (str_starts_with($line, 'info')) {
                $result['info'][] = $line;

                preg_match('/depth\s+(\d+)/', $line, $m);
                if (isset($m[1])) {
                    $result['depth'] = (int) $m[1];
                }

                preg_match('/seldepth\s+(\d+)/', $line, $m);
                if (isset($m[1])) {
                    $result['seldepth'] = (int) $m[1];
                }

                preg_match('/time\s+(\d+)/', $line, $m);
                if (isset($m[1])) {
                    $result['time'] = (int) $m[1];
                }

                preg_match('/nodes\s+(\d+)/', $line, $m);
                if (isset($m[1])) {
                    $result['nodes'] = (int) $m[1];
                }

                preg_match('/nps\s+(\d+)/', $line, $m);
                if (isset($m[1])) {
                    $result['nps'] = (int) $m[1];
                }

                $mpvIndex = 1;
                if (preg_match('/multipv\s+(\d+)/', $line, $m)) {
                    $mpvIndex = (int) $m[1];
                }

                if (!isset($result['multipv'][$mpvIndex])) {
                    $result['multipv'][$mpvIndex] = ['score' => null, 'score_type' => null, 'eval' => null, 'pv' => []];
                }

                $hasInfoData = false;

                if (preg_match('/score\s+(cp|mate)\s+(-?\d+)/', $line, $m)) {
                    $scoreType = $m[1];
                    $scoreVal = (int) $m[2];
                    $result['multipv'][$mpvIndex]['score_type'] = $scoreType;
                    $result['multipv'][$mpvIndex]['score'] = $scoreVal;
                    $result['multipv'][$mpvIndex]['eval'] = self::evalBar($scoreVal);

                    if ($mpvIndex === 1) {
                        $result['score_type'] = $scoreType;
                        $result['score'] = $scoreVal;
                        $result['eval'] = self::evalBar($scoreVal);
                    }
                    $hasInfoData = true;
                }

                if (preg_match('/ pv (.+)$/', $line, $m)) {
                    $pvArr = explode(' ', trim($m[1]));
                    $result['multipv'][$mpvIndex]['pv'] = $pvArr;

                    if ($mpvIndex === 1) {
                        $result['pv'] = $pvArr;
                        if (!empty($pvArr[0])) {
                            $result['bestmove'] = $pvArr[0];
                        }
                    }
                    $hasInfoData = true;
                }

                if ($onInfoCallback && $hasInfoData) {
                    $onInfoCallback($result, $line);
                }
            }

            if (str_starts_with($line, 'bestmove')) {
                $parts = preg_split('/\s+/', $line);

                $result['bestmove'] = $parts[1] ?? ($result['pv'][0] ?? null);
                $result['ponder'] = $parts[3] ?? null;

                break;
            }
        }

        return $result;
    }

    public function __destruct()
    {
        if (!isset($this->process) || !$this->process->isRunning()) {
            return;
        }

        try {
            $this->input->write("quit\n");
        } catch (\Throwable) {
            // Input stream may already be unusable; fall through to a hard stop.
        }
        $this->input->close();
        $this->process->stop(3);
    }
}

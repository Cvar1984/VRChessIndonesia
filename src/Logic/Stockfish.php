<?php

namespace VRchessIndo\Logic;
/**
 * Class Stockfish
 * Wraps the Stockfish chess engine binary to evaluate positions and find best moves.
 */
class Stockfish
{
    private $process;
    private $pipes;

    /**
     * Stockfish constructor. Starts the background process.
     * 
     * @param string $binary Path to the Stockfish binary.
     * @param int $threads Number of threads to use.
     * @param int $hash Hash size in MB.
     * @param int $multipv Number of principal variations.
     * @throws \Exception if the process cannot be started.
     */
    public function __construct(
        string $binary = "/usr/bin/stockfish",
        int $threads = 4,
        int $hash = 512,
        int $multipv = 1,
    ) {
        $this->process = proc_open(
            $binary,
            [
                0 => ["pipe", "r"],
                1 => ["pipe", "w"],
                2 => ["pipe", "w"],
            ],
            $this->pipes
        );

        if (!is_resource($this->process)) {
            throw new \Exception("Unable to start Stockfish.");
        }

        stream_set_blocking($this->pipes[1], true);

        $this->command("uci");
        $this->waitFor("uciok");

        $this->command("setoption name Threads value " . max(1, (int) $threads));
        $this->command("setoption name Hash value " . max(1, (int) $hash));
        $this->command("setoption name MultiPV value " . max(0, (int) $multipv));

        $this->command("isready");
        $this->waitFor("readyok");
    }

    /**
     * Sends a command to the Stockfish process.
     * 
     * @param string $cmd The command string.
     */
    private function command(string $cmd): void
    {
        fwrite($this->pipes[0], $cmd . PHP_EOL);
    }

    /**
     * Waits for a specific string in the Stockfish output.
     * 
     * @param string $needle The string to look for.
     */
    private function waitFor(string $needle): void
    {
        while (($line = fgets($this->pipes[1])) !== false) {
            if (strpos($line, $needle) !== false) {
                return;
            }
        }
    }

    /**
     * Converts a centipawn score to a win probability percentage (0.0 to 100.0).
     * 
     * @param float $cp The centipawn score.
     * @return float The win probability.
     */
    public static function evalBar(float $cp): float
    {
        return 100.0 / (1.0 + exp(-$cp / 120.0));
    }

    /**
     * Analyzes a position given a FEN string.
     * 
     * @param string $fen The FEN string to analyze.
     * @param int|null $depth The depth to analyze to.
     * @param int|null $movetime The time to spend analyzing in milliseconds.
     * @param callable|null $onInfoCallback Optional callback invoked when Stockfish outputs info lines during computation.
     * @return array The analysis results including bestmove, score, and principal variation.
     */
    public function analyze(string $fen, ?int $depth = 15, ?int $movetime = null, ?callable $onInfoCallback = null): array
    {
        $this->command("ucinewgame");
        $this->command("isready");
        $this->waitFor("readyok");

        $this->command("position fen {$fen}");

        if ($movetime !== null) {
            $this->command("go movetime {$movetime}");
        } else {
            $this->command("go depth {$depth}");
        }

        $result = [
            "info" => [],
            "depth" => null,
            "seldepth" => null,
            "time" => null,
            "nodes" => null,
            "nps" => null,
            "score" => null,
            "score_type" => null,
            "eval" => null,
            "pv" => [],
            "bestmove" => null,
            "ponder" => null,
            "multipv" => []
        ];

        while (($line = fgets($this->pipes[1])) !== false) {

            $line = trim($line);

            if (strpos($line, "info") === 0) {

                $result["info"][] = $line;

                preg_match('/depth\s+(\d+)/', $line, $m);
                if (isset($m[1]))
                    $result["depth"] = (int) $m[1];

                preg_match('/seldepth\s+(\d+)/', $line, $m);
                if (isset($m[1]))
                    $result["seldepth"] = (int) $m[1];

                preg_match('/time\s+(\d+)/', $line, $m);
                if (isset($m[1])) $result["time"] = (int) $m[1];

                preg_match('/nodes\s+(\d+)/', $line, $m);
                if (isset($m[1])) $result["nodes"] = (int) $m[1];

                preg_match('/nps\s+(\d+)/', $line, $m);
                if (isset($m[1])) $result["nps"] = (int) $m[1];

                $mpvIndex = 1;
                if (preg_match('/multipv\s+(\d+)/', $line, $m)) {
                    $mpvIndex = (int)$m[1];
                }

                if (!isset($result["multipv"])) $result["multipv"] = [];
                if (!isset($result["multipv"][$mpvIndex])) {
                    $result["multipv"][$mpvIndex] = ["score" => null, "score_type" => null, "eval" => null, "pv" => []];
                }

                $hasInfoData = false;

                if (preg_match('/score\s+(cp|mate)\s+(-?\d+)/', $line, $m)) {
                    $scoreType = $m[1];
                    $scoreVal = (int) $m[2];
                    $result["multipv"][$mpvIndex]["score_type"] = $scoreType;
                    $result["multipv"][$mpvIndex]["score"] = $scoreVal;
                    $result["multipv"][$mpvIndex]["eval"] = Stockfish::evalBar($scoreVal);
                    
                    if ($mpvIndex === 1) {
                        $result["score_type"] = $scoreType;
                        $result["score"] = $scoreVal;
                        $result["eval"] = Stockfish::evalBar($scoreVal);
                    }
                    $hasInfoData = true;
                }

                if (preg_match('/ pv (.+)$/', $line, $m)) {
                    $pvArr = explode(' ', trim($m[1]));
                    $result["multipv"][$mpvIndex]["pv"] = $pvArr;
                    
                    if ($mpvIndex === 1) {
                        $result["pv"] = $pvArr;
                        if (!empty($pvArr[0])) {
                            $result["bestmove"] = $pvArr[0];
                        }
                    }
                    $hasInfoData = true;
                }

                if ($onInfoCallback && $hasInfoData) {
                    $onInfoCallback($result, $line);
                }
            }

            if (strpos($line, "bestmove") === 0) {

                $parts = preg_split('/\s+/', $line);

                $result["bestmove"] = $parts[1] ?? ($result["pv"][0] ?? null);
                $result["ponder"] = $parts[3] ?? null;

                break;
            }
        }

        return $result;
    }

    /**
     * Stockfish destructor. Gracefully quits the process.
     */
    public function __destruct()
    {
        if (is_resource($this->process)) {
            $this->command("quit");
            proc_close($this->process);
        }
    }
}

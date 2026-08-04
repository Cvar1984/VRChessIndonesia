<?php

namespace VRchessIndo\Logic;
class Stockfish
{
    private $process;
    private $pipes;

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

    private function command(string $cmd): void
    {
        fwrite($this->pipes[0], $cmd . PHP_EOL);
    }

    private function waitFor(string $needle): void
    {
        while (($line = fgets($this->pipes[1])) !== false) {
            if (strpos($line, $needle) !== false) {
                return;
            }
        }
    }

    public static function evalBar(float $cp): float
    {
        return 100.0 / (1.0 + exp(-$cp / 120.0));
    }

    public function analyze(string $fen, ?int $depth = 15, ?int $movetime = null): array
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
            "pv" => []
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
                if (isset($m[1]))
                    $result["time"] = (int) $m[1];

                preg_match('/nodes\s+(\d+)/', $line, $m);
                if (isset($m[1]))
                    $result["nodes"] = (int) $m[1];

                preg_match('/nps\s+(\d+)/', $line, $m);
                if (isset($m[1]))
                    $result["nps"] = (int) $m[1];

                if (preg_match('/score\s+(cp|mate)\s+(-?\d+)/', $line, $m)) {
                    $result["score_type"] = $m[1];
                    $result["score"] = (int) $m[2];
                    $result["eval"] = Stockfish::evalBar($result["score"]);
                }

                if (preg_match('/ pv (.+)$/', $line, $m)) {
                    $result["pv"] = explode(' ', trim($m[1]));
                }
            }

            if (strpos($line, "bestmove") === 0) {

                $parts = preg_split('/\s+/', $line);

                $result["bestmove"] = $parts[1] ?? null;
                $result["ponder"] = $parts[3] ?? null;

                break;
            }
        }

        return $result;
    }

    public function __destruct()
    {
        if (is_resource($this->process)) {
            $this->command("quit");
            proc_close($this->process);
        }
    }
}

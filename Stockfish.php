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


function jsonResponse(array $data, int $status = 200)
{
    http_response_code($status);
    header("Content-Type: application/json");
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function getRequest(): array
{
    $data = [];

    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $raw = file_get_contents("php://input");
        $json = json_decode($raw, true);

        if (is_array($json)) {
            $data = $json;
        }
    }

    return array_merge($_GET, $data);
}

function sanitizeFen(string $fen): string
{
    $fen = trim($fen);

    if (strlen($fen) > 128) {
        throw new \Exception("FEN too long.");
    }

    if (preg_match('/[\r\n\x00]/', $fen)) {
        throw new \Exception("Invalid characters in FEN.");
    }

    // Basic FEN character whitelist
    if (!preg_match('/^[prnbqkPRNBQK1-8\/wb\-\sKQkqa-h0-9]+$/', $fen)) {
        throw new \Exception("Invalid FEN.");
    }

    return $fen;
}

/* ---------------------- */

try {

    $request = getRequest();

    if (empty($request["fen"])) {
        jsonResponse([
            "error" => "Missing 'fen'"
        ], 400);
    }

    $fen = sanitizeFen($request["fen"]);

    $depth = isset($request["depth"])
        && is_numeric($request['depth'])
        && $request['depth'] >= 1
        && $request['depth'] <= 30
        ? (int) $request["depth"]
        : null;

    $movetime = isset($request["movetime"])
        && is_numeric($request['movetime'])
        && $request['movetime'] >= 100
        && $request['movetime'] <= 10000 //10 sec
        ? (int) $request["movetime"]
        : null;

    if ($depth === null && $movetime === null) {
        $depth = 15;
    }

    // movetime takes priority
    if ($movetime !== null) {
        $depth = null;
    }

    $sf = new Stockfish(
        $binary = "/usr/bin/stockfish",
        $threads = 4,
        $hash = 16
    );

    $result = $sf->analyze(
        $fen,
        $depth,
        $movetime
    );

    jsonResponse($result);

} catch (\Throwable $e) {

    jsonResponse([
        "error" => $e->getMessage()
    ], 500);

}
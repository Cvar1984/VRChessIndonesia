<?php

use VRchessIndo\Logic\Stockfish;

require_once __DIR__ . '/vendor/autoload.php';

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

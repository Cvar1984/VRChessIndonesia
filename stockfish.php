<?php

require_once __DIR__ . '/vendor/autoload.php';

// Handle CORS preflight
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Token, Authorization");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

use VRchessIndo\Logic\Stockfish;

/**
 * Send a JSON response to the client.
 * 
 * This function sends a JSON response to the client with the given data and status code.
 * 
 * @param array $data The data to send as JSON.
 * @param int $status The HTTP status code.
 */
function jsonResponse(array $data, int $status = 200)
{
    http_response_code($status);
    header("Content-Type: application/json");
    header("Access-Control-Allow-Origin: *");
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Get the request data.
 * 
 * This function retrieves the request data from the request body.
 * 
 * @return array An array containing the request data.
 */
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

/**
 * Sanitize a FEN string.
 * 
 * This function validates and cleans a FEN string.
 * 
 * @param string $fen The FEN string to sanitize.
 * @return string The sanitized FEN string.
 */
function sanitizeFen(string $fen): string
{
    $fen = trim($fen);
    if (strlen($fen) > 128) throw new \Exception("FEN too long.");
    if (preg_match('/[\r\n\x00]/', $fen)) throw new \Exception("Invalid characters in FEN.");
    if (!preg_match('/^[prnbqkPRNBQK1-8\/wb\-\sKQkqa-h0-9]+$/', $fen)) throw new \Exception("Invalid FEN.");
    return $fen;
}

/**
 * Parse PGN header tags and move text from a PGN string.
 * 
 * This function extracts header tags and move text from a PGN string.
 * 
 * @param string $pgn The PGN string to parse.
 * @return array An array containing headers and move text.
 */
function parsePgn(string $pgn): array
{
    $headers = [];

    // Extract headers
    preg_match_all('/\[(\w+)\s+"([^"]*)"\]/', $pgn, $hMatches, PREG_SET_ORDER);
    foreach ($hMatches as $m) {
        $headers[$m[1]] = $m[2];
    }

    // Strip headers from pgn to get move text
    $moveText = preg_replace('/\[[^\]]*\]/', '', $pgn);
    // Remove comments {…} and (…)
    $moveText = preg_replace('/\{[^}]*\}/', '', $moveText);
    $moveText = preg_replace('/\([^)]*\)/', '', $moveText);
    // Remove NAG annotations ($1, $2 …)
    $moveText = preg_replace('/\$\d+/', '', $moveText);
    // Remove result
    $moveText = preg_replace('/1-0|0-1|1\/2-1\/2|\*/', '', $moveText);
    // Remove move numbers (1. 1... 1…)
    $moveText = preg_replace('/\d+\.{1,3}\s*/', '', $moveText);

    $tokens = preg_split('/\s+/', trim($moveText));
    $movesSan = [];
    foreach ($tokens as $t) {
        $t = trim($t);
        if ($t !== '') {
            $movesSan[] = $t;
        }
    }

    return ['headers' => $headers, 'moves_san' => $movesSan];
}

/**
 * Analyze multiple FEN positions using Stockfish.
 * 
 * This function takes an array of FEN strings and analyzes each one using Stockfish.
 * It collects the best move and evaluation for each position.
 * 
 * @param array $fens Array of FEN strings to analyze.
 * @param int $depth Search depth for Stockfish.
 * @return array Array of analysis results, each containing FEN, bestmove, score, etc.
 */
function analyzeFens(array $fens, int $depth): array
{
    $sf = new Stockfish('/usr/bin/stockfish', 4, 16);
    $results = [];

    foreach ($fens as $i => $fen) {
        $res = $sf->analyze($fen, $depth);

        $scoreDisplay = null;
        $scoreCp = null;
        if ($res['score_type'] === 'cp') {
            $cp = (int) $res['score'];
            // Always from White's perspective
            $parts = explode(' ', $fen);
            $turn = $parts[1] ?? 'w';
            if ($turn === 'b') $cp = -$cp;
            $scoreCp = $cp;
            $scoreDisplay = round($cp / 100, 2);
        } elseif ($res['score_type'] === 'mate') {
            $cp = (int) $res['score'];
            $parts = explode(' ', $fen);
            $turn = $parts[1] ?? 'w';
            if ($turn === 'b') $cp = -$cp;
            $scoreCp = $cp > 0 ? 9999 : -9999;
            $scoreDisplay = "M{$cp}";
        }

        $results[] = [
            'fen'        => $fen,
            'move_index' => $i,
            'score'      => $scoreDisplay,
            'score_cp'   => $scoreCp,
            'score_type' => $res['score_type'],
            'bestmove'   => $res['bestmove'],
            'pv'         => array_slice($res['pv'] ?? [], 0, 6),
            'depth'      => $res['depth'],
        ];
    }

    return $results;
}

try {
    $request = getRequest();

    // ── Endpoint: Batch FEN analysis (for full PGN game) ───────────────
    // POST { fens: [...], depth: 12 }
    if (!empty($request['fens'])) {
        $fens = $request['fens'];
        if (!is_array($fens) || count($fens) > 120) {
            jsonResponse(['error' => 'fens must be an array with max 120 positions.'], 400);
        }

        $depth = isset($request['depth']) && is_numeric($request['depth'])
            && $request['depth'] >= 1 && $request['depth'] <= 20
            ? (int) $request['depth'] : 12;

        // Validate all FENs
        $cleanFens = [];
        foreach ($fens as $f) {
            $cleanFens[] = sanitizeFen((string) $f);
        }

        set_time_limit(0); // Batch analysis can take time
        $positions = analyzeFens($cleanFens, $depth);

        jsonResponse([
            'success'   => true,
            'positions' => $positions,
            'depth'     => $depth,
            'count'     => count($positions),
        ]);
    }

    // ── Endpoint: Single FEN analysis ──────────────────────────────────
    if (empty($request['fen'])) {
        jsonResponse(['error' => "Missing 'fen' or 'fens'"], 400);
    }

    $fen = sanitizeFen($request['fen']);

    $depth = isset($request['depth']) && is_numeric($request['depth'])
        && $request['depth'] >= 1 && $request['depth'] <= 30
        ? (int) $request['depth'] : null;

    $movetime = isset($request['movetime']) && is_numeric($request['movetime'])
        && $request['movetime'] >= 100 && $request['movetime'] <= 10000
        ? (int) $request['movetime'] : null;

    if ($depth === null && $movetime === null) $depth = 15;
    if ($movetime !== null) $depth = null;

    $sf = new Stockfish('/usr/bin/stockfish', 4, 16);
    $result = $sf->analyze($fen, $depth, $movetime);
    jsonResponse($result);

} catch (\Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}

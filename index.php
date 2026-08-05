<?php

declare(strict_types=1);

use VRchessIndo\Connection\CSVDatabaseManager;
use VRchessIndo\Logic\MatchManager;

require_once __DIR__ . '/vendor/autoload.php';

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

try {

    $db = new CSVDatabaseManager(
        __DIR__ . '/data/player.csv',
        __DIR__ . '/data/match.csv'
    );

    $manager = new MatchManager($db);

    /*
     * GET ?player
     * GET ?match
     * GET ?white=Alice&black=Bob&result=1&url=https://...
     */

    if (isset($_GET['player'])) {
        jsonResponse([
            'success' => true,
            'count'   => $manager->getPlayerCount(),
            'players' => $manager->getPlayers()
        ]);
    }

    if (isset($_GET['match'])) {
        jsonResponse([
            'success' => true,
            'count'   => $manager->getMatchCount(),
            'matches' => $manager->getMatches()
        ]);
    }

    if (
        isset($_GET['white']) &&
        isset($_GET['black']) &&
        isset($_GET['result'])
    ) {

        $white = trim($_GET['white']);
        $black = trim($_GET['black']);

        switch ((string)$_GET['result']) {
            case '1':
                $result = MatchManager::WHITE_WIN;
                break;

            case '0':
                $result = MatchManager::DRAW;
                break;

            case '-1':
                $result = MatchManager::BLACK_WIN;
                break;

            default:
                jsonResponse([
                    'success' => false,
                    'error' => 'result must be 1 (white win), 0 (draw), or -1 (black win)'
                ], 400);
        }

        $analysisUrl = trim($_GET['url'] ?? '');

        jsonResponse([
            'success' => true,
            'match' => $manager->play(
                $white,
                $black,
                $result,
                $analysisUrl
            )
        ]);
    }

    jsonResponse([
        'success' => false,
    ], 400);

} catch (Throwable $e) {

    jsonResponse([
        'success' => false,
        'error' => $e->getMessage()
    ], 500);

}

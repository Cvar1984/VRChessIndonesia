<?php

declare(strict_types=1);

use VRchessIndo\Connection\CSVDatabaseManager;
use VRchessIndo\Logic\MatchManager;

require_once __DIR__ . '/vendor/autoload.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(200);
    exit;
}

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
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
     * GET ?players
     * GET ?matches
     * GET ?valid-matches
     * GET ?invalid-matches
     * GET ?play&white=Alice&black=Bob&result=1&url=https://...
     * PUT ?invalidate&match=1
     * PUT ?revalidate&match=1
     * DELETE ?match=1 (remove match)
     * DELETE ?player=Alice (remove player)
     * PATCH ?match=1&result=1 (edit match)
     * PATCH ?player=Alice&rating=1500 (edit player)
     * GET ?player-stats&username=Alice
     */

    if (isset($_GET['players'])) {
        jsonResponse([
            'success' => true,
            'count' => $manager->getPlayerCount(),
            'players' => $manager->getPlayers()
        ]);
    }

    if (isset($_GET['matches'])) {
        jsonResponse([
            'success' => true,
            'count' => $manager->getMatchCount(),
            'matches' => $manager->getMatches()
        ]);
    }

    if (isset($_GET['valid-matches'])) {
        jsonResponse([
            'success' => true,
            'count' => count($manager->getValidMatches()),
            'matches' => $manager->getValidMatches()
        ]);
    }

    if (isset($_GET['invalid-matches'])) {
        jsonResponse([
            'success' => true,
            'count' => count($manager->getInvalidMatches()),
            'matches' => $manager->getInvalidMatches()
        ]);
    }

    if (isset($_GET['player-stats']) && isset($_GET['username'])) {
        try {
            $stats = $manager->getPlayerStats($_GET['username']);
            jsonResponse([
                'success' => true,
                'stats' => $stats
            ]);
        } catch (\Exception $e) {
            jsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 404);
        }
    }

    if (isset($_GET['play']) && isset($_GET['white']) && isset($_GET['black']) && isset($_GET['result'])) {
        $white = trim($_GET['white']);
        $black = trim($_GET['black']);

        switch ((string) $_GET['result']) {
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
            'match' => $manager->play($white, $black, $result, $analysisUrl)
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'PUT' && isset($_GET['invalidate']) && isset($_GET['match'])) {
        $matchId = (int) $_GET['match'];

        try {
            $result = $manager->invalidateMatch($matchId);
            jsonResponse([
                'success' => true,
                'message' => "Match #{$matchId} invalidated successfully",
                'data' => $result
            ]);
        } catch (\Exception $e) {
            jsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'PUT' && isset($_GET['revalidate']) && isset($_GET['match'])) {
        $matchId = (int) $_GET['match'];

        try {
            $result = $manager->revalidateMatch($matchId);
            jsonResponse([
                'success' => true,
                'message' => "Match #{$matchId} revalidated successfully",
                'data' => $result
            ]);
        } catch (\Exception $e) {
            jsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && isset($_GET['match'])) {
        $matchId = (int) $_GET['match'];
        $deleted = $manager->removeMatch($matchId);

        jsonResponse([
            'success' => $deleted,
            'message' => $deleted ? "Match #{$matchId} deleted" : "Match not found"
        ], $deleted ? 200 : 404);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && isset($_GET['player'])) {
        $username = trim($_GET['player']);
        $deleted = $manager->removePlayer($username);

        jsonResponse([
            'success' => $deleted,
            'message' => $deleted ? "Player '{$username}' deleted" : "Player not found"
        ], $deleted ? 200 : 404);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'PATCH' && isset($_GET['match'])) {
        $matchId = (int) $_GET['match'];
        $newData = [];

        $input = json_decode(file_get_contents('php://input'), true) ?? $_GET;

        if (isset($input['result'])) {
            $newData['result'] = $input['result'];
        }
        if (isset($input['analysis_url'])) {
            $newData['analysis_url'] = $input['analysis_url'];
        }

        if (empty($newData)) {
            jsonResponse(['success' => false, 'error' => 'No data to update'], 400);
        }

        $updated = $manager->editMatch($matchId, $newData);

        jsonResponse([
            'success' => $updated,
            'message' => $updated ? "Match #{$matchId} updated" : "Match not found"
        ], $updated ? 200 : 404);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'PATCH' && isset($_GET['player'])) {
        $username = trim($_GET['player']);
        $newData = [];

        $input = json_decode(file_get_contents('php://input'), true) ?? $_GET;

        if (isset($input['rating'])) {
            $newData['rating'] = (int) $input['rating'];
        }
        if (isset($input['username'])) {
            $newData['username'] = trim($input['username']);
        }

        if (empty($newData)) {
            jsonResponse(['success' => false, 'error' => 'No data to update'], 400);
        }

        $updated = $manager->editPlayer($username, $newData);

        jsonResponse([
            'success' => $updated,
            'message' => $updated ? "Player '{$username}' updated" : "Player not found"
        ], $updated ? 200 : 404);
    }

    if (isset($_GET['rankings'])) {
        jsonResponse([
            'success' => true,
            'rankings' => $manager->getPlayers()
        ]);
    }
    echo file_get_contents('index.html');
} catch (\Throwable $e) {
    jsonResponse([
        'success' => false,
        'error' => $e->getMessage()
    ], 500);
}

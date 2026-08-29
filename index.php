<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use VRchessIndo\Connection\MongoDBDatabaseManager;
use VRchessIndo\Logic\MatchManager;

require_once __DIR__ . '/vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->safeLoad();
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Token, X-Admin-Password');
    http_response_code(200);
    exit;
}
/**
 * Send a JSON response to the client.
 * 
 * This function sends a JSON response to the client with the given data and status code.
 * 
 * @param array $data The data to send as JSON.
 * @param int $status The HTTP status code.
 */
function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Token, X-Admin-Password');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Get the provided API token from the request.
 * 
 * This function retrieves the API token from the request headers or query parameters.
 * 
 * @return string The API token.
 */
function getProvidedApiToken(): string
{
    $token = $_SERVER['HTTP_X_API_TOKEN'] ?? $_REQUEST['token'] ?? $_REQUEST['api_token'] ?? '';
    if (empty($token) && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/Bearer\s+(\S+)/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
            $token = $matches[1];
        }
    }
    return trim((string) $token);
}
/**
 * Checks if the current user has an active admin session.
 * 
 * @param MongoDBDatabaseManager $db The database manager instance.
 * @return bool True if admin, false otherwise.
 */
function isAdmin(MongoDBDatabaseManager $db): bool
{
    if (!empty($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) {
        return true;
    }

    $headerUsername = $_SERVER['HTTP_X_ADMIN_USERNAME'] ?? $_REQUEST['admin_username'] ?? 'admin';
    $headerPassword = $_SERVER['HTTP_X_ADMIN_PASSWORD'] ?? $_REQUEST['admin_password'] ?? '';
    if ($headerPassword !== '' && $db->verifyAdminLogin((string) $headerUsername, (string) $headerPassword)) {
        $_SESSION['admin_username'] = $headerUsername;
        return true;
    }

    return false;
}
/**
 * Requires an active admin session, terminating execution with a 401 if not found.
 * 
 * @param MongoDBDatabaseManager $db The database manager instance.
 */
function requireAdmin(MongoDBDatabaseManager $db): void
{
    if (!isAdmin($db)) {
        jsonResponse([
            'success' => false,
            'error' => 'Akses ditolak: Diperlukan autentikasi admin.'
        ], 401);
    }
}
/**
 * Requires a valid API token or admin session, terminating execution with a 401 if neither is provided.
 * 
 * @param MongoDBDatabaseManager $db The database manager instance.
 */
function requireApiAccess(MongoDBDatabaseManager $db): void
{
    if (isAdmin($db)) {
        return;
    }

    $token = getProvidedApiToken();
    if (!empty($token) && $db->validateToken($token)) {
        return;
    }

    jsonResponse([
        'success' => false,
        'error' => 'Akses API ditolak: Diperlukan API Token yang valid.'
    ], 401);
}

try {
    $db = new MongoDBDatabaseManager();
    $manager = new MatchManager($db);
    $defaultWebToken = $db->ensureDefaultToken();

    // ── Authentication & Token Status Endpoints ──
    if (isset($_GET['auth-status'])) {
        jsonResponse([
            'success' => true,
            'authenticated' => isAdmin($db),
            'username' => $_SESSION['admin_username'] ?? null
        ]);
    }

    if (isset($_GET['login']) || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'login')) {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $username = (string) ($input['username'] ?? $_GET['username'] ?? 'admin');
        $password = (string) ($input['password'] ?? $_GET['password'] ?? '');

        if ($db->verifyAdminLogin($username, $password)) {
            $_SESSION['is_admin'] = true;
            $_SESSION['admin_username'] = $username;
            jsonResponse([
                'success' => true,
                'message' => 'Login berhasil sebagai Admin!',
                'authenticated' => true,
                'username' => $username
            ]);
        } else {
            jsonResponse([
                'success' => false,
                'error' => 'Username atau password admin salah!'
            ], 401);
        }
    }

    if (isset($_GET['logout'])) {
        $_SESSION['is_admin'] = false;
        unset($_SESSION['is_admin']);
        session_destroy();
        jsonResponse([
            'success' => true,
            'message' => 'Berhasil logout.',
            'authenticated' => false
        ]);
    }

    // ── Token Management Endpoints (Admin Only) ──
    if (isset($_GET['tokens']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requireAdmin($db);
        jsonResponse([
            'success' => true,
            'tokens' => $db->getTokens()
        ]);
    }

    if (isset($_GET['create-token']) || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'create-token')) {
        requireAdmin($db);
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $name = trim((string) ($input['name'] ?? $_GET['name'] ?? 'API Token Baru'));

        $tokenDoc = $db->createToken($name);
        jsonResponse([
            'success' => true,
            'message' => "API Token '{$name}' berhasil dibuat!",
            'token' => $tokenDoc
        ]);
    }

    if (isset($_GET['revoke-token']) || ($_SERVER['REQUEST_METHOD'] === 'DELETE' && isset($_GET['token_id']))) {
        requireAdmin($db);
        $input = json_decode(file_get_contents('php://input'), true) ?? $_GET;
        $tokenId = (string) ($input['id'] ?? $_GET['token_id'] ?? $_GET['id'] ?? '');

        $revoked = $db->revokeToken($tokenId);
        jsonResponse([
            'success' => $revoked,
            'message' => $revoked ? "API Token berhasil dicabut/dihapus." : "Token tidak ditemukan."
        ], $revoked ? 200 : 404);
    }

    if (isset($_GET['update-token']) || ($_SERVER['REQUEST_METHOD'] === 'PATCH' && isset($_GET['token_id']))) {
        requireAdmin($db);
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $tokenId = (string) ($input['id'] ?? $_GET['token_id'] ?? $_GET['id'] ?? '');
        $newName = (string) ($input['name'] ?? '');
        $isActive = (bool) ($input['is_active'] ?? true);

        $updated = $db->updateToken($tokenId, $newName, $isActive);
        jsonResponse([
            'success' => $updated,
            'message' => $updated ? "API Token berhasil diperbarui." : "Token tidak ditemukan atau tidak ada perubahan."
        ], $updated ? 200 : 404);
    }

    // ── Admins Management Endpoints (Admin Only) ──
    if (isset($_GET['admins']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requireAdmin($db);
        jsonResponse([
            'success' => true,
            'admins' => $db->getAdmins()
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'create-admin') {
        requireAdmin($db);
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if (empty($username) || empty($password) || strlen($password) < 4) {
            jsonResponse(['success' => false, 'error' => 'Username dan password (min 4 kar) diperlukan.'], 400);
        }

        if ($db->createAdmin($username, $password)) {
            jsonResponse(['success' => true, 'message' => "Admin '{$username}' berhasil ditambahkan!"]);
        } else {
            jsonResponse(['success' => false, 'error' => "Gagal membuat admin. Username mungkin sudah ada."], 400);
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'PATCH' && isset($_GET['action']) && $_GET['action'] === 'update-admin') {
        requireAdmin($db);
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $username = trim((string) ($input['username'] ?? ''));
        $newPass = (string) ($input['password'] ?? '');

        if (empty($username) || empty($newPass) || strlen($newPass) < 4) {
            jsonResponse(['success' => false, 'error' => 'Password baru (min 4 kar) diperlukan.'], 400);
        }

        if ($db->updateAdmin($username, $newPass)) {
            jsonResponse(['success' => true, 'message' => "Password admin '{$username}' berhasil diperbarui!"]);
        } else {
            jsonResponse(['success' => false, 'error' => "Admin tidak ditemukan atau gagal diperbarui."], 400);
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && isset($_GET['action']) && $_GET['action'] === 'delete-admin') {
        requireAdmin($db);
        $input = json_decode(file_get_contents('php://input'), true) ?? $_GET;
        $username = trim((string) ($input['username'] ?? ''));

        if (empty($username)) {
            jsonResponse(['success' => false, 'error' => 'Username diperlukan.'], 400);
        }

        if ($username === ($_SESSION['admin_username'] ?? '')) {
            jsonResponse(['success' => false, 'error' => 'Tidak bisa menghapus diri sendiri.'], 400);
        }

        try {
            if ($db->deleteAdmin($username)) {
                jsonResponse(['success' => true, 'message' => "Admin '{$username}' berhasil dihapus!"]);
            } else {
                jsonResponse(['success' => false, 'error' => "Admin tidak ditemukan."], 404);
            }
        } catch (\Exception $e) {
            jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    // ── Public Endpoints ──
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save-analysis') {
        $input = json_decode(file_get_contents('php://input'), true);
        $pgn = $input['pgn'] ?? '';
        $analysisData = $input['analysis'] ?? null;

        if (empty($pgn)) {
            jsonResponse(['success' => false, 'error' => 'PGN required'], 400);
        }

        $id = $db->saveAnalysis($pgn, $analysisData);
        jsonResponse(['success' => true, 'id' => $id]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'PATCH' && isset($_GET['action']) && $_GET['action'] === 'update-analysis' && isset($_GET['id'])) {
        $input = json_decode(file_get_contents('php://input'), true);
        $analysisData = $input['analysis'] ?? null;

        if (empty($analysisData) || !is_array($analysisData)) {
            jsonResponse(['success' => false, 'error' => 'analysis array required'], 400);
        }

        $updated = $db->updateAnalysis($_GET['id'], $analysisData);
        jsonResponse(['success' => $updated]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get-analysis' && isset($_GET['id'])) {
        $data = $db->getAnalysis($_GET['id']);
        if ($data) {
            jsonResponse(['success' => true, 'data' => $data]);
        } else {
            jsonResponse(['success' => false, 'error' => 'Analysis not found'], 404);
        }
    }

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

    if (isset($_GET['rankings'])) {
        jsonResponse([
            'success' => true,
            'rankings' => $manager->getPlayers()
        ]);
    }

    // ── API Token Protected Mutation Endpoints (CRUD) ──
    if (isset($_GET['play'])) {
        requireApiAccess($db);

        $white = trim($_GET['white'] ?? '');
        $black = trim($_GET['black'] ?? '');

        if ($white === '' || $black === '') {
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $white = trim($input['white'] ?? $white);
            $black = trim($input['black'] ?? $black);
            $rawResult = $input['result'] ?? $_GET['result'] ?? '';
            $analysisUrl = trim($input['url'] ?? $input['analysis_url'] ?? $_GET['url'] ?? '');
        } else {
            $rawResult = $_GET['result'] ?? '';
            $analysisUrl = trim($_GET['url'] ?? '');
        }

        switch ((string) $rawResult) {
            case '1':
            case '1-0':
                $result = MatchManager::WHITE_WIN;
                break;
            case '0':
            case '1/2-1/2':
                $result = MatchManager::DRAW;
                break;
            case '-1':
            case '0-1':
                $result = MatchManager::BLACK_WIN;
                break;
            default:
                jsonResponse([
                    'success' => false,
                    'error' => 'Nilai result tidak valid (gunakan 1 untuk White, 0 untuk Draw, -1 untuk Black)'
                ], 400);
        }

        try {
            $matchResult = $manager->play($white, $black, $result, $analysisUrl);
            jsonResponse([
                'success' => true,
                'message' => 'Pertandingan berhasil dicatat!',
                'match' => $matchResult
            ]);
        } catch (\Exception $e) {
            jsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }

    if (($_SERVER['REQUEST_METHOD'] === 'PUT' || isset($_GET['invalidate'])) && isset($_GET['match']) && isset($_GET['invalidate'])) {
        requireApiAccess($db);
        $matchId = (int) $_GET['match'];

        try {
            $result = $manager->invalidateMatch($matchId);
            jsonResponse([
                'success' => true,
                'message' => "Pertandingan #{$matchId} berhasil di-anulir (invalidated)",
                'data' => $result
            ]);
        } catch (\Exception $e) {
            jsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }

    if (($_SERVER['REQUEST_METHOD'] === 'PUT' || isset($_GET['revalidate'])) && isset($_GET['match']) && isset($_GET['revalidate'])) {
        requireApiAccess($db);
        $matchId = (int) $_GET['match'];

        try {
            $result = $manager->revalidateMatch($matchId);
            jsonResponse([
                'success' => true,
                'message' => "Pertandingan #{$matchId} berhasil dipulihkan (revalidated)",
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
        requireApiAccess($db);
        $matchId = (int) $_GET['match'];

        // Fetch the match first so we can cascade-delete its analysis if it's internal
        $matchToDelete = $manager->getMatch($matchId);
        $deleted = $manager->removeMatch($matchId);

        if ($deleted && $matchToDelete) {
            $analysisUrl = trim($matchToDelete['analysis_url'] ?? '');
            if (!empty($analysisUrl)) {
                // Raw hex ID (e.g. "bfc44a8496005d38") — new format
                if (preg_match('/^[a-f0-9]{8,}$/i', $analysisUrl)) {
                    $db->deleteAnalysis($analysisUrl);
                // Legacy: path or full URL containing ?analysis=<id>
                } elseif (preg_match('/[?&]analysis=([a-f0-9]+)/i', $analysisUrl, $m)) {
                    $db->deleteAnalysis($m[1]);
                }
            }
        }

        jsonResponse([
            'success' => $deleted,
            'message' => $deleted ? "Pertandingan #{$matchId} telah dihapus." : "Pertandingan tidak ditemukan."
        ], $deleted ? 200 : 404);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && isset($_GET['player'])) {
        requireApiAccess($db);
        $username = trim($_GET['player']);
        $deleted = $manager->removePlayer($username);

        jsonResponse([
            'success' => $deleted,
            'message' => $deleted ? "Pemain '{$username}' telah dihapus." : "Pemain tidak ditemukan."
        ], $deleted ? 200 : 404);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'PATCH' && isset($_GET['match'])) {
        requireApiAccess($db);
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
            jsonResponse(['success' => false, 'error' => 'Tidak ada data untuk diperbarui'], 400);
        }

        $updated = $manager->editMatch($matchId, $newData);

        jsonResponse([
            'success' => $updated,
            'message' => $updated ? "Pertandingan #{$matchId} berhasil diperbarui." : "Pertandingan tidak ditemukan."
        ], $updated ? 200 : 404);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'PATCH' && isset($_GET['player'])) {
        requireApiAccess($db);
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
            jsonResponse(['success' => false, 'error' => 'Tidak ada data untuk diperbarui'], 400);
        }

        $updated = $manager->editPlayer($username, $newData);

        jsonResponse([
            'success' => $updated,
            'message' => $updated ? "Pemain '{$username}' berhasil diperbarui." : "Pemain tidak ditemukan."
        ], $updated ? 200 : 404);
    }

    // Serve Frontend HTML page
    echo file_get_contents('index.html');
} catch (\Throwable $e) {
    jsonResponse([
        'success' => false,
        'error' => $e->getMessage()
    ], 500);
}

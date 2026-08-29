<?php

namespace VRchessIndo\Connection;

use Dotenv\Dotenv;
use MongoDB\Client;
use MongoDB\Collection;
use VRchessIndo\Connection\Interface\DatabaseManager;

class MongoDBDatabaseManager implements DatabaseManager
{
    private ?Client $client = null;
    private Collection $playersCollection;
    private Collection $matchesCollection;
    private Collection $tokensCollection;
    private Collection $settingsCollection;
    private Collection $adminsCollection;
    private Collection $analysesCollection;
    private int $nextPlayerId = 1;
    private int $nextMatchId = 1;

    public function __construct(?string $uri = null, string $databaseName = 'vrchessindo')
    {
        if ($uri === null || trim($uri) === '') {
            $projectRoot = dirname(__DIR__, 2);
            if (file_exists($projectRoot . '/.env')) {
                $dotenv = Dotenv::createImmutable($projectRoot);
                $dotenv->safeLoad();
            }

            $uri = $_ENV['MONGODB_URI'] ?? $_SERVER['MONGODB_URI'] ?? getenv('MONGODB_URI') ?: '';
        }

        if (empty($uri)) {
            throw new \Exception("MongoDB URI not provided and MONGODB_URI environment variable is empty");
        }

        try {
            $this->client = new Client($uri);
            $db = $this->client->selectDatabase($databaseName);
            $this->playersCollection = $db->selectCollection('players');
            $this->matchesCollection = $db->selectCollection('matches');
            $this->tokensCollection = $db->selectCollection('tokens');
            $this->settingsCollection = $db->selectCollection('settings');
            $this->adminsCollection = $db->selectCollection('admins');
            $this->analysesCollection = $db->selectCollection('analyses');

            $this->initializeIndexes();
            $this->loadPlayers();
            $this->loadMatches();
        } catch (\Throwable $e) {
            throw new \Exception("MongoDB connection failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Destructor - Closes MongoDB connection.
     */
    public function __destruct()
    {
        $this->client = null;
    }

    private function getCache(string $key)
    {
        if (function_exists('apcu_fetch')) {
            $val = apcu_fetch($key, $success);
            if ($success) return $val;
        } else {
            $file = sys_get_temp_dir() . '/' . md5('vrchess_' . $key) . '.cache';
            if (file_exists($file)) {
                $data = @unserialize(file_get_contents($file));
                if (is_array($data) && $data['expires'] > time()) {
                    return $data['value'];
                }
            }
        }
        return null;
    }

    private function setCache(string $key, $value, int $ttl = 300): void
    {
        if (function_exists('apcu_store')) {
            apcu_store($key, $value, $ttl);
        } else {
            $file = sys_get_temp_dir() . '/' . md5('vrchess_' . $key) . '.cache';
            $data = ['expires' => time() + $ttl, 'value' => $value];
            @file_put_contents($file, serialize($data));
        }
    }

    private function clearCache(string $key): void
    {
        if (function_exists('apcu_delete')) {
            apcu_delete($key);
        } else {
            $file = sys_get_temp_dir() . '/' . md5('vrchess_' . $key) . '.cache';
            if (file_exists($file)) @unlink($file);
        }
    }

    /**
     * Initializes required indexes on MongoDB collections.
     */
    private function initializeIndexes(): void
    {
        try {
            $this->playersCollection->createIndex(['id' => 1], ['unique' => true]);
            $this->playersCollection->createIndex(['username' => 1], ['unique' => true]);
            $this->matchesCollection->createIndex(['id' => 1], ['unique' => true]);
            $this->matchesCollection->createIndex(['is_valid' => 1]);
            $this->tokensCollection->createIndex(['token' => 1], ['unique' => true]);
            $this->settingsCollection->createIndex(['key' => 1], ['unique' => true]);
            $this->adminsCollection->createIndex(['username' => 1], ['unique' => true]);
            $this->analysesCollection->createIndex(['id' => 1], ['unique' => true]);
        } catch (\Throwable $e) {
            // Ignore index initialization errors if non-critical
        }
    }

    /**
     * Loads all players from the database.
     * 
     * @return array Array of player records.
     */
    public function loadPlayers(): array
    {
        $cached = $this->getCache('players');
        if ($cached !== null) {
            $this->nextPlayerId = $cached['nextPlayerId'];
            return $cached['players'];
        }

        $players = [];
        $maxId = 0;

        $cursor = $this->playersCollection->find([], ['sort' => ['id' => 1]]);
        foreach ($cursor as $doc) {
            $id = (int) ($doc['id'] ?? 0);
            $username = trim((string) ($doc['username'] ?? ''));
            if (!empty($username) && $id > 0) {
                $players[$username] = [
                    'id' => $id,
                    'username' => $username,
                    'rating' => (int) ($doc['rating'] ?? 400),
                    'games' => (int) ($doc['games'] ?? 0),
                    'wins' => (int) ($doc['wins'] ?? 0),
                    'draws' => (int) ($doc['draws'] ?? 0),
                    'losses' => (int) ($doc['losses'] ?? 0),
                ];
                if ($id > $maxId) {
                    $maxId = $id;
                }
            }
        }

        $this->nextPlayerId = max($maxId + 1, 1);
        $this->setCache('players', ['players' => $players, 'nextPlayerId' => $this->nextPlayerId], 600); // 10 mins cache
        return $players;
    }

    /**
     * Saves an array of players to the database, replacing existing ones.
     * 
     * @param array $players The array of players to save.
     */
    public function savePlayers(array $players): void
    {
        $usernames = [];
        $maxId = 0;
        $operations = [];

        foreach ($players as $player) {
            $username = trim((string) ($player['username'] ?? ''));
            $id = (int) ($player['id'] ?? 0);
            if (empty($username) || $id <= 0) {
                continue;
            }

            $usernames[] = $username;
            if ($id > $maxId) {
                $maxId = $id;
            }

            $operations[] = [
                'updateOne' => [
                    ['id' => $id],
                    [
                        '$set' => [
                            'id' => $id,
                            'username' => $username,
                            'rating' => (int) ($player['rating'] ?? 400),
                            'games' => (int) ($player['games'] ?? 0),
                            'wins' => (int) ($player['wins'] ?? 0),
                            'draws' => (int) ($player['draws'] ?? 0),
                            'losses' => (int) ($player['losses'] ?? 0),
                        ]
                    ],
                    ['upsert' => true]
                ]
            ];
        }

        if (!empty($operations)) {
            $this->playersCollection->bulkWrite($operations);
            $this->playersCollection->deleteMany(['username' => ['$nin' => $usernames]]);
        } else {
            $this->playersCollection->deleteMany([]);
        }

        $this->nextPlayerId = max($maxId + 1, 1);
        $this->clearCache('players');
    }

    /**
     * Loads all matches from the database.
     * 
     * @return array Array of match records indexed by match ID.
     */
    public function loadMatches(): array
    {
        $cached = $this->getCache('matches');
        if ($cached !== null) {
            $this->nextMatchId = $cached['nextMatchId'];
            return $cached['matches'];
        }

        $matches = [];
        $maxId = 0;

        $cursor = $this->matchesCollection->find([], ['sort' => ['id' => 1]]);
        foreach ($cursor as $doc) {
            $id = (int) ($doc['id'] ?? 0);
            if ($id > 0) {
                $matches[$id] = [
                    'id' => $id,
                    'date' => (string) ($doc['date'] ?? ''),
                    'white_id' => (int) ($doc['white_id'] ?? 0),
                    'black_id' => (int) ($doc['black_id'] ?? 0),
                    'result' => (string) ($doc['result'] ?? ''),
                    'analysis_url' => (string) ($doc['analysis_url'] ?? ''),
                    'old_white_rating' => (int) ($doc['old_white_rating'] ?? 400),
                    'old_black_rating' => (int) ($doc['old_black_rating'] ?? 400),
                    'rating_change_white' => (int) ($doc['rating_change_white'] ?? 0),
                    'rating_change_black' => (int) ($doc['rating_change_black'] ?? 0),
                    'is_valid' => (bool) ($doc['is_valid'] ?? true),
                    'invalidated_at' => !empty($doc['invalidated_at']) ? (string) $doc['invalidated_at'] : null,
                    'restored_white_rating' => !empty($doc['restored_white_rating']) ? (int) $doc['restored_white_rating'] : null,
                    'restored_black_rating' => !empty($doc['restored_black_rating']) ? (int) $doc['restored_black_rating'] : null,
                ];
                if ($id > $maxId) {
                    $maxId = $id;
                }
            }
        }

        $this->nextMatchId = max($maxId + 1, 1);
        $this->setCache('matches', ['matches' => $matches, 'nextMatchId' => $this->nextMatchId], 600);
        return $matches;
    }

    /**
     * Saves or updates a single match record.
     * 
     * @param array $match The match data to save.
     */
    public function saveMatch(array $match): void
    {
        $id = (int) ($match['id'] ?? 0);
        if ($id <= 0) {
            return;
        }

        $doc = [
            'id' => $id,
            'date' => (string) ($match['date'] ?? ''),
            'white_id' => (int) ($match['white_id'] ?? 0),
            'black_id' => (int) ($match['black_id'] ?? 0),
            'result' => (string) ($match['result'] ?? ''),
            'analysis_url' => (string) ($match['analysis_url'] ?? ''),
            'old_white_rating' => (int) ($match['old_white_rating'] ?? 400),
            'old_black_rating' => (int) ($match['old_black_rating'] ?? 400),
            'rating_change_white' => (int) ($match['rating_change_white'] ?? 0),
            'rating_change_black' => (int) ($match['rating_change_black'] ?? 0),
            'is_valid' => (bool) ($match['is_valid'] ?? true),
        ];

        if (isset($match['invalidated_at'])) {
            $doc['invalidated_at'] = $match['invalidated_at'];
        }
        if (isset($match['restored_white_rating'])) {
            $doc['restored_white_rating'] = $match['restored_white_rating'];
        }
        if (isset($match['restored_black_rating'])) {
            $doc['restored_black_rating'] = $match['restored_black_rating'];
        }

        $this->matchesCollection->updateOne(
            ['id' => $id],
            ['$set' => $doc],
            ['upsert' => true]
        );
        $this->clearCache('matches');
    }

    /**
     * Saves an array of matches to the database, replacing existing ones.
     * 
     * @param array $matches The array of matches to save.
     */
    public function saveMatches(array $matches): void
    {
        $ids = [];
        $maxId = 0;
        $operations = [];

        foreach ($matches as $match) {
            $id = (int) ($match['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $doc = [
                'id' => $id,
                'date' => (string) ($match['date'] ?? ''),
                'white_id' => (int) ($match['white_id'] ?? 0),
                'black_id' => (int) ($match['black_id'] ?? 0),
                'result' => (string) ($match['result'] ?? ''),
                'analysis_url' => (string) ($match['analysis_url'] ?? ''),
                'old_white_rating' => (int) ($match['old_white_rating'] ?? 400),
                'old_black_rating' => (int) ($match['old_black_rating'] ?? 400),
                'rating_change_white' => (int) ($match['rating_change_white'] ?? 0),
                'rating_change_black' => (int) ($match['rating_change_black'] ?? 0),
                'is_valid' => (bool) ($match['is_valid'] ?? true),
            ];

            if (isset($match['invalidated_at'])) {
                $doc['invalidated_at'] = $match['invalidated_at'];
            }
            if (isset($match['restored_white_rating'])) {
                $doc['restored_white_rating'] = $match['restored_white_rating'];
            }
            if (isset($match['restored_black_rating'])) {
                $doc['restored_black_rating'] = $match['restored_black_rating'];
            }

            $operations[] = [
                'updateOne' => [
                    ['id' => $id],
                    ['$set' => $doc],
                    ['upsert' => true]
                ]
            ];

            $ids[] = $id;
            if ($id > $maxId) {
                $maxId = $id;
            }
        }

        if (!empty($operations)) {
            $this->matchesCollection->bulkWrite($operations);
            $this->matchesCollection->deleteMany(['id' => ['$nin' => $ids]]);
        } else {
            $this->matchesCollection->deleteMany([]);
        }

        $this->nextMatchId = max($maxId + 1, 1);
        $this->clearCache('matches');
    }

    /**
     * Gets the next available player ID.
     * 
     * @return int The next player ID.
     */
    public function getNextPlayerId(): int
    {
        return $this->nextPlayerId;
    }

    /**
     * Gets the next available match ID.
     * 
     * @return int The next match ID.
     */
    public function getNextMatchId(): int
    {
        return $this->nextMatchId;
    }

    /**
     * Checks if a player exists by username.
     * 
     * @param string $username The username to check.
     * @return bool True if the player exists, false otherwise.
     */
    public function playerExists(string $username): bool
    {
        $count = $this->playersCollection->countDocuments(['username' => trim($username)]);
        return $count > 0;
    }

    /**
     * Retrieves a player by their username.
     * 
     * @param string $username The username to search for.
     * @return array|null The player data, or null if not found.
     */
    public function getPlayerByUsername(string $username): ?array
    {
        $doc = $this->playersCollection->findOne(['username' => trim($username)]);
        if (!$doc) {
            return null;
        }

        return [
            'id' => (int) $doc['id'],
            'username' => (string) $doc['username'],
            'rating' => (int) $doc['rating'],
            'games' => (int) $doc['games'],
            'wins' => (int) $doc['wins'],
            'draws' => (int) $doc['draws'],
            'losses' => (int) $doc['losses'],
        ];
    }

    /**
     * Retrieves a player by their unique ID.
     * 
     * @param int $id The player ID.
     * @return array|null The player data, or null if not found.
     */
    public function getPlayerById(int $id): ?array
    {
        $doc = $this->playersCollection->findOne(['id' => (int) $id]);
        if (!$doc) {
            return null;
        }

        return [
            'id' => (int) $doc['id'],
            'username' => (string) $doc['username'],
            'rating' => (int) $doc['rating'],
            'games' => (int) $doc['games'],
            'wins' => (int) $doc['wins'],
            'draws' => (int) $doc['draws'],
            'losses' => (int) $doc['losses'],
        ];
    }

    // ── Settings Management ──
    /**
     * Gets a setting value by key.
     * 
     * @param string $key The setting key.
     * @param string|null $default The default value if the setting is not found.
     * @return string|null The setting value.
     */
    public function getSetting(string $key, ?string $default = null): ?string
    {
        $doc = $this->settingsCollection->findOne(['key' => $key]);
        return $doc ? (string) $doc['value'] : $default;
    }

    /**
     * Sets or updates a setting value.
     * 
     * @param string $key The setting key.
     * @param string $value The setting value.
     */
    public function setSetting(string $key, string $value): void
    {
        $this->settingsCollection->updateOne(
            ['key' => $key],
            ['$set' => ['key' => $key, 'value' => $value, 'updated_at' => date('Y-m-d H:i:s')]],
            ['upsert' => true]
        );
    }

    // ── Admins & Password Management ──
    /**
     * Retrieves all admin accounts.
     * 
     * @return array List of admin accounts.
     */
    public function getAdmins(): array
    {
        $admins = [];
        $cursor = $this->adminsCollection->find([], ['sort' => ['created_at' => 1]]);
        foreach ($cursor as $doc) {
            $admins[] = [
                'username' => (string) ($doc['username'] ?? ''),
                'created_at' => (string) ($doc['created_at'] ?? ''),
            ];
        }
        return $admins;
    }

    /**
     * Creates a new admin account.
     * 
     * @param string $username The admin username.
     * @param string $password The raw password (will be hashed).
     * @return bool True on success, false if username already exists.
     */
    public function createAdmin(string $username, string $password): bool
    {
        $username = trim($username);
        if (empty($username) || empty($password)) return false;
        
        if ($this->adminsCollection->countDocuments(['username' => $username]) > 0) {
            return false;
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $this->adminsCollection->insertOne([
            'username' => $username,
            'password' => $hash,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        return true;
    }

    /**
     * Updates an admin's password.
     * 
     * @param string $username The admin username.
     * @param string $newPassword The new raw password.
     * @return bool True on success, false if not found.
     */
    public function updateAdmin(string $username, string $newPassword): bool
    {
        $username = trim($username);
        if (empty($username) || empty($newPassword)) return false;

        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $res = $this->adminsCollection->updateOne(
            ['username' => $username],
            ['$set' => ['password' => $hash]]
        );
        return $res->getMatchedCount() > 0;
    }

    /**
     * Deletes an admin account.
     * 
     * @param string $username The admin username.
     * @return bool True on success, false if not found.
     */
    public function deleteAdmin(string $username): bool
    {
        if ($this->adminsCollection->countDocuments() <= 1) {
            throw new \Exception("Tidak bisa menghapus admin terakhir.");
        }
        
        $res = $this->adminsCollection->deleteOne(['username' => trim($username)]);
        return $res->getDeletedCount() > 0;
    }

    /**
     * Verifies an admin login attempt.
     * 
     * @param string $username The admin username.
     * @param string $password The raw password.
     * @return bool True if credentials are valid, false otherwise.
     */
    public function verifyAdminLogin(string $username, string $password): bool
    {
        // Legacy fallback support: if we have 0 admins, create 'admin' using env or legacy setting
        if ($this->adminsCollection->countDocuments() === 0) {
            $legacyHash = $this->getSetting('admin_password');
            if ($legacyHash) {
                $this->adminsCollection->insertOne([
                    'username' => 'admin',
                    'password' => $legacyHash,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            } else {
                $envPass = $_ENV['ADMIN_PASSWORD'] ?? $_SERVER['ADMIN_PASSWORD'] ?? getenv('ADMIN_PASSWORD') ?: 'admin';
                $this->createAdmin('admin', $envPass);
            }
        }

        $doc = $this->adminsCollection->findOne(['username' => trim($username)]);
        if ($doc && password_verify($password, $doc['password'])) {
            return true;
        }

        if ($doc && hash_equals($doc['password'], $password)) {
            $this->updateAdmin(trim($username), $password);
            return true;
        }

        return false;
    }

    // ── API Token Management ──
    /**
     * Retrieves all API tokens.
     * 
     * @return array List of API tokens.
     */
    public function getTokens(): array
    {
        $tokens = [];
        $cursor = $this->tokensCollection->find([], ['sort' => ['created_at' => -1]]);
        foreach ($cursor as $doc) {
            $tokens[] = [
                'id' => (string) ($doc['id'] ?? $doc['_id']),
                'name' => (string) ($doc['name'] ?? 'API Token'),
                'token' => (string) ($doc['token'] ?? ''),
                'created_at' => (string) ($doc['created_at'] ?? ''),
                'last_used' => !empty($doc['last_used']) ? (string) $doc['last_used'] : 'Belum Pernah',
                'is_active' => (bool) ($doc['is_active'] ?? true)
            ];
        }
        return $tokens;
    }

    /**
     * Creates a new API token.
     * 
     * @param string $name Name or description of the token.
     * @return array The created token document.
     */
    public function createToken(string $name = 'API Token'): array
    {
        $rawToken = 'vrchess_pat_' . bin2hex(random_bytes(16));
        $id = 'tok_' . bin2hex(random_bytes(8));
        $tokenDoc = [
            'id' => $id,
            'name' => trim($name) ?: 'API Token',
            'token' => $rawToken,
            'created_at' => date('Y-m-d H:i:s'),
            'last_used' => null,
            'is_active' => true
        ];

        $this->tokensCollection->insertOne($tokenDoc);
        return $tokenDoc;
    }

    /**
     * Updates an API token's details.
     * 
     * @param string $tokenId The token ID.
     * @param string $newName The new name.
     * @param bool $isActive Whether the token is active.
     * @return bool True on success.
     */
    public function updateToken(string $tokenId, string $newName, bool $isActive = true): bool
    {
        $res = $this->tokensCollection->updateOne(
            ['id' => $tokenId],
            ['$set' => ['name' => trim($newName), 'is_active' => $isActive]]
        );
        return $res->getMatchedCount() > 0;
    }

    /**
     * Revokes or deletes an API token.
     * 
     * @param string $tokenIdOrToken The token ID or the token string itself.
     * @return bool True on success.
     */
    public function revokeToken(string $tokenIdOrToken): bool
    {
        $result = $this->tokensCollection->deleteOne([
            '$or' => [
                ['id' => $tokenIdOrToken],
                ['token' => $tokenIdOrToken]
            ]
        ]);
        return $result->getDeletedCount() > 0;
    }

    /**
     * Validates if a given token string is active and updates its last_used timestamp.
     * 
     * @param string $token The token string.
     * @return bool True if valid, false otherwise.
     */
    public function validateToken(string $token): bool
    {
        if (empty($token)) {
            return false;
        }

        $cacheKey = 'token_' . md5($token);
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $doc = $this->tokensCollection->findOne([
            'token' => trim($token),
            'is_active' => true
        ]);

        if ($doc) {
            $lastUsedStr = $doc['last_used'] ?? null;
            $now = date('Y-m-d H:i:s');
            // Only update last_used if older than 5 minutes to avoid DB writes overload
            if (!$lastUsedStr || strtotime($lastUsedStr) < strtotime('-5 minutes')) {
                $this->tokensCollection->updateOne(
                    ['_id' => $doc['_id']],
                    ['$set' => ['last_used' => $now]]
                );
            }
            $this->setCache($cacheKey, true, 300); // 5 mins cache
            return true;
        }

        $this->setCache($cacheKey, false, 30); // 30s negative cache
        return false;
    }

    /**
     * Ensures a default token exists, creating it if necessary.
     * 
     * @return string The default token string.
     */
    public function ensureDefaultToken(): string
    {
        $existing = $this->tokensCollection->findOne(['name' => 'Default Web App Token']);
        if ($existing) {
            return (string) $existing['token'];
        }
        $tok = $this->createToken('Default Web App Token');
        return $tok['token'];
    }

    // ── Analysis Management ──
    /**
     * Saves a PGN analysis to the database.
     * 
     * @param string $pgn The PGN string.
     * @param array|null $analysisData Optional analysis data (e.g. array of evaluated positions).
     * @return string The unique analysis ID.
     */
    public function saveAnalysis(string $pgn, ?array $analysisData = null): string
    {
        $id = bin2hex(random_bytes(8)); // 16 char hex string
        
        $doc = [
            'id' => $id,
            'pgn' => $pgn,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        
        if ($analysisData !== null) {
            $doc['analysis'] = $analysisData;
        }

        $this->analysesCollection->insertOne($doc);
        return $id;
    }

    /**
     * Retrieves a saved analysis by ID.
     * 
     * @param string $id The analysis ID.
     * @return array|null The analysis document, or null if not found.
     */
    public function getAnalysis(string $id): ?array
    {
        $doc = $this->analysesCollection->findOne(['id' => $id]);
        if (!$doc) {
            return null;
        }

        $result = [
            'id' => (string) $doc['id'],
            'pgn' => (string) ($doc['pgn'] ?? ''),
            'created_at' => (string) ($doc['created_at'] ?? '')
        ];
        
        if (isset($doc['analysis'])) {
            // Need to convert BSONArray/Document to standard PHP array
            $result['analysis'] = json_decode(json_encode($doc['analysis']), true);
        }

        return $result;
    }

    /**
     * Updates the analysis positions data for an existing analysis document.
     * 
     * @param string $id The analysis ID.
     * @param array $analysisData The computed positions array.
     * @return bool True if updated, false if not found.
     */
    public function updateAnalysis(string $id, array $analysisData): bool
    {
        $result = $this->analysesCollection->updateOne(
            ['id' => trim($id)],
            ['$set' => ['analysis' => $analysisData, 'updated_at' => date('Y-m-d H:i:s')]]
        );
        return $result->getMatchedCount() > 0;
    }

    /**
     * Deletes a saved analysis by ID.
     * 
     * @param string $id The analysis ID.
     * @return bool True if deleted, false if not found.
     */
    public function deleteAnalysis(string $id): bool
    {
        $result = $this->analysesCollection->deleteOne(['id' => trim($id)]);
        return $result->getDeletedCount() > 0;
    }

    /**
     * Retrieves all saved analyses, omitting the heavy analysis positions array.
     * 
     * @return array List of analyses.
     */
    public function getAllAnalyses(): array
    {
        // Don't fetch the 'analysis' array to save bandwidth/memory
        $cursor = $this->analysesCollection->find([], [
            'projection' => [
                'id' => 1,
                'created_at' => 1,
                // Extract just a short snippet of the PGN to display
                'pgn' => 1 
            ],
            'sort' => ['created_at' => -1]
        ]);

        $results = [];
        foreach ($cursor as $doc) {
            $rawPgn = trim((string) ($doc['pgn'] ?? ''));
            
            $headers = [];
            if (preg_match_all('/\[([A-Za-z0-9_]+)\s+"([^"]*)"\]/', $rawPgn, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $headers[$match[1]] = $match[2];
                }
            }

            // Remove headers for the preview
            $pgnPreview = preg_replace('/\[.*?\]\s*/s', '', $rawPgn);
            $pgnPreview = mb_substr(trim($pgnPreview), 0, 100) . (mb_strlen($pgnPreview) > 100 ? '...' : '');

            $results[] = [
                'id' => (string) $doc['id'],
                'created_at' => (string) ($doc['created_at'] ?? ''),
                'pgn_preview' => $pgnPreview,
                'headers' => $headers
            ];
        }

        return $results;
    }
}

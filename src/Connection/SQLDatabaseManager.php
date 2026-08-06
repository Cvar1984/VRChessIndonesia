<?php

namespace VRchessIndo\Connection;

use VRchessIndo\Connection\Interface\DatabaseManager;

class SQLDatabaseManager implements DatabaseManager
{
    private $connection;
    private string $host;
    private string $database;
    private string $username;
    private string $password;
    private int $nextPlayerId = 1;
    private int $nextMatchId = 1;

    public function __construct(string $host, string $database, string $username, string $password)
    {
        $this->host = $host;
        $this->database = $database;
        $this->username = $username;
        $this->password = $password;

        try {
            $this->connection = new \PDO(
                "mysql:host={$this->host};dbname={$this->database};charset=utf8mb4",
                $this->username,
                $this->password
            );
            $this->connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->connection->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

            $this->initializeTables();

            // Only load data after tables are initialized
            $this->loadPlayers();
            $this->loadMatches();
        } catch (\PDOException $e) {
            throw new \Exception("Database connection failed: " . $e->getMessage());
        }
    }

    public function __destruct()
    {
        $this->connection = null;
    }

    private function initializeTables(): void
    {
        $queries = [
            "CREATE TABLE IF NOT EXISTS players (
                id INT PRIMARY KEY AUTO_INCREMENT,
                username VARCHAR(50) UNIQUE NOT NULL,
                rating INT DEFAULT 400,
                games INT DEFAULT 0,
                wins INT DEFAULT 0,
                draws INT DEFAULT 0,
                losses INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS matches (
                id INT PRIMARY KEY AUTO_INCREMENT,
                date DATETIME DEFAULT CURRENT_TIMESTAMP,
                white_id INT NOT NULL,
                black_id INT NOT NULL,
                result VARCHAR(10) NOT NULL,
                analysis_url VARCHAR(255) DEFAULT '',
                old_white_rating INT DEFAULT 0,
                old_black_rating INT DEFAULT 0,
                rating_change_white INT DEFAULT 0,
                rating_change_black INT DEFAULT 0,
                is_valid BOOLEAN DEFAULT TRUE,
                invalidated_at DATETIME DEFAULT NULL,
                restored_white_rating INT DEFAULT NULL,
                restored_black_rating INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (white_id) REFERENCES players(id) ON DELETE CASCADE,
                FOREIGN KEY (black_id) REFERENCES players(id) ON DELETE CASCADE,
                INDEX idx_is_valid (is_valid),
                INDEX idx_date (date)
            )"
        ];

        foreach ($queries as $query) {
            $this->connection->exec($query);
        }
    }

    public function loadPlayers(): array
    {
        $stmt = $this->connection->query("SELECT * FROM players ORDER BY id");
        $players = [];
        $maxId = 0;

        while ($row = $stmt->fetch()) {
            $players[$row['username']] = $row;
            if ($row['id'] > $maxId) {
                $maxId = $row['id'];
            }
        }

        $this->nextPlayerId = $maxId + 1;
        return $players;
    }

    public function savePlayers(array $players): void
    {
        foreach ($players as $player) {
            $stmt = $this->connection->prepare(
                "INSERT INTO players (id, username, rating, games, wins, draws, losses) 
                 VALUES (:id, :username, :rating, :games, :wins, :draws, :losses)
                 ON DUPLICATE KEY UPDATE 
                 rating = VALUES(rating), 
                 games = VALUES(games), 
                 wins = VALUES(wins), 
                 draws = VALUES(draws), 
                 losses = VALUES(losses)"
            );
            $stmt->execute([
                'id' => $player['id'],
                'username' => $player['username'],
                'rating' => $player['rating'],
                'games' => $player['games'],
                'wins' => $player['wins'],
                'draws' => $player['draws'],
                'losses' => $player['losses']
            ]);
        }
    }

    public function loadMatches(): array
    {
        $stmt = $this->connection->query("SELECT * FROM matches ORDER BY id");
        $matches = [];
        $maxId = 0;

        while ($row = $stmt->fetch()) {
            // Convert boolean fields
            $row['is_valid'] = (bool) $row['is_valid'];
            $matches[] = $row;
            if ($row['id'] > $maxId) {
                $maxId = $row['id'];
            }
        }

        $this->nextMatchId = $maxId + 1;
        return $matches;
    }

    public function saveMatch(array $match): void
    {
        if ($match['white_id'] === $match['black_id']) {
            throw new \Exception("Cannot save match: White and Black players must be different");
        }

        $stmt = $this->connection->prepare(
            "INSERT INTO matches (
                id, date, white_id, black_id, result, analysis_url,
                old_white_rating, old_black_rating, 
                rating_change_white, rating_change_black,
                is_valid, invalidated_at, 
                restored_white_rating, restored_black_rating
            ) VALUES (
                :id, :date, :white_id, :black_id, :result, :analysis_url,
                :old_white_rating, :old_black_rating,
                :rating_change_white, :rating_change_black,
                :is_valid, :invalidated_at,
                :restored_white_rating, :restored_black_rating
            )"
        );

        $stmt->execute([
            'id' => $match['id'],
            'date' => $match['date'] ?? date('Y-m-d H:i:s'),
            'white_id' => $match['white_id'],
            'black_id' => $match['black_id'],
            'result' => $match['result'],
            'analysis_url' => $match['analysis_url'] ?? '',
            'old_white_rating' => $match['old_white_rating'] ?? 0,
            'old_black_rating' => $match['old_black_rating'] ?? 0,
            'rating_change_white' => $match['rating_change_white'] ?? 0,
            'rating_change_black' => $match['rating_change_black'] ?? 0,
            'is_valid' => isset($match['is_valid']) ? (int) $match['is_valid'] : 1,
            'invalidated_at' => $match['invalidated_at'] ?? null,
            'restored_white_rating' => $match['restored_white_rating'] ?? null,
            'restored_black_rating' => $match['restored_black_rating'] ?? null,
        ]);

        // Update nextMatchId if this ID is greater
        if ($match['id'] >= $this->nextMatchId) {
            $this->nextMatchId = $match['id'] + 1;
        }
    }

    public function saveMatches(array $matches): void
    {
        $this->connection->beginTransaction();

        try {
            // Delete all existing matches
            $this->connection->exec("TRUNCATE TABLE matches");

            // Insert all matches with new data
            foreach ($matches as $match) {
                $stmt = $this->connection->prepare(
                    "INSERT INTO matches (
                        id, date, white_id, black_id, result, analysis_url,
                        old_white_rating, old_black_rating,
                        rating_change_white, rating_change_black,
                        is_valid, invalidated_at,
                        restored_white_rating, restored_black_rating
                    ) VALUES (
                        :id, :date, :white_id, :black_id, :result, :analysis_url,
                        :old_white_rating, :old_black_rating,
                        :rating_change_white, :rating_change_black,
                        :is_valid, :invalidated_at,
                        :restored_white_rating, :restored_black_rating
                    )"
                );

                $stmt->execute([
                    'id' => $match['id'],
                    'date' => $match['date'] ?? date('Y-m-d H:i:s'),
                    'white_id' => $match['white_id'],
                    'black_id' => $match['black_id'],
                    'result' => $match['result'],
                    'analysis_url' => $match['analysis_url'] ?? '',
                    'old_white_rating' => $match['old_white_rating'] ?? 0,
                    'old_black_rating' => $match['old_black_rating'] ?? 0,
                    'rating_change_white' => $match['rating_change_white'] ?? 0,
                    'rating_change_black' => $match['rating_change_black'] ?? 0,
                    'is_valid' => isset($match['is_valid']) ? (int) $match['is_valid'] : 1,
                    'invalidated_at' => $match['invalidated_at'] ?? null,
                    'restored_white_rating' => $match['restored_white_rating'] ?? null,
                    'restored_black_rating' => $match['restored_black_rating'] ?? null,
                ]);
            }

            $this->connection->commit();

            // Update nextMatchId
            $maxId = 0;
            foreach ($matches as $match) {
                if ($match['id'] > $maxId) {
                    $maxId = $match['id'];
                }
            }
            $this->nextMatchId = max($maxId + 1, 1);

        } catch (\Exception $e) {
            $this->connection->rollBack();
            throw new \Exception("Failed to save matches: " . $e->getMessage());
        }
    }

    public function getNextPlayerId(): int
    {
        return $this->nextPlayerId;
    }

    public function getNextMatchId(): int
    {
        return $this->nextMatchId;
    }

    public function playerExists(string $username): bool
    {
        $stmt = $this->connection->prepare("SELECT COUNT(*) FROM players WHERE username = :username");
        $stmt->execute(['username' => $username]);
        return $stmt->fetchColumn() > 0;
    }

    public function getPlayerByUsername(string $username): ?array
    {
        $stmt = $this->connection->prepare("SELECT * FROM players WHERE username = :username");
        $stmt->execute(['username' => $username]);
        return $stmt->fetch() ?: null;
    }

    public function getPlayerById(int $id): ?array
    {
        $stmt = $this->connection->prepare("SELECT * FROM players WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function getValidMatches(): array
    {
        $stmt = $this->connection->query("SELECT * FROM matches WHERE is_valid = 1 ORDER BY id");
        $matches = [];
        while ($row = $stmt->fetch()) {
            $row['is_valid'] = (bool) $row['is_valid'];
            $matches[] = $row;
        }
        return $matches;
    }

    public function getInvalidMatches(): array
    {
        $stmt = $this->connection->query("SELECT * FROM matches WHERE is_valid = 0 ORDER BY id");
        $matches = [];
        while ($row = $stmt->fetch()) {
            $row['is_valid'] = (bool) $row['is_valid'];
            $matches[] = $row;
        }
        return $matches;
    }

    public function getMatchesByPlayer(int $playerId): array
    {
        $stmt = $this->connection->prepare(
            "SELECT * FROM matches WHERE white_id = :player_id OR black_id = :player_id ORDER BY id"
        );
        $stmt->execute(['player_id' => $playerId]);
        $matches = [];
        while ($row = $stmt->fetch()) {
            $row['is_valid'] = (bool) $row['is_valid'];
            $matches[] = $row;
        }
        return $matches;
    }

    public function getPlayerStats(int $playerId): array
    {
        $stmt = $this->connection->prepare(
            "SELECT 
                COUNT(*) as total_matches,
                SUM(CASE WHEN is_valid = 1 THEN 1 ELSE 0 END) as valid_matches,
                SUM(CASE WHEN is_valid = 0 THEN 1 ELSE 0 END) as invalid_matches
            FROM matches 
            WHERE white_id = :player_id OR black_id = :player_id"
        );
        $stmt->execute(['player_id' => $playerId]);
        return $stmt->fetch() ?: ['total_matches' => 0, 'valid_matches' => 0, 'invalid_matches' => 0];
    }

    public function getPlayersWithStats(): array
    {
        $players = $this->loadPlayers();
        $results = [];

        foreach ($players as $player) {
            $stats = $this->getPlayerStats($player['id']);
            $results[] = array_merge($player, $stats);
        }

        return $results;
    }

    public function getMatchWithDetails(int $matchId): ?array
    {
        $stmt = $this->connection->prepare(
            "SELECT 
                m.*,
                w.username as white_username,
                b.username as black_username
            FROM matches m
            LEFT JOIN players w ON m.white_id = w.id
            LEFT JOIN players b ON m.black_id = b.id
            WHERE m.id = :match_id"
        );
        $stmt->execute(['match_id' => $matchId]);
        $match = $stmt->fetch();

        if ($match) {
            $match['is_valid'] = (bool) $match['is_valid'];
        }

        return $match ?: null;
    }

    public function getRecentMatches(int $limit = 10): array
    {
        $stmt = $this->connection->prepare(
            "SELECT * FROM matches ORDER BY date DESC LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $matches = [];
        while ($row = $stmt->fetch()) {
            $row['is_valid'] = (bool) $row['is_valid'];
            $matches[] = $row;
        }
        return $matches;
    }

    public function getMatchStatistics(): array
    {
        $stmt = $this->connection->query(
            "SELECT 
                COUNT(*) as total_matches,
                SUM(CASE WHEN is_valid = 1 THEN 1 ELSE 0 END) as valid_matches,
                SUM(CASE WHEN is_valid = 0 THEN 1 ELSE 0 END) as invalid_matches,
                SUM(CASE WHEN result = '1-0' AND is_valid = 1 THEN 1 ELSE 0 END) as white_wins,
                SUM(CASE WHEN result = '0-1' AND is_valid = 1 THEN 1 ELSE 0 END) as black_wins,
                SUM(CASE WHEN result = '1/2-1/2' AND is_valid = 1 THEN 1 ELSE 0 END) as draws
            FROM matches"
        );
        return $stmt->fetch() ?: [];
    }
}

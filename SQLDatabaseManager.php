<?php

require_once 'DatabaseManager.php';

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
            $this->connection = new PDO(
                "mysql:host={$this->host};dbname={$this->database}",
                $this->username,
                $this->password
            );
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // Initialize tables if they don't exist
            $this->initializeTables();

            // Load data to get next IDs
            $this->loadPlayers();
            $this->loadMatches();
        } catch (PDOException $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
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
                losses INT DEFAULT 0
            )",
            "CREATE TABLE IF NOT EXISTS matches (
                id INT PRIMARY KEY AUTO_INCREMENT,
                date DATETIME DEFAULT CURRENT_TIMESTAMP,
                white_id INT NOT NULL,
                black_id INT NOT NULL,
                result VARCHAR(10) NOT NULL,
                analysis_url VARCHAR(255),
                FOREIGN KEY (white_id) REFERENCES players(id),
                FOREIGN KEY (black_id) REFERENCES players(id)
            )"
        ];

        foreach ($queries as $query) {
            $this->connection->exec($query);
        }
    }
    public function loadPlayers(): array
    {
        $stmt = $this->connection->query("SELECT * FROM players");
        $players = [];
        $maxId = 0;

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
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
        // This is a bulk save - in practice you'd use upsert logic
        foreach ($players as $player) {
            $stmt = $this->connection->prepare(
                "INSERT INTO players (id, username, rating, games, wins, draws, losses) 
                 VALUES (:id, :username, :rating, :games, :wins, :draws, :losses)
                 ON DUPLICATE KEY UPDATE 
                 rating = :rating, games = :games, wins = :wins, 
                 draws = :draws, losses = :losses"
            );
            $stmt->execute($player);
        }
    }

    public function loadMatches(): array
    {
        $stmt = $this->connection->query("SELECT * FROM matches ORDER BY id");
        $matches = [];
        $maxId = 0;

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
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
        $stmt = $this->connection->prepare(
            "INSERT INTO matches (id, date, white_id, black_id, result, analysis_url) 
             VALUES (:id, :date, :white_id, :black_id, :result, :analysis_url)"
        );
        $stmt->execute($match);
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
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getPlayerById(int $id): ?array
    {
        $stmt = $this->connection->prepare("SELECT * FROM players WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

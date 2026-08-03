<?php

require_once 'DatabaseManager.php';

class CSVDatabaseManager implements DatabaseManager
{
    private string $playerFile;
    private string $matchFile;
    private array $players = [];
    private array $matches = [];
    private int $nextPlayerId = 1;
    private int $nextMatchId = 1;
    private bool $connected = false;

    public function __construct(string $playerFile = 'data/player.csv', string $matchFile = 'data/match.csv')
    {
        $this->playerFile = $playerFile;
        $this->matchFile = $matchFile;
    }

    public function connect(): void
    {
        $this->connected = true;
        $this->loadPlayers();
        $this->loadMatches();
    }

    public function disconnect(): void
    {
        $this->connected = false;
        $this->players = [];
        $this->matches = [];
    }

    public function loadPlayers(): array
    {
        $this->players = [];
        $maxId = 0;
        
        if (file_exists($this->playerFile) && ($handle = fopen($this->playerFile, 'r')) !== false) {
            $header = fgetcsv($handle); // Skip header
            while (($data = fgetcsv($handle)) !== false) {
                if (count($data) >= 7) {
                    $id = (int)$data[0];
                    $this->players[$data[1]] = [
                        'id' => $id,
                        'username' => $data[1],
                        'rating' => (int)$data[2],
                        'games' => (int)$data[3],
                        'wins' => (int)$data[4],
                        'draws' => (int)$data[5],
                        'losses' => (int)$data[6]
                    ];
                    if ($id > $maxId) {
                        $maxId = $id;
                    }
                }
            }
            fclose($handle);
        }
        
        $this->nextPlayerId = $maxId + 1;
        return $this->players;
    }

    public function savePlayers(array $players): void
    {
        $handle = fopen($this->playerFile, 'w');
        fputcsv($handle, ['id', 'username', 'rating', 'games', 'wins', 'draws', 'losses']);
        
        foreach ($players as $player) {
            fputcsv($handle, [
                $player['id'],
                $player['username'],
                $player['rating'],
                $player['games'],
                $player['wins'],
                $player['draws'],
                $player['losses']
            ]);
        }
        fclose($handle);
        
        $this->players = $players;
    }

    public function loadMatches(): array
    {
        $this->matches = [];
        $maxId = 0;
        
        if (file_exists($this->matchFile) && ($handle = fopen($this->matchFile, 'r')) !== false) {
            $header = fgetcsv($handle); // Skip header
            while (($data = fgetcsv($handle)) !== false) {
                if (count($data) >= 6) {
                    $id = (int)$data[0];
                    $this->matches[] = [
                        'id' => $id,
                        'date' => $data[1],
                        'white_id' => (int)$data[2],
                        'black_id' => (int)$data[3],
                        'result' => $data[4],
                        'analysis_url' => $data[5] ?? ''
                    ];
                    if ($id > $maxId) {
                        $maxId = $id;
                    }
                }
            }
            fclose($handle);
        }
        
        $this->nextMatchId = $maxId + 1;
        return $this->matches;
    }

    public function saveMatch(array $match): void
    {
        $handle = fopen($this->matchFile, 'a');
        fputcsv($handle, [
            $match['id'],
            $match['date'] ?? date('Y-m-d H:i:s'),
            $match['white_id'],
            $match['black_id'],
            $match['result'],
            $match['analysis_url'] ?? ''
        ]);
        fclose($handle);
        
        $this->matches[] = $match;
        $this->nextMatchId = max($this->nextMatchId, $match['id'] + 1);
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
        return isset($this->players[$username]);
    }

    public function getPlayerByUsername(string $username): ?array
    {
        return $this->players[$username] ?? null;
    }

    public function getPlayerById(int $id): ?array
    {
        foreach ($this->players as $player) {
            if ($player['id'] === $id) {
                return $player;
            }
        }
        return null;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }
}

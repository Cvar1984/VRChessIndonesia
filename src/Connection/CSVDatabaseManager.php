<?php

namespace VRchessIndo\Connection;

use VRchessIndo\Connection\Interface\DatabaseManager;

class CSVDatabaseManager implements DatabaseManager
{
    private string $playerFile;
    private string $matchFile;
    private array $players = [];
    private array $matches = [];
    private int $nextPlayerId = 1;
    private int $nextMatchId = 1;

    public function __construct(string $playerFile = 'player.csv', string $matchFile = 'match.csv')
    {
        $this->playerFile = $playerFile;
        $this->matchFile = $matchFile;
        
        $this->initializeMatchFile();
        $this->initializePlayerFile();
    }

    public function __destruct()
    {
        $this->players = [];
        $this->matches = [];
    }

    private function initializePlayerFile(): void
    {
        if (!file_exists($this->playerFile)) {
            $handle = fopen($this->playerFile, 'w');
            fputcsv($handle, ['id', 'username', 'rating', 'games', 'wins', 'draws', 'losses']);
            fclose($handle);
        }
    }

    private function initializeMatchFile(): void
    {
        if (!file_exists($this->matchFile)) {
            $handle = fopen($this->matchFile, 'w');
            fputcsv($handle, [
                'id', 'date', 'white_id', 'black_id', 'result', 'analysis_url',
                'old_white_rating', 'old_black_rating', 'rating_change_white', 'rating_change_black',
                'is_valid', 'invalidated_at', 'restored_white_rating', 'restored_black_rating'
            ]);
            fclose($handle);
        }
    }

    public function loadPlayers(): array
    {
        $this->players = [];
        $maxId = 0;

        if (file_exists($this->playerFile) && ($handle = fopen($this->playerFile, 'r')) !== false) {
            $header = fgetcsv($handle);
            while (($data = fgetcsv($handle)) !== false) {
                if (count($data) >= 7) {
                    $id = (int) $data[0];
                    $username = trim($data[1]);
                    if (!empty($username) && $id > 0) {
                        $this->players[$username] = [
                            'id' => $id,
                            'username' => $username,
                            'rating' => (int) $data[2],
                            'games' => (int) $data[3],
                            'wins' => (int) $data[4],
                            'draws' => (int) $data[5],
                            'losses' => (int) $data[6]
                        ];
                        if ($id > $maxId) $maxId = $id;
                    }
                }
            }
            fclose($handle);
        }
        $this->nextPlayerId = max($maxId + 1, 1);
        return $this->players;
    }

    public function savePlayers(array $players): void
    {
        $uniquePlayers = [];
        $maxId = 0;

        foreach ($players as $player) {
            $username = trim($player['username']);
            if (!empty($username) && isset($player['id']) && $player['id'] > 0) {
                $uniquePlayers[$username] = $player;
                if ($player['id'] > $maxId) $maxId = $player['id'];
            }
        }

        uasort($uniquePlayers, fn($a, $b) => $a['id'] - $b['id']);

        $handle = fopen($this->playerFile, 'w');
        fputcsv($handle, ['id', 'username', 'rating', 'games', 'wins', 'draws', 'losses']);
        foreach ($uniquePlayers as $player) {
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

        $this->players = $uniquePlayers;
        $this->nextPlayerId = max($maxId + 1, 1);
    }

    public function loadMatches(): array
    {
        $this->matches = [];
        $maxId = 0;

        if (file_exists($this->matchFile) && ($handle = fopen($this->matchFile, 'r')) !== false) {
            $header = fgetcsv($handle);
            
            $isNewFormat = false;
            if ($header && count($header) >= 14) {
                $isNewFormat = true;
            }
            
            while (($data = fgetcsv($handle)) !== false) {
                // Skip if not enough data
                if (count($data) < 6) continue;
                
                $id = (int) $data[0];
                $whiteId = (int) $data[2];
                $blackId = (int) $data[3];
                
                if ($whiteId !== $blackId && $whiteId > 0 && $blackId > 0) {
                    if ($isNewFormat && count($data) >= 14) {
                        // New format with all fields
                        $match = [
                            'id' => $id,
                            'date' => $data[1],
                            'white_id' => $whiteId,
                            'black_id' => $blackId,
                            'result' => $data[4],
                            'analysis_url' => $data[5] ?? '',
                            'old_white_rating' => isset($data[6]) ? (int) $data[6] : 0,
                            'old_black_rating' => isset($data[7]) ? (int) $data[7] : 0,
                            'rating_change_white' => isset($data[8]) ? (int) $data[8] : 0,
                            'rating_change_black' => isset($data[9]) ? (int) $data[9] : 0,
                            'is_valid' => isset($data[10]) ? filter_var($data[10], FILTER_VALIDATE_BOOLEAN) : true,
                            'invalidated_at' => $data[11] ?? null,
                            'restored_white_rating' => isset($data[12]) ? (int) $data[12] : null,
                            'restored_black_rating' => isset($data[13]) ? (int) $data[13] : null,
                        ];
                    } else {
                        // Old format - convert to new format with defaults
                        $match = [
                            'id' => $id,
                            'date' => $data[1],
                            'white_id' => $whiteId,
                            'black_id' => $blackId,
                            'result' => $data[4],
                            'analysis_url' => $data[5] ?? '',
                            'old_white_rating' => 0,
                            'old_black_rating' => 0,
                            'rating_change_white' => 0,
                            'rating_change_black' => 0,
                            'is_valid' => true,
                            'invalidated_at' => null,
                            'restored_white_rating' => null,
                            'restored_black_rating' => null,
                        ];
                    }
                    
                    $this->matches[] = $match;
                    if ($id > $maxId) $maxId = $id;
                }
            }
            fclose($handle);
        }
        
        $this->nextMatchId = max($maxId + 1, 1);
        return $this->matches;
    }

    public function saveMatch(array $match): void
    {
        if ($match['white_id'] === $match['black_id']) {
            throw new \Exception("Cannot save match: White and Black players must be different");
        }

        if (!isset($match['id']) || $match['id'] <= 0) {
            throw new \Exception("Match ID must be provided and > 0");
        }

        // Ensure file exists with header
        $this->initializeMatchFile();

        // Check for duplicate ID by reading the file
        $existingMatches = $this->loadMatches();
        foreach ($existingMatches as $existing) {
            if ($existing['id'] == $match['id']) {
                throw new \Exception("Match ID {$match['id']} already exists");
            }
        }

        // Append to file with all fields
        $handle = fopen($this->matchFile, 'a');
        fputcsv($handle, [
            $match['id'],
            $match['date'] ?? date('Y-m-d H:i:s'),
            $match['white_id'],
            $match['black_id'],
            $match['result'],
            $match['analysis_url'] ?? '',
            $match['old_white_rating'] ?? 0,
            $match['old_black_rating'] ?? 0,
            $match['rating_change_white'] ?? 0,
            $match['rating_change_black'] ?? 0,
            isset($match['is_valid']) ? ($match['is_valid'] ? 'true' : 'false') : 'true',
            $match['invalidated_at'] ?? null,
            $match['restored_white_rating'] ?? null,
            $match['restored_black_rating'] ?? null,
        ]);
        fclose($handle);

        $this->matches[] = $match;
        $this->nextMatchId = max($this->nextMatchId, $match['id'] + 1);
    }

    public function saveMatches(array $matches): void
    {
        // Sort by ID
        uasort($matches, fn($a, $b) => $a['id'] - $b['id']);

        $handle = fopen($this->matchFile, 'w');
        fputcsv($handle, [
            'id',
            'date',
            'white_id',
            'black_id',
            'result',
            'analysis_url',
            'old_white_rating',
            'old_black_rating',
            'rating_change_white',
            'rating_change_black',
            'is_valid',
            'invalidated_at',
            'restored_white_rating',
            'restored_black_rating'
        ]);

        foreach ($matches as $match) {
            fputcsv($handle, [
                $match['id'],
                $match['date'] ?? date('Y-m-d H:i:s'),
                $match['white_id'],
                $match['black_id'],
                $match['result'],
                $match['analysis_url'] ?? '',
                $match['old_white_rating'] ?? 0,
                $match['old_black_rating'] ?? 0,
                $match['rating_change_white'] ?? 0,
                $match['rating_change_black'] ?? 0,
                isset($match['is_valid']) ? ($match['is_valid'] ? 'true' : 'false') : 'true',
                $match['invalidated_at'] ?? null,
                $match['restored_white_rating'] ?? null,
                $match['restored_black_rating'] ?? null,
            ]);
        }
        fclose($handle);

        $this->matches = $matches;

        // Update nextMatchId
        $maxId = 0;
        foreach ($matches as $match) {
            if ($match['id'] > $maxId) $maxId = $match['id'];
        }
        $this->nextMatchId = max($maxId + 1, 1);
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
        return isset($this->players[trim($username)]);
    }

    public function getPlayerByUsername(string $username): ?array
    {
        return $this->players[trim($username)] ?? null;
    }

    public function getPlayerById(int $id): ?array
    {
        foreach ($this->players as $player) {
            if ($player['id'] === $id) return $player;
        }
        return null;
    }
}
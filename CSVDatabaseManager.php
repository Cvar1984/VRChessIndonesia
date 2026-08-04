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

    public function __construct(string $playerFile = 'player.csv', string $matchFile = 'match.csv')
    {
        $this->playerFile = $playerFile;
        $this->matchFile = $matchFile;
    }
    public function __destruct()
    {
        $this->players = [];
        $this->matches = [];
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
                        if ($id > $maxId)
                            $maxId = $id;
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
                if ($player['id'] > $maxId)
                    $maxId = $player['id'];
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
            while (($data = fgetcsv($handle)) !== false) {
                if (count($data) >= 6) {
                    $id = (int) $data[0];
                    $whiteId = (int) $data[2];
                    $blackId = (int) $data[3];
                    if ($whiteId !== $blackId && $whiteId > 0 && $blackId > 0) {
                        $this->matches[] = [
                            'id' => $id,
                            'date' => $data[1],
                            'white_id' => $whiteId,
                            'black_id' => $blackId,
                            'result' => $data[4],
                            'analysis_url' => $data[5] ?? ''
                        ];
                        if ($id > $maxId)
                            $maxId = $id;
                    }
                }
            }
            fclose($handle);
        }
        $this->nextMatchId = max($maxId + 1, 1);
        return $this->matches;
    }

    /**
     * Save a match using the ID provided in the $match array.
     * Does NOT recalculate the ID – uses the one passed.
     */
    public function saveMatch(array $match): void
    {
        if ($match['white_id'] === $match['black_id']) {
            throw new Exception("Cannot save match: White and Black players must be different");
        }
        if (!isset($match['id']) || $match['id'] <= 0) {
            throw new Exception("Match ID must be provided and > 0");
        }

        // Load existing matches to check for ID conflicts
        $this->loadMatches();
        foreach ($this->matches as $existing) {
            if ($existing['id'] == $match['id']) {
                throw new Exception("Match ID {$match['id']} already exists");
            }
        }

        // Append to file
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

        // Update internal cache
        $this->matches[] = $match;
        // Ensure nextMatchId is beyond this ID
        $this->nextMatchId = max($this->nextMatchId, $match['id'] + 1);
    }

    public function getNextPlayerId(): int
    {
        $this->loadPlayers();
        return $this->nextPlayerId;
    }

    public function getNextMatchId(): int
    {
        $this->loadMatches();
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
            if ($player['id'] === $id)
                return $player;
        }
        return null;
    }
}

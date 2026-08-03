<?php

require_once 'Rating.php';
require_once 'DatabaseManager.php';
require_once 'CSVDatabaseManager.php';

class MatchManager
{
    public const WHITE_WIN = '1-0';
    public const BLACK_WIN = '0-1';
    public const DRAW = '1/2-1/2';
    public const INITIAL_RATING = 400;

    private DatabaseManager $db;
    private array $players = [];
    private array $matches = [];

    public function __construct(DatabaseManager $db)
    {
        $this->db = $db;
    }

    public function initialize(): void
    {
        $this->db->connect();
        $this->players = $this->db->loadPlayers();
        $this->matches = $this->db->loadMatches();
    }

    public function getOrCreatePlayer(string $username): array
    {
        $username = trim($username);
        
        $player = $this->db->getPlayerByUsername($username);
        
        if ($player !== null) {
            return $player;
        }
        
        $nextId = $this->db->getNextPlayerId();
        
        $newPlayer = [
            'id' => $nextId,
            'username' => $username,
            'rating' => self::INITIAL_RATING,
            'games' => 0,
            'wins' => 0,
            'draws' => 0,
            'losses' => 0
        ];
        
        $this->players[$username] = $newPlayer;
        $this->db->savePlayers($this->players);
        
        echo "📝 New player '{$username}' created with ID: {$nextId}, Rating: " . self::INITIAL_RATING . "\n";
        
        $this->players = $this->db->loadPlayers();
        
        return $newPlayer;
    }

    public function play(string $whiteUsername, string $blackUsername, string $result, string $analysisUrl): array
    {
        $whiteUsername = trim($whiteUsername);
        $blackUsername = trim($blackUsername);
        
        if ($whiteUsername === $blackUsername) {
            throw new Exception("A player cannot play against themselves!");
        }
        
        $white = $this->getOrCreatePlayer($whiteUsername);
        $black = $this->getOrCreatePlayer($blackUsername);

        if ($white['id'] === $black['id']) {
            throw new Exception("White and Black players must be different!");
        }

        $whiteResult = null;
        $blackResult = null;

        if ($result === self::WHITE_WIN) {
            $whiteResult = Rating::WIN;
            $blackResult = Rating::LOSS;
        } elseif ($result === self::BLACK_WIN) {
            $whiteResult = Rating::LOSS;
            $blackResult = Rating::WIN;
        } elseif ($result === self::DRAW) {
            $whiteResult = Rating::DRAW;
            $blackResult = Rating::DRAW;
        } else {
            throw new Exception("Invalid result. Use WHITE_WIN, BLACK_WIN, or DRAW");
        }

        $whiteCalculation = Rating::calculate($white['rating'], $black['rating'], $whiteResult);
        $blackCalculation = Rating::calculate($black['rating'], $white['rating'], $blackResult);

        $this->players[$whiteUsername]['rating'] = $whiteCalculation['new_rating'];
        $this->players[$whiteUsername]['games']++;
        $this->players[$blackUsername]['rating'] = $blackCalculation['new_rating'];
        $this->players[$blackUsername]['games']++;

        if ($result === self::WHITE_WIN) {
            $this->players[$whiteUsername]['wins']++;
            $this->players[$blackUsername]['losses']++;
        } elseif ($result === self::BLACK_WIN) {
            $this->players[$whiteUsername]['losses']++;
            $this->players[$blackUsername]['wins']++;
        } else {
            $this->players[$whiteUsername]['draws']++;
            $this->players[$blackUsername]['draws']++;
        }

        $this->db->savePlayers($this->players);
        $this->players = $this->db->loadPlayers();

        // Get the match ID BEFORE saving
        $matchId = $this->db->getNextMatchId();

        $match = [
            'id' => $matchId,
            'date' => date('Y-m-d H:i:s'),
            'white_id' => $white['id'],
            'black_id' => $black['id'],
            'result' => $result,
            'analysis_url' => $analysisUrl
        ];
        
        $this->db->saveMatch($match);
        $this->matches = $this->db->loadMatches();

        return [
            'white' => [
                'username' => $whiteUsername,
                'old_rating' => $whiteCalculation['old_rating'],
                'new_rating' => $whiteCalculation['new_rating'],
                'change' => $whiteCalculation['change'],
                'expected' => $whiteCalculation['expected']
            ],
            'black' => [
                'username' => $blackUsername,
                'old_rating' => $blackCalculation['old_rating'],
                'new_rating' => $blackCalculation['new_rating'],
                'change' => $blackCalculation['change'],
                'expected' => $blackCalculation['expected']
            ],
            'result' => $result,
            'analysis_url' => $analysisUrl,
            'match_id' => $matchId
        ];
    }

    public function showRankings(): void
    {
        $this->players = $this->db->loadPlayers();
        
        if (empty($this->players)) {
            echo "No players found.\n";
            return;
        }
        
        $players = array_values($this->players);
        usort($players, function($a, $b) {
            if ($b['rating'] !== $a['rating']) {
                return $b['rating'] - $a['rating'];
            }
            return $b['games'] - $a['games'];
        });

        echo "\n📊 Rankings:\n";
        echo str_repeat('=', 70) . "\n";
        echo str_pad("Rank", 6) . "\t";
        echo str_pad("ID", 4) . "\t";
        echo str_pad("Username", 15) . "\t";
        echo str_pad("Rating", 8) . "\t";
        echo str_pad("Games", 6) . "\t";
        echo "W/D/L\n";
        echo str_repeat('-', 70) . "\n";
        
        $rank = 1;
        foreach ($players as $player) {
            $record = "{$player['wins']}/{$player['draws']}/{$player['losses']}";
            echo str_pad("#{$rank}", 6) . "\t";
            echo str_pad($player['id'], 4) . "\t";
            echo str_pad($player['username'], 15) . "\t";
            echo str_pad($player['rating'], 8) . "\t";
            echo str_pad($player['games'], 6) . "\t";
            echo $record . "\n";
            $rank++;
        }
        echo str_repeat('=', 70) . "\n";
    }

    public function showHistory(): void
    {
        $this->matches = $this->db->loadMatches();
        $this->players = $this->db->loadPlayers();
        
        if (empty($this->matches)) {
            echo "No matches found.\n";
            return;
        }
        
        echo "\n📋 Match History:\n";
        echo str_repeat('=', 95) . "\n";
        echo str_pad("ID", 4) . "\t";
        echo str_pad("Date", 20) . "\t";
        echo str_pad("White (ID)", 12) . "\t";
        echo str_pad("Black (ID)", 12) . "\t";
        echo "Result\n";
        echo str_repeat('-', 95) . "\n";
        
        foreach ($this->matches as $match) {
            $whitePlayer = $this->db->getPlayerById($match['white_id']);
            $blackPlayer = $this->db->getPlayerById($match['black_id']);
            
            if ($whitePlayer && $blackPlayer) {
                echo str_pad($match['id'], 4) . "\t";
                echo str_pad($match['date'], 20) . "\t";
                echo str_pad($whitePlayer['username'] . " ({$whitePlayer['id']})", 12) . "\t";
                echo str_pad($blackPlayer['username'] . " ({$blackPlayer['id']})", 12) . "\t";
                echo $match['result'] . "\n";
            }
        }
        echo str_repeat('=', 95) . "\n";
    }

    public function getPlayerCount(): int
    {
        return count($this->players);
    }

    public function getMatchCount(): int
    {
        return count($this->matches);
    }

    public function cleanup(): void
    {
        $this->db->disconnect();
    }
    
    public function debug(): void
    {
        if (method_exists($this->db, 'debug')) {
            $this->db->debug();
        }
    }
}

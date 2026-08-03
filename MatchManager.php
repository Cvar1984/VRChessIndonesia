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

    /**
     * Initialize the match manager
     */
    public function initialize(): void
    {
        $this->db->connect();
        $this->players = $this->db->loadPlayers();
        $this->matches = $this->db->loadMatches();
    }

    /**
     * Get or create player by username
     */
    public function getOrCreatePlayer(string $username): array
    {
        // Check if player exists
        $player = $this->db->getPlayerByUsername($username);
        
        if ($player !== null) {
            return $player;
        }
        
        // Create new player
        $newPlayer = [
            'id' => $this->db->getNextPlayerId(),
            'username' => $username,
            'rating' => self::INITIAL_RATING,
            'games' => 0,
            'wins' => 0,
            'draws' => 0,
            'losses' => 0
        ];
        
        $this->players[$username] = $newPlayer;
        $this->db->savePlayers($this->players);
        
        echo "📝 New player '{$username}' created with rating " . self::INITIAL_RATING . "\n";
        
        return $newPlayer;
    }

    /**
     * Play a match between two players
     */
    public function play(string $whiteUsername, string $blackUsername, string $result, string $analysisUrl): array
    {
        // Get or create players
        $white = $this->getOrCreatePlayer($whiteUsername);
        $black = $this->getOrCreatePlayer($blackUsername);

        // Calculate rating changes
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

        // Calculate new ratings
        $whiteCalculation = Rating::calculate($white['rating'], $black['rating'], $whiteResult);
        $blackCalculation = Rating::calculate($black['rating'], $white['rating'], $blackResult);

        // Update player stats
        $this->players[$whiteUsername]['rating'] = $whiteCalculation['new_rating'];
        $this->players[$whiteUsername]['games']++;
        $this->players[$blackUsername]['rating'] = $blackCalculation['new_rating'];
        $this->players[$blackUsername]['games']++;

        // Update win/draw/loss counts
        if ($result === self::WHITE_WIN) {
            $this->players[$whiteUsername]['wins']++;
            $this->players[$blackUsername]['losses']++;
        } elseif ($result === self::BLACK_WIN) {
            $this->players[$whiteUsername]['losses']++;
            $this->players[$blackUsername]['wins']++;
        } else { // DRAW
            $this->players[$whiteUsername]['draws']++;
            $this->players[$blackUsername]['draws']++;
        }

        // Save players
        $this->db->savePlayers($this->players);

        // Save match
        $match = [
            'id' => $this->db->getNextMatchId(),
            'date' => date('Y-m-d H:i:s'),
            'white_id' => $white['id'],
            'black_id' => $black['id'],
            'result' => $result,
            'analysis_url' => $analysisUrl
        ];
        $this->db->saveMatch($match);
        $this->matches[] = $match;

        // Return result details
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
            'match_id' => $match['id']
        ];
    }

    /**
     * Display player rankings
     */
    public function showRankings(): void
    {
        $this->players = $this->db->loadPlayers();
        
        if (empty($this->players)) {
            echo "No players found.\n";
            return;
        }
        
        $players = array_values($this->players);
        usort($players, function($a, $b) {
            return $b['rating'] - $a['rating'];
        });

        echo "\n📊 Rankings:\n";
        echo str_repeat('=', 60) . "\n";
        echo str_pad("Rank", 6) . "\t";
        echo str_pad("Username", 12) . "\t";
        echo str_pad("Rating", 8) . "\t";
        echo str_pad("Games", 6) . "\t";
        echo "W/D/L\n";
        echo str_repeat('-', 60) . "\n";
        
        $rank = 1;
        foreach ($players as $player) {
            $record = "{$player['wins']}/{$player['draws']}/{$player['losses']}";
            echo str_pad("#{$rank}", 6) . "\t";
            echo str_pad($player['username'], 12) . "\t";
            echo str_pad($player['rating'], 8) . "\t";
            echo str_pad($player['games'], 6) . "\t";
            echo $record . "\n";
            $rank++;
        }
        echo str_repeat('=', 60) . "\n";
    }

    /**
     * Display match history
     */
    public function showHistory(): void
    {
        $this->matches = $this->db->loadMatches();
        $this->players = $this->db->loadPlayers();
        
        if (empty($this->matches)) {
            echo "No matches found.\n";
            return;
        }
        
        echo "\n📋 Match History:\n";
        echo str_repeat('=', 85) . "\n";
        echo str_pad("ID", 4) . "\t";
        echo str_pad("Date", 20) . "\t";
        echo str_pad("White", 12) . "\t";
        echo str_pad("Black", 12) . "\t";
        echo "Result\n";
        echo str_repeat('-', 85) . "\n";
        
        foreach ($this->matches as $match) {
            $whitePlayer = $this->db->getPlayerById($match['white_id']);
            $blackPlayer = $this->db->getPlayerById($match['black_id']);
            
            if ($whitePlayer && $blackPlayer) {
                echo str_pad($match['id'], 4) . "\t";
                echo str_pad($match['date'], 20) . "\t";
                echo str_pad($whitePlayer['username'], 12) . "\t";
                echo str_pad($blackPlayer['username'], 12) . "\t";
                echo $match['result'] . "\n";
            }
        }
        echo str_repeat('=', 85) . "\n";
    }

    /**
     * Get player count
     */
    public function getPlayerCount(): int
    {
        return count($this->players);
    }

    /**
     * Get match count
     */
    public function getMatchCount(): int
    {
        return count($this->matches);
    }

    /**
     * Clean up resources
     */
    public function cleanup(): void
    {
        $this->db->disconnect();
    }
}

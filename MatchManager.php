<?php

require_once 'Rating.php';

class MatchManager
{
    public const WHITE_WIN = '1-0';
    public const BLACK_WIN = '0-1';
    public const DRAW = '1/2-1/2';

    private static $players = [];
    private static $matches = [];
    private static $nextPlayerId = 4;
    private static $nextMatchId = 1;

    /**
     * Load players from CSV file
     */
    public static function loadPlayers()
    {
        self::$players = [];
        if (file_exists('data/player.csv') && ($handle = fopen('data/player.csv', 'r')) !== false) {
            $header = fgetcsv($handle); // Skip header
            $maxId = 0;
            while (($data = fgetcsv($handle)) !== false) {
                $id = (int)$data[0];
                self::$players[$data[1]] = [
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
            fclose($handle);
            self::$nextPlayerId = $maxId + 1;
        }
    }

    /**
     * Load matches and get the next match ID
     */
    public static function loadMatches()
    {
        self::$matches = [];
        $maxId = 0;
        
        if (file_exists('data/match.csv') && ($handle = fopen('data/match.csv', 'r')) !== false) {
            $header = fgetcsv($handle); // Skip header
            while (($data = fgetcsv($handle)) !== false) {
                $id = (int)$data[0];
                self::$matches[] = [
                    'id' => $id,
                    'date' => $data[1],
                    'white_id' => (int)$data[2],
                    'black_id' => (int)$data[3],
                    'result' => $data[4],
                    'analysis_url' => $data[5]
                ];
                if ($id > $maxId) {
                    $maxId = $id;
                }
            }
            fclose($handle);
        }
        
        self::$nextMatchId = $maxId + 1;
    }

    /**
     * Save players to CSV file
     */
    public static function savePlayers()
    {
        $handle = fopen('data/player.csv', 'w');
        fputcsv($handle, ['id', 'username', 'rating', 'games', 'wins', 'draws', 'losses']);
        
        foreach (self::$players as $player) {
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
    }

    /**
     * Save match to CSV file
     */
    public static function saveMatch($whiteId, $blackId, $result, $analysisUrl)
    {
        // Load existing matches first to get the correct next ID
        self::loadMatches();
        
        $handle = fopen('data/match.csv', 'a');
        fputcsv($handle, [
            self::$nextMatchId,
            date('Y-m-d H:i:s'),
            $whiteId,
            $blackId,
            $result,
            $analysisUrl
        ]);
        fclose($handle);
        
        // Increment for next match
        self::$nextMatchId++;
    }

    /**
     * Get player by username
     */
    public static function getPlayer($username)
    {
        if (!isset(self::$players[$username])) {
            throw new Exception("Player '$username' not found");
        }
        return self::$players[$username];
    }

    /**
     * Play a match between two players
     */
    public static function play($whiteUsername, $blackUsername, $result, $analysisUrl)
    {
        // Load existing data
        self::loadPlayers();
        self::loadMatches();

        // Get players
        $white = self::getPlayer($whiteUsername);
        $black = self::getPlayer($blackUsername);

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
        self::$players[$whiteUsername]['rating'] = $whiteCalculation['new_rating'];
        self::$players[$whiteUsername]['games']++;
        self::$players[$blackUsername]['rating'] = $blackCalculation['new_rating'];
        self::$players[$blackUsername]['games']++;

        // Update win/draw/loss counts
        if ($result === self::WHITE_WIN) {
            self::$players[$whiteUsername]['wins']++;
            self::$players[$blackUsername]['losses']++;
        } elseif ($result === self::BLACK_WIN) {
            self::$players[$whiteUsername]['losses']++;
            self::$players[$blackUsername]['wins']++;
        } else { // DRAW
            self::$players[$whiteUsername]['draws']++;
            self::$players[$blackUsername]['draws']++;
        }

        // Save data
        self::savePlayers();
        self::saveMatch($white['id'], $black['id'], $result, $analysisUrl);

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
            'analysis_url' => $analysisUrl
        ];
    }

    /**
     * Display player rankings
     */
    public static function showRankings()
    {
        self::loadPlayers();
        
        $players = array_values(self::$players);
        usort($players, function($a, $b) {
            return $b['rating'] - $a['rating'];
        });

        echo "Rankings:\n";
        echo str_repeat('-', 50) . "\n";
        echo "Rank\tUsername\tRating\tGames\tW/D/L\n";
        echo str_repeat('-', 50) . "\n";
        
        $rank = 1;
        foreach ($players as $player) {
            $record = "{$player['wins']}/{$player['draws']}/{$player['losses']}";
            echo "{$rank}\t{$player['username']}\t\t{$player['rating']}\t{$player['games']}\t{$record}\n";
            $rank++;
        }
        echo str_repeat('-', 50) . "\n";
    }

    /**
     * Display match history
     */
    public static function showHistory()
    {
        self::loadPlayers();
        self::loadMatches();
        
        if (empty(self::$matches)) {
            echo "No matches found.\n";
            return;
        }
        
        echo "Match History:\n";
        echo str_repeat('-', 80) . "\n";
        echo str_pad("ID", 4) . "\t";
        echo str_pad("Date", 20) . "\t";
        echo str_pad("White", 12) . "\t";
        echo str_pad("Black", 12) . "\t";
        echo "Result\n";
        echo str_repeat('-', 80) . "\n";
        
        foreach (self::$matches as $match) {
            $whitePlayer = self::getPlayerById($match['white_id']);
            $blackPlayer = self::getPlayerById($match['black_id']);
            
            if ($whitePlayer && $blackPlayer) {
                echo str_pad($match['id'], 4) . "\t";
                echo str_pad($match['date'], 20) . "\t";
                echo str_pad($whitePlayer['username'], 12) . "\t";
                echo str_pad($blackPlayer['username'], 12) . "\t";
                echo $match['result'] . "\n";
            }
        }
        echo str_repeat('-', 80) . "\n";
    }

    /**
     * Get player by ID
     */
    private static function getPlayerById($id)
    {
        foreach (self::$players as $player) {
            if ($player['id'] === $id) {
                return $player;
            }
        }
        return null;
    }
}

// Example usage:
try {
    MatchManager::showRankings();
    MatchManager::showHistory();
    $result = MatchManager::play('Charlie', 'Alice', MatchManager::DRAW, 'https://example.com/analysis/123');
    MatchManager::showRankings();
    MatchManager::showHistory();

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

<?php

namespace VRchessIndo\Logic;

use VRchessIndo\Connection\Interface\DatabaseManager;
use VRchessIndo\Logic\Rating;

class MatchManager
{
    public const string WHITE_WIN = '1-0';
    public const string BLACK_WIN = '0-1';
    public const string DRAW = '1/2-1/2';
    public const int INITIAL_RATING = 400;

    private DatabaseManager $db;
    private array $players = [];
    private array $matches = [];

    public function __construct(DatabaseManager $db)
    {
        $this->db = $db;
        $this->players = $this->db->loadPlayers();
        $this->matches = $this->db->loadMatches();
    }

    public function getPlayers(): array
    {
        $players = array_values($this->players);

        usort($players, function (array $a, array $b): int {
            return $b['rating'] <=> $a['rating'];
        });

        return $players;
    }

    private function getUsernameById(int $id): string
    {
        foreach ($this->players as $username => $data) {
            if ($data['id'] === $id) {
                return $username;
            }
        }
        throw new \Exception("Player ID $id not found");
    }

    private function getPlayerRecordById(int $playerId): array
    {
        foreach ($this->players as $player) {
            if ($player['id'] === $playerId) {
                $player['__deleted'] = false;
                return $player;
            }
        }

        return [
            'id' => $playerId,
            'username' => "DeletedPlayer#{$playerId}",
            'rating' => self::INITIAL_RATING,
            'games' => 0,
            'wins' => 0,
            'draws' => 0,
            'losses' => 0,
            '__deleted' => true,
        ];
    }

    public function getMatches(): array
    {
        return array_values($this->matches);
    }

    public function getMatch(int $matchId): ?array
    {
        foreach ($this->matches as $match) {
            if ($match['id'] === $matchId) {
                return $match;
            }
        }
        return null;
    }

    public function getValidMatches(): array
    {
        return array_values(array_filter($this->matches, function ($match) {
            return !isset($match['is_valid']) || $match['is_valid'] === true;
        }));
    }

    public function getInvalidMatches(): array
    {
        return array_values(array_filter($this->matches, function ($match) {
            return isset($match['is_valid']) && $match['is_valid'] === false;
        }));
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
        $this->players = $this->db->loadPlayers();

        return $newPlayer;
    }

    public function play(string $whiteUsername, string $blackUsername, string $result, string $analysisUrl): array
    {
        $whiteUsername = trim($whiteUsername);
        $blackUsername = trim($blackUsername);

        if ($whiteUsername === $blackUsername) {
            throw new \Exception("A player cannot play against themselves!");
        }

        $white = $this->getOrCreatePlayer($whiteUsername);
        $black = $this->getOrCreatePlayer($blackUsername);

        if ($white['id'] === $black['id']) {
            throw new \Exception("White and Black players must be different!");
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
            throw new \Exception("Invalid result. Use WHITE_WIN, BLACK_WIN, or DRAW");
        }

        $whiteCalculation = Rating::calculate($white['rating'], $black['rating'], $whiteResult);
        $blackCalculation = Rating::calculate($black['rating'], $white['rating'], $blackResult);

        // Store old ratings for potential invalidation
        $oldWhiteRating = $this->players[$whiteUsername]['rating'];
        $oldBlackRating = $this->players[$blackUsername]['rating'];

        // Update player stats
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

        $matchId = $this->db->getNextMatchId();

        $match = [
            'id' => $matchId,
            'date' => date('Y-m-d H:i:s'),
            'white_id' => $white['id'],
            'black_id' => $black['id'],
            'result' => $result,
            'analysis_url' => $analysisUrl,
            'is_valid' => true,  // Mark as valid by default
            'old_white_rating' => $oldWhiteRating,
            'old_black_rating' => $oldBlackRating,
            'rating_change_white' => $whiteCalculation['change'],
            'rating_change_black' => $blackCalculation['change'],
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
            'match_id' => $matchId,
            'is_valid' => true
        ];
    }

    private function recalculateRatings(): void
    {
        // 1. Reset all players to initial state
        foreach ($this->players as $username => &$player) {
            $player['rating'] = self::INITIAL_RATING;
            $player['games'] = 0;
            $player['wins'] = 0;
            $player['draws'] = 0;
            $player['losses'] = 0;
        }
        unset($player);

        // 2. Get only valid matches, sorted by date (oldest first)
        $validMatches = $this->getValidMatches();
        usort($validMatches, function ($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });

        // 3. Replay all valid matches in chronological order
        foreach ($validMatches as $match) {
            $whitePlayer = $this->getPlayerRecordById($match['white_id']);
            $blackPlayer = $this->getPlayerRecordById($match['black_id']);

            $whiteUsername = $whitePlayer['username'];
            $blackUsername = $blackPlayer['username'];

            $whiteRating = $whitePlayer['__deleted'] ? ($match['old_white_rating'] ?? self::INITIAL_RATING) : $whitePlayer['rating'];
            $blackRating = $blackPlayer['__deleted'] ? ($match['old_black_rating'] ?? self::INITIAL_RATING) : $blackPlayer['rating'];

            // Normalize result string (trim, remove extra spaces)
            $result = trim($match['result']);

            // Determine result constants
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
                // Fallback: skip invalid results to avoid corruption.
                continue;
            }

            $whiteCalc = Rating::calculate($whiteRating, $blackRating, $whiteResult);
            $blackCalc = Rating::calculate($blackRating, $whiteRating, $blackResult);

            // Update ratings for existing players only
            if (!$whitePlayer['__deleted']) {
                $this->players[$whiteUsername]['rating'] = $whiteCalc['new_rating'];
                $this->players[$whiteUsername]['games']++;
            }

            if (!$blackPlayer['__deleted']) {
                $this->players[$blackUsername]['rating'] = $blackCalc['new_rating'];
                $this->players[$blackUsername]['games']++;
            }

            if ($result === self::WHITE_WIN) {
                if (!$whitePlayer['__deleted']) {
                    $this->players[$whiteUsername]['wins']++;
                }
                if (!$blackPlayer['__deleted']) {
                    $this->players[$blackUsername]['losses']++;
                }
            } elseif ($result === self::BLACK_WIN) {
                if (!$whitePlayer['__deleted']) {
                    $this->players[$whiteUsername]['losses']++;
                }
                if (!$blackPlayer['__deleted']) {
                    $this->players[$blackUsername]['wins']++;
                }
            } else { // DRAW
                if (!$whitePlayer['__deleted']) {
                    $this->players[$whiteUsername]['draws']++;
                }
                if (!$blackPlayer['__deleted']) {
                    $this->players[$blackUsername]['draws']++;
                }
            }
        }

        // Save the recalculated players
        $this->db->savePlayers($this->players);
        // Reload from DB to keep in sync
        $this->players = $this->db->loadPlayers();
    }

    public function invalidateMatch(int $matchId): array
    {
        // Find the match
        $matchIndex = null;
        $match = null;

        foreach ($this->matches as $index => $m) {
            if ($m['id'] === $matchId) {
                $matchIndex = $index;
                $match = $m;
                break;
            }
        }

        if ($match === null) {
            throw new \Exception("Match #{$matchId} not found");
        }

        // Check if already invalid
        if (isset($match['is_valid']) && $match['is_valid'] === false) {
            throw new \Exception("Match #{$matchId} is already marked as invalid");
        }

        // Get players for response
        $white = $this->db->getPlayerById($match['white_id']);
        if ($white === null) {
            $white = [
                'id' => $match['white_id'],
                'username' => "DeletedPlayer#{$match['white_id']}",
                'rating' => ($match['old_white_rating'] ?? self::INITIAL_RATING) + ($match['rating_change_white'] ?? 0),
                'games' => 0,
                'wins' => 0,
                'draws' => 0,
                'losses' => 0,
            ];
        }

        $black = $this->db->getPlayerById($match['black_id']);
        if ($black === null) {
            $black = [
                'id' => $match['black_id'],
                'username' => "DeletedPlayer#{$match['black_id']}",
                'rating' => ($match['old_black_rating'] ?? self::INITIAL_RATING) + ($match['rating_change_black'] ?? 0),
                'games' => 0,
                'wins' => 0,
                'draws' => 0,
                'losses' => 0,
            ];
        }

        // Store current ratings before invalidation for response
        $currentWhiteRating = $white['rating'];
        $currentBlackRating = $black['rating'];

        // Mark match as invalid
        $this->matches[$matchIndex]['is_valid'] = false;
        $this->matches[$matchIndex]['invalidated_at'] = date('Y-m-d H:i:s');

        // Save matches
        $this->db->saveMatches($this->matches);
        $this->matches = $this->db->loadMatches();

        // Recalculate all ratings from scratch based on valid matches
        $this->recalculateRatings();

        // Get updated ratings
        $updatedWhite = $this->db->getPlayerById($match['white_id']);
        $updatedBlack = $this->db->getPlayerById($match['black_id']);

        return [
            'match_id' => $matchId,
            'is_valid' => false,
            'invalidated_at' => date('Y-m-d H:i:s'),
            'white' => [
                'username' => $white['username'],
                'rating_before' => $currentWhiteRating,
                'rating_after' => $updatedWhite['rating'],
                'change' => $updatedWhite['rating'] - $currentWhiteRating,
            ],
            'black' => [
                'username' => $black['username'],
                'rating_before' => $currentBlackRating,
                'rating_after' => $updatedBlack['rating'],
                'change' => $updatedBlack['rating'] - $currentBlackRating,
            ],
            'original_result' => $match['result'],
            'restored' => true,
            'total_valid_matches' => count($this->getValidMatches()),
            'total_invalid_matches' => count($this->getInvalidMatches())
        ];
    }

    public function revalidateMatch(int $matchId): array
    {
        // Find the match
        $matchIndex = null;
        $match = null;

        foreach ($this->matches as $index => $m) {
            if ($m['id'] === $matchId) {
                $matchIndex = $index;
                $match = $m;
                break;
            }
        }

        if ($match === null) {
            throw new \Exception("Match #{$matchId} not found");
        }

        // Check if it's invalid
        if (!isset($match['is_valid']) || $match['is_valid'] !== false) {
            throw new \Exception("Match #{$matchId} is not marked as invalid");
        }

        // Get players for response
        $white = $this->db->getPlayerById($match['white_id']);
        if ($white === null) {
            $white = [
                'id' => $match['white_id'],
                'username' => "DeletedPlayer#{$match['white_id']}",
                'rating' => ($match['old_white_rating'] ?? self::INITIAL_RATING) + ($match['rating_change_white'] ?? 0),
                'games' => 0,
                'wins' => 0,
                'draws' => 0,
                'losses' => 0,
            ];
        }

        $black = $this->db->getPlayerById($match['black_id']);
        if ($black === null) {
            $black = [
                'id' => $match['black_id'],
                'username' => "DeletedPlayer#{$match['black_id']}",
                'rating' => ($match['old_black_rating'] ?? self::INITIAL_RATING) + ($match['rating_change_black'] ?? 0),
                'games' => 0,
                'wins' => 0,
                'draws' => 0,
                'losses' => 0,
            ];
        }

        // Store current ratings before revalidation for response
        $currentWhiteRating = $white['rating'];
        $currentBlackRating = $black['rating'];

        // Mark match as valid again
        $this->matches[$matchIndex]['is_valid'] = true;
        unset($this->matches[$matchIndex]['invalidated_at']);
        unset($this->matches[$matchIndex]['restored_white_rating']);
        unset($this->matches[$matchIndex]['restored_black_rating']);

        // Save matches
        $this->db->saveMatches($this->matches);
        $this->matches = $this->db->loadMatches();

        // Recalculate all ratings from scratch based on valid matches
        $this->recalculateRatings();

        // Get updated ratings
        $updatedWhite = $this->db->getPlayerById($match['white_id']);
        $updatedBlack = $this->db->getPlayerById($match['black_id']);

        return [
            'match_id' => $matchId,
            'is_valid' => true,
            'revalidated_at' => date('Y-m-d H:i:s'),
            'white' => [
                'username' => $white['username'],
                'rating_before' => $currentWhiteRating,
                'rating_after' => $updatedWhite['rating'],
                'change' => $updatedWhite['rating'] - $currentWhiteRating,
            ],
            'black' => [
                'username' => $black['username'],
                'rating_before' => $currentBlackRating,
                'rating_after' => $updatedBlack['rating'],
                'change' => $updatedBlack['rating'] - $currentBlackRating,
            ],
            'original_result' => $match['result'],
            'revalidated' => true,
            'total_valid_matches' => count($this->getValidMatches()),
            'total_invalid_matches' => count($this->getInvalidMatches())
        ];
    }

    public function removePlayer(string $username): bool
    {
        $username = trim($username);

        if (!isset($this->players[$username])) {
            return false;
        }

        unset($this->players[$username]);
        $this->db->savePlayers($this->players);
        $this->players = $this->db->loadPlayers();

        return true;
    }

    public function removeMatch(int $matchId): bool
    {
        $matchIndex = null;

        foreach ($this->matches as $index => $match) {
            if ($match['id'] === $matchId) {
                $matchIndex = $index;
                break;
            }
        }

        if ($matchIndex === null) {
            return false;
        }

        unset($this->matches[$matchIndex]);
        $this->db->saveMatches($this->matches);
        $this->matches = $this->db->loadMatches();

        // Recalculate ratings after removing a match
        $this->recalculateRatings();

        return true;
    }

    public function editPlayer(string $username, array $newData): bool
    {
        $username = trim($username);

        if (!isset($this->players[$username])) {
            return false;
        }

        foreach ($newData as $key => $value) {
            if (array_key_exists($key, $this->players[$username])) {
                $this->players[$username][$key] = $value;
            }
        }

        $this->db->savePlayers($this->players);
        $this->players = $this->db->loadPlayers();

        return true;
    }

    public function editMatch(int $matchId, array $newData): bool
    {
        $matchIndex = null;

        foreach ($this->matches as $index => $match) {
            if ($match['id'] === $matchId) {
                $matchIndex = $index;
                break;
            }
        }

        if ($matchIndex === null) {
            return false;
        }

        foreach ($newData as $key => $value) {
            if (array_key_exists($key, $this->matches[$matchIndex])) {
                $this->matches[$matchIndex][$key] = $value;
            }
        }

        $this->db->saveMatches($this->matches);
        $this->matches = $this->db->loadMatches();

        // Recalculate ratings after editing a match
        $this->recalculateRatings();

        return true;
    }

    public function getPlayerCount(): int
    {
        return count($this->players);
    }

    public function getMatchCount(): int
    {
        return count($this->matches);
    }

    public function getPlayerStats(string $username): array
    {
        $username = trim($username);
        $player = $this->db->getPlayerByUsername($username);

        if ($player === null) {
            throw new \Exception("Player '{$username}' not found");
        }

        $validMatches = $this->getValidMatches();
        $invalidMatches = $this->getInvalidMatches();

        $playerMatches = array_filter($validMatches, function ($match) use ($player) {
            return $match['white_id'] === $player['id'] || $match['black_id'] === $player['id'];
        });

        $playerInvalidMatches = array_filter($invalidMatches, function ($match) use ($player) {
            return $match['white_id'] === $player['id'] || $match['black_id'] === $player['id'];
        });

        return [
            'player' => $player,
            'total_matches' => count($playerMatches) + count($playerInvalidMatches),
            'valid_matches' => count($playerMatches),
            'invalid_matches' => count($playerInvalidMatches),
            'current_rating' => $player['rating'],
            'games' => $player['games'],
            'wins' => $player['wins'],
            'draws' => $player['draws'],
            'losses' => $player['losses']
        ];
    }
}

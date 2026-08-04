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

    public function getMatches(): array
    {
        return array_values($this->matches);
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

    public function getPlayerCount(): int
    {
        return count($this->players);
    }

    public function getMatchCount(): int
    {
        return count($this->matches);
    }
}

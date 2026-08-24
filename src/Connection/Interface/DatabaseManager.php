<?php

namespace VRchessIndo\Connection\Interface;
/**
 * Interface DatabaseManager
 * Defines the contract for all database managers used in the application.
 */
interface DatabaseManager
{
    /**
     * Initializes the database connection.
     */
    public function __construct();

    /**
     * Closes the database connection.
     */
    public function __destruct();

    /**
     * Loads all players.
     * @return array
     */
    public function loadPlayers(): array;

    /**
     * Saves all players.
     * @param array $players
     */
    public function savePlayers(array $players): void;

    /**
     * Loads all matches.
     * @return array
     */
    public function loadMatches(): array;

    /**
     * Saves a single match.
     * @param array $match
     */
    public function saveMatch(array $match): void;

    /**
     * Saves all matches.
     * @param array $matches
     */
    public function saveMatches(array $matches): void;

    /**
     * Gets the next available player ID.
     * @return int
     */
    public function getNextPlayerId(): int;

    /**
     * Gets the next available match ID.
     * @return int
     */
    public function getNextMatchId(): int;

    /**
     * Checks if a player exists by username.
     * @param string $username
     * @return bool
     */
    public function playerExists(string $username): bool;

    /**
     * Gets a player by their username.
     * @param string $username
     * @return array|null
     */
    public function getPlayerByUsername(string $username): ?array;

    /**
     * Gets a player by their ID.
     * @param int $id
     * @return array|null
     */
    public function getPlayerById(int $id): ?array;
}

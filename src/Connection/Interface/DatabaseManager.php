<?php

namespace VRchessIndo\Connection\Interface;
interface DatabaseManager
{
    public function __construct();
    public function __destruct();
    public function loadPlayers(): array;
    public function savePlayers(array $players): void;
    public function loadMatches(): array;
    public function saveMatch(array $match): void;
    public function getNextPlayerId(): int;
    public function getNextMatchId(): int;
    public function playerExists(string $username): bool;
    public function getPlayerByUsername(string $username): ?array;
    public function getPlayerById(int $id): ?array;
}

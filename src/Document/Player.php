<?php

declare(strict_types=1);

namespace VRchessIndo\Document;

use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;
use VRchessIndo\Repository\PlayerRepository;

/**
 * Maps onto the existing `players` collection — same field names as the
 * legacy MongoDBDatabaseManager wrote, so this reads real production data
 * with zero migration.
 *
 * Mongo's own `_id` (auto-generated on every document regardless of app
 * logic) is the true ODM identity. The app's own auto-incrementing `id`
 * (used throughout match records, VRChat links, etc. — see MatchManager's
 * getNextPlayerId()) is kept as a plain indexed field, not the ODM identity,
 * since that's how it already exists in the live data; remapping it onto
 * `_id` would mean looking for an integer where an ObjectId already lives.
 */
#[ODM\Document(collection: 'players', repositoryClass: PlayerRepository::class)]
class Player
{
    #[ODM\Id]
    private ?string $mongoId = null;

    #[ODM\Field(type: 'int')]
    #[ODM\Index]
    private int $id;

    #[ODM\Field(type: 'string')]
    #[ODM\UniqueIndex]
    private string $username;

    #[ODM\Field(type: 'int')]
    private int $rating = 400;

    #[ODM\Field(type: 'int')]
    private int $games = 0;

    #[ODM\Field(type: 'int')]
    private int $wins = 0;

    #[ODM\Field(type: 'int')]
    private int $draws = 0;

    #[ODM\Field(type: 'int')]
    private int $losses = 0;

    #[ODM\Field(type: 'string', nullable: true, name: 'vrchat_user_id')]
    private ?string $vrchatUserId = null;

    #[ODM\Field(type: 'string', nullable: true, name: 'vrchat_display_name')]
    private ?string $vrchatDisplayName = null;

    #[ODM\Field(type: 'string', nullable: true, name: 'avatar_url')]
    private ?string $avatarUrl = null;

    #[ODM\Field(type: 'string', nullable: true, name: 'avatar_cached_at')]
    private ?string $avatarCachedAt = null;

    public const int INITIAL_RATING = 400;

    public function __construct(int $id, string $username)
    {
        $this->id = $id;
        $this->username = $username;
        $this->rating = self::INITIAL_RATING;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getRating(): int
    {
        return $this->rating;
    }

    public function getGames(): int
    {
        return $this->games;
    }

    public function getWins(): int
    {
        return $this->wins;
    }

    public function getDraws(): int
    {
        return $this->draws;
    }

    public function getLosses(): int
    {
        return $this->losses;
    }

    public function getVrchatUserId(): ?string
    {
        return $this->vrchatUserId;
    }

    public function getVrchatDisplayName(): ?string
    {
        return $this->vrchatDisplayName;
    }

    public function getAvatarUrl(): ?string
    {
        return $this->avatarUrl;
    }

    public function getAvatarCachedAt(): ?string
    {
        return $this->avatarCachedAt;
    }

    /**
     * Matches the legacy MatchManager::getPlayers() + mergeVrchatMeta() shape
     * (avatar_cached_at was never exposed over the API either).
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'rating' => $this->rating,
            'games' => $this->games,
            'wins' => $this->wins,
            'draws' => $this->draws,
            'losses' => $this->losses,
            'vrchat_user_id' => $this->vrchatUserId,
            'vrchat_display_name' => $this->vrchatDisplayName,
            'avatar_url' => $this->avatarUrl,
        ];
    }

    public function setUsername(string $username): void
    {
        $this->username = $username;
    }

    /**
     * Matches legacy MongoDBDatabaseManager::setPlayerVrchatLink().
     */
    public function setVrchatLink(string $vrchatUserId, ?string $vrchatDisplayName, ?string $avatarUrl): void
    {
        $this->vrchatUserId = trim($vrchatUserId);
        $this->vrchatDisplayName = $vrchatDisplayName;
        $this->avatarUrl = $avatarUrl;
        $this->avatarCachedAt = date('Y-m-d H:i:s');
    }

    /**
     * Matches legacy MongoDBDatabaseManager::clearPlayerVrchatLink().
     */
    public function clearVrchatLink(): void
    {
        $this->vrchatUserId = null;
        $this->vrchatDisplayName = null;
        $this->avatarUrl = null;
        $this->avatarCachedAt = null;
    }

    /**
     * Matches legacy MongoDBDatabaseManager::updatePlayerAvatarCache() — the
     * periodic refresh, which updates the cached picture without touching
     * the VRChat link itself.
     */
    public function updateAvatarCache(?string $avatarUrl): void
    {
        $this->avatarUrl = $avatarUrl;
        $this->avatarCachedAt = date('Y-m-d H:i:s');
    }

    public function setRating(int $rating): void
    {
        $this->rating = $rating;
    }

    public function incrementGames(): void
    {
        $this->games++;
    }

    public function incrementWins(): void
    {
        $this->wins++;
    }

    public function incrementDraws(): void
    {
        $this->draws++;
    }

    public function incrementLosses(): void
    {
        $this->losses++;
    }

    /**
     * Resets rating and stats to their initial state — the first step of
     * MatchManager::recalculateRatings()'s full replay-from-scratch.
     */
    public function resetRating(): void
    {
        $this->rating = self::INITIAL_RATING;
        $this->games = 0;
        $this->wins = 0;
        $this->draws = 0;
        $this->losses = 0;
    }
}

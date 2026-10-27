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

    /**
     * Every earlier username, oldest first: [{username, changed_at, source}], where
     * source is 'vrchat' (followed a VRChat display-name change), 'admin', or 'merge'
     * (inherited from a duplicate player folded into this one). Old names keep
     * resolving to this player — see PlayerRepository::findOneByUsername().
     */
    #[ODM\Field(type: 'raw', name: 'username_history')]
    private array $usernameHistory = [];

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
     * @return string[] earlier usernames, oldest first
     */
    public function getPreviousUsernames(): array
    {
        return array_column($this->usernameHistory, 'username');
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
            'previous_usernames' => $this->getPreviousUsernames(),
        ];
    }

    /**
     * The only way a username changes, so every change lands in username_history.
     * Returns false (recording nothing) when the name is already $newUsername.
     *
     * @param 'vrchat'|'admin' $source
     */
    public function rename(string $newUsername, string $source): bool
    {
        if ($newUsername === $this->username) {
            return false;
        }

        $this->usernameHistory[] = ['username' => $this->username, 'changed_at' => date('Y-m-d H:i:s'), 'source' => $source];
        $this->username = $newUsername;

        return true;
    }

    /**
     * Takes over the names of a duplicate player being merged into this one
     * (MatchManager::mergePlayers()): their current username and every name they
     * held before, so all of them keep resolving here rather than dying with the
     * duplicate's document.
     *
     * Entries are stamped at the merge rather than at the original rename, so the
     * old-name grace period (PlayerRepository::OLD_NAME_GRACE_DAYS) runs from when
     * the merge happened — the names only became ambiguous at that point. Names
     * this player already holds or has held are skipped, so merging twice can't
     * duplicate them.
     */
    public function absorbUsernamesFrom(self $other): void
    {
        $known = array_merge([$this->username], $this->getPreviousUsernames());
        $changedAt = date('Y-m-d H:i:s');

        foreach (array_merge([$other->getUsername()], $other->getPreviousUsernames()) as $name) {
            if (in_array($name, $known, true)) {
                continue;
            }

            $this->usernameHistory[] = ['username' => $name, 'changed_at' => $changedAt, 'source' => 'merge'];
            $known[] = $name;
        }
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
     * The periodic refresh: updates the cached picture and display name without
     * touching the VRChat link itself. A null display name (the VRChat account
     * couldn't be fetched) keeps the last known one.
     */
    public function updateVrchatProfile(?string $displayName, ?string $avatarUrl): void
    {
        $this->vrchatDisplayName = $displayName ?? $this->vrchatDisplayName;
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

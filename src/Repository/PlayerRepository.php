<?php

declare(strict_types=1);

namespace VRchessIndo\Repository;

use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\Bundle\MongoDBBundle\Repository\ServiceDocumentRepository;
use VRchessIndo\Document\Player;

/**
 * Extends the bundle's ServiceDocumentRepository (not the plain ODM
 * DocumentRepository) specifically so this class is autowireable — a plain
 * DocumentRepository can only be obtained via
 * DocumentManager::getRepository(), it can't be `new`'d directly, which is
 * exactly what generic service autowiring tried (and failed) to do here.
 *
 * @extends ServiceDocumentRepository<Player>
 */
class PlayerRepository extends ServiceDocumentRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Player::class);
    }

    /**
     * All players sorted by rating descending — same ordering as the
     * legacy MatchManager::getPlayers().
     *
     * @return Player[]
     */
    public function findAllSortedByRating(): array
    {
        return $this->findBy([], ['rating' => 'desc']);
    }

    public function findOneByAppId(int $id): ?Player
    {
        return $this->findOneBy(['id' => $id]);
    }

    /**
     * Falls back to earlier usernames, so a bot or admin still using a player's old
     * name (before a VRChat rename) reaches the same record instead of, say, a match
     * submission creating a duplicate player. A current username always wins.
     */
    public function findOneByUsername(string $username): ?Player
    {
        return $this->findOneBy(['username' => $username])
            ?? $this->findOneBy(['username_history.username' => $username]);
    }

    /** Current usernames only — what the unique index on `username` cares about. */
    public function isUsernameTaken(string $username): bool
    {
        return $this->findOneBy(['username' => $username]) !== null;
    }

    /**
     * Next auto-increment app-level ID — same "max(id) + 1" scheme as the
     * legacy MongoDBDatabaseManager::getNextPlayerId().
     */
    public function nextId(): int
    {
        $last = $this->createQueryBuilder()
            ->sort('id', 'desc')
            ->limit(1)
            ->getQuery()
            ->getSingleResult();

        return $last !== null ? $last->getId() + 1 : 1;
    }
}

<?php

declare(strict_types=1);

namespace VRchessIndo\Repository;

use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\Bundle\MongoDBBundle\Repository\ServiceDocumentRepository;
use VRchessIndo\Document\ChessMatch;

/**
 * @extends ServiceDocumentRepository<ChessMatch>
 */
class ChessMatchRepository extends ServiceDocumentRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChessMatch::class);
    }

    /**
     * @return ChessMatch[]
     */
    public function findAllSortedById(): array
    {
        return $this->findBy([], ['id' => 'asc']);
    }

    public function findOneByAppId(int $id): ?ChessMatch
    {
        return $this->findOneBy(['id' => $id]);
    }

    /**
     * @return ChessMatch[]
     */
    public function findValid(): array
    {
        return $this->findBy(['isValid' => true]);
    }

    /**
     * @return ChessMatch[]
     */
    public function findInvalid(): array
    {
        return $this->findBy(['isValid' => false]);
    }

    /**
     * Next auto-increment app-level ID — same "max(id) + 1" scheme as the
     * legacy MongoDBDatabaseManager::getNextMatchId().
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

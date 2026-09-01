<?php

declare(strict_types=1);

namespace VRchessIndo\Repository;

use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\Bundle\MongoDBBundle\Repository\ServiceDocumentRepository;
use VRchessIndo\Document\ApiToken;

/**
 * @extends ServiceDocumentRepository<ApiToken>
 */
class ApiTokenRepository extends ServiceDocumentRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    /**
     * @return ApiToken[]
     */
    public function findAllSortedByCreatedAtDesc(): array
    {
        return $this->findBy([], ['createdAt' => 'desc']);
    }

    public function findOneActiveByToken(string $token): ?ApiToken
    {
        return $this->findOneBy(['token' => trim($token), 'isActive' => true]);
    }

    public function findOneByIdOrToken(string $idOrToken): ?ApiToken
    {
        return $this->createQueryBuilder()
            ->addOr($this->createQueryBuilder()->expr()->field('id')->equals($idOrToken))
            ->addOr($this->createQueryBuilder()->expr()->field('token')->equals($idOrToken))
            ->getQuery()
            ->getSingleResult();
    }

    public function findOneByName(string $name): ?ApiToken
    {
        return $this->findOneBy(['name' => $name]);
    }
}

<?php

declare(strict_types=1);

namespace VRchessIndo\Repository;

use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\Bundle\MongoDBBundle\Repository\ServiceDocumentRepository;
use VRchessIndo\Document\Admin;

/**
 * @extends ServiceDocumentRepository<Admin>
 */
class AdminRepository extends ServiceDocumentRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Admin::class);
    }

    /**
     * @return Admin[]
     */
    public function findAllSortedByCreatedAt(): array
    {
        return $this->findBy([], ['createdAt' => 'asc']);
    }

    public function findOneByUsername(string $username): ?Admin
    {
        return $this->findOneBy(['username' => trim($username)]);
    }
}

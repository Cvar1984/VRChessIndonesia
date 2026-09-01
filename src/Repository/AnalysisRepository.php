<?php

declare(strict_types=1);

namespace VRchessIndo\Repository;

use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\Bundle\MongoDBBundle\Repository\ServiceDocumentRepository;
use VRchessIndo\Document\Analysis;

/**
 * @extends ServiceDocumentRepository<Analysis>
 */
class AnalysisRepository extends ServiceDocumentRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Analysis::class);
    }

    public function findOneById(string $id): ?Analysis
    {
        return $this->findOneBy(['id' => trim($id)]);
    }

    /**
     * @return Analysis[]
     */
    public function findAllSortedByCreatedAtDesc(): array
    {
        return $this->findBy([], ['createdAt' => 'desc']);
    }
}

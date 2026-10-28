<?php

declare(strict_types=1);

namespace VRchessIndo\Repository;

use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\Bundle\MongoDBBundle\Repository\ServiceDocumentRepository;
use VRchessIndo\Document\AuditEntry;

/**
 * @extends ServiceDocumentRepository<AuditEntry>
 */
class AuditEntryRepository extends ServiceDocumentRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditEntry::class);
    }

    /**
     * Newest first, always bounded — the collection is append-only, so an
     * unlimited read would eventually try to load 90 days of entries at once.
     *
     * @return AuditEntry[]
     */
    public function recent(int $limit = 100, ?string $actor = null): array
    {
        $criteria = $actor !== null && $actor !== '' ? ['actor' => $actor] : [];

        return $this->findBy($criteria, ['at' => 'desc'], max(1, min($limit, 500)));
    }
}

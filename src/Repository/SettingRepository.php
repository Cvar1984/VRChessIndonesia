<?php

declare(strict_types=1);

namespace VRchessIndo\Repository;

use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\Bundle\MongoDBBundle\Repository\ServiceDocumentRepository;
use VRchessIndo\Document\Setting;

/**
 * @extends ServiceDocumentRepository<Setting>
 */
class SettingRepository extends ServiceDocumentRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Setting::class);
    }

    public function findOneByKey(string $key): ?Setting
    {
        return $this->findOneBy(['key' => $key]);
    }
}

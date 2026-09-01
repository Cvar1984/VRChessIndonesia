<?php

declare(strict_types=1);

namespace VRchessIndo\Document;

use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;
use VRchessIndo\Repository\ApiTokenRepository;

/**
 * Maps onto the existing `tokens` collection.
 */
#[ODM\Document(collection: 'tokens', repositoryClass: ApiTokenRepository::class)]
class ApiToken
{
    #[ODM\Id]
    private ?string $mongoId = null;

    #[ODM\Field(type: 'string')]
    #[ODM\UniqueIndex]
    private string $id;

    #[ODM\Field(type: 'string')]
    private string $name;

    #[ODM\Field(type: 'string')]
    #[ODM\UniqueIndex]
    private string $token;

    #[ODM\Field(type: 'string', name: 'created_at')]
    private string $createdAt;

    #[ODM\Field(type: 'string', nullable: true, name: 'last_used')]
    private ?string $lastUsed = null;

    #[ODM\Field(type: 'bool', name: 'is_active')]
    private bool $isActive = true;

    public function __construct(string $id, string $name, string $token)
    {
        $this->id = $id;
        $this->name = $name;
        $this->token = $token;
        $this->createdAt = date('Y-m-d H:i:s');
        $this->isActive = true;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function getLastUsed(): ?string
    {
        return $this->lastUsed;
    }

    public function setLastUsed(string $lastUsed): void
    {
        $this->lastUsed = $lastUsed;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
    }

    /**
     * Matches legacy MongoDBDatabaseManager::getTokens() exactly, including
     * the 'Belum Pernah' ("Never") placeholder for a token that's never been used.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'token' => $this->token,
            'created_at' => $this->createdAt,
            'last_used' => $this->lastUsed !== null && $this->lastUsed !== '' ? $this->lastUsed : 'Belum Pernah',
            'is_active' => $this->isActive,
        ];
    }
}

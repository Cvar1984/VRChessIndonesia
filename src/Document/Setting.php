<?php

declare(strict_types=1);

namespace VRchessIndo\Document;

use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;
use VRchessIndo\Repository\SettingRepository;

/**
 * Maps onto the existing `settings` collection — a generic key/value store,
 * currently used for the cached VRChat session cookie blob and the legacy
 * admin_password fallback.
 */
#[ODM\Document(collection: 'settings', repositoryClass: SettingRepository::class)]
class Setting
{
    #[ODM\Id]
    private ?string $mongoId = null;

    #[ODM\Field(type: 'string')]
    #[ODM\UniqueIndex]
    private string $key;

    #[ODM\Field(type: 'string')]
    private string $value;

    #[ODM\Field(type: 'string', nullable: true, name: 'updated_at')]
    private ?string $updatedAt = null;

    public function __construct(string $key, string $value)
    {
        $this->key = $key;
        $this->value = $value;
        $this->updatedAt = date('Y-m-d H:i:s');
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): void
    {
        $this->value = $value;
        $this->updatedAt = date('Y-m-d H:i:s');
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }
}

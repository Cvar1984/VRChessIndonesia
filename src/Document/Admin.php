<?php

declare(strict_types=1);

namespace VRchessIndo\Document;

use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use VRchessIndo\Repository\AdminRepository;

/**
 * Maps onto the existing `admins` collection. `password` is a bcrypt hash
 * (password_hash()/PASSWORD_BCRYPT), same as the legacy manager.
 *
 * Implements Security's UserInterface directly (a Doctrine document doubling
 * as the security user is a common, supported pattern) rather than through a
 * separate wrapper class — there's only ever one kind of "admin" identity.
 */
#[ODM\Document(collection: 'admins', repositoryClass: AdminRepository::class)]
class Admin implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ODM\Id]
    private ?string $mongoId = null;

    #[ODM\Field(type: 'string')]
    #[ODM\UniqueIndex]
    private string $username;

    #[ODM\Field(type: 'string')]
    private string $password;

    #[ODM\Field(type: 'string', name: 'created_at')]
    private string $createdAt;

    public function __construct(string $username, string $passwordHash)
    {
        $this->username = $username;
        $this->password = $passwordHash;
        $this->createdAt = date('Y-m-d H:i:s');
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $passwordHash): void
    {
        $this->password = $passwordHash;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function getRoles(): array
    {
        return ['ROLE_ADMIN'];
    }

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    public function eraseCredentials(): void
    {
    }

    /**
     * Matches legacy MongoDBDatabaseManager::getAdmins() — never exposes
     * the password hash over the API.
     */
    public function toArray(): array
    {
        return [
            'username' => $this->username,
            'created_at' => $this->createdAt,
        ];
    }
}

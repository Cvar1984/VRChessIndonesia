<?php

declare(strict_types=1);

namespace VRchessIndo\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Represents an authenticated API-token client (as opposed to an Admin) —
 * grants ROLE_API_TOKEN, which the role hierarchy also grants to admins, so
 * "requires API access" endpoints accept either. Always built fresh from the
 * request's own token header/query value (see ApiTokenAuthenticator); never
 * looked up independently, since token validity is re-checked every request.
 */
class ApiTokenUser implements UserInterface
{
    public function __construct(
        private readonly string $tokenId,
        private readonly string $tokenName,
    ) {
    }

    public function getTokenId(): string
    {
        return $this->tokenId;
    }

    public function getTokenName(): string
    {
        return $this->tokenName;
    }

    public function getRoles(): array
    {
        return ['ROLE_API_TOKEN'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return 'api-token:' . $this->tokenId;
    }
}

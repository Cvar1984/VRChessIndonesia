<?php

declare(strict_types=1);

namespace VRchessIndo\Security;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Required so the firewall's chained provider can refresh an ApiTokenUser if
 * one ever ends up session-stored. There's nothing meaningful to re-load —
 * token identity is re-established fresh from the request on every request
 * (see ApiTokenAuthenticator) — so refresh is a no-op passthrough.
 */
class ApiTokenUserProvider implements UserProviderInterface
{
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        throw new UserNotFoundException('API token users are never loaded by identifier alone.');
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof ApiTokenUser) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        return $user;
    }

    public function supportsClass(string $class): bool
    {
        return ApiTokenUser::class === $class;
    }
}

<?php

declare(strict_types=1);

namespace VRchessIndo\Security;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use VRchessIndo\Repository\ApiTokenRepository;

/**
 * The firewall is stateful, so a request authenticated by ApiTokenAuthenticator
 * leaves its ApiTokenUser in the session, and a client that keeps cookies (a
 * bot's HTTP session, curl with a cookie jar) is then authenticated by that
 * session alone on later requests. refreshUser() therefore re-checks the token
 * on every one of them: once it's deleted or deactivated, the session stops
 * working too, instead of outliving the revocation.
 */
class ApiTokenUserProvider implements UserProviderInterface
{
    public function __construct(private readonly ApiTokenRepository $tokens)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        throw new UserNotFoundException('API token users are never loaded by identifier alone.');
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof ApiTokenUser) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        if ($this->tokens->findOneBy(['id' => $user->getTokenId(), 'isActive' => true]) === null) {
            throw new UserNotFoundException("API token '{$user->getTokenId()}' was revoked or deactivated.");
        }

        return $user;
    }

    public function supportsClass(string $class): bool
    {
        return ApiTokenUser::class === $class;
    }
}

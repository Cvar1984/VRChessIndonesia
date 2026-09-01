<?php

declare(strict_types=1);

namespace VRchessIndo\Security;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use VRchessIndo\Document\Admin;
use VRchessIndo\Repository\AdminRepository;

/**
 * Loads/refreshes the Admin user for session-restored security tokens.
 */
class AdminUserProvider implements UserProviderInterface
{
    public function __construct(private readonly AdminRepository $admins)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $admin = $this->admins->findOneByUsername($identifier);
        if ($admin === null) {
            throw new UserNotFoundException("Admin '{$identifier}' not found");
        }

        return $admin;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof Admin) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return Admin::class === $class || is_subclass_of($class, Admin::class);
    }
}

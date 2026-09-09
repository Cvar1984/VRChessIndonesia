<?php

declare(strict_types=1);

namespace VRchessIndo\Tests\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use VRchessIndo\Security\ApiTokenUser;
use VRchessIndo\Security\ApiTokenUserProvider;

/**
 * No dependencies, no I/O — a pure passthrough provider (see its own
 * docblock: token identity is re-established fresh per request, never
 * session-refreshed for real), so a plain TestCase covers it fully.
 */
class ApiTokenUserProviderTest extends TestCase
{
    private ApiTokenUserProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new ApiTokenUserProvider();
    }

    public function testLoadUserByIdentifierAlwaysThrows(): void
    {
        $this->expectException(UserNotFoundException::class);
        $this->provider->loadUserByIdentifier('anything');
    }

    public function testRefreshUserReturnsTheSameInstanceForApiTokenUser(): void
    {
        $user = new ApiTokenUser('token-id-1', 'My Token');

        self::assertSame($user, $this->provider->refreshUser($user));
    }

    public function testRefreshUserRejectsAnyOtherUserType(): void
    {
        $this->expectException(UnsupportedUserException::class);
        $this->provider->refreshUser(new InMemoryUser('someone', null));
    }

    public function testSupportsClassOnlyMatchesApiTokenUser(): void
    {
        self::assertTrue($this->provider->supportsClass(ApiTokenUser::class));
        self::assertFalse($this->provider->supportsClass(InMemoryUser::class));
    }
}

<?php

declare(strict_types=1);

namespace VRchessIndo\Tests\Security;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use VRchessIndo\Document\ApiToken;
use VRchessIndo\Repository\ApiTokenRepository;
use VRchessIndo\Security\ApiTokenUser;
use VRchessIndo\Security\ApiTokenUserProvider;

/**
 * The token lookup is the only dependency, so a mocked repository and a plain
 * TestCase cover it; the end-to-end session behavior is in
 * tests/Controller/IntegrityFixesTest.php.
 */
class ApiTokenUserProviderTest extends TestCase
{
    private ApiTokenUserProvider $provider;
    private ApiTokenRepository&MockObject $tokens;

    protected function setUp(): void
    {
        $this->tokens = $this->createMock(ApiTokenRepository::class);
        $this->provider = new ApiTokenUserProvider($this->tokens);
    }

    public function testLoadUserByIdentifierAlwaysThrows(): void
    {
        $this->expectException(UserNotFoundException::class);
        $this->provider->loadUserByIdentifier('anything');
    }

    public function testRefreshUserKeepsAStillActiveToken(): void
    {
        $user = new ApiTokenUser('token-id-1', 'My Token');
        $this->tokens->method('findOneBy')
            ->with(['id' => 'token-id-1', 'isActive' => true])
            ->willReturn(new ApiToken('token-id-1', 'My Token', 'vrchess_pat_x'));

        self::assertSame($user, $this->provider->refreshUser($user));
    }

    public function testRefreshUserRejectsARevokedOrInactiveToken(): void
    {
        $this->tokens->method('findOneBy')->willReturn(null);

        $this->expectException(UserNotFoundException::class);
        $this->provider->refreshUser(new ApiTokenUser('token-id-1', 'My Token'));
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

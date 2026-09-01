<?php

declare(strict_types=1);

namespace VRchessIndo\Tests\Security;

use VRchessIndo\Tests\ApiTestCase;

class AdminAuthTest extends ApiTestCase
{
    public function testLoginSuccessAndAuthStatusPersistsViaSession(): void
    {
        $this->loginAsAdmin();
        $body = $this->jsonBody();
        self::assertTrue($body['success']);
        self::assertTrue($body['authenticated']);
        self::assertSame(self::ADMIN_USERNAME, $body['username']);

        // Same client (session cookie carried over) — no credentials resent.
        $this->client->request('GET', '/api/auth/status');
        $status = $this->jsonBody();
        self::assertTrue($status['authenticated']);
        self::assertSame(self::ADMIN_USERNAME, $status['username']);
    }

    public function testLoginFailureWithWrongPassword(): void
    {
        $this->jsonRequest('POST', '/api/admin/login', ['username' => self::ADMIN_USERNAME, 'password' => 'wrong']);

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        $body = $this->jsonBody();
        self::assertFalse($body['success']);
        self::assertSame('Username atau password admin salah!', $body['error']);
    }

    public function testAuthStatusWhenNotLoggedIn(): void
    {
        $this->client->request('GET', '/api/auth/status');
        $body = $this->jsonBody();
        self::assertTrue($body['success']);
        self::assertFalse($body['authenticated']);
        self::assertNull($body['username']);
    }

    public function testLogoutClearsSession(): void
    {
        $this->loginAsAdmin();

        $this->client->request('POST', '/api/admin/logout');
        $body = $this->jsonBody();
        self::assertTrue($body['success']);
        self::assertFalse($body['authenticated']);

        $this->client->request('GET', '/api/auth/status');
        self::assertFalse($this->jsonBody()['authenticated']);
    }

    public function testAdminOnlyEndpointRejectsUnauthenticatedRequest(): void
    {
        $this->client->request('GET', '/api/admin/tokens');

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        $body = $this->jsonBody();
        self::assertFalse($body['success']);
        self::assertSame('Akses ditolak: Diperlukan autentikasi admin.', $body['error']);
    }

    public function testAdminOnlyEndpointAcceptsSession(): void
    {
        $this->loginAsAdmin();

        $this->client->request('GET', '/api/admin/tokens');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->jsonBody()['success']);
    }

    public function testAdminHeaderAuthWorksWithoutPriorLogin(): void
    {
        // A fresh client with no session at all — proves per-request header
        // auth (X-Admin-Username/X-Admin-Password) works independently of
        // the session-based login flow, matching legacy isAdmin()'s fallback.
        $this->client->request('GET', '/api/admin/tokens', [], [], [
            'HTTP_X_ADMIN_USERNAME' => self::ADMIN_USERNAME,
            'HTTP_X_ADMIN_PASSWORD' => self::ADMIN_PASSWORD,
        ]);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->jsonBody()['success']);
    }

    public function testAdminHeaderAuthRejectsWrongPassword(): void
    {
        $this->client->request('GET', '/api/admin/tokens', [], [], [
            'HTTP_X_ADMIN_USERNAME' => self::ADMIN_USERNAME,
            'HTTP_X_ADMIN_PASSWORD' => 'wrong',
        ]);

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertSame('Akses ditolak: Diperlukan autentikasi admin.', $this->jsonBody()['error']);
    }
}

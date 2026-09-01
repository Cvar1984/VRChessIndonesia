<?php

declare(strict_types=1);

namespace VRchessIndo\Tests\Controller;

use VRchessIndo\Tests\ApiTestCase;

class AdminManagementControllerTest extends ApiTestCase
{
    public function testCreateListUpdatePasswordDelete(): void
    {
        $this->loginAsAdmin();

        // Create
        $this->jsonRequest('POST', '/api/admin/admins', ['username' => 'secondadmin', 'password' => 'secondpass']);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->jsonBody()['success']);

        // Duplicate username rejected
        $this->jsonRequest('POST', '/api/admin/admins', ['username' => 'secondadmin', 'password' => 'anotherpass']);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        // Password too short rejected
        $this->jsonRequest('POST', '/api/admin/admins', ['username' => 'thirdadmin', 'password' => 'abc']);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        // List
        $this->client->request('GET', '/api/admin/admins');
        $admins = $this->jsonBody()['admins'];
        self::assertCount(2, $admins);
        self::assertArrayNotHasKey('password', $admins[0], 'Password hash never exposed');

        // Update password
        $this->jsonRequest('PATCH', '/api/admin/admins/secondadmin', ['password' => 'newpassword']);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->jsonBody()['success']);

        // New password actually works for login
        $freshClient = $this->freshClient();
        $freshClient->request(
            'POST',
            '/api/admin/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['username' => 'secondadmin', 'password' => 'newpassword']),
        );
        self::assertTrue(json_decode($freshClient->getResponse()->getContent(), true)['success']);

        // Delete
        $this->client->request('DELETE', '/api/admin/admins/secondadmin');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->jsonBody()['success']);

        $this->client->request('GET', '/api/admin/admins');
        self::assertCount(1, $this->jsonBody()['admins']);
    }

    public function testCannotDeleteSelf(): void
    {
        $this->loginAsAdmin();

        $this->jsonRequest('POST', '/api/admin/admins', ['username' => 'secondadmin', 'password' => 'secondpass']);

        $this->client->request('DELETE', '/api/admin/admins/' . self::ADMIN_USERNAME);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame('Tidak bisa menghapus diri sendiri.', $this->jsonBody()['error']);
    }

    public function testAdminManagementRequiresAdminAuth(): void
    {
        $this->client->request('GET', '/api/admin/admins');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        $this->jsonRequest('POST', '/api/admin/admins', ['username' => 'x', 'password' => 'password']);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }
}

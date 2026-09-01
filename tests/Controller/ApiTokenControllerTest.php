<?php

declare(strict_types=1);

namespace VRchessIndo\Tests\Controller;

use VRchessIndo\Tests\ApiTestCase;

class ApiTokenControllerTest extends ApiTestCase
{
    public function testTokenLifecycleAndItGrantsApiAccess(): void
    {
        $this->loginAsAdmin();

        // Create
        $this->jsonRequest('POST', '/api/admin/tokens', ['name' => 'CI Bot']);
        self::assertResponseIsSuccessful();
        $created = $this->jsonBody();
        self::assertTrue($created['success']);
        self::assertSame('CI Bot', $created['token']['name']);
        self::assertStringStartsWith('vrchess_pat_', $created['token']['token']);
        self::assertTrue($created['token']['is_active']);
        self::assertSame('Belum Pernah', $created['token']['last_used']);
        $tokenId = $created['token']['id'];
        $tokenValue = $created['token']['token'];

        // List
        $this->client->request('GET', '/api/admin/tokens');
        $list = $this->jsonBody();
        self::assertCount(1, $list['tokens']);

        // The token grants ROLE_API_TOKEN access on a fresh, session-less client.
        $anonymousClient = $this->freshClient();
        $anonymousClient->request('GET', '/api/matches', [], [], ['HTTP_X_API_TOKEN' => $tokenValue]);
        // /api/matches is a public read endpoint (no gate) — prove the token
        // instead via a requireApiAccess()-gated mutation: playing a match.
        $anonymousClient->request(
            'POST',
            '/api/matches',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_API_TOKEN' => $tokenValue],
            json_encode(['white' => 'Alice', 'black' => 'Bob', 'result' => '1']),
        );
        self::assertResponseIsSuccessful();
        $playBody = json_decode($anonymousClient->getResponse()->getContent(), true);
        self::assertTrue($playBody['success']);

        // Update (rename + deactivate)
        $this->jsonRequest('PATCH', "/api/admin/tokens/{$tokenId}", ['name' => 'CI Bot Renamed', 'is_active' => false]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->jsonBody()['success']);

        // Deactivated token no longer grants access.
        $deactivatedClient = $this->freshClient();
        $deactivatedClient->request(
            'POST',
            '/api/matches',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_API_TOKEN' => $tokenValue],
            json_encode(['white' => 'Charlie', 'black' => 'Dave', 'result' => '1']),
        );
        self::assertSame(401, $deactivatedClient->getResponse()->getStatusCode());

        // Revoke
        $this->client->request('DELETE', "/api/admin/tokens/{$tokenId}");
        self::assertResponseIsSuccessful();
        self::assertTrue($this->jsonBody()['success']);

        $this->client->request('GET', '/api/admin/tokens');
        self::assertCount(0, $this->jsonBody()['tokens']);
    }

    public function testRevokeNonexistentTokenReturns404(): void
    {
        $this->loginAsAdmin();

        $this->client->request('DELETE', '/api/admin/tokens/tok_doesnotexist');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->jsonBody()['success']);
    }

    public function testMissingTokenRejectsApiAccessEndpoint(): void
    {
        $this->jsonRequest('POST', '/api/matches', ['white' => 'A', 'black' => 'B', 'result' => '1']);

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertSame('Akses API ditolak: Diperlukan API Token yang valid.', $this->jsonBody()['error']);
    }
}

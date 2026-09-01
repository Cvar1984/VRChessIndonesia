<?php

declare(strict_types=1);

namespace VRchessIndo\Tests\Controller;

use Symfony\Component\HttpClient\Response\MockResponse;
use VRchessIndo\Document\Player;
use VRchessIndo\Tests\ApiTestCase;

/**
 * VRChatController end-to-end via real HTTP requests, with the VRChat API
 * itself mocked through the shared test.mock_http_client binding (see
 * config/services.yaml's when@test block and ApiTestCase::mockHttpClient())
 * — never touches the real api.vrchat.cloud or the real account credentials.
 */
class VRChatControllerTest extends ApiTestCase
{
    /** A no-2FA login response, first in queue for any action that builds a fresh VRChatClient. */
    private function loginResponse(): MockResponse
    {
        return new MockResponse(json_encode(['id' => 'usr_me']), [
            'http_code' => 200,
            'response_headers' => ['Set-Cookie: auth=cookie; Path=/'],
        ]);
    }

    public function testSearchRequiresAdmin(): void
    {
        $this->client->request('GET', '/api/admin/vrchat/search?q=alice');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testSearchRequiresQueryParam(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/api/admin/vrchat/search');
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame('Parameter q (kata kunci pencarian) diperlukan', $this->jsonBody()['error']);
    }

    public function testSearchReturnsResults(): void
    {
        $this->mockHttpClient([
            $this->loginResponse(),
            new MockResponse(json_encode([
                ['id' => 'usr_abc', 'displayName' => 'Alice VR', 'userIcon' => 'https://example.com/icon.png'],
            ]), ['http_code' => 200]),
        ]);

        $this->loginAsAdmin();
        $this->client->request('GET', '/api/admin/vrchat/search?q=alice');

        self::assertResponseIsSuccessful();
        $body = $this->jsonBody();
        self::assertTrue($body['success']);
        self::assertSame('usr_abc', $body['results'][0]['id']);
        self::assertSame('Alice VR', $body['results'][0]['displayName']);
    }

    public function testLinkRequiresAdmin(): void
    {
        $this->jsonRequest('POST', '/api/admin/vrchat/link', ['username' => 'Alice', 'vrchat_user_id' => 'usr_abc']);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testLinkRejectsUnknownPlayer(): void
    {
        $this->loginAsAdmin();
        $this->jsonRequest('POST', '/api/admin/vrchat/link', ['username' => 'NoSuchPlayer', 'vrchat_user_id' => 'usr_abc']);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testLinkSuccessfullyLinksExistingPlayer(): void
    {
        $this->loginAsAdmin();
        $this->jsonRequest('POST', '/api/matches', ['white' => 'Alice', 'black' => 'Bob', 'result' => '1']);

        $this->mockHttpClient([
            $this->loginResponse(),
            new MockResponse(json_encode([
                'id' => 'usr_abc',
                'displayName' => 'Alice VR',
                'userIcon' => 'https://example.com/icon.png',
            ]), ['http_code' => 200]),
        ]);

        $this->jsonRequest('POST', '/api/admin/vrchat/link', ['username' => 'Alice', 'vrchat_user_id' => 'usr_abc']);

        self::assertResponseIsSuccessful();
        $body = $this->jsonBody();
        self::assertTrue($body['success']);
        self::assertSame('usr_abc', $body['vrchat']['id']);

        $this->client->request('GET', '/api/players');
        $players = $this->jsonBody()['players'];
        $alice = current(array_filter($players, static fn ($p) => $p['username'] === 'Alice'));
        self::assertSame('usr_abc', $alice['vrchat_user_id']);
        self::assertSame('Alice VR', $alice['vrchat_display_name']);
        self::assertSame('https://example.com/icon.png', $alice['avatar_url']);
    }

    public function testUnlinkRequiresAdmin(): void
    {
        $this->jsonRequest('POST', '/api/admin/vrchat/unlink', ['username' => 'Alice']);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testUnlinkClearsExistingLink(): void
    {
        $player = new Player(1, 'Alice');
        $player->setVrchatLink('usr_abc', 'Alice VR', 'https://example.com/icon.png');
        $this->dm->persist($player);
        $this->dm->flush();
        $this->dm->clear();

        $this->loginAsAdmin();
        $this->jsonRequest('POST', '/api/admin/vrchat/unlink', ['username' => 'Alice']);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->jsonBody()['success']);

        $this->client->request('GET', '/api/players');
        $alice = $this->jsonBody()['players'][0];
        self::assertNull($alice['vrchat_user_id']);
        self::assertNull($alice['avatar_url']);
    }

    public function testUnlinkNonexistentPlayerReturns404(): void
    {
        $this->loginAsAdmin();
        $this->jsonRequest('POST', '/api/admin/vrchat/unlink', ['username' => 'NoSuchPlayer']);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testRefreshAvatarsSkipsFreshLinksUnlessForced(): void
    {
        $player = new Player(1, 'Alice');
        $player->setVrchatLink('usr_abc', 'Alice VR', 'https://example.com/old.png');
        $this->dm->persist($player);
        $this->dm->flush();
        $this->dm->clear();

        // Only the login response is queued — if a getUser() call for the
        // still-fresh player were attempted, MockHttpClient would throw for
        // running out of queued responses, failing the test.
        $this->mockHttpClient([$this->loginResponse()]);

        $this->loginAsAdmin();
        $this->jsonRequest('POST', '/api/admin/vrchat/refresh-avatars', []);

        self::assertResponseIsSuccessful();
        $body = $this->jsonBody();
        self::assertSame(0, $body['refreshed']);
        self::assertSame(1, $body['skipped']);
        self::assertSame(0, $body['failed']);
    }

    public function testRefreshAvatarsForcedUpdatesEvenFreshLinks(): void
    {
        $player = new Player(1, 'Alice');
        $player->setVrchatLink('usr_abc', 'Alice VR', 'https://example.com/old.png');
        $this->dm->persist($player);
        $this->dm->flush();
        $this->dm->clear();

        $this->mockHttpClient([
            $this->loginResponse(),
            new MockResponse(json_encode([
                'id' => 'usr_abc',
                'displayName' => 'Alice VR',
                'userIcon' => 'https://example.com/new.png',
            ]), ['http_code' => 200]),
        ]);

        $this->loginAsAdmin();
        $this->jsonRequest('POST', '/api/admin/vrchat/refresh-avatars', ['force' => true]);

        self::assertResponseIsSuccessful();
        $body = $this->jsonBody();
        self::assertSame(1, $body['refreshed']);
        self::assertSame(0, $body['skipped']);

        $this->client->request('GET', '/api/players');
        $alice = $this->jsonBody()['players'][0];
        self::assertSame('https://example.com/new.png', $alice['avatar_url']);
    }

    public function testRefreshAvatarsRequiresAdmin(): void
    {
        $this->jsonRequest('POST', '/api/admin/vrchat/refresh-avatars', []);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }
}

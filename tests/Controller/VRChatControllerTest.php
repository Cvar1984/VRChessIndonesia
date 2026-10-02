<?php

declare(strict_types=1);

namespace VRchessIndo\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
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
        self::assertSame('Alice VR', $body['username']);

        // Linking makes the VRChat display name the player's username, keeping the old one on record.
        $this->client->request('GET', '/api/players');
        $players = $this->jsonBody()['players'];
        $alice = current(array_filter($players, static fn ($p) => $p['username'] === 'Alice VR'));
        self::assertNotFalse($alice, 'Player was renamed to the VRChat display name');
        self::assertSame(['Alice'], $alice['previous_usernames']);
        self::assertSame('usr_abc', $alice['vrchat_user_id']);
        self::assertSame('Alice VR', $alice['vrchat_display_name']);
        self::assertSame('https://example.com/icon.png', $alice['avatar_url']);
    }

    public function testLinkKeepsNameWhenVrchatNameBelongsToAnotherPlayer(): void
    {
        $this->dm->persist(new Player(1, 'Alice'));
        $this->dm->persist(new Player(2, 'Alice VR')); // an unlinked duplicate of the same person
        $this->dm->flush();
        $this->dm->clear();

        $this->mockHttpClient([
            $this->loginResponse(),
            new MockResponse(json_encode(['id' => 'usr_abc', 'displayName' => 'Alice VR', 'userIcon' => 'https://example.com/icon.png']), ['http_code' => 200]),
        ]);

        $this->loginAsAdmin();
        $this->jsonRequest('POST', '/api/admin/vrchat/link', ['username' => 'Alice', 'vrchat_user_id' => 'usr_abc']);

        self::assertResponseIsSuccessful();
        $body = $this->jsonBody();
        self::assertSame('Alice', $body['username']);
        self::assertStringContainsString('sudah dipakai pemain lain', $body['message']);

        $this->client->request('GET', '/api/players');
        $names = array_column($this->jsonBody()['players'], 'username');
        sort($names);
        self::assertSame(['Alice', 'Alice VR'], $names);
    }

    public function testRenamedPlayerIsStillFoundByOldName(): void
    {
        $this->loginAsAdmin();
        $this->jsonRequest('POST', '/api/matches', ['white' => 'Alice', 'black' => 'Bob', 'result' => '1']);

        $this->mockHttpClient([
            $this->loginResponse(),
            new MockResponse(json_encode(['id' => 'usr_abc', 'displayName' => 'Alice VR', 'userIcon' => 'https://example.com/icon.png']), ['http_code' => 200]),
        ]);
        $this->jsonRequest('POST', '/api/admin/vrchat/link', ['username' => 'Alice', 'vrchat_user_id' => 'usr_abc']);
        self::assertSame('Alice VR', $this->jsonBody()['username']);

        // A bot still submitting under the old name must not create a second Alice.
        $this->jsonRequest('POST', '/api/matches', ['white' => 'Alice', 'black' => 'Bob', 'result' => '0']);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/players');
        $players = $this->jsonBody()['players'];
        self::assertCount(2, $players);
        $alice = current(array_filter($players, static fn ($p) => $p['username'] === 'Alice VR'));
        self::assertSame(2, $alice['games']);

        $this->client->request('GET', '/api/players/Alice/stats');
        self::assertResponseIsSuccessful();
        self::assertSame('Alice VR', $this->jsonBody()['stats']['player']['username']);
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

    public function testDailyRefreshCommandUpdatesOnlyStaleAvatars(): void
    {
        $stale = new Player(1, 'Alice');
        $stale->setVrchatLink('usr_abc', 'Alice VR', 'https://example.com/old.png');
        $fresh = new Player(2, 'Bob');
        $fresh->setVrchatLink('usr_bob', 'Bob VR', 'https://example.com/bob.png');
        $this->dm->persist($stale);
        $this->dm->persist($fresh);
        $this->dm->flush();
        $this->dm->getDocumentCollection(Player::class)
            ->updateOne(['id' => 1], ['$set' => ['avatar_cached_at' => '2000-01-01 00:00:00']]);
        $this->dm->clear();

        // Login + exactly one getUser() — a fetch for fresh Bob would exhaust the queue and fail.
        $this->mockHttpClient([
            $this->loginResponse(),
            new MockResponse(json_encode(['id' => 'usr_abc', 'displayName' => 'Alice VR', 'iconUrl' => 'https://example.com/new.png']), ['http_code' => 200]),
        ]);

        $tester = new CommandTester((new Application(self::$kernel))->find('app:vrchat:refresh-avatars'));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('1 refreshed, 1 skipped', $tester->getDisplay());
        self::assertStringContainsString('1 renamed', $tester->getDisplay());

        // The refreshed player now carries their VRChat name; the skipped one is left alone.
        $this->client->request('GET', '/api/players');
        $players = $this->jsonBody()['players'];
        $alice = current(array_filter($players, static fn ($p) => $p['username'] === 'Alice VR'));
        self::assertSame('https://example.com/new.png', $alice['avatar_url']);
        self::assertSame(['Alice'], $alice['previous_usernames']);
        self::assertContains('Bob', array_column($players, 'username'));
    }

    public function testRefreshRecordsVrchatNameChange(): void
    {
        $player = new Player(1, 'Alice VR');
        $player->setVrchatLink('usr_abc', 'Alice VR', 'https://example.com/icon.png');
        $this->dm->persist($player);
        $this->dm->flush();
        $this->dm->clear();

        // The person changed their display name on VRChat.
        $this->mockHttpClient([
            $this->loginResponse(),
            new MockResponse(json_encode(['id' => 'usr_abc', 'displayName' => 'Alicia', 'userIcon' => 'https://example.com/icon.png']), ['http_code' => 200]),
        ]);

        $this->loginAsAdmin();
        $this->jsonRequest('POST', '/api/admin/vrchat/refresh-avatars', ['force' => true]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->jsonBody()['renamed']);

        $this->client->request('GET', '/api/players');
        $alicia = $this->jsonBody()['players'][0];
        self::assertSame('Alicia', $alicia['username']);
        self::assertSame('Alicia', $alicia['vrchat_display_name']);
        self::assertSame(['Alice VR'], $alicia['previous_usernames']);

        $history = $this->dm->getDocumentCollection(Player::class)->findOne(['id' => 1])['username_history'];
        self::assertSame('Alice VR', $history[0]['username']);
        self::assertSame('vrchat', $history[0]['source']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $history[0]['changed_at']);
    }
}

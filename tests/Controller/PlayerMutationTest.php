<?php

declare(strict_types=1);

namespace VRchessIndo\Tests\Controller;

use Symfony\Component\HttpClient\Response\MockResponse;
use VRchessIndo\Document\Player;
use VRchessIndo\Tests\ApiTestCase;

class PlayerMutationTest extends ApiTestCase
{
    public function testEditPlayerRatingAndUsername(): void
    {
        $this->loginAsAdmin();

        $this->jsonRequest('POST', '/api/matches', ['white' => 'Alice', 'black' => 'Bob', 'result' => '1']);
        self::assertResponseIsSuccessful();

        $this->jsonRequest('PATCH', '/api/players/Alice', ['rating' => 999, 'username' => 'AliceRenamed']);
        self::assertResponseIsSuccessful();
        $body = $this->jsonBody();
        self::assertTrue($body['success']);
        self::assertStringContainsString('Alice', $body['message']);

        $this->client->request('GET', '/api/players');
        $players = $this->jsonBody()['players'];
        $renamed = current(array_filter($players, static fn ($p) => $p['username'] === 'AliceRenamed'));
        self::assertNotFalse($renamed, 'Player was renamed');
        self::assertSame(999, $renamed['rating']);
        self::assertSame(['Alice'], $renamed['previous_usernames']);
    }

    public function testAdminRenameOfLinkedPlayerLastsUntilNextVrchatSync(): void
    {
        $player = new Player(1, 'Alice VR');
        $player->setVrchatLink('usr_abc', 'Alice VR', null);
        $this->dm->persist($player);
        $this->dm->flush();
        $this->dm->clear();

        // Admin edits are for debugging, so the rename goes through and is recorded.
        $this->loginAsAdmin();
        $this->jsonRequest('PATCH', '/api/players/Alice VR', ['username' => 'Debug Name', 'rating' => 500]);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/players');
        $alice = $this->jsonBody()['players'][0];
        self::assertSame('Debug Name', $alice['username']);
        self::assertSame(500, $alice['rating']);
        self::assertSame(['Alice VR'], $alice['previous_usernames']);

        // The next VRChat sync puts the real name back.
        $this->mockHttpClient([
            new MockResponse(json_encode(['id' => 'usr_me']), ['http_code' => 200, 'response_headers' => ['Set-Cookie: auth=cookie; Path=/']]),
            new MockResponse(json_encode(['id' => 'usr_abc', 'displayName' => 'Alice VR', 'userIcon' => null]), ['http_code' => 200]),
        ]);
        $this->jsonRequest('POST', '/api/admin/vrchat/refresh-avatars', ['force' => true]);
        self::assertSame(1, $this->jsonBody()['renamed']);

        $this->client->request('GET', '/api/players');
        $alice = $this->jsonBody()['players'][0];
        self::assertSame('Alice VR', $alice['username']);
        self::assertSame(['Alice VR', 'Debug Name'], $alice['previous_usernames']);

        $history = $this->dm->getDocumentCollection(Player::class)->findOne(['id' => 1])['username_history'];
        self::assertSame(['admin', 'vrchat'], array_column(iterator_to_array($history), 'source'));
    }

    public function testRenamingToTakenUsernameIsRefused(): void
    {
        $this->loginAsAdmin();
        $this->jsonRequest('POST', '/api/matches', ['white' => 'Alice', 'black' => 'Bob', 'result' => '1']);

        $this->jsonRequest('PATCH', '/api/players/Alice', ['username' => 'Bob']);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('sudah dipakai', $this->jsonBody()['error']);
    }

    public function testEditNonexistentPlayerReturns404(): void
    {
        $this->loginAsAdmin();

        $this->jsonRequest('PATCH', '/api/players/NoSuchPlayer', ['rating' => 500]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->jsonBody()['success']);
    }

    public function testEditWithNoRecognizedFieldsIsRejected(): void
    {
        $this->loginAsAdmin();
        $this->jsonRequest('POST', '/api/matches', ['white' => 'Alice', 'black' => 'Bob', 'result' => '1']);

        $this->jsonRequest('PATCH', '/api/players/Alice', ['nonsense' => 'x']);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testDeletePlayerLeavesMatchHistoryIntact(): void
    {
        $this->loginAsAdmin();
        $this->jsonRequest('POST', '/api/matches', ['white' => 'Alice', 'black' => 'Bob', 'result' => '1']);

        $this->client->request('DELETE', '/api/players/Alice');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->jsonBody()['success']);

        $this->client->request('GET', '/api/players');
        $players = $this->jsonBody()['players'];
        self::assertCount(1, $players, 'Only Bob remains');

        $this->client->request('GET', '/api/matches');
        self::assertSame(1, $this->jsonBody()['count'], 'The match itself is untouched');
    }

    public function testDeleteNonexistentPlayerReturns404(): void
    {
        $this->loginAsAdmin();

        $this->client->request('DELETE', '/api/players/NoSuchPlayer');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testMutationsRequireApiAccess(): void
    {
        $this->jsonRequest('PATCH', '/api/players/Alice', ['rating' => 500]);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        $this->client->request('DELETE', '/api/players/Alice');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }
}

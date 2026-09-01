<?php

declare(strict_types=1);

namespace VRchessIndo\Tests\Controller;

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

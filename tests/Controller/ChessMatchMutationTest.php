<?php

declare(strict_types=1);

namespace VRchessIndo\Tests\Controller;

use VRchessIndo\Tests\ApiTestCase;

class ChessMatchMutationTest extends ApiTestCase
{
    public function testPlayInvalidateRevalidateEditDeleteFullLifecycle(): void
    {
        $this->loginAsAdmin();

        // Play
        $this->jsonRequest('POST', '/api/matches', [
            'white' => 'Alice',
            'black' => 'Bob',
            'result' => '1',
            'url' => 'https://example.com/game/1',
        ]);
        self::assertResponseIsSuccessful();
        $played = $this->jsonBody();
        self::assertTrue($played['success']);
        self::assertSame('Alice', $played['match']['white']['username']);
        self::assertSame(1, $played['match']['match_id']);
        $aliceRatingAfterWin = $played['match']['white']['new_rating'];
        self::assertGreaterThan(400, $aliceRatingAfterWin);

        // Reject self-play
        $this->jsonRequest('POST', '/api/matches', ['white' => 'Alice', 'black' => 'Alice', 'result' => '1']);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        // Reject invalid result
        $this->jsonRequest('POST', '/api/matches', ['white' => 'Alice', 'black' => 'Charlie', 'result' => 'nope']);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        // Invalidate
        $this->client->request('PUT', '/api/matches/1/invalidate');
        self::assertResponseIsSuccessful();
        $invalidated = $this->jsonBody();
        self::assertTrue($invalidated['success']);
        self::assertFalse($invalidated['data']['is_valid']);

        $this->client->request('GET', '/api/players');
        $players = $this->jsonBody()['players'];
        $alice = current(array_filter($players, static fn ($p) => $p['username'] === 'Alice'));
        self::assertSame(400, $alice['rating'], 'Rating resets once the only match is invalidated');

        // Invalidating again should fail
        $this->client->request('PUT', '/api/matches/1/invalidate');
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        // Revalidate
        $this->client->request('PUT', '/api/matches/1/revalidate');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->jsonBody()['data']['is_valid']);

        $this->client->request('GET', '/api/players');
        $players = $this->jsonBody()['players'];
        $alice = current(array_filter($players, static fn ($p) => $p['username'] === 'Alice'));
        self::assertSame($aliceRatingAfterWin, $alice['rating'], 'Rating restored after revalidation');

        // Edit
        $this->jsonRequest('PATCH', '/api/matches/1', ['result' => '1/2-1/2']);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->jsonBody()['success']);

        $this->client->request('GET', '/api/matches');
        $match = current(array_filter($this->jsonBody()['matches'], static fn ($m) => $m['id'] === 1));
        self::assertSame('1/2-1/2', $match['result']);

        // Edit with no recognized fields is rejected
        $this->jsonRequest('PATCH', '/api/matches/1', ['nonsense' => 'x']);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        // Delete
        $this->client->request('DELETE', '/api/matches/1');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->jsonBody()['success']);

        $this->client->request('GET', '/api/matches');
        self::assertSame(0, $this->jsonBody()['count']);

        // Deleting again returns 404
        $this->client->request('DELETE', '/api/matches/1');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testPlayWithPgnSavesAnalysisAndUsesItsIdAsAnalysisUrl(): void
    {
        $this->loginAsAdmin();

        $this->jsonRequest('POST', '/api/matches', [
            'white' => 'Alice',
            'black' => 'Bob',
            'result' => '0',
            'pgn' => '1. e4 e5 2. Nf3 Nc6 *',
        ]);
        self::assertResponseIsSuccessful();
        $body = $this->jsonBody();
        $analysisId = $body['match']['analysis_url'];
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $analysisId);

        $this->client->request('GET', '/api/matches');
        $match = $this->jsonBody()['matches'][0];
        self::assertSame($analysisId, $match['analysis_url']);
    }

    public function testMutationsRequireApiAccess(): void
    {
        $this->jsonRequest('POST', '/api/matches', ['white' => 'A', 'black' => 'B', 'result' => '1']);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        $this->client->request('PUT', '/api/matches/1/invalidate');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        $this->client->request('DELETE', '/api/matches/1');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }
}

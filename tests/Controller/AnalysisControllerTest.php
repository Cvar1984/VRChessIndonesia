<?php

declare(strict_types=1);

namespace VRchessIndo\Tests\Controller;

use VRchessIndo\Tests\ApiTestCase;

class AnalysisControllerTest extends ApiTestCase
{
    private const string SAMPLE_PGN = '[Event "Test"]
[White "Alice"]
[Black "Bob"]

1. e4 e5 2. Nf3 Nc6 3. Bb5 *';

    public function testSaveGetUpdateFullLifecycle(): void
    {
        // Save (public, no auth needed)
        $this->jsonRequest('POST', '/api/analyses', ['pgn' => self::SAMPLE_PGN]);
        self::assertResponseIsSuccessful();
        $body = $this->jsonBody();
        self::assertTrue($body['success']);
        $id = $body['id'];
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $id);

        // Get (public)
        $this->client->request('GET', "/api/analyses/{$id}");
        self::assertResponseIsSuccessful();
        $data = $this->jsonBody()['data'];
        self::assertSame($id, $data['id']);
        self::assertSame(self::SAMPLE_PGN, $data['pgn']);
        self::assertArrayNotHasKey('analysis', $data, 'No analysis array was saved yet');

        // Update (public) — attach computed analysis positions
        $this->jsonRequest('PATCH', "/api/analyses/{$id}", ['analysis' => [['fen' => 'x', 'eval' => 12]]]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->jsonBody()['success']);

        $this->client->request('GET', "/api/analyses/{$id}");
        $data = $this->jsonBody()['data'];
        self::assertSame([['fen' => 'x', 'eval' => 12]], $data['analysis']);
    }

    public function testSaveRejectsEmptyPgn(): void
    {
        $this->jsonRequest('POST', '/api/analyses', ['pgn' => '']);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testGetNonexistentReturns404(): void
    {
        $this->client->request('GET', '/api/analyses/doesnotexist12345');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testUpdateNonexistentReturnsSuccessFalseWith200(): void
    {
        // Legacy quirk, preserved deliberately: updateAnalysis() on a
        // missing id returns {success:false} with a 200, not a 404.
        $this->jsonRequest('PATCH', '/api/analyses/doesnotexist12345', ['analysis' => [['x' => 1]]]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->jsonBody()['success']);
    }

    public function testUpdateRejectsNonArrayAnalysis(): void
    {
        $this->jsonRequest('POST', '/api/analyses', ['pgn' => self::SAMPLE_PGN]);
        $id = $this->jsonBody()['id'];

        $this->jsonRequest('PATCH', "/api/analyses/{$id}", ['analysis' => 'not-an-array']);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testListReturnsPreviewShapeNotFullPgn(): void
    {
        $this->jsonRequest('POST', '/api/analyses', ['pgn' => self::SAMPLE_PGN]);

        $this->client->request('GET', '/api/analyses');
        self::assertResponseIsSuccessful();
        $list = $this->jsonBody()['analyses'];
        self::assertCount(1, $list);
        self::assertArrayHasKey('pgn_preview', $list[0]);
        self::assertArrayNotHasKey('pgn', $list[0]);
        self::assertSame('Test', $list[0]['headers']['Event']);
        self::assertSame('Alice', $list[0]['headers']['White']);
        self::assertStringStartsWith('1. e4 e5', $list[0]['pgn_preview']);
    }

    public function testDeleteRequiresApiAccess(): void
    {
        $this->jsonRequest('POST', '/api/analyses', ['pgn' => self::SAMPLE_PGN]);
        $id = $this->jsonBody()['id'];

        $this->client->request('DELETE', "/api/analyses/{$id}");
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testDeleteAsAdminRemovesAnalysis(): void
    {
        $this->jsonRequest('POST', '/api/analyses', ['pgn' => self::SAMPLE_PGN]);
        $id = $this->jsonBody()['id'];

        $this->loginAsAdmin();
        $this->client->request('DELETE', "/api/analyses/{$id}");
        self::assertResponseIsSuccessful();
        self::assertTrue($this->jsonBody()['success']);

        $this->client->request('GET', "/api/analyses/{$id}");
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testDeleteNonexistentReturns404(): void
    {
        $this->loginAsAdmin();
        $this->client->request('DELETE', '/api/analyses/doesnotexist12345');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }
}

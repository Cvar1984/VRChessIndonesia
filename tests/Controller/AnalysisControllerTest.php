<?php

declare(strict_types=1);

namespace VRchessIndo\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    public static function realWorldPgnFixtures(): array
    {
        return [
            'standard multi-line PGN, unicode player name' => [
                'example.pgn',
                ['White' => 'Dr Slebew', 'Black' => 'James-Music 杰姆斯', 'Result' => '1-0', 'GameID' => '20260726143042'],
            ],
            'FIDE tournament PGN with %clk/%emt move annotations' => [
                'example_fide.pgn',
                ['White' => 'Cheparinov Ivan (BUL)', 'Black' => 'Alhassadi Yousef A. (LBA)', 'WhiteElo' => '2663', 'BlackFideId' => '9204725'],
            ],
            'Chess960 PGN with X-FEN (file-letter) castling rights' => [
                '960_rapid_CH_fischer.pgn',
                [
                    'Variant' => 'Fischerandom',
                    'White' => 'Ganguly, Surya Shekhar',
                    'FEN' => 'qnbnrkrb/pppppppp/8/8/8/8/PPPPPPPP/QNBNRKRB w GEge - 0 1',
                ],
            ],
            "VRChess's own compact single-line format (all headers on one line)" => [
                'vrchess.pgn',
                ['White' => 'Dr Slebew', 'Black' => 'nekofold', 'Result' => '0-1', 'GameID' => '20260809161251'],
            ],
        ];
    }

    /**
     * Real PGN samples (data/pgn/) spanning the format variety this app
     * actually receives: standard multi-line, a FIDE export with %clk/%emt
     * comment annotations, a Chess960 game whose [FEN] castling rights use
     * X-FEN file letters instead of KQkq, and VRChess's own compact
     * single-line PGN. Confirms Analysis::toPreviewArray()'s header regex —
     * the only PHP-side PGN parsing in this app (move-by-move parsing is
     * entirely client-side, in assets/app.js) — handles all of them.
     *
     * @param array<string, string> $expectedHeaders
     */
    #[DataProvider('realWorldPgnFixtures')]
    public function testHeaderExtractionAcrossRealWorldPgnFormats(string $fixtureFile, array $expectedHeaders): void
    {
        $path = dirname(__DIR__, 2) . '/data/pgn/' . $fixtureFile;
        $pgn = file_get_contents($path);
        self::assertIsString($pgn, "Fixture {$fixtureFile} must exist and be readable at {$path}");

        $this->jsonRequest('POST', '/api/analyses', ['pgn' => $pgn]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->jsonBody()['success']);

        $this->client->request('GET', '/api/analyses');
        $list = $this->jsonBody()['analyses'];
        self::assertCount(1, $list);

        foreach ($expectedHeaders as $key => $value) {
            self::assertSame(
                $value,
                $list[0]['headers'][$key] ?? null,
                "Header [{$key}] extracted from {$fixtureFile}",
            );
        }
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

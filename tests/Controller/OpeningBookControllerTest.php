<?php

declare(strict_types=1);

namespace VRchessIndo\Tests\Controller;

use Symfony\Component\HttpClient\Response\MockResponse;
use VRchessIndo\Tests\ApiTestCase;

/**
 * GET /api/opening-book — the Lichess masters lookup behind Game Review's Book
 * label. Lichess itself is answered from the shared MockHttpClient queue.
 */
class OpeningBookControllerTest extends ApiTestCase
{
    private const string FEN = 'rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq - 0 1';

    protected function setUp(): void
    {
        parent::setUp();
        // cache.app is on disk under test too; start each test without cached positions.
        self::getContainer()->get('cache.app')->clear();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['LICHESS_API_TOKEN'], $_ENV['LICHESS_API_TOKEN']);
        parent::tearDown();
    }

    private function withToken(): void
    {
        $_SERVER['LICHESS_API_TOKEN'] = $_ENV['LICHESS_API_TOKEN'] = 'lip_test_token';
    }

    private function book(string $fen = self::FEN): array
    {
        $this->client->request('GET', '/api/opening-book?fen=' . rawurlencode($fen));

        return $this->jsonBody();
    }

    public function testWithoutATokenTheBookIsUnavailable(): void
    {
        // Explicitly blank: a developer's .env may well carry a real token.
        $_SERVER['LICHESS_API_TOKEN'] = $_ENV['LICHESS_API_TOKEN'] = '';
        self::assertSame(['success' => true, 'available' => false, 'moves' => []], $this->book());
    }

    public function testReturnsMastersMovesWithGameCountsAndCachesThem(): void
    {
        $this->withToken();
        $sent = [];
        $this->mockHttpClient(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent[] = ['url' => $url, 'auth' => implode(' ', $options['normalized_headers']['authorization'] ?? [])];

            return new MockResponse(json_encode(['moves' => [
                ['uci' => 'c7c5', 'san' => 'c5', 'white' => 100, 'draws' => 50, 'black' => 80],
                ['uci' => 'e7e5', 'san' => 'e5', 'white' => 90, 'draws' => 60, 'black' => 40],
            ]]), ['http_code' => 200]);
        });

        $body = $this->book();
        self::assertTrue($body['available']);
        self::assertSame([['uci' => 'c7c5', 'san' => 'c5', 'games' => 230], ['uci' => 'e7e5', 'san' => 'e5', 'games' => 190]], $body['moves']);
        self::assertStringStartsWith('https://explorer.lichess.ovh/masters?', $sent[0]['url']);
        self::assertSame('Authorization: Bearer lip_test_token', $sent[0]['auth']);

        $this->book(); // same position again: served from cache, Lichess isn't asked twice
        self::assertCount(1, $sent);
    }

    public function testALichessFailureMeansNoBookRatherThanAnError(): void
    {
        $this->withToken();
        $this->mockHttpClient([new MockResponse('down', ['http_code' => 503])]);

        self::assertSame(['success' => true, 'available' => false, 'moves' => []], $this->book());
    }

    public function testRejectsSomethingThatIsNotAFen(): void
    {
        $this->withToken();
        $this->client->request('GET', '/api/opening-book?fen=' . rawurlencode("x\nquit"));
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }
}

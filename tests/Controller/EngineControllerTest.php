<?php

declare(strict_types=1);

namespace VRchessIndo\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * HTTP-level tests for EngineController, against the real local Stockfish
 * binary (no external network involved — safe to test directly, unlike
 * VRChatClient). Kept to shallow depths to keep the suite fast. Doesn't
 * extend ApiTestCase — the engine endpoints are unauthenticated and don't
 * touch the database, so none of that shared setup applies.
 */
class EngineControllerTest extends WebTestCase
{
    private const string STARTPOS = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';

    public function testAnalyzeSingleFen(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/engine/analyze', ['fen' => self::STARTPOS, 'depth' => 6]);

        self::assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertNotNull($body['bestmove']);
        self::assertSame(6, $body['depth']);
    }

    public function testAnalyzeMissingFenReturns400(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/engine/analyze', []);

        self::assertSame(400, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertSame("Missing 'fen' or 'fens'", $body['error']);
    }

    public function testAnalyzeInvalidFenReturns500(): void
    {
        // Legacy quirk, preserved deliberately: sanitizeFen()'s exceptions
        // are caught by the same outer catch-all as engine errors, so a bad
        // FEN is a 500 here, not a 400 — see EngineController's docblock.
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/engine/analyze', ['fen' => 'not a fen; DROP TABLE']);

        self::assertSame(500, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertStringContainsString('Invalid', $body['error']);
    }

    public function testAnalyzeDepthIsCappedAt26(): void
    {
        $client = self::createClient();
        // Depth above the single-shot cap: legacy's bounds check rejects it
        // as "not numeric-and-in-range", falling back to the 18 default.
        $client->jsonRequest('POST', '/api/engine/analyze', ['fen' => self::STARTPOS, 'depth' => 200]);

        self::assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(18, $body['depth']);
    }

    public function testAnalyzeMovetimeMode(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/engine/analyze', ['fen' => self::STARTPOS, 'movetime' => 150]);

        self::assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertNotNull($body['bestmove']);
    }

    public function testAnalyzeBatch(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/engine/analyze/batch', [
            'fens' => [
                self::STARTPOS,
                'rnbqkbnr/pppp1ppp/8/4p3/4P3/8/PPPP1PPP/RNBQKBNR w KQkq - 0 2',
            ],
            'depth' => 6,
        ]);

        self::assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertTrue($body['success']);
        self::assertSame(2, $body['count']);
        self::assertSame(0, $body['positions'][0]['move_index']);
        self::assertSame(1, $body['positions'][1]['move_index']);
        self::assertNotNull($body['positions'][0]['bestmove']);
    }

    public function testAnalyzeBatchRejectsNonArrayFens(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/engine/analyze/batch', ['fens' => 'not-an-array']);

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testAnalyzeBatchScoreSignFlipsForBlackToMove(): void
    {
        $client = self::createClient();
        // A position where Black has just blundered a queen — White (to
        // move next in this FEN's "w") should show a large positive score.
        $client->jsonRequest('POST', '/api/engine/analyze/batch', [
            'fens' => ['rnb1kbnr/pppp1ppp/8/4p3/4P2q/8/PPPP1PPP/RNBQKBNR w KQkq - 2 3'],
            'depth' => 8,
        ]);

        self::assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('cp', $body['positions'][0]['score_type']);
    }

    public function testAnalyzeStreamReturnsSseWithDoneEvent(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/engine/analyze/stream', ['fen' => self::STARTPOS, 'depth' => 6]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/event-stream', $client->getResponse()->headers->get('Content-Type'));

        // StreamedResponse::getContent() always returns false — the test
        // client already ran the streaming callback once internally (via
        // sendContent(), output-buffer-captured) and stashed the result on
        // the browser-kit-level response, not the raw HttpFoundation one.
        $content = $client->getInternalResponse()->getContent();

        self::assertStringContainsString('"type":"done"', $content);
        self::assertStringContainsString('data: ', $content);
    }

    public function testAnalyzeStreamMissingFenReturnsJsonNotSse(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/engine/analyze/stream', []);

        self::assertSame(400, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('application/json', $client->getResponse()->headers->get('Content-Type'));
    }

    public function testCorsHeadersPresentOnJsonResponse(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/engine/analyze', ['fen' => self::STARTPOS, 'depth' => 6]);

        self::assertSame('*', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    public function testOptionsPreflightReturns204WithCorsHeaders(): void
    {
        $client = self::createClient();
        $client->request('OPTIONS', '/api/engine/analyze');

        self::assertSame(204, $client->getResponse()->getStatusCode());
        self::assertSame('*', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));
        self::assertNotNull($client->getResponse()->headers->get('Access-Control-Allow-Methods'));
    }
}

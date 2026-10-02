<?php

declare(strict_types=1);

namespace VRchessIndo\Tests\Controller;

use VRchessIndo\Document\Admin;
use VRchessIndo\Document\ApiToken;
use VRchessIndo\Document\Player;
use VRchessIndo\Repository\PlayerRepository;
use VRchessIndo\Tests\ApiTestCase;

/**
 * Regression tests for the logic flaws found in the 2026-10-02 system review —
 * each one failed (the flaw was reproducible) before its fix.
 */
class IntegrityFixesTest extends ApiTestCase
{
    private const string TOKEN = 'vrchess_pat_integrity';

    protected function setUp(): void
    {
        parent::setUp();
        // The engine limiter's pool is on disk even under test, so start every
        // test with empty buckets rather than whatever an earlier run left.
        self::getContainer()->get('cache.rate_limiter.engine_requests')->clear();
    }

    private function createToken(): void
    {
        $this->dm->persist(new ApiToken('tok_integrity', 'integrity', self::TOKEN));
        $this->dm->flush();
    }

    public function testRecordingAMatchRejectsAnAnalysisUrlThatIsNotAnIdOrHttpUrl(): void
    {
        $this->loginAsAdmin();
        $this->jsonRequest('POST', '/api/matches', ['white' => 'A', 'black' => 'B', 'result' => '1', 'url' => "x');alert(document.cookie);//"]);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        $this->jsonRequest('POST', '/api/matches', ['white' => 'A', 'black' => 'B', 'result' => '1', 'url' => 'javascript:alert(1)']);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/matches');
        self::assertSame([], $this->jsonBody()['matches']);

        $this->jsonRequest('POST', '/api/matches', ['white' => 'A', 'black' => 'B', 'result' => '1', 'url' => 'https://lichess.org/abcd1234']);
        self::assertResponseIsSuccessful();
    }

    public function testRevokingATokenEndsTheSessionItStarted(): void
    {
        $this->createToken();
        $this->jsonRequest('POST', '/api/matches', ['white' => 'A', 'black' => 'B', 'result' => '1'], ['HTTP_X_API_TOKEN' => self::TOKEN]);
        self::assertResponseIsSuccessful();

        $this->dm->getDocumentCollection(ApiToken::class)->deleteMany([]);
        $this->dm->clear();

        // Same client and cookie jar, no token header any more.
        $this->jsonRequest('POST', '/api/matches', ['white' => 'C', 'black' => 'D', 'result' => '1']);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testAnonymousPatchCanBackfillAnAnalysisButNotOverwriteIt(): void
    {
        $this->jsonRequest('POST', '/api/analyses', ['pgn' => '1.e4 *']);
        $id = $this->jsonBody()['id'];
        $positions = [['fen' => 'rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq - 0 1', 'score_cp' => 30]];

        $anon = $this->freshClient();
        $patch = static fn (array $analysis, array $server = []) => $anon->request('PATCH', "/api/analyses/{$id}", [], [], ['CONTENT_TYPE' => 'application/json', ...$server], json_encode(['analysis' => $analysis]));

        $patch($positions); // first visitor's Game Review fills in the missing evaluations
        self::assertTrue(json_decode($anon->getResponse()->getContent(), true)['success']);

        $patch([['fen' => 'x', 'score_cp' => -9999]]); // replacing them needs a token
        self::assertSame(401, $anon->getResponse()->getStatusCode());

        $this->createToken();
        $patch([['fen' => 'y', 'score_cp' => 10]], ['HTTP_X_API_TOKEN' => self::TOKEN]);
        self::assertTrue(json_decode($anon->getResponse()->getContent(), true)['success']);
    }

    public function testAnalysisPayloadsAreValidatedAndCapped(): void
    {
        $this->jsonRequest('POST', '/api/analyses', ['pgn' => '1.e4 *', 'analysis' => ['not' => 'a list']]);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        $this->jsonRequest('POST', '/api/analyses', ['pgn' => '1.e4 *', 'analysis' => array_fill(0, 2001, ['fen' => 'x'])]);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        $this->jsonRequest('POST', '/api/analyses', ['pgn' => str_repeat('x', 200_001)]);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testTheStoredHashIsNotAcceptedAsThePassword(): void
    {
        $hash = $this->dm->getDocumentCollection(Admin::class)->findOne(['username' => self::ADMIN_USERNAME])['password'];

        $anon = $this->freshClient();
        $anon->request('GET', '/api/auth/status', [], [], ['HTTP_X_ADMIN_USERNAME' => self::ADMIN_USERNAME, 'HTTP_X_ADMIN_PASSWORD' => $hash]);
        self::assertFalse(json_decode($anon->getResponse()->getContent(), true)['authenticated']);
    }

    public function testAReplayRewritesTheRatingsShownInMatchHistory(): void
    {
        $this->loginAsAdmin();
        $this->jsonRequest('POST', '/api/matches', ['white' => 'Alice', 'black' => 'Bob', 'result' => '1']); // #1
        $this->jsonRequest('POST', '/api/matches', ['white' => 'Alice', 'black' => 'Bob', 'result' => '1']); // #2

        $this->jsonRequest('PUT', '/api/matches/1/invalidate');
        self::assertResponseIsSuccessful();

        // With #1 annulled, #2 is replayed as a game between two 400-rated players.
        $this->client->request('GET', '/api/matches');
        $second = $this->jsonBody()['matches'][1];
        self::assertSame([400, 400, 30, -5], [$second['old_white_rating'], $second['old_black_rating'], $second['rating_change_white'], $second['rating_change_black']]);
    }

    public function testADeletedPlayersIdIsNeverHandedToANewPlayer(): void
    {
        $this->loginAsAdmin();
        $this->jsonRequest('POST', '/api/matches', ['white' => 'Alice', 'black' => 'Bob', 'result' => '1']);   // Alice 1, Bob 2
        $this->jsonRequest('POST', '/api/matches', ['white' => 'Carol', 'black' => 'Alice', 'result' => '1']); // Carol 3
        $this->jsonRequest('DELETE', '/api/players/Carol');
        $this->jsonRequest('POST', '/api/matches', ['white' => 'Dave', 'black' => 'Bob', 'result' => '1']);

        $this->client->request('GET', '/api/players/Dave/stats');
        $stats = $this->jsonBody()['stats'];
        self::assertSame(4, $stats['player']['id']);
        self::assertSame(1, $stats['total_matches']);
    }

    public function testARejectedMatchSubmissionLeavesNoOrphanAnalysis(): void
    {
        $this->loginAsAdmin();
        $pgn = "[White \"A\"]\n\n1.e4 *";
        $this->jsonRequest('POST', '/api/matches', ['white' => 'A', 'black' => 'B', 'result' => 'nonsense', 'pgn' => $pgn]);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        $this->jsonRequest('POST', '/api/matches', ['white' => 'A', 'black' => 'A', 'result' => '1', 'pgn' => $pgn]);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/analyses');
        self::assertSame([], $this->jsonBody()['analyses']);

        // A successful one still saves the PGN and links it.
        $this->jsonRequest('POST', '/api/matches', ['white' => 'A', 'black' => 'B', 'result' => '1', 'pgn' => $pgn]);
        $analysisUrl = $this->jsonBody()['match']['analysis_url'];
        $this->client->request('GET', "/api/analyses/{$analysisUrl}");
        self::assertSame($pgn, $this->jsonBody()['data']['pgn']);
    }

    public function testAnOldNameStopsResolvingAfterTheGracePeriod(): void
    {
        $player = new Player(1, 'Old Name');
        $player->rename('New Name', 'vrchat');
        $this->dm->persist($player);
        $this->dm->flush();
        $players = self::getContainer()->get(PlayerRepository::class);

        self::assertSame('New Name', $players->findOneByUsername('Old Name')?->getUsername());

        $expired = date('Y-m-d H:i:s', strtotime('-' . (PlayerRepository::OLD_NAME_GRACE_DAYS + 1) . ' days'));
        $this->dm->getDocumentCollection(Player::class)->updateOne(['id' => 1], ['$set' => ['username_history.0.changed_at' => $expired]]);
        $this->dm->clear();

        self::assertNull($players->findOneByUsername('Old Name'));
    }

    /**
     * Fen-less engine requests until the first 429 (the limit is checked before
     * validation, so these never run Stockfish). Returns how many got through;
     * null if none was ever limited. The bucket refills 2/s while this runs, so
     * the cut-off lands a little after the burst of 60, not exactly on it.
     */
    private function requestsUntilLimited(callable $server): ?int
    {
        for ($i = 0; $i < 300; $i++) {
            $this->client->request('GET', '/api/engine/analyze', [], [], $server($i));
            if ($this->client->getResponse()->getStatusCode() === 429) {
                return $i;
            }
        }

        return null;
    }

    private function engineStatus(array $server): int
    {
        $this->client->request('GET', '/api/engine/analyze', [], [], $server);

        return $this->client->getResponse()->getStatusCode();
    }

    public function testAnonymousEngineRequestsAreRateLimitedPerClientIp(): void
    {
        $passed = $this->requestsUntilLimited(static fn (): array => ['REMOTE_ADDR' => '203.0.113.7']);
        self::assertNotNull($passed);
        self::assertGreaterThanOrEqual(60, $passed);

        self::assertSame(400, $this->engineStatus(['REMOTE_ADDR' => '203.0.113.8']), 'other visitors are unaffected');

        $this->createToken();
        self::assertSame(400, $this->engineStatus(['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_API_TOKEN' => self::TOKEN]), 'token holders skip the limit');
    }

    public function testVisitorIpComesFromXForwardedForOnlyBehindCloudflare(): void
    {
        $viaCloudflare = static fn (string $visitor): array => ['REMOTE_ADDR' => '104.16.0.1', 'HTTP_X_FORWARDED_FOR' => $visitor];

        self::assertNotNull($this->requestsUntilLimited(static fn (): array => $viaCloudflare('198.51.100.1')));
        self::assertSame(400, $this->engineStatus($viaCloudflare('198.51.100.2')), 'a second visitor on the same Cloudflare edge has its own budget');

        // From a non-Cloudflare address the header is ignored, so rotating it can't dodge the limit.
        self::assertNotNull($this->requestsUntilLimited(static fn (int $i): array => ['REMOTE_ADDR' => '192.0.2.50', 'HTTP_X_FORWARDED_FOR' => '198.51.100.' . ($i % 250)]));
    }
}

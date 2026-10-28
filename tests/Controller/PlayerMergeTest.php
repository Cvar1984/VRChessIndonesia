<?php

declare(strict_types=1);

namespace VRchessIndo\Tests\Controller;

use Symfony\Component\HttpClient\Response\MockResponse;
use VRchessIndo\Document\ApiToken;
use VRchessIndo\Document\Player;
use VRchessIndo\Tests\ApiTestCase;

/**
 * POST /api/admin/players/merge — the HTTP surface of the duplicate-player merge,
 * including the VRChat rename it exists to unblock.
 */
class PlayerMergeTest extends ApiTestCase
{
    /** Two player rows for the same human: 'Alice' is the unlinked duplicate. */
    private function seedDuplicate(): void
    {
        $this->loginAsAdmin();
        $this->jsonRequest('POST', '/api/matches', ['white' => 'Alice', 'black' => 'Carol', 'result' => '1']);
        self::assertResponseIsSuccessful();
        $this->jsonRequest('POST', '/api/matches', ['white' => 'Alice VR', 'black' => 'Carol', 'result' => '1']);
        self::assertResponseIsSuccessful();
    }

    /** Links a player to a VRChat account whose display name is $displayName. */
    private function linkVrchat(string $username, string $vrchatUserId, string $displayName): void
    {
        $this->mockHttpClient([
            new MockResponse(json_encode(['id' => 'usr_me']), [
                'http_code' => 200,
                'response_headers' => ['Set-Cookie: auth=cookie; Path=/'],
            ]),
            new MockResponse(json_encode([
                'id' => $vrchatUserId,
                'displayName' => $displayName,
                'userIcon' => 'https://example.com/icon.png',
            ]), ['http_code' => 200]),
        ]);
        $this->jsonRequest('POST', '/api/admin/vrchat/link', ['username' => $username, 'vrchat_user_id' => $vrchatUserId]);
        self::assertResponseIsSuccessful();
    }

    private function players(): array
    {
        $this->client->request('GET', '/api/players');
        self::assertResponseIsSuccessful();

        return $this->jsonBody()['players'];
    }

    private function findPlayer(string $username): ?array
    {
        foreach ($this->players() as $player) {
            if ($player['username'] === $username) {
                return $player;
            }
        }

        return null;
    }

    public function testMergeMovesMatchesAndTakesTheVrchatName(): void
    {
        $this->seedDuplicate();
        // 'Alice VR' is linked to a VRChat account now called 'Alice', which the
        // duplicate row holds — so the rename was skipped as a conflict on linking.
        $this->linkVrchat('Alice VR', 'usr_alice', 'Alice');
        self::assertNotNull($this->findPlayer('Alice VR'), 'guard: the rename was blocked by the duplicate');

        $this->jsonRequest('POST', '/api/admin/players/merge', ['from' => 'Alice', 'into' => 'Alice VR']);
        self::assertResponseIsSuccessful();

        $body = $this->jsonBody();
        self::assertTrue($body['success']);
        self::assertSame(1, $body['matches_moved']);
        self::assertSame('renamed', $body['name_status'], 'The merge unblocks the VRChat rename');
        self::assertSame('Alice', $body['into']);

        // One player left, carrying the VRChat name, both games, and the old name.
        $survivor = $this->findPlayer('Alice');
        self::assertNotNull($survivor);
        self::assertSame(2, $survivor['games']);
        self::assertSame('usr_alice', $survivor['vrchat_user_id']);
        self::assertContains('Alice VR', $survivor['previous_usernames']);
        self::assertCount(2, $this->players(), 'Only the survivor and Carol remain');
    }

    public function testMergeWithoutAnyVrchatLinkJustFoldsThePlayers(): void
    {
        $this->seedDuplicate();

        $this->jsonRequest('POST', '/api/admin/players/merge', ['from' => 'Alice', 'into' => 'Alice VR']);
        self::assertResponseIsSuccessful();

        $body = $this->jsonBody();
        self::assertSame('unchanged', $body['name_status']);
        self::assertFalse($body['vrchat_link_moved']);
        self::assertSame(['Alice'], $body['names_absorbed']);
        self::assertNull($this->findPlayer('Alice'));
        self::assertSame(2, $this->findPlayer('Alice VR')['games']);
    }

    public function testUnknownPlayerIsRejectedAsConflict(): void
    {
        $this->seedDuplicate();

        $this->jsonRequest('POST', '/api/admin/players/merge', ['from' => 'Nobody', 'into' => 'Alice VR']);
        self::assertResponseStatusCodeSame(409);
        self::assertFalse($this->jsonBody()['success']);
    }

    public function testMissingFieldsAreRejected(): void
    {
        $this->loginAsAdmin();

        $this->jsonRequest('POST', '/api/admin/players/merge', ['from' => 'Alice']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testAnonymousCallerIsRejected(): void
    {
        $this->seedDuplicate();

        $anonymous = $this->freshClient();
        $anonymous->request(
            'POST',
            '/api/admin/players/merge',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['from' => 'Alice', 'into' => 'Alice VR']),
        );
        self::assertSame(401, $anonymous->getResponse()->getStatusCode());

        // Nothing happened.
        self::assertNotNull($this->findPlayer('Alice'));
    }

    public function testApiTokenCannotMerge(): void
    {
        $this->seedDuplicate();

        $this->dm->persist(new ApiToken('tok_merge', 'merge-test', 'tok_merge_test'));
        $this->dm->flush();

        // A match-reporting token can create and delete players, but merging two
        // records and replaying every rating is admin-only.
        $bot = $this->freshClient();
        $bot->request(
            'POST',
            '/api/admin/players/merge',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_API_TOKEN' => 'tok_merge_test'],
            json_encode(['from' => 'Alice', 'into' => 'Alice VR']),
        );
        self::assertSame(401, $bot->getResponse()->getStatusCode());
        self::assertNotNull($this->findPlayer('Alice'));
    }

    public function testMergedPlayerIsGoneFromTheDatabase(): void
    {
        $this->seedDuplicate();
        $this->jsonRequest('POST', '/api/admin/players/merge', ['from' => 'Alice', 'into' => 'Alice VR']);
        self::assertResponseIsSuccessful();

        self::assertSame(
            0,
            $this->dm->getDocumentCollection(Player::class)->countDocuments(['username' => 'Alice']),
            'The duplicate document is deleted, not just renamed',
        );
    }
}

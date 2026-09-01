<?php

declare(strict_types=1);

namespace VRchessIndo\Tests\Service;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use VRchessIndo\Document\ChessMatch;
use VRchessIndo\Document\Player;
use VRchessIndo\Service\MatchManager;

/**
 * Ports the legacy test.php::RatingSystemTest scenarios onto PHPUnit,
 * scenario-for-scenario, so the coverage this session already validated by
 * hand carries over unchanged to the new ODM-backed MatchManager.
 *
 * Runs against MONGODB_DB from .env.test — a dedicated "vrchessindo_test"
 * database on the same Atlas cluster as production, never the live
 * "vrchessindo" database. setUp() wipes that database's players/matches
 * collections before every test (mirroring the legacy suite's own
 * resetDatabase()) and hard-asserts the database name first, since a wrong
 * value there would otherwise silently wipe production data.
 */
class MatchManagerTest extends KernelTestCase
{
    private MatchManager $manager;
    private DocumentManager $dm;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->dm = $container->get(DocumentManager::class);
        $this->manager = $container->get(MatchManager::class);

        $dbName = $this->dm->getConfiguration()->getDefaultDB();
        self::assertSame(
            'vrchessindo_test',
            $dbName,
            "Refusing to run: MONGODB_DB must be the dedicated test database, not '{$dbName}'. " .
            'This test wipes its database before every run.',
        );

        $this->dm->getDocumentCollection(Player::class)->deleteMany([]);
        $this->dm->getDocumentCollection(ChessMatch::class)->deleteMany([]);
        $this->dm->clear();
    }

    // ── Assertion helpers (mirroring test.php::RatingSystemTest) ──

    /**
     * @return Player[]
     */
    private function getPlayers(): array
    {
        return $this->manager->getPlayers();
    }

    private function getRating(string $username): ?int
    {
        foreach ($this->getPlayers() as $p) {
            if ($p->getUsername() === $username) {
                return $p->getRating();
            }
        }
        return null;
    }

    private function assertRating(string $username, int $expected): void
    {
        foreach ($this->getPlayers() as $p) {
            if ($p->getUsername() === $username) {
                self::assertSame($expected, $p->getRating(), "Rating for {$username}");
                return;
            }
        }
        self::fail("Player {$username} not found");
    }

    private function assertStats(string $username, int $games, int $wins, int $draws, int $losses): void
    {
        foreach ($this->getPlayers() as $p) {
            if ($p->getUsername() === $username) {
                self::assertSame($games, $p->getGames(), "Games for {$username}");
                self::assertSame($wins, $p->getWins(), "Wins for {$username}");
                self::assertSame($draws, $p->getDraws(), "Draws for {$username}");
                self::assertSame($losses, $p->getLosses(), "Losses for {$username}");
                return;
            }
        }
        self::fail("Player {$username} not found");
    }

    /**
     * @param array<int, array{white: string, black: string, result: string, url?: string}> $matches
     * @return array<int, array>
     */
    private function playMatches(array $matches): array
    {
        $results = [];
        foreach ($matches as $match) {
            $results[] = $this->manager->play(
                $match['white'],
                $match['black'],
                $match['result'],
                $match['url'] ?? 'https://test.com',
            );
        }
        return $results;
    }

    // ============================================================
    // TEST 1: Basic Flow
    // ============================================================
    public function testBasicFlow(): void
    {
        $this->playMatches([
            ['white' => 'Alice', 'black' => 'Bob', 'result' => MatchManager::WHITE_WIN],
            ['white' => 'Bob', 'black' => 'Charlie', 'result' => MatchManager::DRAW],
            ['white' => 'Charlie', 'black' => 'Alice', 'result' => MatchManager::BLACK_WIN],
        ]);

        $aliceRating = $this->getRating('Alice');
        $bobRating = $this->getRating('Bob');
        $charlieRating = $this->getRating('Charlie');

        self::assertNotNull($aliceRating, 'Alice has a rating');
        self::assertNotNull($bobRating, 'Bob has a rating');
        self::assertNotNull($charlieRating, 'Charlie has a rating');

        $this->manager->invalidateMatch(1);
        self::assertNotSame($aliceRating, $this->getRating('Alice'), 'Alice rating changed after invalidation');
        self::assertNotSame($bobRating, $this->getRating('Bob'), 'Bob rating changed after invalidation');

        $this->manager->revalidateMatch(1);
        self::assertSame($aliceRating, $this->getRating('Alice'), 'Alice rating restored after revalidation');
        self::assertSame($bobRating, $this->getRating('Bob'), 'Bob rating restored after revalidation');
    }

    // ============================================================
    // TEST 2: Invalidation Order Independence
    // ============================================================
    public function testInvalidationOrderIndependence(): void
    {
        $this->playMatches([
            ['white' => 'Alice', 'black' => 'Bob', 'result' => MatchManager::WHITE_WIN],
            ['white' => 'Bob', 'black' => 'Charlie', 'result' => MatchManager::DRAW],
            ['white' => 'Charlie', 'black' => 'Alice', 'result' => MatchManager::BLACK_WIN],
            ['white' => 'Alice', 'black' => 'Charlie', 'result' => MatchManager::WHITE_WIN],
            ['white' => 'Bob', 'black' => 'Alice', 'result' => MatchManager::DRAW],
        ]);

        $finalRatings = [];
        foreach ($this->getPlayers() as $p) {
            $finalRatings[$p->getUsername()] = $p->getRating();
        }

        $orders = [
            [1, 2, 3, 4, 5],
            [5, 4, 3, 2, 1],
            [2, 4, 1, 5, 3],
            [3, 1, 5, 2, 4],
        ];

        foreach ($orders as $order) {
            for ($i = 1; $i <= 5; $i++) {
                $match = $this->manager->getMatch($i);
                if ($match !== null && !$match->isValid()) {
                    $this->manager->revalidateMatch($i);
                }
            }

            foreach ($order as $id) {
                $this->manager->invalidateMatch($id);
            }

            $this->assertRating('Alice', 400);
            $this->assertRating('Bob', 400);
            $this->assertRating('Charlie', 400);

            foreach ($order as $id) {
                $this->manager->revalidateMatch($id);
            }

            foreach ($finalRatings as $username => $expected) {
                $this->assertRating($username, $expected);
            }
        }
    }

    // ============================================================
    // TEST 3: Mixed Invalidations
    // ============================================================
    public function testMixedInvalidations(): void
    {
        $this->playMatches([
            ['white' => 'Alice', 'black' => 'Bob', 'result' => MatchManager::WHITE_WIN],
            ['white' => 'Bob', 'black' => 'Charlie', 'result' => MatchManager::DRAW],
            ['white' => 'Charlie', 'black' => 'Alice', 'result' => MatchManager::BLACK_WIN],
            ['white' => 'Alice', 'black' => 'Charlie', 'result' => MatchManager::WHITE_WIN],
            ['white' => 'Bob', 'black' => 'Alice', 'result' => MatchManager::DRAW],
            ['white' => 'Charlie', 'black' => 'Bob', 'result' => MatchManager::BLACK_WIN],
            ['white' => 'Alice', 'black' => 'Bob', 'result' => MatchManager::DRAW],
        ]);

        $originalRatings = [];
        foreach ($this->getPlayers() as $p) {
            $originalRatings[$p->getUsername()] = $p->getRating();
        }

        $operations = [
            ['type' => 'invalidate', 'id' => 3],
            ['type' => 'invalidate', 'id' => 5],
            ['type' => 'revalidate', 'id' => 3],
            ['type' => 'invalidate', 'id' => 1],
            ['type' => 'invalidate', 'id' => 7],
            ['type' => 'revalidate', 'id' => 5],
            ['type' => 'invalidate', 'id' => 2],
            ['type' => 'revalidate', 'id' => 7],
            ['type' => 'invalidate', 'id' => 4],
            ['type' => 'revalidate', 'id' => 1],
        ];

        foreach ($operations as $op) {
            if ($op['type'] === 'invalidate') {
                $this->manager->invalidateMatch($op['id']);
            } else {
                $this->manager->revalidateMatch($op['id']);
            }
        }

        for ($i = 1; $i <= 7; $i++) {
            $match = $this->manager->getMatch($i);
            if ($match !== null && !$match->isValid()) {
                $this->manager->revalidateMatch($i);
            }
        }

        foreach ($originalRatings as $username => $expected) {
            $this->assertRating($username, $expected);
        }
    }

    // ============================================================
    // TEST 4: Complex Scenario with Multiple Players
    // ============================================================
    public function testComplexScenario(): void
    {
        $players = ['Alice', 'Bob', 'Charlie', 'David', 'Eve', 'Frank'];
        $results = [MatchManager::WHITE_WIN, MatchManager::DRAW, MatchManager::BLACK_WIN];

        $matches = [];
        for ($i = 0; $i < count($players); $i++) {
            for ($j = $i + 1; $j < count($players); $j++) {
                $matches[] = [
                    'white' => $players[$i],
                    'black' => $players[$j],
                    'result' => $results[array_rand($results)],
                    'url' => 'https://tournament.com/match',
                ];
            }
        }

        $this->playMatches($matches);

        $finalRatings = [];
        foreach ($this->getPlayers() as $p) {
            $finalRatings[$p->getUsername()] = $p->getRating();
        }

        $matchIds = range(1, count($matches));
        shuffle($matchIds);
        $invalidatedIds = array_slice($matchIds, 0, 8);

        foreach ($invalidatedIds as $id) {
            $this->manager->invalidateMatch($id);
        }

        $changed = false;
        foreach ($this->getPlayers() as $p) {
            if ($p->getRating() !== $finalRatings[$p->getUsername()]) {
                $changed = true;
                break;
            }
        }
        self::assertTrue($changed, 'Ratings changed after invalidation');

        shuffle($invalidatedIds);
        foreach ($invalidatedIds as $id) {
            $this->manager->revalidateMatch($id);
        }

        foreach ($finalRatings as $username => $expected) {
            $this->assertRating($username, $expected);
        }
    }

    // ============================================================
    // TEST 5: Invalidate All Matches
    // ============================================================
    public function testInvalidationAllMatches(): void
    {
        $this->playMatches([
            ['white' => 'Alice', 'black' => 'Bob', 'result' => MatchManager::WHITE_WIN],
            ['white' => 'Bob', 'black' => 'Charlie', 'result' => MatchManager::DRAW],
            ['white' => 'Charlie', 'black' => 'Alice', 'result' => MatchManager::BLACK_WIN],
            ['white' => 'Alice', 'black' => 'Charlie', 'result' => MatchManager::WHITE_WIN],
            ['white' => 'Bob', 'black' => 'Alice', 'result' => MatchManager::DRAW],
            ['white' => 'Charlie', 'black' => 'Bob', 'result' => MatchManager::BLACK_WIN],
            ['white' => 'Alice', 'black' => 'Bob', 'result' => MatchManager::DRAW],
            ['white' => 'Bob', 'black' => 'Charlie', 'result' => MatchManager::WHITE_WIN],
            ['white' => 'Charlie', 'black' => 'Alice', 'result' => MatchManager::DRAW],
            ['white' => 'Alice', 'black' => 'Bob', 'result' => MatchManager::BLACK_WIN],
        ]);

        for ($i = 1; $i <= 10; $i++) {
            $this->manager->invalidateMatch($i);
        }

        $this->assertRating('Alice', 400);
        $this->assertRating('Bob', 400);
        $this->assertRating('Charlie', 400);

        $this->assertStats('Alice', 0, 0, 0, 0);
        $this->assertStats('Bob', 0, 0, 0, 0);
        $this->assertStats('Charlie', 0, 0, 0, 0);

        self::assertCount(10, $this->manager->getInvalidMatches(), 'All 10 matches should be invalid');
    }

    // ============================================================
    // TEST 6: Revalidate All Matches
    // ============================================================
    public function testRevalidationAllMatches(): void
    {
        $this->playMatches([
            ['white' => 'Alice', 'black' => 'Bob', 'result' => MatchManager::WHITE_WIN],
            ['white' => 'Bob', 'black' => 'Charlie', 'result' => MatchManager::DRAW],
            ['white' => 'Charlie', 'black' => 'Alice', 'result' => MatchManager::BLACK_WIN],
            ['white' => 'Alice', 'black' => 'Charlie', 'result' => MatchManager::WHITE_WIN],
            ['white' => 'Bob', 'black' => 'Alice', 'result' => MatchManager::DRAW],
            ['white' => 'Charlie', 'black' => 'Bob', 'result' => MatchManager::BLACK_WIN],
            ['white' => 'Alice', 'black' => 'Bob', 'result' => MatchManager::DRAW],
            ['white' => 'Bob', 'black' => 'Charlie', 'result' => MatchManager::WHITE_WIN],
        ]);

        $finalRatings = [];
        foreach ($this->getPlayers() as $p) {
            $finalRatings[$p->getUsername()] = $p->getRating();
        }

        for ($i = 1; $i <= 8; $i++) {
            $this->manager->invalidateMatch($i);
        }

        $ids = range(1, 8);
        shuffle($ids);
        foreach ($ids as $id) {
            $this->manager->revalidateMatch($id);
        }

        foreach ($finalRatings as $username => $expected) {
            $this->assertRating($username, $expected);
        }

        self::assertCount(8, $this->manager->getValidMatches(), 'All 8 matches should be valid');
    }

    // ============================================================
    // TEST 7: Consecutive Invalidations
    // ============================================================
    public function testConsecutiveInvalidations(): void
    {
        $this->playMatches([
            ['white' => 'Alice', 'black' => 'Bob', 'result' => MatchManager::WHITE_WIN],
            ['white' => 'Bob', 'black' => 'Charlie', 'result' => MatchManager::DRAW],
        ]);

        $aliceRating = $this->getRating('Alice');

        try {
            $this->manager->invalidateMatch(1);
        } catch (\Exception $e) {
            self::fail('First invalidation failed: ' . $e->getMessage());
        }

        try {
            $this->manager->invalidateMatch(1);
            self::fail('Second invalidation should have thrown an exception');
        } catch (\Exception) {
            self::assertTrue(true, 'Second invalidation correctly threw exception');
        }

        try {
            $this->manager->revalidateMatch(1);
        } catch (\Exception $e) {
            self::fail('Revalidation failed: ' . $e->getMessage());
        }

        try {
            $this->manager->revalidateMatch(1);
            self::fail('Second revalidation should have thrown an exception');
        } catch (\Exception) {
            self::assertTrue(true, 'Second revalidation correctly threw exception');
        }

        $this->assertRating('Alice', $aliceRating);
    }

    // ============================================================
    // TEST 8: Rating Consistency After Operations
    // ============================================================
    public function testRatingConsistencyAfterOperations(): void
    {
        $this->playMatches([
            ['white' => 'Alice', 'black' => 'Bob', 'result' => MatchManager::WHITE_WIN],
            ['white' => 'Bob', 'black' => 'Charlie', 'result' => MatchManager::DRAW],
            ['white' => 'Charlie', 'black' => 'Alice', 'result' => MatchManager::BLACK_WIN],
            ['white' => 'Alice', 'black' => 'Bob', 'result' => MatchManager::DRAW],
        ]);

        $initialRatings = [];
        foreach ($this->getPlayers() as $p) {
            $initialRatings[$p->getUsername()] = $p->getRating();
        }

        $this->manager->invalidateMatch(2);
        $this->manager->invalidateMatch(4);
        $this->manager->revalidateMatch(2);
        $this->manager->invalidateMatch(1);
        $this->manager->revalidateMatch(4);
        $this->manager->revalidateMatch(1);

        for ($i = 1; $i <= 4; $i++) {
            $match = $this->manager->getMatch($i);
            if ($match !== null && !$match->isValid()) {
                $this->manager->revalidateMatch($i);
            }
        }

        foreach ($initialRatings as $username => $expected) {
            $this->assertRating($username, $expected);
        }
    }

    // ============================================================
    // TEST 9: Player Stats After Invalidations
    // ============================================================
    public function testPlayerStatsAfterInvalidations(): void
    {
        $this->playMatches([
            ['white' => 'Alice', 'black' => 'Bob', 'result' => MatchManager::WHITE_WIN],
            ['white' => 'Alice', 'black' => 'Charlie', 'result' => MatchManager::WHITE_WIN],
            ['white' => 'Alice', 'black' => 'David', 'result' => MatchManager::WHITE_WIN],
            ['white' => 'Alice', 'black' => 'Eve', 'result' => MatchManager::DRAW],
            ['white' => 'Bob', 'black' => 'Alice', 'result' => MatchManager::BLACK_WIN],
        ]);

        $this->assertStats('Alice', 5, 4, 1, 0);
        $this->assertStats('Bob', 2, 0, 0, 2);

        $this->manager->invalidateMatch(3);
        $this->manager->invalidateMatch(1);
        $this->manager->invalidateMatch(2);

        $this->assertStats('Alice', 2, 1, 1, 0);

        $this->manager->revalidateMatch(1);
        $this->manager->revalidateMatch(2);
        $this->manager->revalidateMatch(3);

        $this->assertStats('Alice', 5, 4, 1, 0);
    }

    // ============================================================
    // TEST 10: Massive Match Sequence
    // ============================================================
    public function testMassiveMatchSequence(): void
    {
        $playerNames = ['Alice', 'Bob', 'Charlie', 'David', 'Eve', 'Frank', 'Grace', 'Henry', 'Ivy', 'Jack'];
        $results = [MatchManager::WHITE_WIN, MatchManager::DRAW, MatchManager::BLACK_WIN];

        $matches = [];
        for ($i = 0; $i < 50; $i++) {
            $players = array_rand(array_flip($playerNames), 2);
            $matches[] = [
                'white' => $players[0],
                'black' => $players[1],
                'result' => $results[array_rand($results)],
                'url' => 'https://massive.com/match/' . $i,
            ];
        }

        $this->playMatches($matches);

        $finalRatings = [];
        foreach ($this->getPlayers() as $p) {
            $finalRatings[$p->getUsername()] = $p->getRating();
        }

        $matchIds = range(1, 50);
        shuffle($matchIds);
        $toInvalidate = array_slice($matchIds, 0, 20);

        foreach ($toInvalidate as $id) {
            $this->manager->invalidateMatch($id);
        }

        shuffle($toInvalidate);
        foreach ($toInvalidate as $id) {
            $this->manager->revalidateMatch($id);
        }

        foreach ($finalRatings as $username => $expected) {
            $this->assertRating($username, $expected);
        }
    }

    // ============================================================
    // TEST 11: Random Operations
    // ============================================================
    public function testRandomOperations(): void
    {
        $this->playMatches([
            ['white' => 'Alice', 'black' => 'Bob', 'result' => MatchManager::WHITE_WIN],
            ['white' => 'Bob', 'black' => 'Charlie', 'result' => MatchManager::DRAW],
            ['white' => 'Charlie', 'black' => 'Alice', 'result' => MatchManager::BLACK_WIN],
            ['white' => 'Alice', 'black' => 'Charlie', 'result' => MatchManager::WHITE_WIN],
            ['white' => 'Bob', 'black' => 'Alice', 'result' => MatchManager::DRAW],
            ['white' => 'Charlie', 'black' => 'Bob', 'result' => MatchManager::BLACK_WIN],
            ['white' => 'Alice', 'black' => 'Bob', 'result' => MatchManager::DRAW],
            ['white' => 'Bob', 'black' => 'Charlie', 'result' => MatchManager::WHITE_WIN],
            ['white' => 'Charlie', 'black' => 'Alice', 'result' => MatchManager::DRAW],
            ['white' => 'Alice', 'black' => 'Bob', 'result' => MatchManager::BLACK_WIN],
            ['white' => 'Bob', 'black' => 'Charlie', 'result' => MatchManager::WHITE_WIN],
            ['white' => 'Charlie', 'black' => 'Alice', 'result' => MatchManager::DRAW],
            ['white' => 'Alice', 'black' => 'Bob', 'result' => MatchManager::WHITE_WIN],
            ['white' => 'Bob', 'black' => 'Charlie', 'result' => MatchManager::BLACK_WIN],
            ['white' => 'Charlie', 'black' => 'Alice', 'result' => MatchManager::DRAW],
        ]);

        $finalRatings = [];
        foreach ($this->getPlayers() as $p) {
            $finalRatings[$p->getUsername()] = $p->getRating();
        }

        $operations = ['invalidate', 'revalidate'];
        for ($i = 0; $i < 100; $i++) {
            $op = $operations[array_rand($operations)];
            $id = rand(1, 15);

            try {
                if ($op === 'invalidate') {
                    $this->manager->invalidateMatch($id);
                } else {
                    $this->manager->revalidateMatch($id);
                }
            } catch (\Exception) {
                // Expected when invalidating an already-invalid match (or
                // revalidating an already-valid one) — we're testing
                // stability under churn, not asserting every op succeeds.
            }
        }

        for ($i = 1; $i <= 15; $i++) {
            $match = $this->manager->getMatch($i);
            if ($match !== null && !$match->isValid()) {
                $this->manager->revalidateMatch($i);
            }
        }

        foreach ($finalRatings as $username => $expected) {
            $this->assertRating($username, $expected);
        }
    }

    // ============================================================
    // TEST 12: Edge Cases
    // ============================================================
    public function testEdgeCases(): void
    {
        // 1. Invalidate non-existent match
        try {
            $this->manager->invalidateMatch(999);
            self::fail('Invalidating non-existent match should throw exception');
        } catch (\Exception) {
            self::assertTrue(true, 'Non-existent match correctly threw exception');
        }

        // 2. Play match with same player
        try {
            $this->manager->play('Alice', 'Alice', MatchManager::WHITE_WIN, '');
            self::fail('Playing against self should throw exception');
        } catch (\Exception) {
            self::assertTrue(true, 'Self-play correctly threw exception');
        }

        // 3. Play match with invalid result
        try {
            $this->manager->play('Alice', 'Bob', 'invalid_result', '');
            self::fail('Invalid result should throw exception');
        } catch (\Exception) {
            self::assertTrue(true, 'Invalid result correctly threw exception');
        }

        // 4. Remove player and check removal
        $this->manager->play('Alice', 'Bob', MatchManager::WHITE_WIN, '');
        $playedMatch = $this->manager->getMatch(1);
        self::assertNotNull($playedMatch, 'Match exists before player removal');
        $aliceId = $playedMatch->getWhiteId();
        $bobId = $playedMatch->getBlackId();

        $this->manager->removePlayer('Alice');
        $found = false;
        foreach ($this->getPlayers() as $p) {
            if ($p->getUsername() === 'Alice') {
                $found = true;
                break;
            }
        }
        self::assertFalse($found, 'Player Alice was removed');

        $match = $this->manager->getMatch(1);
        self::assertNotNull($match, 'Match remains after player removal');
        self::assertSame($aliceId, $match->getWhiteId(), "Removed player's match still references Alice ID");
        self::assertSame($bobId, $match->getBlackId(), 'Match still references remaining player Bob ID');

        // 5. Remove match and check recalculation
        $this->manager->play('Charlie', 'David', MatchManager::WHITE_WIN, '');
        $this->manager->play('Eve', 'Frank', MatchManager::DRAW, '');

        $ratingsBefore = [];
        foreach ($this->getPlayers() as $p) {
            $ratingsBefore[$p->getUsername()] = $p->getRating();
        }

        $removed = $this->manager->removeMatch(1);
        self::assertTrue($removed, 'Match 1 removed successfully');

        $ratingsAfter = [];
        foreach ($this->getPlayers() as $p) {
            $ratingsAfter[$p->getUsername()] = $p->getRating();
        }

        $changed = false;
        foreach ($ratingsBefore as $username => $rating) {
            if (isset($ratingsAfter[$username]) && $ratingsAfter[$username] !== $rating) {
                $changed = true;
                break;
            }
        }
        self::assertTrue($changed, 'Ratings changed after match removal');

        // 6. Get stats for non-existent player
        try {
            $this->manager->getPlayerStats('NonExistent');
            self::fail('Stats for non-existent player should throw exception');
        } catch (\Exception) {
            self::assertTrue(true, 'Non-existent player stats correctly threw exception');
        }
    }
}

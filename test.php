<?php

use VRchessIndo\Connection\CSVDatabaseManager;
use VRchessIndo\Logic\MatchManager;

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Advanced Test Suite for Chess Rating System
 * Tests invalidation, revalidation, and rating consistency
 */
class RatingSystemTest
{
    private $manager;
    private $testResults = [];
    private $passed = 0;
    private $failed = 0;
    private $matchHistory = [];
    private function resetDatabase(): void
    {
        unlink(__DIR__ . '/data/test_player.csv');
        unlink(__DIR__ . '/data/test_match.csv');

        $db = new CSVDatabaseManager(
            __DIR__ . '/data/test_player.csv',
            __DIR__ . '/data/test_match.csv'
        );

        $this->manager = new MatchManager($db);
        $this->matchHistory = [];
    }

    public function __construct()
    {
        $this->resetDatabase();
    }

    public function run(): void
    {
        echo "\n🧪 CHESS RATING SYSTEM - COMPREHENSIVE TEST SUITE\n";
        echo str_repeat('=', 70) . "\n";

        $this->testBasicFlow();
        $this->testInvalidationOrderIndependence();
        $this->testMixedInvalidations();
        $this->testComplexScenario();
        $this->testInvalidationAllMatches();
        $this->testRevalidationAllMatches();
        $this->testConsecutiveInvalidations();
        $this->testRatingConsistencyAfterOperations();
        $this->testPlayerStatsAfterInvalidations();
        $this->testMassiveMatchSequence();
        $this->testRandomOperations();
        $this->testEdgeCases();

        // Summary
        echo "\n" . str_repeat('=', 70) . "\n";
        echo "📊 TEST SUMMARY\n";
        echo str_repeat('=', 70) . "\n";
        echo "✅ Passed: " . $this->passed . "\n";
        echo "❌ Failed: " . $this->failed . "\n";
        echo "Total: " . ($this->passed + $this->failed) . "\n";
        echo "Success Rate: " . round(($this->passed / ($this->passed + $this->failed)) * 100, 2) . "%\n";
        echo str_repeat('=', 70) . "\n";

        if ($this->failed > 0) {
            echo "\n❌ Some tests failed!\n";
            exit(1);
        } else {
            echo "\n✅ All tests passed! 🎉\n";
        }
    }

    private function assertTrue($condition, $message): void
    {
        if ($condition) {
            $this->passed++;
            echo "  ✅ PASS: $message\n";
        } else {
            $this->failed++;
            echo "  ❌ FAIL: $message\n";
            // Debug info
            $trace = debug_backtrace();
            $caller = $trace[1] ?? $trace[0];
            echo "     Location: " . ($caller['function'] ?? 'unknown') . "\n";
        }
    }

    private function assertEquals($expected, $actual, $message): void
    {
        if ($expected === $actual) {
            $this->passed++;
            echo "  ✅ PASS: $message (Expected: $expected, Got: $actual)\n";
        } else {
            $this->failed++;
            echo "  ❌ FAIL: $message (Expected: $expected, Got: $actual)\n";
        }
    }

    private function assertRating($username, $expected): void
    {
        $players = $this->manager->getPlayers();
        foreach ($players as $p) {
            if ($p['username'] === $username) {
                $this->assertEquals($expected, $p['rating'], "Rating for $username");
                return;
            }
        }
        $this->assertTrue(false, "Player $username not found");
    }

    private function assertStats($username, $expectedGames, $expectedWins, $expectedDraws, $expectedLosses): void
    {
        $players = $this->manager->getPlayers();
        foreach ($players as $p) {
            if ($p['username'] === $username) {
                $this->assertEquals($expectedGames, $p['games'], "Games for $username");
                $this->assertEquals($expectedWins, $p['wins'], "Wins for $username");
                $this->assertEquals($expectedDraws, $p['draws'], "Draws for $username");
                $this->assertEquals($expectedLosses, $p['losses'], "Losses for $username");
                return;
            }
        }
        $this->assertTrue(false, "Player $username not found");
    }

    private function getRating($username): ?int
    {
        $players = $this->manager->getPlayers();
        foreach ($players as $p) {
            if ($p['username'] === $username) {
                return $p['rating'];
            }
        }
        return null;
    }

    private function playMatches(array $matches): array
    {
        $results = [];
        foreach ($matches as $match) {
            $result = $this->manager->play(
                $match['white'],
                $match['black'],
                $match['result'],
                $match['url'] ?? 'https://test.com'
            );
            $results[] = $result;
            $this->matchHistory[] = [
                'id' => $result['match_id'],
                'white' => $match['white'],
                'black' => $match['black'],
                'result' => $match['result']
            ];
        }
        return $results;
    }

    // ============================================================
    // TEST 1: Basic Flow
    // ============================================================
    private function testBasicFlow(): void
    {
        echo "\n📋 TEST 1: Basic Flow - Play, Invalidate, Revalidate\n";
        echo str_repeat('-', 70) . "\n";

        // Reset
        $this->resetDatabase();

        // Play matches
        $this->playMatches([
            ['white' => 'Alice', 'black' => 'Bob', 'result' => MatchManager::WHITE_WIN],
            ['white' => 'Bob', 'black' => 'Charlie', 'result' => MatchManager::DRAW],
            ['white' => 'Charlie', 'black' => 'Alice', 'result' => MatchManager::BLACK_WIN],
        ]);

        // Get ratings after all matches
        $aliceRating = $this->getRating('Alice');
        $bobRating = $this->getRating('Bob');
        $charlieRating = $this->getRating('Charlie');

        $this->assertTrue($aliceRating !== null, "Alice has a rating");
        $this->assertTrue($bobRating !== null, "Bob has a rating");
        $this->assertTrue($charlieRating !== null, "Charlie has a rating");

        // Invalidate match 1
        $this->manager->invalidateMatch(1);
        $newAliceRating = $this->getRating('Alice');
        $newBobRating = $this->getRating('Bob');

        $this->assertTrue($newAliceRating !== $aliceRating, "Alice rating changed after invalidation");
        $this->assertTrue($newBobRating !== $bobRating, "Bob rating changed after invalidation");

        // Revalidate match 1
        $this->manager->revalidateMatch(1);
        $finalAliceRating = $this->getRating('Alice');
        $finalBobRating = $this->getRating('Bob');

        $this->assertEquals($aliceRating, $finalAliceRating, "Alice rating restored after revalidation");
        $this->assertEquals($bobRating, $finalBobRating, "Bob rating restored after revalidation");

        echo "  ✅ Basic flow test passed\n";
    }

    // ============================================================
    // TEST 2: Invalidation Order Independence
    // ============================================================
    private function testInvalidationOrderIndependence(): void
    {
        echo "\n📋 TEST 2: Invalidation Order Independence\n";
        echo str_repeat('-', 70) . "\n";

        // Reset
        $this->resetDatabase();

        // Play 5 matches
        $this->playMatches([
            ['white' => 'Alice', 'black' => 'Bob', 'result' => MatchManager::WHITE_WIN],
            ['white' => 'Bob', 'black' => 'Charlie', 'result' => MatchManager::DRAW],
            ['white' => 'Charlie', 'black' => 'Alice', 'result' => MatchManager::BLACK_WIN],
            ['white' => 'Alice', 'black' => 'Charlie', 'result' => MatchManager::WHITE_WIN],
            ['white' => 'Bob', 'black' => 'Alice', 'result' => MatchManager::DRAW],
        ]);

        // Get final ratings
        $finalRatings = [];
        $players = $this->manager->getPlayers();
        foreach ($players as $p) {
            $finalRatings[$p['username']] = $p['rating'];
        }

        // Invalidate all matches in different orders
        $orders = [
            [1, 2, 3, 4, 5],
            [5, 4, 3, 2, 1],
            [2, 4, 1, 5, 3],
            [3, 1, 5, 2, 4],
        ];

        foreach ($orders as $order) {
            // Reset by revalidating all matches first
            for ($i = 1; $i <= 5; $i++) {
                $match = $this->manager->getMatch($i);
                if ($match && isset($match['is_valid']) && $match['is_valid'] === false) {
                    $this->manager->revalidateMatch($i);
                }
            }

            // Invalidate in this order
            foreach ($order as $id) {
                $this->manager->invalidateMatch($id);
            }

            // After all invalidated, everyone should be back to 400
            $this->assertRating('Alice', 400);
            $this->assertRating('Bob', 400);
            $this->assertRating('Charlie', 400);

            // Revalidate in same order
            foreach ($order as $id) {
                $this->manager->revalidateMatch($id);
            }

            // Check final ratings match original
            foreach ($finalRatings as $username => $expected) {
                $this->assertRating($username, $expected);
            }
        }

        echo "  ✅ Order independence test passed\n";
    }

    // ============================================================
    // TEST 3: Mixed Invalidations
    // ============================================================
    private function testMixedInvalidations(): void
    {
        echo "\n📋 TEST 3: Mixed Invalidations (Random Invalidate/Revalidate)\n";
        echo str_repeat('-', 70) . "\n";

        $this->resetDatabase();

        // Play 7 matches
        $this->playMatches([
            ['white' => 'Alice', 'black' => 'Bob', 'result' => MatchManager::WHITE_WIN],
            ['white' => 'Bob', 'black' => 'Charlie', 'result' => MatchManager::DRAW],
            ['white' => 'Charlie', 'black' => 'Alice', 'result' => MatchManager::BLACK_WIN],
            ['white' => 'Alice', 'black' => 'Charlie', 'result' => MatchManager::WHITE_WIN],
            ['white' => 'Bob', 'black' => 'Alice', 'result' => MatchManager::DRAW],
            ['white' => 'Charlie', 'black' => 'Bob', 'result' => MatchManager::BLACK_WIN],
            ['white' => 'Alice', 'black' => 'Bob', 'result' => MatchManager::DRAW],
        ]);

        // Get original final ratings
        $originalRatings = [];
        $players = $this->manager->getPlayers();
        foreach ($players as $p) {
            $originalRatings[$p['username']] = $p['rating'];
        }

        // Perform random operations
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

        // Revalidate all matches
        for ($i = 1; $i <= 7; $i++) {
            $match = $this->manager->getMatch($i);
            if ($match && isset($match['is_valid']) && $match['is_valid'] === false) {
                $this->manager->revalidateMatch($i);
            }
        }

        // Final ratings should match original
        foreach ($originalRatings as $username => $expected) {
            $this->assertRating($username, $expected);
        }

        echo "  ✅ Mixed invalidation test passed\n";
    }

    // ============================================================
    // TEST 4: Complex Scenario with Multiple Players
    // ============================================================
    private function testComplexScenario(): void
    {
        echo "\n📋 TEST 4: Complex Scenario (6 Players, 15 Matches)\n";
        echo str_repeat('-', 70) . "\n";

        $this->resetDatabase();

        $players = ['Alice', 'Bob', 'Charlie', 'David', 'Eve', 'Frank'];
        $results = [MatchManager::WHITE_WIN, MatchManager::DRAW, MatchManager::BLACK_WIN];

        // Round-robin tournament (15 matches)
        $matches = [];
        for ($i = 0; $i < count($players); $i++) {
            for ($j = $i + 1; $j < count($players); $j++) {
                $matches[] = [
                    'white' => $players[$i],
                    'black' => $players[$j],
                    'result' => $results[array_rand($results)],
                    'url' => 'https://tournament.com/match'
                ];
            }
        }

        $this->playMatches($matches);

        // Get final ratings
        $finalRatings = [];
        $playersList = $this->manager->getPlayers();
        foreach ($playersList as $p) {
            $finalRatings[$p['username']] = $p['rating'];
        }

        // Invalidate random matches
        $matchIds = range(1, count($matches));
        shuffle($matchIds);
        $invalidatedIds = array_slice($matchIds, 0, 8);   // pick 8 to invalidate

        foreach ($invalidatedIds as $id) {
            $this->manager->invalidateMatch($id);
        }

        // Confirm ratings changed
        $changed = false;
        $newRatings = $this->manager->getPlayers();
        foreach ($newRatings as $p) {
            if ($p['rating'] !== $finalRatings[$p['username']]) {
                $changed = true;
                break;
            }
        }
        $this->assertTrue($changed, "Ratings changed after invalidation");

        // Revalidate the **same** matches in a different order
        shuffle($invalidatedIds);
        foreach ($invalidatedIds as $id) {
            $this->manager->revalidateMatch($id);
        }

        // Final ratings should match original
        foreach ($finalRatings as $username => $expected) {
            $this->assertRating($username, $expected);
        }

        echo "  ✅ Complex scenario test passed\n";
    }

    // ============================================================
    // TEST 5: Invalidate All Matches
    // ============================================================
    private function testInvalidationAllMatches(): void
    {
        echo "\n📋 TEST 5: Invalidate All Matches\n";
        echo str_repeat('-', 70) . "\n";

        $this->resetDatabase();

        // Play 10 matches
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

        // Invalidate all matches
        for ($i = 1; $i <= 10; $i++) {
            $this->manager->invalidateMatch($i);
        }

        // All ratings should be 400
        $this->assertRating('Alice', 400);
        $this->assertRating('Bob', 400);
        $this->assertRating('Charlie', 400);

        // All stats should be 0
        $this->assertStats('Alice', 0, 0, 0, 0);
        $this->assertStats('Bob', 0, 0, 0, 0);
        $this->assertStats('Charlie', 0, 0, 0, 0);

        // Check invalid matches count
        $invalidMatches = $this->manager->getInvalidMatches();
        $this->assertEquals(10, count($invalidMatches), "All 10 matches should be invalid");

        echo "  ✅ All matches invalidation test passed\n";
    }

    // ============================================================
    // TEST 6: Revalidate All Matches
    // ============================================================
    private function testRevalidationAllMatches(): void
    {
        echo "\n📋 TEST 6: Revalidate All Matches After Invalidation\n";
        echo str_repeat('-', 70) . "\n";

        $this->resetDatabase();

        // Play 8 matches
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

        // Get final ratings
        $finalRatings = [];
        $players = $this->manager->getPlayers();
        foreach ($players as $p) {
            $finalRatings[$p['username']] = $p['rating'];
        }

        // Invalidate all
        for ($i = 1; $i <= 8; $i++) {
            $this->manager->invalidateMatch($i);
        }

        // Revalidate in random order
        $ids = range(1, 8);
        shuffle($ids);
        foreach ($ids as $id) {
            $this->manager->revalidateMatch($id);
        }

        // Final ratings should match original
        foreach ($finalRatings as $username => $expected) {
            $this->assertRating($username, $expected);
        }

        // All matches should be valid
        $validMatches = $this->manager->getValidMatches();
        $this->assertEquals(8, count($validMatches), "All 8 matches should be valid");

        echo "  ✅ Revalidation all matches test passed\n";
    }

    // ============================================================
    // TEST 7: Consecutive Invalidations
    // ============================================================
    private function testConsecutiveInvalidations(): void
    {
        echo "\n📋 TEST 7: Consecutive Invalidations (Invalidate same match twice)\n";
        echo str_repeat('-', 70) . "\n";

        $this->resetDatabase();

        $this->playMatches([
            ['white' => 'Alice', 'black' => 'Bob', 'result' => MatchManager::WHITE_WIN],
            ['white' => 'Bob', 'black' => 'Charlie', 'result' => MatchManager::DRAW],
        ]);

        $aliceRating = $this->getRating('Alice');

        // First invalidation should work
        try {
            $this->manager->invalidateMatch(1);
            $this->assertTrue(true, "First invalidation succeeded");
        } catch (\Exception $e) {
            $this->assertTrue(false, "First invalidation failed: " . $e->getMessage());
        }

        // Second invalidation should throw exception
        try {
            $this->manager->invalidateMatch(1);
            $this->assertTrue(false, "Second invalidation should have thrown an exception");
        } catch (\Exception $e) {
            $this->assertTrue(true, "Second invalidation correctly threw exception");
        }

        // Revalidate should work
        try {
            $this->manager->revalidateMatch(1);
            $this->assertTrue(true, "Revalidation succeeded");
        } catch (\Exception $e) {
            $this->assertTrue(false, "Revalidation failed: " . $e->getMessage());
        }

        // Second revalidate should throw exception (already valid)
        try {
            $this->manager->revalidateMatch(1);
            $this->assertTrue(false, "Second revalidation should have thrown an exception");
        } catch (\Exception $e) {
            $this->assertTrue(true, "Second revalidation correctly threw exception");
        }

        // Rating should be restored
        $this->assertRating('Alice', $aliceRating);

        echo "  ✅ Consecutive invalidation test passed\n";
    }

    // ============================================================
    // TEST 8: Rating Consistency After Operations
    // ============================================================
    private function testRatingConsistencyAfterOperations(): void
    {
        echo "\n📋 TEST 8: Rating Consistency After Multiple Operations\n";
        echo str_repeat('-', 70) . "\n";

        $this->resetDatabase();

        // Play matches
        $this->playMatches([
            ['white' => 'Alice', 'black' => 'Bob', 'result' => MatchManager::WHITE_WIN],
            ['white' => 'Bob', 'black' => 'Charlie', 'result' => MatchManager::DRAW],
            ['white' => 'Charlie', 'black' => 'Alice', 'result' => MatchManager::BLACK_WIN],
            ['white' => 'Alice', 'black' => 'Bob', 'result' => MatchManager::DRAW],
        ]);

        // Get initial ratings
        $initialRatings = [];
        $players = $this->manager->getPlayers();
        foreach ($players as $p) {
            $initialRatings[$p['username']] = $p['rating'];
        }

        // Perform operations: invalidate, revalidate, invalidate different, revalidate all
        $this->manager->invalidateMatch(2);
        $this->manager->invalidateMatch(4);
        $this->manager->revalidateMatch(2);
        $this->manager->invalidateMatch(1);
        $this->manager->revalidateMatch(4);
        $this->manager->revalidateMatch(1);

        // Revalidate all
        for ($i = 1; $i <= 4; $i++) {
            $match = $this->manager->getMatch($i);
            if ($match && isset($match['is_valid']) && $match['is_valid'] === false) {
                $this->manager->revalidateMatch($i);
            }
        }

        // Ratings should match initial
        foreach ($initialRatings as $username => $expected) {
            $this->assertRating($username, $expected);
        }

        echo "  ✅ Rating consistency test passed\n";
    }

    // ============================================================
    // TEST 9: Player Stats After Invalidations
    // ============================================================
    private function testPlayerStatsAfterInvalidations(): void
    {
        echo "\n📋 TEST 9: Player Stats After Invalidations\n";
        echo str_repeat('-', 70) . "\n";

        $this->resetDatabase();

        // Play matches
        $this->playMatches([
            ['white' => 'Alice', 'black' => 'Bob', 'result' => MatchManager::WHITE_WIN],
            ['white' => 'Alice', 'black' => 'Charlie', 'result' => MatchManager::WHITE_WIN],
            ['white' => 'Alice', 'black' => 'David', 'result' => MatchManager::WHITE_WIN],
            ['white' => 'Alice', 'black' => 'Eve', 'result' => MatchManager::DRAW],
            ['white' => 'Bob', 'black' => 'Alice', 'result' => MatchManager::BLACK_WIN],
        ]);

        // Check initial stats
        $this->assertStats('Alice', 5, 4, 1, 0);
        $this->assertStats('Bob', 2, 0, 0, 2);

        // Invalidate Alice's wins (matches 1, 2, 3)
        $this->manager->invalidateMatch(3);
        $this->manager->invalidateMatch(1);
        $this->manager->invalidateMatch(2);

        // Alice should have fewer wins
        $this->assertStats('Alice', 2, 1, 1, 0);

        // Revalidate them
        $this->manager->revalidateMatch(1);
        $this->manager->revalidateMatch(2);
        $this->manager->revalidateMatch(3);

        // Stats should be back
        $this->assertStats('Alice', 5, 4, 1, 0);

        echo "  ✅ Player stats test passed\n";
    }

    // ============================================================
    // TEST 10: Massive Match Sequence
    // ============================================================
    private function testMassiveMatchSequence(): void
    {
        echo "\n📋 TEST 10: Massive Match Sequence (50 matches, 10 players)\n";
        echo str_repeat('-', 70) . "\n";

        $this->resetDatabase();

        $playerNames = [
            'Alice',
            'Bob',
            'Charlie',
            'David',
            'Eve',
            'Frank',
            'Grace',
            'Henry',
            'Ivy',
            'Jack'
        ];

        $results = [MatchManager::WHITE_WIN, MatchManager::DRAW, MatchManager::BLACK_WIN];

        // Create 50 random matches
        $matches = [];
        for ($i = 0; $i < 50; $i++) {
            $players = array_rand(array_flip($playerNames), 2);
            $matches[] = [
                'white' => $players[0],
                'black' => $players[1],
                'result' => $results[array_rand($results)],
                'url' => 'https://massive.com/match/' . $i
            ];
        }

        $this->playMatches($matches);

        // Get final ratings
        $finalRatings = [];
        $players = $this->manager->getPlayers();
        foreach ($players as $p) {
            $finalRatings[$p['username']] = $p['rating'];
        }

        // Invalidate 20 random matches
        $matchIds = range(1, 50);
        shuffle($matchIds);
        $toInvalidate = array_slice($matchIds, 0, 20);

        foreach ($toInvalidate as $id) {
            $this->manager->invalidateMatch($id);
        }

        // Revalidate them in different order
        shuffle($toInvalidate);
        foreach ($toInvalidate as $id) {
            $this->manager->revalidateMatch($id);
        }

        // Ratings should match final
        foreach ($finalRatings as $username => $expected) {
            $this->assertRating($username, $expected);
        }

        echo "  ✅ Massive match sequence test passed\n";
    }

    // ============================================================
    // TEST 11: Random Operations
    // ============================================================
    private function testRandomOperations(): void
    {
        echo "\n📋 TEST 11: Random Operations (100 random invalidate/revalidate)\n";
        echo str_repeat('-', 70) . "\n";

        $this->resetDatabase();

        // Play 15 matches
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

        // Get final ratings
        $finalRatings = [];
        $players = $this->manager->getPlayers();
        foreach ($players as $p) {
            $finalRatings[$p['username']] = $p['rating'];
        }

        // Perform 100 random operations
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
            } catch (\Exception $e) {
                // Expected when trying to invalidate already invalid or revalidate valid
                // This is fine - we just want to test stability
            }
        }

        // Revalidate all matches to get back to initial state
        for ($i = 1; $i <= 15; $i++) {
            $match = $this->manager->getMatch($i);
            if ($match && isset($match['is_valid']) && $match['is_valid'] === false) {
                $this->manager->revalidateMatch($i);
            }
        }

        // Final ratings should match original
        foreach ($finalRatings as $username => $expected) {
            $this->assertRating($username, $expected);
        }

        echo "  ✅ Random operations test passed\n";
    }

    // ============================================================
    // TEST 12: Edge Cases
    // ============================================================
    private function testEdgeCases(): void
    {
        echo "\n📋 TEST 12: Edge Cases\n";
        echo str_repeat('-', 70) . "\n";

        $this->resetDatabase();

        // 1. Invalidate non-existent match
        try {
            $this->manager->invalidateMatch(999);
            $this->assertTrue(false, "Invalidating non-existent match should throw exception");
        } catch (\Exception $e) {
            $this->assertTrue(true, "Non-existent match correctly threw exception");
        }

        // 2. Play match with same player
        try {
            $this->manager->play('Alice', 'Alice', MatchManager::WHITE_WIN, '');
            $this->assertTrue(false, "Playing against self should throw exception");
        } catch (\Exception $e) {
            $this->assertTrue(true, "Self-play correctly threw exception");
        }

        // 3. Play match with invalid result
        try {
            $this->manager->play('Alice', 'Bob', 'invalid_result', '');
            $this->assertTrue(false, "Invalid result should throw exception");
        } catch (\Exception $e) {
            $this->assertTrue(true, "Invalid result correctly threw exception");
        }

        // 4. Remove player and check removal
        $this->manager->play('Alice', 'Bob', MatchManager::WHITE_WIN, '');
        $playedMatch = $this->manager->getMatch(1);
        $this->assertTrue($playedMatch !== null, "Match exists before player removal");
        $aliceId = $playedMatch['white_id'];
        $bobId = $playedMatch['black_id'];

        $this->manager->removePlayer('Alice');
        $players = $this->manager->getPlayers();
        $found = false;
        foreach ($players as $p) {
            if ($p['username'] === 'Alice') {
                $found = true;
                break;
            }
        }
        $this->assertTrue(!$found, "Player Alice was removed");

        // The match should remain in history after removing the player.
        $match = $this->manager->getMatch(1);
        $this->assertTrue($match !== null, "Match remains after player removal");
        $this->assertEquals($aliceId, $match['white_id'], "Removed player's match still references Alice ID");
        $this->assertEquals($bobId, $match['black_id'], "Match still references remaining player Bob ID");

        // 5. Remove match and check recalculation
        // Since we are starting fresh, these are match IDs 1 and 2
        $result1 = $this->manager->play('Charlie', 'David', MatchManager::WHITE_WIN, '');
        $result2 = $this->manager->play('Eve', 'Frank', MatchManager::DRAW, '');

        $ratingsBefore = [];
        $playersBefore = $this->manager->getPlayers();
        foreach ($playersBefore as $p) {
            $ratingsBefore[$p['username']] = $p['rating'];
        }

        // Remove Charlie vs David (match 1)
        $removed = $this->manager->removeMatch(1);
        $this->assertTrue($removed, "Match 1 removed successfully");

        $ratingsAfter = [];
        $playersAfter = $this->manager->getPlayers();
        foreach ($playersAfter as $p) {
            $ratingsAfter[$p['username']] = $p['rating'];
        }

        // Ratings should change for Charlie and David
        $changed = false;
        foreach ($ratingsBefore as $username => $rating) {
            if (isset($ratingsAfter[$username]) && $ratingsAfter[$username] !== $rating) {
                $changed = true;
                break;
            }
        }
        $this->assertTrue($changed, "Ratings changed after match removal");

        // 6. Get stats for non-existent player
        try {
            $this->manager->getPlayerStats('NonExistent');
            $this->assertTrue(false, "Stats for non-existent player should throw exception");
        } catch (\Exception $e) {
            $this->assertTrue(true, "Non-existent player stats correctly threw exception");
        }

        echo "  ✅ Edge cases test passed\n";
    }
}

// ============================================================
// RUN TESTS
// ============================================================
$test = new RatingSystemTest();
$test->run();
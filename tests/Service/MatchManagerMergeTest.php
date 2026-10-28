<?php

declare(strict_types=1);

namespace VRchessIndo\Tests\Service;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use VRchessIndo\Document\ChessMatch;
use VRchessIndo\Document\Player;
use VRchessIndo\Repository\PlayerRepository;
use VRchessIndo\Service\MatchManager;

/**
 * MatchManager::mergePlayers() — folding a duplicate player into the player they
 * duplicate, which is what unblocks a VRChat rename that AvatarRefresher keeps
 * skipping because the display name is already taken.
 *
 * Same database guard as MatchManagerTest: runs against vrchessindo_test only,
 * hard-asserted before every test, since setUp() wipes what it points at.
 */
class MatchManagerMergeTest extends KernelTestCase
{
    private MatchManager $manager;
    private PlayerRepository $players;
    private DocumentManager $dm;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->dm = $container->get(DocumentManager::class);
        $this->manager = $container->get(MatchManager::class);
        $this->players = $container->get(PlayerRepository::class);

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

    private function player(string $username): Player
    {
        $this->dm->clear();
        $player = $this->players->findOneByCurrentUsername($username);
        self::assertNotNull($player, "Player '{$username}' should exist");

        return $player;
    }

    /**
     * Sets a VRChat link and flushes straight away — player() clears the
     * DocumentManager, so holding an unflushed entity across two calls would
     * silently discard the first change.
     */
    private function linkVrchat(string $username, string $vrchatUserId, ?string $displayName = null, ?string $avatarUrl = null): void
    {
        $this->player($username)->setVrchatLink($vrchatUserId, $displayName, $avatarUrl);
        $this->dm->flush();
    }

    /** The raw username_history array, as stored. */
    private function usernameHistory(string $username): array
    {
        $doc = $this->dm->getDocumentCollection(Player::class)->findOne(['username' => $username]);
        self::assertNotNull($doc, "Player '{$username}' should exist");

        return iterator_to_array($doc['username_history']);
    }

    public function testMovesTheDuplicatesMatchesAndDeletesIt(): void
    {
        // Carol beats "Alice VR" twice; the duplicate row "Alice" beat Carol once.
        $this->manager->play('Alice VR', 'Carol', MatchManager::BLACK_WIN, '');
        $this->manager->play('Alice VR', 'Carol', MatchManager::BLACK_WIN, '');
        $this->manager->play('Alice', 'Carol', MatchManager::WHITE_WIN, '');

        $survivorId = $this->player('Alice VR')->getId();
        $duplicateId = $this->player('Alice')->getId();

        $result = $this->manager->mergePlayers('Alice', 'Alice VR');

        self::assertSame(1, $result['matches_moved']);
        self::assertSame(0, $result['head_to_head_invalidated']);
        self::assertNull($this->players->findOneByCurrentUsername('Alice'), 'The duplicate is deleted');

        // Every match now points at the survivor; none still references the duplicate.
        $byPlayer = $this->dm->getDocumentCollection(ChessMatch::class);
        self::assertSame(0, $byPlayer->countDocuments(['$or' => [['white_id' => $duplicateId], ['black_id' => $duplicateId]]]));
        self::assertSame(3, $byPlayer->countDocuments(['$or' => [['white_id' => $survivorId], ['black_id' => $survivorId]]]));

        // All three games now belong to one player, and the ratings were replayed over
        // the merged set rather than two stat lines being added together.
        $survivor = $this->player('Alice VR');
        self::assertSame(3, $survivor->getGames());
        self::assertSame(1, $survivor->getWins());
        self::assertSame(2, $survivor->getLosses());
        self::assertSame(3, $this->player('Carol')->getGames());
    }

    public function testReplayedRatingMatchesTheSamePlayerHavingPlayedEveryGame(): void
    {
        // The merged outcome has to equal the counterfactual: one player, same games,
        // same order. Anything else means stats were summed rather than replayed.
        $this->manager->play('Alice VR', 'Carol', MatchManager::BLACK_WIN, '');
        $this->manager->play('Alice', 'Carol', MatchManager::WHITE_WIN, '');
        $this->manager->mergePlayers('Alice', 'Alice VR');

        $mergedRating = $this->player('Alice VR')->getRating();
        $mergedCarol = $this->player('Carol')->getRating();

        $this->dm->getDocumentCollection(Player::class)->deleteMany([]);
        $this->dm->getDocumentCollection(ChessMatch::class)->deleteMany([]);
        $this->dm->clear();

        $this->manager->play('Alice VR', 'Carol', MatchManager::BLACK_WIN, '');
        $this->manager->play('Alice VR', 'Carol', MatchManager::WHITE_WIN, '');

        self::assertSame($mergedRating, $this->player('Alice VR')->getRating());
        self::assertSame($mergedCarol, $this->player('Carol')->getRating());
    }

    public function testInvalidatesMatchesTheTwoPlayedAgainstEachOther(): void
    {
        $this->manager->play('Alice', 'Alice VR', MatchManager::WHITE_WIN, '');
        $this->manager->play('Alice VR', 'Carol', MatchManager::WHITE_WIN, '');

        $result = $this->manager->mergePlayers('Alice', 'Alice VR');

        self::assertSame(1, $result['matches_moved']);
        self::assertSame(1, $result['head_to_head_invalidated']);

        // Kept as a record, but excluded from the replay — so the self-match contributes
        // no games and no rating to the survivor.
        $matches = $this->dm->getDocumentCollection(ChessMatch::class);
        self::assertSame(2, $matches->countDocuments([]), 'The head-to-head match is invalidated, not deleted');
        self::assertSame(1, $matches->countDocuments(['is_valid' => false]));
        self::assertSame(1, $this->player('Alice VR')->getGames(), 'Only the game against Carol counts');
    }

    public function testTheDuplicatesNamesKeepResolvingToTheSurvivor(): void
    {
        $this->manager->play('Alice', 'Carol', MatchManager::WHITE_WIN, '');
        $this->manager->editPlayer('Alice', ['username' => 'Alice Old']);
        $this->manager->play('Alice VR', 'Carol', MatchManager::WHITE_WIN, '');

        $result = $this->manager->mergePlayers('Alice Old', 'Alice VR');

        // Both the duplicate's current name and the one it used to hold come across.
        self::assertSame(['Alice Old', 'Alice'], $result['names_absorbed']);

        $survivorId = $this->player('Alice VR')->getId();
        foreach (['Alice Old', 'Alice'] as $oldName) {
            $resolved = $this->players->findOneByUsername($oldName);
            self::assertNotNull($resolved, "'{$oldName}' still resolves after the merge");
            self::assertSame($survivorId, $resolved->getId(), "'{$oldName}' resolves to the survivor");
        }

        // Stamped at the merge, so the grace period runs from when the name became
        // ambiguous rather than from the duplicate's own long-ago rename.
        self::assertSame(['merge', 'merge'], array_column($this->usernameHistory('Alice VR'), 'source'));
    }

    public function testMergingIsIdempotentForNameHistory(): void
    {
        $this->manager->play('Alice', 'Carol', MatchManager::WHITE_WIN, '');
        $this->manager->play('Alice VR', 'Carol', MatchManager::WHITE_WIN, '');
        $this->manager->mergePlayers('Alice', 'Alice VR');

        // Re-create the duplicate (a bot still posting the old name would), then merge
        // again: the name must not be recorded twice.
        $this->manager->play('Alice', 'Carol', MatchManager::WHITE_WIN, '');
        self::assertNotNull($this->players->findOneByCurrentUsername('Alice'), 'guard: duplicate re-created');
        $this->manager->mergePlayers('Alice', 'Alice VR');

        $names = $this->player('Alice VR')->getPreviousUsernames();
        self::assertSame(['Alice'], $names, 'The absorbed name is recorded once, not once per merge');
    }

    public function testCarriesTheVrchatLinkWhenOnlyTheDuplicateHasOne(): void
    {
        $this->manager->play('Alice', 'Carol', MatchManager::WHITE_WIN, '');
        $this->manager->play('Alice VR', 'Carol', MatchManager::WHITE_WIN, '');

        $this->linkVrchat('Alice', 'usr_alice', 'Alice VR', 'https://example.test/a.png');

        $result = $this->manager->mergePlayers('Alice', 'Alice VR');

        self::assertTrue($result['vrchat_link_moved']);
        $survivor = $this->player('Alice VR');
        self::assertSame('usr_alice', $survivor->getVrchatUserId());
        self::assertSame('https://example.test/a.png', $survivor->getAvatarUrl());
    }

    public function testKeepsTheSurvivorsOwnVrchatLink(): void
    {
        $this->manager->play('Alice', 'Carol', MatchManager::WHITE_WIN, '');
        $this->manager->play('Alice VR', 'Carol', MatchManager::WHITE_WIN, '');

        $this->linkVrchat('Alice VR', 'usr_survivor', 'Alice VR', 'https://example.test/keep.png');
        $this->manager->mergePlayers('Alice', 'Alice VR');

        $result = $this->player('Alice VR');
        self::assertSame('usr_survivor', $result->getVrchatUserId());
        self::assertSame('https://example.test/keep.png', $result->getAvatarUrl());
    }

    public function testRefusesTwoPlayersLinkedToDifferentVrchatAccounts(): void
    {
        $this->manager->play('Alice', 'Carol', MatchManager::WHITE_WIN, '');
        $this->manager->play('Bob', 'Carol', MatchManager::WHITE_WIN, '');

        $this->linkVrchat('Alice', 'usr_alice', 'Alice');
        $this->linkVrchat('Bob', 'usr_bob', 'Bob');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/berbeda/');
        $this->manager->mergePlayers('Alice', 'Bob');
    }

    public function testRefusesMergingAPlayerIntoThemselves(): void
    {
        $this->manager->play('Alice', 'Carol', MatchManager::WHITE_WIN, '');

        $this->expectException(\DomainException::class);
        $this->manager->mergePlayers('Alice', 'Alice');
    }

    public function testRefusesAnUnknownPlayer(): void
    {
        $this->manager->play('Alice', 'Carol', MatchManager::WHITE_WIN, '');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/Nobody/');
        $this->manager->mergePlayers('Nobody', 'Alice');
    }

    public function testResolvesBothPlayersByCurrentNameOnly(): void
    {
        // 'Alice' is Bob's *old* name here. Merging "from 'Alice'" must not silently
        // act on Bob via the history fallback — there is no player currently named
        // Alice, so the merge is refused.
        $this->manager->play('Alice', 'Carol', MatchManager::WHITE_WIN, '');
        $this->manager->editPlayer('Alice', ['username' => 'Bob']);
        $this->manager->play('Alice VR', 'Carol', MatchManager::WHITE_WIN, '');

        self::assertNotNull($this->players->findOneByUsername('Alice'), 'guard: the old name still resolves');

        $this->expectException(\DomainException::class);
        $this->manager->mergePlayers('Alice', 'Alice VR');
    }
}

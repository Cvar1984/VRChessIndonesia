<?php

declare(strict_types=1);

namespace VRchessIndo\Service;

use Doctrine\ODM\MongoDB\DocumentManager;
use VRchessIndo\Document\ChessMatch;
use VRchessIndo\Document\Player;
use VRchessIndo\Repository\ChessMatchRepository;
use VRchessIndo\Repository\PlayerRepository;

/**
 * Ports VRchessIndo\Logic\MatchManager onto the ODM. Behavioral parity is
 * the whole point here — every quirk below is intentional, not incidental:
 *
 * - recalculateRatings() resets every player to the initial rating/stats and
 *   replays every *valid* match in chronological order. This is what makes
 *   invalidate/revalidate order-independent: the end state only depends on
 *   which matches are currently valid, never on the order operations
 *   happened in. See tests/Service/MatchManagerTest.php, ported from the
 *   legacy test.php's RatingSystemTest, which exercises this directly.
 * - Removing a player never touches match history — matches keep
 *   referencing the deleted player's old id, and both replay and the
 *   invalidate/revalidate response resolve a missing id to a
 *   "DeletedPlayer#{id}" placeholder using the rating the match record
 *   already carries, so history stays intact and inspectable.
 * - Unlike the legacy DatabaseManager, which held plain arrays in memory,
 *   the ODM's DocumentManager is itself a unit-of-work/identity map, so
 *   there's no need to replicate the legacy's manual
 *   "mutate array, save, reload" cycle — persist()/flush() is enough.
 */
class MatchManager
{
    public const string WHITE_WIN = '1-0';
    public const string BLACK_WIN = '0-1';
    public const string DRAW = '1/2-1/2';
    public const int INITIAL_RATING = Player::INITIAL_RATING;

    public function __construct(
        private readonly DocumentManager $dm,
        private readonly PlayerRepository $playerRepository,
        private readonly ChessMatchRepository $matchRepository,
        private readonly RatingCalculator $ratingCalculator,
    ) {
    }

    /**
     * @return Player[] sorted by rating descending
     */
    public function getPlayers(): array
    {
        return $this->playerRepository->findAllSortedByRating();
    }

    /**
     * @return ChessMatch[]
     */
    public function getMatches(): array
    {
        return $this->matchRepository->findAllSortedById();
    }

    public function getMatch(int $matchId): ?ChessMatch
    {
        return $this->matchRepository->findOneByAppId($matchId);
    }

    /**
     * @return ChessMatch[]
     */
    public function getValidMatches(): array
    {
        return $this->matchRepository->findValid();
    }

    /**
     * @return ChessMatch[]
     */
    public function getInvalidMatches(): array
    {
        return $this->matchRepository->findInvalid();
    }

    public function getOrCreatePlayer(string $username): Player
    {
        $username = trim($username);

        $player = $this->playerRepository->findOneByUsername($username);
        if ($player !== null) {
            return $player;
        }

        // Matches keep a deleted player's id, so a plain max(player id) + 1 could hand
        // that id to a newcomer, who would then inherit the deleted player's matches
        // (their stats, and their rating on the next replay). Skip every referenced id.
        $id = max($this->playerRepository->nextId(), $this->matchRepository->maxReferencedPlayerId() + 1);
        $player = new Player($id, $username);
        $this->dm->persist($player);
        $this->dm->flush();

        return $player;
    }

    /**
     * @return array{
     *     white: array{username: string, old_rating: int|float, new_rating: int|float, change: int, expected: float},
     *     black: array{username: string, old_rating: int|float, new_rating: int|float, change: int, expected: float},
     *     result: string, analysis_url: string, match_id: int, is_valid: bool
     * }
     * @throws \Exception if the players are the same, or the result is invalid
     */
    public function play(string $whiteUsername, string $blackUsername, string $result, string $analysisUrl): array
    {
        $whiteUsername = trim($whiteUsername);
        $blackUsername = trim($blackUsername);

        if ($whiteUsername === $blackUsername) {
            throw new \Exception('A player cannot play against themselves!');
        }

        $white = $this->getOrCreatePlayer($whiteUsername);
        $black = $this->getOrCreatePlayer($blackUsername);

        if ($white->getId() === $black->getId()) {
            throw new \Exception('White and Black players must be different!');
        }

        [$whiteResult, $blackResult] = $this->resultToScores($result);

        $whiteCalculation = $this->ratingCalculator->calculate($white->getRating(), $black->getRating(), $whiteResult);
        $blackCalculation = $this->ratingCalculator->calculate($black->getRating(), $white->getRating(), $blackResult);

        $oldWhiteRating = $white->getRating();
        $oldBlackRating = $black->getRating();

        $white->setRating((int) $whiteCalculation['new_rating']);
        $white->incrementGames();
        $black->setRating((int) $blackCalculation['new_rating']);
        $black->incrementGames();

        if ($result === self::WHITE_WIN) {
            $white->incrementWins();
            $black->incrementLosses();
        } elseif ($result === self::BLACK_WIN) {
            $white->incrementLosses();
            $black->incrementWins();
        } else {
            $white->incrementDraws();
            $black->incrementDraws();
        }

        $match = new ChessMatch(
            $this->matchRepository->nextId(),
            date('Y-m-d H:i:s'),
            $white->getId(),
            $black->getId(),
            $result,
            $analysisUrl,
            $oldWhiteRating,
            $oldBlackRating,
            $whiteCalculation['change'],
            $blackCalculation['change'],
        );

        $this->dm->persist($match);
        $this->dm->flush();

        return [
            'white' => [
                'username' => $whiteUsername,
                'old_rating' => $whiteCalculation['old_rating'],
                'new_rating' => $whiteCalculation['new_rating'],
                'change' => $whiteCalculation['change'],
                'expected' => $whiteCalculation['expected'],
            ],
            'black' => [
                'username' => $blackUsername,
                'old_rating' => $blackCalculation['old_rating'],
                'new_rating' => $blackCalculation['new_rating'],
                'change' => $blackCalculation['change'],
                'expected' => $blackCalculation['expected'],
            ],
            'result' => $result,
            'analysis_url' => $analysisUrl,
            'match_id' => $match->getId(),
            'is_valid' => true,
        ];
    }

    /**
     * @throws \Exception if the match doesn't exist, or is already invalid
     */
    public function invalidateMatch(int $matchId): array
    {
        $match = $this->matchRepository->findOneByAppId($matchId);
        if ($match === null) {
            throw new \Exception("Match #{$matchId} not found");
        }

        if (!$match->isValid()) {
            throw new \Exception("Match #{$matchId} is already marked as invalid");
        }

        return $this->toggleValidity($match, false);
    }

    /**
     * @throws \Exception if the match doesn't exist, or is not currently invalid
     */
    public function revalidateMatch(int $matchId): array
    {
        $match = $this->matchRepository->findOneByAppId($matchId);
        if ($match === null) {
            throw new \Exception("Match #{$matchId} not found");
        }

        if ($match->isValid()) {
            throw new \Exception("Match #{$matchId} is not marked as invalid");
        }

        return $this->toggleValidity($match, true);
    }

    private function toggleValidity(ChessMatch $match, bool $newValidity): array
    {
        $whiteBefore = $this->resolvePlayerSnapshot($match->getWhiteId(), $match->getOldWhiteRating(), $match->getRatingChangeWhite());
        $blackBefore = $this->resolvePlayerSnapshot($match->getBlackId(), $match->getOldBlackRating(), $match->getRatingChangeBlack());

        $match->setIsValid($newValidity);
        $match->setInvalidatedAt($newValidity ? null : date('Y-m-d H:i:s'));
        $this->dm->flush();

        $this->recalculateRatings();

        $whiteAfter = $this->resolvePlayerSnapshot($match->getWhiteId(), $match->getOldWhiteRating(), $match->getRatingChangeWhite());
        $blackAfter = $this->resolvePlayerSnapshot($match->getBlackId(), $match->getOldBlackRating(), $match->getRatingChangeBlack());

        $counts = [
            'total_valid_matches' => count($this->matchRepository->findValid()),
            'total_invalid_matches' => count($this->matchRepository->findInvalid()),
        ];

        $white = [
            'username' => $whiteBefore['username'],
            'rating_before' => $whiteBefore['rating'],
            'rating_after' => $whiteAfter['rating'],
            'change' => $whiteAfter['rating'] - $whiteBefore['rating'],
        ];
        $black = [
            'username' => $blackBefore['username'],
            'rating_before' => $blackBefore['rating'],
            'rating_after' => $blackAfter['rating'],
            'change' => $blackAfter['rating'] - $blackBefore['rating'],
        ];

        if ($newValidity) {
            return [
                'match_id' => $match->getId(),
                'is_valid' => true,
                'revalidated_at' => date('Y-m-d H:i:s'),
                'white' => $white,
                'black' => $black,
                'original_result' => $match->getResult(),
                'revalidated' => true,
                ...$counts,
            ];
        }

        return [
            'match_id' => $match->getId(),
            'is_valid' => false,
            'invalidated_at' => $match->getInvalidatedAt(),
            'white' => $white,
            'black' => $black,
            'original_result' => $match->getResult(),
            'restored' => true,
            ...$counts,
        ];
    }

    /**
     * Resolves a player's identity/rating for an invalidate/revalidate
     * response. Falls back to a frozen "DeletedPlayer#{id}" placeholder
     * using the rating this specific match recorded, for players removed
     * since the match was played.
     *
     * @return array{username: string, rating: int}
     */
    private function resolvePlayerSnapshot(int $playerId, int $oldRating, int $ratingChange): array
    {
        $player = $this->playerRepository->findOneByAppId($playerId);
        if ($player !== null) {
            return ['username' => $player->getUsername(), 'rating' => $player->getRating()];
        }

        return [
            'username' => "DeletedPlayer#{$playerId}",
            'rating' => $oldRating + $ratingChange,
        ];
    }

    public function removePlayer(string $username): bool
    {
        $player = $this->playerRepository->findOneByUsername(trim($username));
        if ($player === null) {
            return false;
        }

        $this->dm->remove($player);
        $this->dm->flush();

        return true;
    }

    /**
     * Folds a duplicate player into the player they duplicate: their matches, their
     * names and (only if the survivor has none) their VRChat link move across, the
     * duplicate's document is deleted, and every rating is then replayed from
     * scratch over the merged match set. The replay is what makes this correct —
     * the survivor ends up with the rating and record they would have had if the
     * duplicate had never existed, which adding two stat lines together would not
     * produce, since each match's rating delta depends on both players' ratings at
     * the time.
     *
     * This exists because AvatarRefresher::syncUsername() refuses to rename a
     * linked player onto a display name another player currently holds, and that
     * other player is almost always an unlinked duplicate of the same human. Until
     * the duplicate is merged away the rename is skipped on every refresh, forever.
     *
     * Matches the two played against each other become one player against
     * themselves. They are invalidated rather than deleted — the record is still
     * evidence of what happened, and invalid matches are excluded from the replay.
     *
     * @return array{
     *     from: string, into: string, matches_moved: int, head_to_head_invalidated: int,
     *     names_absorbed: string[], vrchat_link_moved: bool
     * }
     * @throws \DomainException if either player is missing, both are the same
     *   record, or the two are linked to different VRChat accounts (in which case
     *   they are two different people and must not be merged)
     */
    public function mergePlayers(string $fromUsername, string $intoUsername): array
    {
        $fromUsername = trim($fromUsername);
        $intoUsername = trim($intoUsername);

        $from = $this->playerRepository->findOneByCurrentUsername($fromUsername);
        if ($from === null) {
            throw new \DomainException("Pemain '{$fromUsername}' tidak ditemukan.");
        }

        $into = $this->playerRepository->findOneByCurrentUsername($intoUsername);
        if ($into === null) {
            throw new \DomainException("Pemain '{$intoUsername}' tidak ditemukan.");
        }

        if ($from->getId() === $into->getId()) {
            throw new \DomainException('Tidak bisa menggabungkan pemain dengan dirinya sendiri.');
        }

        $fromVrchatId = $from->getVrchatUserId();
        $intoVrchatId = $into->getVrchatUserId();
        if ($fromVrchatId !== null && $intoVrchatId !== null && $fromVrchatId !== $intoVrchatId) {
            throw new \DomainException(
                "'{$fromUsername}' dan '{$intoUsername}' tertaut ke akun VRChat yang berbeda, " .
                'jadi keduanya orang yang berbeda — bukan duplikat.',
            );
        }

        $matchesMoved = 0;
        $headToHeadInvalidated = 0;
        foreach ($this->matchRepository->findAll() as $match) {
            if (!$match->reassignPlayer($from->getId(), $into->getId())) {
                continue;
            }

            $matchesMoved++;

            if ($match->isSelfMatch() && $match->isValid()) {
                $match->setIsValid(false);
                $match->setInvalidatedAt(date('Y-m-d H:i:s'));
                $headToHeadInvalidated++;
            }
        }

        $historyBefore = $into->getPreviousUsernames();
        $into->absorbUsernamesFrom($from);
        $namesAbsorbed = array_values(array_diff($into->getPreviousUsernames(), $historyBefore));

        $vrchatLinkMoved = false;
        if ($intoVrchatId === null && $fromVrchatId !== null) {
            $into->setVrchatLink($fromVrchatId, $from->getVrchatDisplayName(), $from->getAvatarUrl());
            $vrchatLinkMoved = true;
        }

        // Flushed before the replay so it reads the reassigned matches and no longer
        // finds the duplicate to reset or credit.
        $this->dm->remove($from);
        $this->dm->flush();

        $this->recalculateRatings();

        return [
            'from' => $fromUsername,
            'into' => $into->getUsername(),
            'matches_moved' => $matchesMoved,
            'head_to_head_invalidated' => $headToHeadInvalidated,
            'names_absorbed' => $namesAbsorbed,
            'vrchat_link_moved' => $vrchatLinkMoved,
        ];
    }

    public function removeMatch(int $matchId): bool
    {
        $match = $this->matchRepository->findOneByAppId($matchId);
        if ($match === null) {
            return false;
        }

        $this->dm->remove($match);
        $this->dm->flush();

        $this->recalculateRatings();

        return true;
    }

    /**
     * Only supports the fields the app actually edits (rating, username) —
     * the legacy version's generic array_key_exists loop would also let a
     * caller silently overwrite id, which is dangerous and never exercised.
     *
     * Admin edits are for debugging, so a VRChat-linked player can be renamed
     * too; the next VRChat sync puts their real name back (both renames land
     * in username_history).
     *
     * @throws \DomainException when the new name belongs to another player,
     *   which the unique index on `username` would otherwise reject as a 500.
     */
    public function editPlayer(string $username, array $newData): bool
    {
        $player = $this->playerRepository->findOneByUsername(trim($username));
        if ($player === null) {
            return false;
        }

        $newUsername = array_key_exists('username', $newData) ? trim((string) $newData['username']) : null;
        if ($newUsername !== null && $newUsername !== '' && $newUsername !== $player->getUsername()) {
            if ($this->playerRepository->isUsernameTaken($newUsername)) {
                throw new \DomainException("Nama '{$newUsername}' sudah dipakai pemain lain.");
            }
            $player->rename($newUsername, 'admin');
        }

        if (array_key_exists('rating', $newData)) {
            $player->setRating((int) $newData['rating']);
        }

        $this->dm->flush();

        return true;
    }

    /**
     * Only supports the fields the app actually edits (result, analysis_url).
     */
    public function editMatch(int $matchId, array $newData): bool
    {
        $match = $this->matchRepository->findOneByAppId($matchId);
        if ($match === null) {
            return false;
        }

        if (array_key_exists('result', $newData)) {
            $match->setResult((string) $newData['result']);
        }
        if (array_key_exists('analysis_url', $newData)) {
            $match->setAnalysisUrl((string) $newData['analysis_url']);
        }

        $this->dm->flush();

        $this->recalculateRatings();

        return true;
    }

    public function getPlayerCount(): int
    {
        return count($this->playerRepository->findAll());
    }

    public function getMatchCount(): int
    {
        return count($this->matchRepository->findAll());
    }

    /**
     * @throws \Exception if the player is not found
     */
    public function getPlayerStats(string $username): array
    {
        $player = $this->playerRepository->findOneByUsername(trim($username));
        if ($player === null) {
            throw new \Exception("Player '{$username}' not found");
        }

        $belongsToPlayer = static fn (ChessMatch $m): bool
            => $m->getWhiteId() === $player->getId() || $m->getBlackId() === $player->getId();

        $validMatches = array_filter($this->matchRepository->findValid(), $belongsToPlayer);
        $invalidMatches = array_filter($this->matchRepository->findInvalid(), $belongsToPlayer);

        return [
            'player' => [
                'id' => $player->getId(),
                'username' => $player->getUsername(),
                'rating' => $player->getRating(),
                'games' => $player->getGames(),
                'wins' => $player->getWins(),
                'draws' => $player->getDraws(),
                'losses' => $player->getLosses(),
            ],
            'total_matches' => count($validMatches) + count($invalidMatches),
            'valid_matches' => count($validMatches),
            'invalid_matches' => count($invalidMatches),
            'current_rating' => $player->getRating(),
            'games' => $player->getGames(),
            'wins' => $player->getWins(),
            'draws' => $player->getDraws(),
            'losses' => $player->getLosses(),
        ];
    }

    /**
     * @return array{0: int|float, 1: int|float}
     * @throws \Exception if the result string isn't one of the three known constants
     */
    private function resultToScores(string $result): array
    {
        return match ($result) {
            self::WHITE_WIN => [RatingCalculator::WIN, RatingCalculator::LOSS],
            self::BLACK_WIN => [RatingCalculator::LOSS, RatingCalculator::WIN],
            self::DRAW => [RatingCalculator::DRAW, RatingCalculator::DRAW],
            default => throw new \Exception('Invalid result. Use WHITE_WIN, BLACK_WIN, or DRAW'),
        };
    }

    /**
     * Resets every player to the initial rating/stats, then replays every
     * valid match in chronological order. This full replay-from-scratch is
     * what makes invalidate/revalidate order-independent — see the class
     * docblock.
     */
    private function recalculateRatings(): void
    {
        $playersById = [];
        foreach ($this->playerRepository->findAll() as $player) {
            $player->resetRating();
            $playersById[$player->getId()] = $player;
        }

        // Dates have one-second precision, so two matches recorded in the same second
        // tie; the id (assigned in recording order) keeps the replay order fixed.
        $validMatches = $this->matchRepository->findValid();
        usort(
            $validMatches,
            static fn (ChessMatch $a, ChessMatch $b): int
                => [strtotime($a->getDate()), $a->getId()] <=> [strtotime($b->getDate()), $b->getId()],
        );

        foreach ($validMatches as $match) {
            $whitePlayer = $playersById[$match->getWhiteId()] ?? null;
            $blackPlayer = $playersById[$match->getBlackId()] ?? null;

            $whiteRating = $whitePlayer?->getRating() ?? $match->getOldWhiteRating();
            $blackRating = $blackPlayer?->getRating() ?? $match->getOldBlackRating();

            try {
                [$whiteResult, $blackResult] = $this->resultToScores(trim($match->getResult()));
            } catch (\Exception) {
                // Corrupt/unrecognized result string — skip rather than corrupt the replay.
                continue;
            }

            $whiteCalc = $this->ratingCalculator->calculate($whiteRating, $blackRating, $whiteResult);
            $blackCalc = $this->ratingCalculator->calculate($blackRating, $whiteRating, $blackResult);

            // Keep the record's before/±change in step with this replay; otherwise Match
            // History keeps showing the numbers from when the match was first recorded.
            $match->setReplayedRatings((int) $whiteRating, (int) $blackRating, $whiteCalc['change'], $blackCalc['change']);

            $whitePlayer?->setRating((int) $whiteCalc['new_rating']);
            $whitePlayer?->incrementGames();
            $blackPlayer?->setRating((int) $blackCalc['new_rating']);
            $blackPlayer?->incrementGames();

            $result = trim($match->getResult());
            if ($result === self::WHITE_WIN) {
                $whitePlayer?->incrementWins();
                $blackPlayer?->incrementLosses();
            } elseif ($result === self::BLACK_WIN) {
                $whitePlayer?->incrementLosses();
                $blackPlayer?->incrementWins();
            } else {
                $whitePlayer?->incrementDraws();
                $blackPlayer?->incrementDraws();
            }
        }

        $this->dm->flush();
    }
}

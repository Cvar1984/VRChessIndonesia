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

        $player = new Player($this->playerRepository->nextId(), $username);
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
     */
    public function editPlayer(string $username, array $newData): bool
    {
        $player = $this->playerRepository->findOneByUsername(trim($username));
        if ($player === null) {
            return false;
        }

        if (array_key_exists('rating', $newData)) {
            $player->setRating((int) $newData['rating']);
        }
        if (array_key_exists('username', $newData)) {
            $player->setUsername(trim((string) $newData['username']));
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

        $validMatches = $this->matchRepository->findValid();
        usort(
            $validMatches,
            static fn (ChessMatch $a, ChessMatch $b): int => strtotime($a->getDate()) <=> strtotime($b->getDate()),
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

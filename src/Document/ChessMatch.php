<?php

declare(strict_types=1);

namespace VRchessIndo\Document;

use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;
use VRchessIndo\Repository\ChessMatchRepository;

/**
 * Maps onto the existing `matches` collection. Named ChessMatch rather than
 * Match, since `Match` has been a reserved word since PHP 8.0.
 *
 * `white_id`/`black_id` reference Player::$id (the app-level int), not
 * Mongo's `_id` — same indirection the legacy schema already uses, so a
 * removed player's matches keep their historical id even after the player
 * document is gone (see MatchManager::removePlayer(), which never touches
 * match history).
 */
#[ODM\Document(collection: 'matches', repositoryClass: ChessMatchRepository::class)]
class ChessMatch
{
    #[ODM\Id]
    private ?string $mongoId = null;

    #[ODM\Field(type: 'int')]
    #[ODM\Index]
    private int $id;

    #[ODM\Field(type: 'string')]
    private string $date;

    #[ODM\Field(type: 'int', name: 'white_id')]
    private int $whiteId;

    #[ODM\Field(type: 'int', name: 'black_id')]
    private int $blackId;

    #[ODM\Field(type: 'string')]
    private string $result;

    #[ODM\Field(type: 'string', name: 'analysis_url')]
    private string $analysisUrl;

    #[ODM\Field(type: 'int', name: 'old_white_rating')]
    private int $oldWhiteRating;

    #[ODM\Field(type: 'int', name: 'old_black_rating')]
    private int $oldBlackRating;

    #[ODM\Field(type: 'int', name: 'rating_change_white')]
    private int $ratingChangeWhite;

    #[ODM\Field(type: 'int', name: 'rating_change_black')]
    private int $ratingChangeBlack;

    #[ODM\Field(type: 'bool', name: 'is_valid')]
    #[ODM\Index]
    private bool $isValid = true;

    #[ODM\Field(type: 'string', nullable: true, name: 'invalidated_at')]
    private ?string $invalidatedAt = null;

    public function __construct(
        int $id,
        string $date,
        int $whiteId,
        int $blackId,
        string $result,
        string $analysisUrl,
        int $oldWhiteRating,
        int $oldBlackRating,
        int $ratingChangeWhite,
        int $ratingChangeBlack,
    ) {
        $this->id = $id;
        $this->date = $date;
        $this->whiteId = $whiteId;
        $this->blackId = $blackId;
        $this->result = $result;
        $this->analysisUrl = $analysisUrl;
        $this->oldWhiteRating = $oldWhiteRating;
        $this->oldBlackRating = $oldBlackRating;
        $this->ratingChangeWhite = $ratingChangeWhite;
        $this->ratingChangeBlack = $ratingChangeBlack;
        $this->isValid = true;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getDate(): string
    {
        return $this->date;
    }

    public function getWhiteId(): int
    {
        return $this->whiteId;
    }

    public function getBlackId(): int
    {
        return $this->blackId;
    }

    public function getResult(): string
    {
        return $this->result;
    }

    public function setResult(string $result): void
    {
        $this->result = $result;
    }

    public function getAnalysisUrl(): string
    {
        return $this->analysisUrl;
    }

    public function setAnalysisUrl(string $analysisUrl): void
    {
        $this->analysisUrl = $analysisUrl;
    }

    public function getOldWhiteRating(): int
    {
        return $this->oldWhiteRating;
    }

    public function getOldBlackRating(): int
    {
        return $this->oldBlackRating;
    }

    public function getRatingChangeWhite(): int
    {
        return $this->ratingChangeWhite;
    }

    public function getRatingChangeBlack(): int
    {
        return $this->ratingChangeBlack;
    }

    public function isValid(): bool
    {
        return $this->isValid;
    }

    public function setIsValid(bool $isValid): void
    {
        $this->isValid = $isValid;
    }

    public function getInvalidatedAt(): ?string
    {
        return $this->invalidatedAt;
    }

    public function setInvalidatedAt(?string $invalidatedAt): void
    {
        $this->invalidatedAt = $invalidatedAt;
    }

    /**
     * Matches the legacy MongoDBDatabaseManager::loadMatches() shape, minus
     * restored_white_rating/restored_black_rating — those were always null
     * in every live record (nothing in MatchManager ever sets them) and
     * index.html never reads them, so they're dropped rather than carried
     * forward as dead fields.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date,
            'white_id' => $this->whiteId,
            'black_id' => $this->blackId,
            'result' => $this->result,
            'analysis_url' => $this->analysisUrl,
            'old_white_rating' => $this->oldWhiteRating,
            'old_black_rating' => $this->oldBlackRating,
            'rating_change_white' => $this->ratingChangeWhite,
            'rating_change_black' => $this->ratingChangeBlack,
            'is_valid' => $this->isValid,
            'invalidated_at' => $this->invalidatedAt,
        ];
    }
}

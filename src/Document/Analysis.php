<?php

declare(strict_types=1);

namespace VRchessIndo\Document;

use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;
use VRchessIndo\Repository\AnalysisRepository;

/**
 * Maps onto the existing `analyses` collection. `analysis` holds the
 * engine-evaluated position array as opaque, arbitrarily-shaped data (type
 * 'raw' — no coercion), exactly as the legacy manager stored/read it via
 * json_decode(json_encode(...)).
 */
#[ODM\Document(collection: 'analyses', repositoryClass: AnalysisRepository::class)]
class Analysis
{
    #[ODM\Id]
    private ?string $mongoId = null;

    #[ODM\Field(type: 'string')]
    #[ODM\UniqueIndex]
    private string $id;

    #[ODM\Field(type: 'string')]
    private string $pgn;

    #[ODM\Field(type: 'raw', nullable: true)]
    private mixed $analysis = null;

    #[ODM\Field(type: 'string', name: 'created_at')]
    private string $createdAt;

    #[ODM\Field(type: 'string', nullable: true, name: 'updated_at')]
    private ?string $updatedAt = null;

    public function __construct(string $id, string $pgn, ?array $analysis = null)
    {
        $this->id = $id;
        $this->pgn = $pgn;
        $this->analysis = $analysis;
        $this->createdAt = date('Y-m-d H:i:s');
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getPgn(): string
    {
        return $this->pgn;
    }

    public function getAnalysis(): mixed
    {
        return $this->analysis;
    }

    public function setAnalysis(array $analysis): void
    {
        $this->analysis = $analysis;
        $this->updatedAt = date('Y-m-d H:i:s');
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    /**
     * Matches legacy MongoDBDatabaseManager::getAnalysis() — full record
     * including the (possibly heavy) analysis positions array, when present.
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'pgn' => $this->pgn,
            'created_at' => $this->createdAt,
        ];

        if ($this->analysis !== null) {
            $data['analysis'] = $this->analysis;
        }

        return $data;
    }

    /**
     * Matches legacy MongoDBDatabaseManager::getAllAnalyses() — a lightweight
     * list-view shape: PGN headers extracted, a short move-text preview
     * instead of the full PGN, and no analysis positions array at all.
     */
    public function toPreviewArray(): array
    {
        $rawPgn = trim($this->pgn);

        $headers = [];
        if (preg_match_all('/\[([A-Za-z0-9_]+)\s+"([^"]*)"\]/', $rawPgn, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $headers[$match[1]] = $match[2];
            }
        }

        $pgnPreview = preg_replace('/\[.*?\]\s*/s', '', $rawPgn);
        $pgnPreview = mb_substr(trim($pgnPreview), 0, 100) . (mb_strlen($pgnPreview) > 100 ? '...' : '');

        return [
            'id' => $this->id,
            'created_at' => $this->createdAt,
            'pgn_preview' => $pgnPreview,
            'headers' => $headers,
            // Lets the admin panel find saved games that still need a Game Review
            // without fetching every full analysis.
            'analyzed' => !empty($this->analysis),
            'updated_at' => $this->updatedAt,
            'depth' => $this->depthSummary(),
        ];
    }

    /**
     * Search depth of the stored evaluations — the admin panel lists it so shallow
     * reviews can be redone. Null when no position records a depth (no analysis yet,
     * or one saved before depths were kept); game-over positions report none.
     *
     * @return array{min: int, avg: float}|null
     */
    private function depthSummary(): ?array
    {
        $depths = [];
        foreach (is_array($this->analysis) ? $this->analysis : [] as $position) {
            $depth = is_array($position) ? ($position['depth'] ?? null) : null;
            if (is_int($depth) && $depth > 0) {
                $depths[] = $depth;
            }
        }

        return $depths === [] ? null : ['min' => min($depths), 'avg' => round(array_sum($depths) / count($depths), 1)];
    }
}

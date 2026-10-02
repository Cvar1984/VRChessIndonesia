<?php

declare(strict_types=1);

namespace VRchessIndo\Controller;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use VRchessIndo\Document\Analysis;
use VRchessIndo\Repository\AnalysisRepository;

/**
 * Saved PGN analyses (the "Analysis" tab's persistence + the Submit Match
 * tab's PGN-to-analysis-URL flow). All public except delete — matches
 * legacy exactly: index.php never gates save/get/update behind any auth,
 * only delete-analysis calls requireApiAccess().
 */
class AnalysisController extends AbstractApiController
{
    // Both endpoints are public, so both are size-capped. A position entry is ~0.5 KB
    // and real games stay far below 1000 plies; 2000 leaves room for very long ones.
    private const int MAX_POSITIONS = 2000;
    private const int MAX_PGN_BYTES = 200_000;

    public function __construct(
        private readonly AnalysisRepository $analyses,
        private readonly DocumentManager $dm,
    ) {
    }

    #[Route('/api/analyses', name: 'api_analyses_save', methods: ['POST'])]
    public function save(Request $request): JsonResponse
    {
        $input = json_decode($request->getContent(), true) ?? [];
        $pgn = (string) ($input['pgn'] ?? '');
        $analysisData = $input['analysis'] ?? null;

        if ($pgn === '') {
            return $this->json(['success' => false, 'error' => 'PGN required'], 400);
        }
        if (strlen($pgn) > self::MAX_PGN_BYTES) {
            return $this->json(['success' => false, 'error' => 'PGN terlalu besar'], 400);
        }
        if (is_array($analysisData) && ($error = $this->invalidPositions($analysisData)) !== null) {
            return $this->json(['success' => false, 'error' => $error], 400);
        }

        $analysis = new Analysis(bin2hex(random_bytes(8)), $pgn, is_array($analysisData) ? $analysisData : null);
        $this->dm->persist($analysis);
        $this->dm->flush();

        return $this->json(['success' => true, 'id' => $analysis->getId()]);
    }

    #[Route('/api/analyses', name: 'api_analyses_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $analyses = array_map(
            static fn (Analysis $a): array => $a->toPreviewArray(),
            $this->analyses->findAllSortedByCreatedAtDesc(),
        );

        return $this->json(['success' => true, 'analyses' => $analyses]);
    }

    #[Route('/api/analyses/{id}', name: 'api_analyses_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $analysis = $this->analyses->findOneById($id);
        if ($analysis === null) {
            return $this->json(['success' => false, 'error' => 'Analysis not found'], 404);
        }

        return $this->json(['success' => true, 'data' => $analysis->toArray()]);
    }

    #[Route('/api/analyses/{id}', name: 'api_analyses_update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $input = json_decode($request->getContent(), true) ?? [];
        $analysisData = $input['analysis'] ?? null;

        if (empty($analysisData) || !is_array($analysisData)) {
            return $this->json(['success' => false, 'error' => 'analysis array required'], 400);
        }

        $analysis = $this->analyses->findOneById($id);
        if ($analysis === null) {
            // Matches legacy exactly: {success:false} with a 200 status,
            // since updateAnalysis() just returns bool and index.php never
            // sets a custom status code for this action.
            return $this->json(['success' => false]);
        }

        if (($error = $this->invalidPositions($analysisData)) !== null) {
            return $this->json(['success' => false, 'error' => $error], 400);
        }

        // Anyone may backfill a saved game that has no evaluations yet — that's how a
        // shared link without them gets filled in by its first visitor's Game Review.
        // Replacing existing evaluations needs an API token: they feed every viewer's
        // review and each player's profile accuracy/Brilliant stats.
        if (!empty($analysis->getAnalysis()) && ($response = $this->requireApiAccess())) {
            return $response;
        }

        $analysis->setAnalysis($analysisData);
        $this->dm->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/api/analyses/{id}', name: 'api_analyses_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        if ($response = $this->requireApiAccess()) {
            return $response;
        }

        $analysis = $this->analyses->findOneById($id);
        if ($analysis === null) {
            return $this->json(['success' => false, 'error' => 'Analysis not found'], 404);
        }

        $this->dm->remove($analysis);
        $this->dm->flush();

        return $this->json(['success' => true, 'message' => "Analisis {$id} telah dihapus."]);
    }

    /** Null when $positions is a plausible list of position entries, else the error. */
    private function invalidPositions(array $positions): ?string
    {
        if (!array_is_list($positions) || count($positions) > self::MAX_POSITIONS) {
            return 'analysis harus berupa daftar posisi (maks. ' . self::MAX_POSITIONS . ')';
        }
        foreach ($positions as $position) {
            if (!is_array($position) || !is_string($position['fen'] ?? null)) {
                return 'setiap posisi harus berupa objek dengan fen';
            }
        }

        return null;
    }
}

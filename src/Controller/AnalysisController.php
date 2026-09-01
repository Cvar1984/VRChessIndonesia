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
}

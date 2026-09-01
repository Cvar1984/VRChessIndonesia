<?php

declare(strict_types=1);

namespace VRchessIndo\Controller;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use VRchessIndo\Document\Analysis;
use VRchessIndo\Document\ChessMatch;
use VRchessIndo\Repository\AnalysisRepository;
use VRchessIndo\Service\MatchManager;

/**
 * Match read + mutation endpoints. Read response shapes match the legacy
 * GET /index.php?matches=1, ?valid-matches=1 and ?invalid-matches=1 exactly
 * (verified by diffing against the live legacy endpoints on the same data),
 * minus the always-null restored_white_rating/restored_black_rating fields
 * — see ChessMatch::toArray(). Mutation shapes match ?play / ?invalidate /
 * ?revalidate / DELETE ?match / PATCH ?match.
 */
class ChessMatchController extends AbstractApiController
{
    public function __construct(
        private readonly MatchManager $manager,
        private readonly AnalysisRepository $analyses,
        private readonly DocumentManager $dm,
    ) {
    }

    #[Route('/api/matches', name: 'api_matches', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $matches = $this->manager->getMatches();

        return $this->json([
            'success' => true,
            'count' => $this->manager->getMatchCount(),
            'matches' => $this->toArrayList($matches),
        ]);
    }

    #[Route('/api/matches/valid', name: 'api_matches_valid', methods: ['GET'])]
    public function valid(): JsonResponse
    {
        $matches = $this->manager->getValidMatches();

        return $this->json([
            'success' => true,
            'count' => count($matches),
            'matches' => $this->toArrayList($matches),
        ]);
    }

    #[Route('/api/matches/invalid', name: 'api_matches_invalid', methods: ['GET'])]
    public function invalid(): JsonResponse
    {
        $matches = $this->manager->getInvalidMatches();

        return $this->json([
            'success' => true,
            'count' => count($matches),
            'matches' => $this->toArrayList($matches),
        ]);
    }

    #[Route('/api/matches', name: 'api_matches_play', methods: ['POST'])]
    public function play(Request $request): JsonResponse
    {
        if ($response = $this->requireApiAccess()) {
            return $response;
        }

        $input = json_decode($request->getContent(), true) ?? [];

        $white = trim((string) ($input['white'] ?? $request->query->get('white') ?? ''));
        $black = trim((string) ($input['black'] ?? $request->query->get('black') ?? ''));
        $rawResult = (string) ($input['result'] ?? $request->query->get('result') ?? '');
        $pgn = trim((string) ($input['pgn'] ?? ''));
        $analysisUrl = trim((string) ($input['url'] ?? $input['analysis_url'] ?? $request->query->get('url') ?? ''));

        if ($white === '' || $black === '') {
            return $this->json(['success' => false, 'error' => 'Nama pemain tidak boleh kosong'], 400);
        }

        // Priority: PGN > URL > blank. If PGN is supplied, save it as an
        // analysis and use the returned ID as the match's analysis_url.
        if ($pgn !== '') {
            $analysis = new Analysis(bin2hex(random_bytes(8)), $pgn);
            $this->dm->persist($analysis);
            $this->dm->flush();
            $analysisUrl = $analysis->getId();
        }

        $result = match ($rawResult) {
            '1', '1-0' => MatchManager::WHITE_WIN,
            '0', '1/2-1/2' => MatchManager::DRAW,
            '-1', '0-1' => MatchManager::BLACK_WIN,
            default => null,
        };

        if ($result === null) {
            return $this->json([
                'success' => false,
                'error' => 'Nilai result tidak valid (gunakan 1 untuk White, 0 untuk Draw, -1 untuk Black)',
            ], 400);
        }

        try {
            $matchResult = $this->manager->play($white, $black, $result, $analysisUrl);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return $this->json([
            'success' => true,
            'message' => 'Pertandingan berhasil dicatat!',
            'match' => $matchResult,
        ]);
    }

    #[Route('/api/matches/{id}/invalidate', name: 'api_matches_invalidate', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function invalidateMatch(int $id): JsonResponse
    {
        if ($response = $this->requireApiAccess()) {
            return $response;
        }

        try {
            $result = $this->manager->invalidateMatch($id);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return $this->json([
            'success' => true,
            'message' => "Pertandingan #{$id} berhasil di-anulir (invalidated)",
            'data' => $result,
        ]);
    }

    #[Route('/api/matches/{id}/revalidate', name: 'api_matches_revalidate', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function revalidateMatch(int $id): JsonResponse
    {
        if ($response = $this->requireApiAccess()) {
            return $response;
        }

        try {
            $result = $this->manager->revalidateMatch($id);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return $this->json([
            'success' => true,
            'message' => "Pertandingan #{$id} berhasil dipulihkan (revalidated)",
            'data' => $result,
        ]);
    }

    #[Route('/api/matches/{id}', name: 'api_matches_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        if ($response = $this->requireApiAccess()) {
            return $response;
        }

        // Fetch the match first so we can cascade-delete its analysis if it's internal.
        $matchToDelete = $this->manager->getMatch($id);
        $deleted = $this->manager->removeMatch($id);

        if ($deleted && $matchToDelete !== null) {
            $analysisUrl = trim($matchToDelete->getAnalysisUrl());
            if ($analysisUrl !== '') {
                if (preg_match('/^[a-f0-9]{8,}$/i', $analysisUrl)) {
                    $this->deleteAnalysisById($analysisUrl);
                } elseif (preg_match('/[?&]analysis=([a-f0-9]+)/i', $analysisUrl, $m)) {
                    $this->deleteAnalysisById($m[1]);
                }
            }
        }

        return $this->json([
            'success' => $deleted,
            'message' => $deleted ? "Pertandingan #{$id} telah dihapus." : 'Pertandingan tidak ditemukan.',
        ], $deleted ? 200 : 404);
    }

    #[Route('/api/matches/{id}', name: 'api_matches_edit', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): JsonResponse
    {
        if ($response = $this->requireApiAccess()) {
            return $response;
        }

        $input = json_decode($request->getContent(), true) ?? [];
        $newData = [];
        if (isset($input['result'])) {
            $newData['result'] = $input['result'];
        }
        if (isset($input['analysis_url'])) {
            $newData['analysis_url'] = $input['analysis_url'];
        }

        if ($newData === []) {
            return $this->json(['success' => false, 'error' => 'Tidak ada data untuk diperbarui'], 400);
        }

        $updated = $this->manager->editMatch($id, $newData);

        return $this->json([
            'success' => $updated,
            'message' => $updated ? "Pertandingan #{$id} berhasil diperbarui." : 'Pertandingan tidak ditemukan.',
        ], $updated ? 200 : 404);
    }

    private function deleteAnalysisById(string $id): void
    {
        $analysis = $this->analyses->findOneById($id);
        if ($analysis !== null) {
            $this->dm->remove($analysis);
            $this->dm->flush();
        }
    }

    /**
     * @param ChessMatch[] $matches
     */
    private function toArrayList(array $matches): array
    {
        return array_map(static fn (ChessMatch $m): array => $m->toArray(), $matches);
    }
}

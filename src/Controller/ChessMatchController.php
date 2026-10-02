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

        if (!$this->isAllowedAnalysisUrl($analysisUrl)) {
            return $this->json(['success' => false, 'error' => 'analysis_url harus berupa ID analisis internal atau URL http(s)://'], 400);
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

        // Priority: PGN > URL > blank. A supplied PGN is saved as an analysis whose id
        // becomes the match's analysis_url — but only once the match itself has been
        // recorded, so a rejected submission (bad result, self-play) leaves no orphan.
        $analysisId = $pgn !== '' ? bin2hex(random_bytes(8)) : null;

        try {
            $matchResult = $this->manager->play($white, $black, $result, $analysisId ?? $analysisUrl);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        if ($analysisId !== null) {
            $this->dm->persist(new Analysis($analysisId, $pgn));
            $this->dm->flush();
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
            // Same enum as play() — unvalidated free text here previously let
            // an API-token-only caller (ROLE_API_TOKEN, not ROLE_ADMIN) stash
            // arbitrary JS in a value the SPA later interpolates unescaped
            // into an onclick="..." attribute for admins viewing the match
            // list (stored XSS -> admin session takeover).
            $result = match ((string) $input['result']) {
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

            $newData['result'] = $result;
        }

        if (isset($input['analysis_url'])) {
            $analysisUrl = trim((string) $input['analysis_url']);
            if (!$this->isAllowedAnalysisUrl($analysisUrl)) {
                return $this->json([
                    'success' => false,
                    'error' => 'analysis_url harus berupa ID analisis internal atau URL http(s)://',
                ], 400);
            }

            $newData['analysis_url'] = $analysisUrl;
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

    /**
     * Blank, our own internal analysis id (hex), or a genuine http(s) URL (an
     * external chess.com/chessigma link) — never a javascript:/data: URI, which
     * the SPA renders into an <a href> for every visitor. Shared by play() and
     * edit() so the two can't drift apart again.
     */
    private function isAllowedAnalysisUrl(string $url): bool
    {
        return $url === ''
            || preg_match('/^[a-f0-9]{8,}$/i', $url) === 1
            || preg_match('#^https?://\S+$#i', $url) === 1;
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

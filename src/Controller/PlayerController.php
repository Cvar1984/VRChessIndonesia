<?php

declare(strict_types=1);

namespace VRchessIndo\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use VRchessIndo\Document\Player;
use VRchessIndo\Service\MatchManager;

/**
 * Player read + mutation endpoints. Read response shapes match the legacy
 * GET /index.php?players=1, ?rankings=1 and ?player-stats=1&username=...
 * exactly (verified by diffing against the live legacy endpoints on the
 * same data) — only the URLs are new, matching the clean-route convention
 * Phase 0 already established with /api/players. Mutation shapes match
 * DELETE ?player / PATCH ?player.
 */
class PlayerController extends AbstractApiController
{
    public function __construct(private readonly MatchManager $manager)
    {
    }

    #[Route('/api/players', name: 'api_players', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $players = $this->manager->getPlayers();

        return $this->json([
            'success' => true,
            'count' => $this->manager->getPlayerCount(),
            'players' => array_map(static fn (Player $p): array => $p->toArray(), $players),
        ]);
    }

    #[Route('/api/rankings', name: 'api_rankings', methods: ['GET'])]
    public function rankings(): JsonResponse
    {
        $players = $this->manager->getPlayers();

        return $this->json([
            'success' => true,
            'rankings' => array_map(static fn (Player $p): array => $p->toArray(), $players),
        ]);
    }

    #[Route('/api/players/{username}/stats', name: 'api_player_stats', methods: ['GET'])]
    public function stats(string $username): JsonResponse
    {
        try {
            $stats = $this->manager->getPlayerStats($username);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 404);
        }

        return $this->json(['success' => true, 'stats' => $stats]);
    }

    #[Route('/api/players/{username}', name: 'api_players_delete', methods: ['DELETE'])]
    public function delete(string $username): JsonResponse
    {
        if ($response = $this->requireApiAccess()) {
            return $response;
        }

        $username = trim($username);
        $deleted = $this->manager->removePlayer($username);

        return $this->json([
            'success' => $deleted,
            'message' => $deleted ? "Pemain '{$username}' telah dihapus." : 'Pemain tidak ditemukan.',
        ], $deleted ? 200 : 404);
    }

    #[Route('/api/players/{username}', name: 'api_players_edit', methods: ['PATCH'])]
    public function edit(string $username, Request $request): JsonResponse
    {
        if ($response = $this->requireApiAccess()) {
            return $response;
        }

        $username = trim($username);
        $input = json_decode($request->getContent(), true) ?? [];

        $newData = [];
        if (isset($input['rating'])) {
            $newData['rating'] = (int) $input['rating'];
        }
        if (isset($input['username'])) {
            $newData['username'] = trim((string) $input['username']);
        }

        if ($newData === []) {
            return $this->json(['success' => false, 'error' => 'Tidak ada data untuk diperbarui'], 400);
        }

        $updated = $this->manager->editPlayer($username, $newData);

        return $this->json([
            'success' => $updated,
            'message' => $updated ? "Pemain '{$username}' berhasil diperbarui." : 'Pemain tidak ditemukan.',
        ], $updated ? 200 : 404);
    }
}

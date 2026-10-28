<?php

declare(strict_types=1);

namespace VRchessIndo\Controller;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use VRchessIndo\Document\Player;
use VRchessIndo\Repository\PlayerRepository;
use VRchessIndo\Service\MatchManager;
use VRchessIndo\Service\VRChat\AvatarRefresher;

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
    public function __construct(
        private readonly MatchManager $manager,
        private readonly PlayerRepository $players,
        private readonly AvatarRefresher $avatarRefresher,
        private readonly DocumentManager $dm,
    ) {
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

    /**
     * Folds the duplicate player `from` into `into`, then — since that is the whole
     * point — immediately retries the VRChat rename the duplicate was blocking, so
     * one call leaves the survivor already carrying their VRChat display name.
     *
     * Admin-only rather than requireApiAccess() like the other mutations: it merges
     * two records irreversibly and replays every rating, which is not something a
     * match-reporting bot's token should be able to do.
     */
    #[Route('/api/admin/players/merge', name: 'api_players_merge', methods: ['POST'])]
    public function merge(Request $request): JsonResponse
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $input = json_decode($request->getContent(), true) ?? [];
        $from = trim((string) ($input['from'] ?? ''));
        $into = trim((string) ($input['into'] ?? ''));

        if ($from === '' || $into === '') {
            return $this->json(['success' => false, 'error' => 'from dan into diperlukan'], 400);
        }

        try {
            $result = $this->manager->mergePlayers($from, $into);
        } catch (\DomainException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 409);
        }

        // With the duplicate gone the display name is free, so the rename that kept
        // being skipped can finally go through. A survivor with no VRChat link just
        // reports 'unchanged'.
        $survivor = $this->players->findOneByCurrentUsername($result['into']);
        $nameStatus = $survivor !== null ? $this->avatarRefresher->syncUsername($survivor) : 'unchanged';
        if ($nameStatus === 'renamed') {
            $this->dm->flush();
            $result['into'] = $survivor->getUsername();
        }

        $message = "'{$from}' digabungkan ke '{$result['into']}': {$result['matches_moved']} match dipindahkan";
        if ($result['head_to_head_invalidated'] > 0) {
            $message .= ", {$result['head_to_head_invalidated']} match antar-keduanya dibatalkan";
        }
        if ($result['vrchat_link_moved']) {
            $message .= ', tautan VRChat dipindahkan';
        }
        if ($nameStatus === 'renamed') {
            $message .= ", nama kini mengikuti VRChat: '{$result['into']}'";
        }

        return $this->json([
            'success' => true,
            'message' => $message . '.',
            'name_status' => $nameStatus,
            ...$result,
        ]);
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

        try {
            $updated = $this->manager->editPlayer($username, $newData);
        } catch (\DomainException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 409);
        }

        return $this->json([
            'success' => $updated,
            'message' => $updated ? "Pemain '{$username}' berhasil diperbarui." : 'Pemain tidak ditemukan.',
        ], $updated ? 200 : 404);
    }
}

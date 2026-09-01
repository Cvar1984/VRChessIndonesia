<?php

declare(strict_types=1);

namespace VRchessIndo\Controller;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use VRchessIndo\Repository\PlayerRepository;
use VRchessIndo\Service\VRChat\VRChatClientFactory;

/**
 * Admin-only VRChat profile linking. Response shapes match the legacy
 * ?vrchat-search / link-vrchat / unlink-vrchat / refresh-vrchat-avatars
 * actions. Every action builds a fresh VRChatClient inline (via the
 * factory) and catches \Throwable itself, exactly like the legacy
 * buildVrchatClient($db) call sites — a VRChat outage or misconfiguration
 * degrades to a clean 502 on these specific actions, not a hard failure.
 */
class VRChatController extends AbstractApiController
{
    public function __construct(
        private readonly VRChatClientFactory $clientFactory,
        private readonly PlayerRepository $players,
        private readonly DocumentManager $dm,
    ) {
    }

    #[Route('/api/admin/vrchat/search', name: 'api_vrchat_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $query = trim((string) $request->query->get('q', ''));
        if ($query === '') {
            return $this->json(['success' => false, 'error' => 'Parameter q (kata kunci pencarian) diperlukan'], 400);
        }

        try {
            $results = $this->clientFactory->build()->searchUsers($query, 10);
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 502);
        }

        return $this->json(['success' => true, 'results' => $results]);
    }

    #[Route('/api/admin/vrchat/link', name: 'api_vrchat_link', methods: ['POST'])]
    public function link(Request $request): JsonResponse
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $input = json_decode($request->getContent(), true) ?? [];
        $username = trim((string) ($input['username'] ?? ''));
        $vrchatUserId = trim((string) ($input['vrchat_user_id'] ?? ''));

        if ($username === '' || $vrchatUserId === '') {
            return $this->json(['success' => false, 'error' => 'username dan vrchat_user_id diperlukan'], 400);
        }

        $player = $this->players->findOneByUsername($username);
        if ($player === null) {
            return $this->json(['success' => false, 'error' => "Pemain '{$username}' tidak ditemukan"], 404);
        }

        try {
            $vrchatUser = $this->clientFactory->build()->getUser($vrchatUserId);
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 502);
        }

        if ($vrchatUser === null) {
            return $this->json(['success' => false, 'error' => 'Akun VRChat tidak ditemukan'], 404);
        }

        $player->setVrchatLink($vrchatUser['id'], $vrchatUser['displayName'], $vrchatUser['avatarUrl']);
        $this->dm->flush();

        return $this->json([
            'success' => true,
            'message' => "'{$username}' berhasil ditautkan ke VRChat '{$vrchatUser['displayName']}'.",
            'vrchat' => $vrchatUser,
        ]);
    }

    #[Route('/api/admin/vrchat/unlink', name: 'api_vrchat_unlink', methods: ['POST'])]
    public function unlink(Request $request): JsonResponse
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $input = json_decode($request->getContent(), true) ?? [];
        $username = trim((string) ($input['username'] ?? ''));

        if ($username === '') {
            return $this->json(['success' => false, 'error' => 'username diperlukan'], 400);
        }

        $player = $this->players->findOneByUsername($username);
        if ($player === null) {
            return $this->json(['success' => false, 'message' => 'Pemain tidak ditemukan.'], 404);
        }

        $player->clearVrchatLink();
        $this->dm->flush();

        return $this->json(['success' => true, 'message' => "Tautan VRChat untuk '{$username}' dihapus."]);
    }

    #[Route('/api/admin/vrchat/refresh-avatars', name: 'api_vrchat_refresh_avatars', methods: ['POST'])]
    public function refreshAvatars(Request $request): JsonResponse
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $input = json_decode($request->getContent(), true) ?? [];
        $force = !empty($input['force']);
        $ttlSeconds = 24 * 60 * 60;

        try {
            $client = $this->clientFactory->build();
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 502);
        }

        $refreshed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($this->players->findAll() as $player) {
            if ($player->getVrchatUserId() === null) {
                continue;
            }

            $cachedAt = $player->getAvatarCachedAt() !== null ? strtotime($player->getAvatarCachedAt()) : false;
            if (!$force && $cachedAt !== false && (time() - $cachedAt) < $ttlSeconds) {
                $skipped++;
                continue;
            }

            try {
                $vrchatUser = $client->getUser($player->getVrchatUserId());
                $player->updateAvatarCache($vrchatUser !== null ? $vrchatUser['avatarUrl'] : null);
                $refreshed++;
            } catch (\Throwable) {
                $failed++;
            }
        }

        $this->dm->flush();

        return $this->json([
            'success' => true,
            'message' => "Selesai: {$refreshed} diperbarui, {$skipped} dilewati (masih baru), {$failed} gagal.",
            'refreshed' => $refreshed,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);
    }
}

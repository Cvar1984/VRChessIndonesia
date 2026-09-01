<?php

declare(strict_types=1);

namespace VRchessIndo\Controller;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use VRchessIndo\Document\ApiToken;
use VRchessIndo\Repository\ApiTokenRepository;

/**
 * Admin-only API token management. Response shapes match the legacy
 * ?tokens=1 / ?create-token / ?update-token / ?revoke-token endpoints.
 */
class ApiTokenController extends AbstractApiController
{
    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly DocumentManager $dm,
    ) {
    }

    #[Route('/api/admin/tokens', name: 'api_admin_tokens_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        return $this->json([
            'success' => true,
            'tokens' => array_map(
                static fn (ApiToken $t): array => $t->toArray(),
                $this->tokens->findAllSortedByCreatedAtDesc(),
            ),
        ]);
    }

    #[Route('/api/admin/tokens', name: 'api_admin_tokens_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $input = json_decode($request->getContent(), true) ?? [];
        $name = trim((string) ($input['name'] ?? $request->query->get('name') ?? 'API Token Baru'));

        $token = new ApiToken(
            'tok_' . bin2hex(random_bytes(8)),
            $name !== '' ? $name : 'API Token Baru',
            'vrchess_pat_' . bin2hex(random_bytes(16)),
        );
        $this->dm->persist($token);
        $this->dm->flush();

        return $this->json([
            'success' => true,
            'message' => "API Token '{$token->getName()}' berhasil dibuat!",
            'token' => $token->toArray(),
        ]);
    }

    #[Route('/api/admin/tokens/{id}', name: 'api_admin_tokens_update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $input = json_decode($request->getContent(), true) ?? [];
        $newName = (string) ($input['name'] ?? '');
        $isActive = (bool) ($input['is_active'] ?? true);

        $token = $this->tokens->findOneByIdOrToken($id);
        if ($token === null) {
            return $this->json(['success' => false, 'message' => 'Token tidak ditemukan atau tidak ada perubahan.'], 404);
        }

        $token->setName(trim($newName));
        $token->setIsActive($isActive);
        $this->dm->flush();

        return $this->json(['success' => true, 'message' => 'API Token berhasil diperbarui.']);
    }

    #[Route('/api/admin/tokens/{id}', name: 'api_admin_tokens_revoke', methods: ['DELETE'])]
    public function revoke(string $id): JsonResponse
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $token = $this->tokens->findOneByIdOrToken($id);
        if ($token === null) {
            return $this->json(['success' => false, 'message' => 'Token tidak ditemukan.'], 404);
        }

        $this->dm->remove($token);
        $this->dm->flush();

        return $this->json(['success' => true, 'message' => 'API Token berhasil dicabut/dihapus.']);
    }
}

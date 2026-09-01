<?php

declare(strict_types=1);

namespace VRchessIndo\Controller;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use VRchessIndo\Document\Admin;
use VRchessIndo\Repository\AdminRepository;

/**
 * Admin-only management of admin accounts themselves. Response shapes match
 * the legacy ?admins=1 / create-admin / update-admin / delete-admin actions.
 */
class AdminManagementController extends AbstractApiController
{
    public function __construct(
        private readonly AdminRepository $admins,
        private readonly DocumentManager $dm,
    ) {
    }

    #[Route('/api/admin/admins', name: 'api_admin_admins_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        return $this->json([
            'success' => true,
            'admins' => array_map(
                static fn (Admin $a): array => $a->toArray(),
                $this->admins->findAllSortedByCreatedAt(),
            ),
        ]);
    }

    #[Route('/api/admin/admins', name: 'api_admin_admins_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $input = json_decode($request->getContent(), true) ?? [];
        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if ($username === '' || $password === '' || \strlen($password) < 4) {
            return $this->json(['success' => false, 'error' => 'Username dan password (min 4 kar) diperlukan.'], 400);
        }

        if ($this->admins->findOneByUsername($username) !== null) {
            return $this->json(['success' => false, 'error' => "Gagal membuat admin. Username mungkin sudah ada."], 400);
        }

        $admin = new Admin($username, password_hash($password, PASSWORD_BCRYPT));
        $this->dm->persist($admin);
        $this->dm->flush();

        return $this->json(['success' => true, 'message' => "Admin '{$username}' berhasil ditambahkan!"]);
    }

    #[Route('/api/admin/admins/{username}', name: 'api_admin_admins_update', methods: ['PATCH'])]
    public function update(string $username, Request $request): JsonResponse
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $input = json_decode($request->getContent(), true) ?? [];
        $newPassword = (string) ($input['password'] ?? '');

        if ($newPassword === '' || \strlen($newPassword) < 4) {
            return $this->json(['success' => false, 'error' => 'Password baru (min 4 kar) diperlukan.'], 400);
        }

        $admin = $this->admins->findOneByUsername(trim($username));
        if ($admin === null) {
            return $this->json(['success' => false, 'error' => 'Admin tidak ditemukan atau gagal diperbarui.'], 400);
        }

        $admin->setPassword(password_hash($newPassword, PASSWORD_BCRYPT));
        $this->dm->flush();

        return $this->json(['success' => true, 'message' => "Password admin '{$admin->getUsername()}' berhasil diperbarui!"]);
    }

    #[Route('/api/admin/admins/{username}', name: 'api_admin_admins_delete', methods: ['DELETE'])]
    public function delete(string $username): JsonResponse
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $username = trim($username);

        $currentUser = $this->getUser();
        $currentUsername = $currentUser instanceof Admin ? $currentUser->getUsername() : null;
        if ($username === $currentUsername) {
            return $this->json(['success' => false, 'error' => 'Tidak bisa menghapus diri sendiri.'], 400);
        }

        if (\count($this->admins->findAll()) <= 1) {
            return $this->json(['success' => false, 'error' => 'Tidak bisa menghapus admin terakhir.'], 400);
        }

        $admin = $this->admins->findOneByUsername($username);
        if ($admin === null) {
            return $this->json(['success' => false, 'error' => 'Admin tidak ditemukan.'], 404);
        }

        $this->dm->remove($admin);
        $this->dm->flush();

        return $this->json(['success' => true, 'message' => "Admin '{$username}' berhasil dihapus!"]);
    }
}

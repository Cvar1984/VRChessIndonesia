<?php

declare(strict_types=1);

namespace VRchessIndo\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Mirrors legacy index.php's requireAdmin($db)/requireApiAccess($db): each
 * mutating action calls one of these as its first line and returns early on
 * a non-null response, exactly like the legacy functions terminating via
 * jsonResponse(...). Kept as explicit in-controller checks (rather than
 * routing-layer #[IsGranted]/access_control) specifically so the JSON error
 * bodies match the legacy text byte-for-byte.
 *
 * ROLE_API_TOKEN is satisfied by either a valid API token or an admin
 * session/header (role_hierarchy: ROLE_ADMIN => ROLE_API_TOKEN in
 * security.yaml), matching requireApiAccess()'s "isAdmin() OR valid token".
 */
abstract class AbstractApiController extends AbstractController
{
    protected function requireAdmin(): ?JsonResponse
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return null;
        }

        return $this->json([
            'success' => false,
            'error' => 'Akses ditolak: Diperlukan autentikasi admin.',
        ], 401);
    }

    protected function requireApiAccess(): ?JsonResponse
    {
        if ($this->isGranted('ROLE_API_TOKEN')) {
            return null;
        }

        return $this->json([
            'success' => false,
            'error' => 'Akses API ditolak: Diperlukan API Token yang valid.',
        ], 401);
    }
}

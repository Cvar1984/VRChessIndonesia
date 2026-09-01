<?php

declare(strict_types=1);

namespace VRchessIndo\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use VRchessIndo\Document\Admin;

/**
 * Session auth lifecycle. Response shapes match the legacy ?login / ?logout
 * / ?auth-status endpoints.
 */
class AdminAuthController extends AbstractApiController
{
    /**
     * Unreachable in normal operation: AdminLoginAuthenticator::supports()
     * matches this exact route name and always returns a Response from
     * onAuthenticationSuccess()/onAuthenticationFailure(), short-circuiting
     * the request before the controller runs. This action only exists
     * because routing requires a controller reference.
     */
    #[Route('/api/admin/login', name: 'api_admin_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        return $this->json(['success' => false, 'error' => 'Login handler misconfigured'], 500);
    }

    #[Route('/api/admin/logout', name: 'api_admin_logout', methods: ['POST'])]
    public function logout(Request $request, TokenStorageInterface $tokenStorage): JsonResponse
    {
        $tokenStorage->setToken(null);
        $request->getSession()->invalidate();

        return $this->json([
            'success' => true,
            'message' => 'Berhasil logout.',
            'authenticated' => false,
        ]);
    }

    #[Route('/api/auth/status', name: 'api_auth_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $user = $this->getUser();
        $authenticated = $user instanceof Admin;

        return $this->json([
            'success' => true,
            'authenticated' => $authenticated,
            'username' => $authenticated ? $user->getUsername() : null,
        ]);
    }
}

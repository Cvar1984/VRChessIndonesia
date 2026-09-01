<?php

declare(strict_types=1);

namespace VRchessIndo\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use VRchessIndo\Document\Admin;

/**
 * Handles POST /api/admin/login. Matches the legacy ?login endpoint: JSON
 * body (falling back to query params), username defaults to 'admin' when
 * omitted, and success establishes a session (this firewall is stateful) so
 * subsequent requests carry ROLE_ADMIN without resending credentials —
 * exactly like the legacy $_SESSION['is_admin'] flag.
 */
class AdminLoginAuthenticator extends AbstractAuthenticator
{
    public function __construct(private readonly AdminCredentialsVerifier $verifier)
    {
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'api_admin_login';
    }

    public function authenticate(Request $request): Passport
    {
        $input = json_decode($request->getContent(), true) ?? [];
        $username = (string) ($input['username'] ?? $request->query->get('username') ?? 'admin');
        $password = (string) ($input['password'] ?? $request->query->get('password') ?? '');

        $admin = $this->verifier->verify($username, $password);
        if ($admin === null) {
            throw new CustomUserMessageAuthenticationException('Username atau password admin salah!');
        }

        return new SelfValidatingPassport(new UserBadge($admin->getUsername(), static fn (): Admin => $admin));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        /** @var Admin $admin */
        $admin = $token->getUser();

        return new JsonResponse([
            'success' => true,
            'message' => 'Login berhasil sebagai Admin!',
            'authenticated' => true,
            'username' => $admin->getUsername(),
        ]);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse([
            'success' => false,
            'error' => $exception->getMessage(),
        ], 401);
    }
}

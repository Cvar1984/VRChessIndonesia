<?php

declare(strict_types=1);

namespace VRchessIndo\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use VRchessIndo\Document\Admin;

/**
 * Mirrors legacy isAdmin()'s per-request fallback: X-Admin-Username /
 * X-Admin-Password headers (or admin_username/admin_password in the
 * query/body) let a caller prove admin identity on a single request without
 * a prior session login. Failure here doesn't fail the whole request — it
 * returns null from onAuthenticationFailure() so ApiTokenAuthenticator still
 * gets a chance, exactly like requireApiAccess() falling through to token
 * validation when isAdmin() is false.
 */
class AdminHeaderAuthenticator extends AbstractAuthenticator
{
    public function __construct(private readonly AdminCredentialsVerifier $verifier)
    {
    }

    public function supports(Request $request): ?bool
    {
        return $this->extractPassword($request) !== null;
    }

    public function authenticate(Request $request): Passport
    {
        $username = $this->extractUsername($request);
        $password = $this->extractPassword($request) ?? '';

        $admin = $this->verifier->verify($username, $password);
        if ($admin === null) {
            throw new CustomUserMessageAuthenticationException('Invalid admin credentials');
        }

        return new SelfValidatingPassport(new UserBadge($admin->getUsername(), static fn (): Admin => $admin));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null;
    }

    private function extractUsername(Request $request): string
    {
        return (string) (
            $request->headers->get('X-Admin-Username')
            ?? $request->request->get('admin_username')
            ?? $request->query->get('admin_username')
            ?? 'admin'
        );
    }

    private function extractPassword(Request $request): ?string
    {
        $password = $request->headers->get('X-Admin-Password')
            ?? $request->request->get('admin_password')
            ?? $request->query->get('admin_password');

        return ($password !== null && $password !== '') ? (string) $password : null;
    }
}

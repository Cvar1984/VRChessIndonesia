<?php

declare(strict_types=1);

namespace VRchessIndo\Security;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use VRchessIndo\Repository\ApiTokenRepository;

/**
 * Mirrors legacy getProvidedApiToken()/validateToken(): reads X-API-Token,
 * then token/api_token (query or body), then an `Authorization: Bearer ...`
 * header, in that order. Bumps last_used only if stale by more than 5
 * minutes, same write-amplification guard as the legacy version — minus its
 * APCu/file result cache, which is explicitly Phase 4 scope (Symfony Cache).
 */
class ApiTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly DocumentManager $dm,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $this->extractToken($request) !== null;
    }

    public function authenticate(Request $request): Passport
    {
        $tokenValue = $this->extractToken($request);
        $tokenDoc = $tokenValue !== null ? $this->tokens->findOneActiveByToken($tokenValue) : null;

        if ($tokenDoc === null) {
            throw new CustomUserMessageAuthenticationException('Invalid or inactive API token');
        }

        $lastUsed = $tokenDoc->getLastUsed();
        if ($lastUsed === null || strtotime($lastUsed) < strtotime('-5 minutes')) {
            $tokenDoc->setLastUsed(date('Y-m-d H:i:s'));
            $this->dm->flush();
        }

        $user = new ApiTokenUser($tokenDoc->getId(), $tokenDoc->getName());

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn (): ApiTokenUser => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null;
    }

    private function extractToken(Request $request): ?string
    {
        $token = $request->headers->get('X-API-Token')
            ?? $request->query->get('token')
            ?? $request->request->get('token')
            ?? $request->query->get('api_token')
            ?? $request->request->get('api_token');

        if (empty($token) && $request->headers->has('Authorization')) {
            if (preg_match('/Bearer\s+(\S+)/i', (string) $request->headers->get('Authorization'), $m)) {
                $token = $m[1];
            }
        }

        $token = trim((string) $token);

        return $token !== '' ? $token : null;
    }
}

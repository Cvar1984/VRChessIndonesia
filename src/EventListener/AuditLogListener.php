<?php

declare(strict_types=1);

namespace VRchessIndo\EventListener;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use VRchessIndo\Document\Admin;
use VRchessIndo\Document\AuditEntry;
use VRchessIndo\Security\ApiTokenUser;

/**
 * Records every successful mutating request made by an authenticated caller.
 *
 * Done in one listener rather than at ~25 controller call sites: authorization
 * here is already a uniform in-controller `requireAdmin()`/`requireApiAccess()`
 * check, so every admin action is, by construction, a mutating HTTP request that
 * returned 2xx with a security token in place. Catching them centrally also means
 * an endpoint added later is logged without anyone remembering to do it.
 *
 * The trade-off is that entries describe the request rather than the domain
 * effect: a merge is recorded as `POST /api/admin/players/merge {from, into}`,
 * not as the number of matches it moved.
 */
class AuditLogListener implements EventSubscriberInterface
{
    /** Only state-changing verbs. GETs would bury the log in leaderboard reads. */
    private const array AUDITED_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Any payload key containing one of these is replaced before storage. The
     * audit log is read by every admin and survives 90 days, so it must never
     * become a place where credentials sit in plaintext — admin creation posts a
     * password, and the VRChat settings carry a TOTP secret.
     */
    private const array SECRET_KEY_FRAGMENTS = ['password', 'token', 'secret', 'totp'];

    private const int MAX_VALUE_LENGTH = 200;

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly DocumentManager $dm,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', -100]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        if (!in_array($request->getMethod(), self::AUDITED_METHODS, true)) {
            return;
        }
        // Only what actually took effect. A rejected or failed attempt is not an
        // admin action, and logging 401s would let an unauthenticated caller fill
        // the collection.
        if (!$response->isSuccessful()) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        [$actor, $actorType] = match (true) {
            $user instanceof Admin => [$user->getUserIdentifier(), 'admin'],
            $user instanceof ApiTokenUser => [$user->getUserIdentifier(), 'token'],
            default => [null, null],
        };
        if ($actor === null) {
            return;
        }

        // Audit logging must never be the reason a request fails: the action has
        // already happened and its response is built, so a write problem here is
        // reported nowhere but must not turn a success into a 500.
        try {
            $this->dm->persist(new AuditEntry(
                $actor,
                $actorType,
                $request->getMethod(),
                $request->getPathInfo(),
                $request->attributes->get('_route'),
                $response->getStatusCode(),
                $this->describe($request),
                $request->getClientIp(),
            ));
            $this->dm->flush();
        } catch (\Throwable) {
            // Deliberately swallowed.
        }
    }

    /**
     * What the request asked for, small and free of secrets: redacted by key,
     * long strings truncated, and nested arrays reduced to a count — the saved
     * analysis endpoint posts an entire game's evaluations, which would otherwise
     * put megabytes of engine output into the log.
     */
    private function describe(Request $request): array
    {
        $payload = [];
        if (str_contains((string) $request->headers->get('Content-Type'), 'json')) {
            $decoded = json_decode((string) $request->getContent(), true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $detail = [];
        foreach ([...$request->query->all(), ...$request->request->all(), ...$payload] as $key => $value) {
            $detail[$key] = $this->scrub((string) $key, $value);
        }

        $files = array_keys($request->files->all());
        if ($files !== []) {
            $detail['_files'] = $files;
        }

        return $detail;
    }

    private function scrub(string $key, mixed $value): mixed
    {
        $lowerKey = strtolower($key);
        foreach (self::SECRET_KEY_FRAGMENTS as $fragment) {
            if (str_contains($lowerKey, $fragment)) {
                return '[redacted]';
            }
        }

        if (is_array($value)) {
            return '[' . count($value) . ' items]';
        }
        if (is_string($value) && strlen($value) > self::MAX_VALUE_LENGTH) {
            return substr($value, 0, self::MAX_VALUE_LENGTH) . '… (' . strlen($value) . ' chars)';
        }
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return get_debug_type($value);
    }
}

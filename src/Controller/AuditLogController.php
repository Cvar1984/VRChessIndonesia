<?php

declare(strict_types=1);

namespace VRchessIndo\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use VRchessIndo\Document\AuditEntry;
use VRchessIndo\Repository\AuditEntryRepository;

/**
 * Reads the admin action log (written by AuditLogListener).
 *
 * Admin-only rather than requireApiAccess(): the log names who did what and from
 * which IP across every admin action, which is more than a match-reporting bot's
 * token has any reason to see.
 */
class AuditLogController extends AbstractApiController
{
    public function __construct(private readonly AuditEntryRepository $entries)
    {
    }

    #[Route('/api/admin/audit-log', name: 'api_admin_audit_log', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $limit = (int) ($request->query->get('limit') ?? 100);
        $actor = trim((string) $request->query->get('actor', ''));
        $entries = $this->entries->recent($limit, $actor !== '' ? $actor : null);

        return $this->json([
            'success' => true,
            'count' => count($entries),
            'retention_days' => (int) (AuditEntry::RETENTION_SECONDS / 86400),
            'entries' => array_map(static fn (AuditEntry $e): array => $e->toArray(), $entries),
        ]);
    }
}

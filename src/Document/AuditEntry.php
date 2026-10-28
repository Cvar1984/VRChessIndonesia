<?php

declare(strict_types=1);

namespace VRchessIndo\Document;

use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;
use VRchessIndo\Repository\AuditEntryRepository;

/**
 * One admin-authenticated mutating request, recorded after it succeeded.
 *
 * A new collection rather than a file: there is no Monolog bundle in this app,
 * and Railway's container filesystem is recreated on every deploy, so a log file
 * would be gone exactly when it was needed. The `audit_log` collection outlives
 * deploys, is readable through the API, and shows up in the admin panel.
 *
 * `at` is a real Date (unlike the 'Y-m-d H:i:s' strings the rest of the schema
 * uses) because the TTL index below needs one — Mongo expires documents off a
 * BSON date field, not a string. toArray() formats it back to the app's usual
 * string shape for the API.
 */
#[ODM\Document(collection: 'audit_log', repositoryClass: AuditEntryRepository::class)]
class AuditEntry
{
    /**
     * Entries expire 90 days after `at`. Self-managing, so the collection can't
     * grow without bound on a small Atlas tier — but Mongo only enforces it once
     * the index exists, which means `doctrine:mongodb:schema:update` has to run
     * on an existing deployment (see README).
     */
    public const int RETENTION_SECONDS = 90 * 24 * 60 * 60;

    #[ODM\Id]
    private ?string $mongoId = null;

    #[ODM\Field(type: 'date')]
    #[ODM\Index(expireAfterSeconds: self::RETENTION_SECONDS)]
    private \DateTimeInterface $at;

    /** Admin username, or `api-token:<id>` for a token-authenticated caller. */
    #[ODM\Field(type: 'string')]
    #[ODM\Index]
    private string $actor;

    /** 'admin' or 'token' — which credential type performed the action. */
    #[ODM\Field(type: 'string', name: 'actor_type')]
    private string $actorType;

    #[ODM\Field(type: 'string')]
    private string $method;

    #[ODM\Field(type: 'string')]
    private string $path;

    /** Symfony route name, which survives a URL change better than the path. */
    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $route = null;

    #[ODM\Field(type: 'int')]
    private int $status;

    /**
     * The request's own parameters, with secrets redacted — what was actually
     * asked for, which the method and path alone don't say.
     */
    #[ODM\Field(type: 'raw')]
    private array $detail = [];

    #[ODM\Field(type: 'string', nullable: true)]
    private ?string $ip = null;

    public function __construct(
        string $actor,
        string $actorType,
        string $method,
        string $path,
        ?string $route,
        int $status,
        array $detail = [],
        ?string $ip = null,
    ) {
        $this->at = new \DateTimeImmutable();
        $this->actor = $actor;
        $this->actorType = $actorType;
        $this->method = $method;
        $this->path = $path;
        $this->route = $route;
        $this->status = $status;
        $this->detail = $detail;
        $this->ip = $ip;
    }

    public function getActor(): string
    {
        return $this->actor;
    }

    public function getAt(): \DateTimeInterface
    {
        return $this->at;
    }

    public function toArray(): array
    {
        return [
            'at' => $this->at->format('Y-m-d H:i:s'),
            'actor' => $this->actor,
            'actor_type' => $this->actorType,
            'method' => $this->method,
            'path' => $this->path,
            'route' => $this->route,
            'status' => $this->status,
            'detail' => $this->detail,
            'ip' => $this->ip,
        ];
    }
}

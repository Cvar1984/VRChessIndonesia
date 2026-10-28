<?php

declare(strict_types=1);

namespace VRchessIndo\Tests\Controller;

use VRchessIndo\Document\ApiToken;
use VRchessIndo\Document\AuditEntry;
use VRchessIndo\Tests\ApiTestCase;

/**
 * The admin action log: what AuditLogListener records, what it refuses to record,
 * and who may read it back.
 */
class AuditLogTest extends ApiTestCase
{
    /** @return array<mixed> */
    private function auditEntries(): array
    {
        $this->client->request('GET', '/api/admin/audit-log');
        self::assertResponseIsSuccessful();

        return $this->jsonBody()['entries'];
    }

    /** @return array<mixed>|null */
    private function entryForRoute(string $route): ?array
    {
        foreach ($this->auditEntries() as $entry) {
            if ($entry['route'] === $route) {
                return $entry;
            }
        }

        return null;
    }

    public function testRecordsAnAdminMutationWithItsActorAndDetail(): void
    {
        $this->loginAsAdmin();
        $this->jsonRequest('POST', '/api/matches', ['white' => 'Alice', 'black' => 'Bob', 'result' => '1']);
        self::assertResponseIsSuccessful();

        $entry = $this->entryForRoute('api_matches_play');
        self::assertNotNull($entry, 'Recording a match is logged');
        self::assertSame(self::ADMIN_USERNAME, $entry['actor']);
        self::assertSame('admin', $entry['actor_type']);
        self::assertSame('POST', $entry['method']);
        self::assertSame(200, $entry['status']);
        // The payload is what makes the entry useful — method and path alone don't
        // say which players were involved.
        self::assertSame('Alice', $entry['detail']['white']);
        self::assertSame('Bob', $entry['detail']['black']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $entry['at']);
    }

    public function testNeverRecordsAPassword(): void
    {
        $this->loginAsAdmin();
        $this->jsonRequest('POST', '/api/admin/admins', ['username' => 'newadmin', 'password' => 'sup3rsecret!']);
        self::assertResponseIsSuccessful();

        $entry = $this->entryForRoute('api_admin_admins_create');
        self::assertNotNull($entry);
        self::assertSame('newadmin', $entry['detail']['username'], 'The non-secret field is kept');
        self::assertSame('[redacted]', $entry['detail']['password']);

        // Belt and braces: the secret must not appear anywhere in the stored
        // document, under any key.
        $raw = $this->dm->getDocumentCollection(AuditEntry::class)->find()->toArray();
        self::assertStringNotContainsString('sup3rsecret!', json_encode($raw));
    }

    public function testLoginIsRecordedWithoutItsPassword(): void
    {
        $this->loginAsAdmin();

        $entry = $this->entryForRoute('api_admin_login');
        self::assertNotNull($entry, 'A successful login is an auditable event');
        self::assertSame('[redacted]', $entry['detail']['password']);
        $raw = json_encode($this->dm->getDocumentCollection(AuditEntry::class)->find()->toArray());
        self::assertStringNotContainsString(self::ADMIN_PASSWORD, $raw);
    }

    public function testDoesNotRecordReadsOrRejectedAttempts(): void
    {
        // A GET changes nothing, and an unauthenticated POST is not an admin action
        // — logging those would let anyone fill the collection.
        $anonymous = $this->freshClient();
        $anonymous->request('GET', '/api/players');
        $anonymous->request(
            'POST',
            '/api/matches',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['white' => 'X', 'black' => 'Y', 'result' => '1']),
        );
        self::assertSame(401, $anonymous->getResponse()->getStatusCode());

        $this->loginAsAdmin();
        $this->client->request('GET', '/api/players');

        foreach ($this->auditEntries() as $entry) {
            self::assertSame('POST', $entry['method'], 'Only the login POST should be present');
            self::assertSame('api_admin_login', $entry['route']);
        }
    }

    public function testRecordsTheTokenBehindATokenAuthenticatedMutation(): void
    {
        $this->dm->persist(new ApiToken('tok_audit', 'audit-test', 'tok_audit_secret'));
        $this->dm->flush();

        $bot = $this->freshClient();
        $bot->request(
            'POST',
            '/api/matches',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_API_TOKEN' => 'tok_audit_secret'],
            json_encode(['white' => 'Alice', 'black' => 'Bob', 'result' => '1']),
        );
        self::assertSame(200, $bot->getResponse()->getStatusCode());

        $this->loginAsAdmin();
        $entry = $this->entryForRoute('api_matches_play');
        self::assertNotNull($entry, 'A bot-reported match is attributed too');
        self::assertSame('token', $entry['actor_type']);
        self::assertStringContainsString('api-token:', $entry['actor']);
        // The token value itself must never be stored, only which token it was.
        $raw = json_encode($this->dm->getDocumentCollection(AuditEntry::class)->find()->toArray());
        self::assertStringNotContainsString('tok_audit_secret', $raw);
    }

    public function testRecordsAPlayerMerge(): void
    {
        $this->loginAsAdmin();
        $this->jsonRequest('POST', '/api/matches', ['white' => 'Alice', 'black' => 'Carol', 'result' => '1']);
        $this->jsonRequest('POST', '/api/matches', ['white' => 'Alice VR', 'black' => 'Carol', 'result' => '1']);
        $this->jsonRequest('POST', '/api/admin/players/merge', ['from' => 'Alice', 'into' => 'Alice VR']);
        self::assertResponseIsSuccessful();

        // ADR-0001 action item #8: a merge deletes a player document, so there has
        // to be a record that it happened and who did it.
        $entry = $this->entryForRoute('api_players_merge');
        self::assertNotNull($entry);
        self::assertSame('Alice', $entry['detail']['from']);
        self::assertSame('Alice VR', $entry['detail']['into']);
        self::assertSame(self::ADMIN_USERNAME, $entry['actor']);
    }

    public function testDoesNotStoreALargeAnalysisPayload(): void
    {
        $this->loginAsAdmin();
        $this->jsonRequest('POST', '/api/analyses', ['pgn' => '[White "A"] [Black "B"] 1. e4 e5']);
        self::assertResponseIsSuccessful();
        $id = $this->jsonBody()['id'];

        $positions = array_fill(0, 60, ['fen' => str_repeat('x', 80), 'score' => 10]);
        $this->jsonRequest('PATCH', "/api/analyses/{$id}", ['analysis' => $positions]);
        self::assertResponseIsSuccessful();

        $entry = $this->entryForRoute('api_analyses_update');
        self::assertNotNull($entry);
        // A whole game's evaluations would be megabytes of engine output in the log.
        self::assertSame('[60 items]', $entry['detail']['analysis']);
    }

    public function testFiltersByActor(): void
    {
        $this->loginAsAdmin();
        $this->jsonRequest('POST', '/api/matches', ['white' => 'Alice', 'black' => 'Bob', 'result' => '1']);

        $this->client->request('GET', '/api/admin/audit-log?actor=' . urlencode(self::ADMIN_USERNAME));
        self::assertResponseIsSuccessful();
        self::assertNotEmpty($this->jsonBody()['entries']);

        $this->client->request('GET', '/api/admin/audit-log?actor=nobody');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonBody()['entries']);
    }

    public function testReadingTheLogRequiresAdminNotAToken(): void
    {
        $this->dm->persist(new ApiToken('tok_read', 'read-test', 'tok_read_secret'));
        $this->dm->flush();

        $anonymous = $this->freshClient();
        $anonymous->request('GET', '/api/admin/audit-log');
        self::assertSame(401, $anonymous->getResponse()->getStatusCode());

        // The log names who acted and from which IP — more than a reporting bot needs.
        $bot = $this->freshClient();
        $bot->request('GET', '/api/admin/audit-log', [], [], ['HTTP_X_API_TOKEN' => 'tok_read_secret']);
        self::assertSame(401, $bot->getResponse()->getStatusCode());
    }
}

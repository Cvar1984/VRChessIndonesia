<?php

declare(strict_types=1);

namespace VRchessIndo\Tests;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use VRchessIndo\Document\Admin;
use VRchessIndo\Document\Analysis;
use VRchessIndo\Document\ApiToken;
use VRchessIndo\Document\ChessMatch;
use VRchessIndo\Document\Player;
use VRchessIndo\Document\Setting;

/**
 * Shared setup for Phase 3 functional tests: a real HTTP client through the
 * full stack (routing + security firewall + controllers + ODM), running
 * against the dedicated vrchessindo_test database — never the live one,
 * hard-asserted before every test, same pattern as MatchManagerTest.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected const string ADMIN_USERNAME = 'testadmin';
    protected const string ADMIN_PASSWORD = 'testpass1234';

    protected KernelBrowser $client;
    protected DocumentManager $dm;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // KernelBrowser reboots the kernel (and rebuilds the container)
        // before every request after the first by default — which silently
        // discards any self::getContainer() mutation made between requests,
        // including mockHttpClient()'s setResponseFactory() calls. Disabling
        // this keeps one consistent container for a whole test.
        $this->client->disableReboot();
        $this->dm = self::getContainer()->get(DocumentManager::class);

        $dbName = $this->dm->getConfiguration()->getDefaultDB();
        self::assertSame(
            'vrchessindo_test',
            $dbName,
            "Refusing to run: MONGODB_DB must be the dedicated test database, not '{$dbName}'. " .
            'This test wipes its database before every run.',
        );

        foreach ([Player::class, ChessMatch::class, ApiToken::class, Admin::class, Analysis::class, Setting::class] as $class) {
            $this->dm->getDocumentCollection($class)->deleteMany([]);
        }
        $this->dm->clear();

        $admin = new Admin(self::ADMIN_USERNAME, password_hash(self::ADMIN_PASSWORD, PASSWORD_BCRYPT));
        $this->dm->persist($admin);
        $this->dm->flush();
        $this->dm->clear();
    }

    /**
     * A second, independent client (own cookie jar, no shared session) for
     * tests that need to prove something works for an unauthenticated or
     * differently-authenticated caller. Reuses the already-booted kernel —
     * WebTestCase::createClient() only supports being called once per test.
     */
    protected function freshClient(): KernelBrowser
    {
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();

        return $client;
    }

    protected function loginAsAdmin(): void
    {
        $this->client->request(
            'POST',
            '/api/admin/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['username' => self::ADMIN_USERNAME, 'password' => self::ADMIN_PASSWORD]),
        );
        self::assertResponseIsSuccessful();
    }

    /**
     * @return array<mixed>
     */
    protected function jsonBody(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true);
    }

    protected function jsonRequest(string $method, string $uri, array $data = [], array $server = []): void
    {
        $this->client->request($method, $uri, [], [], array_merge(['CONTENT_TYPE' => 'application/json'], $server), json_encode($data));
    }

    /**
     * Queues responses on the shared test-only HttpClientInterface binding
     * (config/services.yaml's when@test block rebinds it to a MockHttpClient)
     * so any outbound HTTP call — VRChat API, avatar CDN fetch — is answered
     * from this queue instead of ever reaching a real network destination.
     *
     * A prior attempt used a runtime container override
     * (self::getContainer()->set()) instead of this compile-time rebinding;
     * that turned out to be unreliable for controllers that depend on
     * HttpClientInterface indirectly (through VRChatClientFactory) — it was
     * confirmed empirically that calls still reached the live VRChat API
     * with real credentials during a test run despite the override being in
     * place. This binds the same shared MockHttpClient instance everywhere,
     * so there's no override to fail to propagate.
     *
     * @param iterable<\Symfony\Contracts\HttpClient\ResponseInterface>|callable $responses
     */
    protected function mockHttpClient(iterable|callable $responses): void
    {
        /** @var MockHttpClient $mock */
        $mock = self::getContainer()->get('test.mock_http_client');
        $mock->setResponseFactory($responses);
    }
}

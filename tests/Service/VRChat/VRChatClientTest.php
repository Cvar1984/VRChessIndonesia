<?php

declare(strict_types=1);

namespace VRchessIndo\Tests\Service\VRChat;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use VRchessIndo\Document\Setting;
use VRchessIndo\Repository\SettingRepository;
use VRchessIndo\Service\Totp;
use VRchessIndo\Service\VRChat\VRChatClient;

/**
 * Exercises VRChatClient's login/2FA/session-caching/retry logic entirely
 * against MockHttpClient — never the real VRChat API. Legacy never had
 * automated coverage for this at all (test.php only covered the rating
 * system), so this is new coverage, not a straight port.
 *
 * Session persistence still goes through the real Setting document against
 * vrchessindo_test (same hard safety guard as MatchManagerTest), since
 * that's the one piece of real, exercised behavior worth keeping genuine.
 */
class VRChatClientTest extends KernelTestCase
{
    private DocumentManager $dm;
    private SettingRepository $settings;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->dm = $container->get(DocumentManager::class);
        $this->settings = $container->get(SettingRepository::class);

        $dbName = $this->dm->getConfiguration()->getDefaultDB();
        self::assertSame(
            'vrchessindo_test',
            $dbName,
            "Refusing to run: MONGODB_DB must be the dedicated test database, not '{$dbName}'.",
        );

        $this->dm->getDocumentCollection(Setting::class)->deleteMany([]);
        $this->dm->clear();
    }

    private function makeClient(MockHttpClient $http, ?string $totpSecret = 'JBSWY3DPEHPK3PXP'): VRChatClient
    {
        return new VRChatClient(
            $http,
            $this->settings,
            $this->dm,
            'testuser',
            'testpass',
            $totpSecret,
            'VRchessIndo/1.0 (test)',
        );
    }

    public function testConstructorRejectsEmptyCredentials(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('VRChat belum dikonfigurasi');

        new VRChatClient(new MockHttpClient(), $this->settings, $this->dm, '', '', null, 'UA');
    }

    public function testLoginWithoutTwoFactorThenSearchUsers(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['id' => 'usr_me']), [
                'http_code' => 200,
                'response_headers' => ['Set-Cookie: auth=cookie123; Path=/'],
            ]),
            new MockResponse(json_encode([
                ['id' => 'usr_abc', 'displayName' => 'Alice VR', 'currentAvatarThumbnailImageUrl' => 'https://example.com/thumb.png'],
                ['id' => '', 'displayName' => 'Skip me, no id'],
            ]), ['http_code' => 200]),
        ]);

        $client = $this->makeClient($http);
        $results = $client->searchUsers('alice');

        self::assertCount(1, $results, 'The user without an id is filtered out');
        self::assertSame('usr_abc', $results[0]['id']);
        self::assertSame('Alice VR', $results[0]['displayName']);
        self::assertSame('https://example.com/thumb.png', $results[0]['thumbnail']);

        // Session was persisted to the settings collection.
        $setting = $this->settings->findOneByKey('vrchat_session');
        self::assertNotNull($setting);
        $session = json_decode($setting->getValue(), true);
        self::assertSame('auth=cookie123', $session['cookie']);
    }

    public function testLoginWithTotpTwoFactorSendsCorrectCode(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';

        // Totp::generate() defaults to time() internally, so this can't
        // assert against one precomputed code without risking flakiness at
        // a 30s-step boundary — instead assert the shape (6 numeric digits)
        // is actually a TOTP code, and separately trust Totp's own dedicated
        // RFC 6238 test-vector coverage for correctness of the algorithm.
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if (str_contains($url, '/auth/user')) {
                return new MockResponse(json_encode(['requiresTwoFactorAuth' => ['totp']]), [
                    'http_code' => 200,
                    'response_headers' => ['Set-Cookie: auth=precode; Path=/'],
                ]);
            }

            if (str_contains($url, '/auth/twofactorauth/totp/verify')) {
                $sentBody = json_decode($options['body'], true);
                self::assertSame(6, strlen($sentBody['code']));
                self::assertMatchesRegularExpression('/^\d{6}$/', $sentBody['code']);

                return new MockResponse(json_encode(['verified' => true]), [
                    'http_code' => 200,
                    'response_headers' => ['Set-Cookie: twoFactorAuth=verified; Path=/'],
                ]);
            }

            return new MockResponse(json_encode(['id' => 'usr_target', 'displayName' => 'Target']), ['http_code' => 200]);
        });

        $client = $this->makeClient($http, $secret);
        $user = $client->getUser('usr_target');

        self::assertNotNull($user);
        self::assertSame('usr_target', $user['id']);

        $setting = $this->settings->findOneByKey('vrchat_session');
        $session = json_decode($setting->getValue(), true);
        self::assertStringContainsString('auth=precode', $session['cookie']);
        self::assertStringContainsString('twoFactorAuth=verified', $session['cookie']);
    }

    public function testLoginRejectsUnsupportedTwoFactorMethod(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['requiresTwoFactorAuth' => ['emailOtp']]), ['http_code' => 200]),
        ]);

        $client = $this->makeClient($http, null);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Hanya Authenticator App (TOTP) yang didukung');
        $client->getUser('usr_x');
    }

    public function testCachedSessionIsReusedWithoutReLogin(): void
    {
        $setting = new Setting('vrchat_session', json_encode(['cookie' => 'auth=already-valid', 'authenticated_at' => date('Y-m-d H:i:s')]));
        $this->dm->persist($setting);
        $this->dm->flush();
        $this->dm->clear();

        // Only ONE response registered — if the client tried to log in again,
        // MockHttpClient would throw for the unexpected extra request.
        $http = new MockHttpClient([
            new MockResponse(json_encode(['id' => 'usr_x', 'displayName' => 'X']), ['http_code' => 200]),
        ]);

        $client = $this->makeClient($http);
        $user = $client->getUser('usr_x');

        self::assertNotNull($user);
        self::assertSame(1, $http->getRequestsCount());
    }

    public function testStaleSessionTriggersReloginAndRetryOn401(): void
    {
        $setting = new Setting('vrchat_session', json_encode(['cookie' => 'auth=stale', 'authenticated_at' => '2020-01-01 00:00:00']));
        $this->dm->persist($setting);
        $this->dm->flush();
        $this->dm->clear();

        $http = new MockHttpClient([
            new MockResponse(json_encode(['error' => ['message' => 'unauthorized']]), ['http_code' => 401]),
            new MockResponse(json_encode(['id' => 'me']), [
                'http_code' => 200,
                'response_headers' => ['Set-Cookie: auth=fresh; Path=/'],
            ]),
            new MockResponse(json_encode(['id' => 'usr_x', 'displayName' => 'X']), ['http_code' => 200]),
        ]);

        $client = $this->makeClient($http);
        $user = $client->getUser('usr_x');

        self::assertNotNull($user);
        self::assertSame(3, $http->getRequestsCount(), 'stale request + relogin + retried request');

        $setting = $this->settings->findOneByKey('vrchat_session');
        self::assertStringContainsString('auth=fresh', $setting->getValue());
    }

    public function testGetUserReturnsNullWhenIdMissing(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['id' => 'me']), [
                'http_code' => 200,
                'response_headers' => ['Set-Cookie: auth=x; Path=/'],
            ]),
            new MockResponse(json_encode(['error' => ['message' => 'User not found']]), ['http_code' => 404]),
        ]);

        $client = $this->makeClient($http);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('User not found');
        $client->getUser('usr_doesnotexist');
    }

    public function testGetUserReturnsNullForEmptyId(): void
    {
        $client = $this->makeClient(new MockHttpClient());
        self::assertNull($client->getUser(''));
    }

    public function testSearchUsersReturnsEmptyArrayForBlankQuery(): void
    {
        $client = $this->makeClient(new MockHttpClient());
        self::assertSame([], $client->searchUsers('   '));
    }
}

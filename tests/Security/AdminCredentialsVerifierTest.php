<?php

declare(strict_types=1);

namespace VRchessIndo\Tests\Security;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use VRchessIndo\Document\Admin;
use VRchessIndo\Document\Setting;
use VRchessIndo\Security\AdminCredentialsVerifier;

/**
 * AdminAuthTest (functional, via HTTP) already covers the everyday bcrypt
 * login path end to end. This targets the paths it can't reach because
 * ApiTestCase::setUp() always creates a bcrypt admin first: the legacy
 * plaintext-password fallback (auto-rehashed to bcrypt on success) and
 * bootstrapFirstAdminIfNone()'s two sources (legacy `admin_password`
 * setting, then the ADMIN_PASSWORD env var) when the admins collection is
 * genuinely empty.
 *
 * Runs against MONGODB_DB from .env.test, same dedicated database and same
 * hard-asserted-name safety check as MatchManagerTest.
 */
class AdminCredentialsVerifierTest extends KernelTestCase
{
    private DocumentManager $dm;
    private AdminCredentialsVerifier $verifier;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->dm = $container->get(DocumentManager::class);
        $this->verifier = $container->get(AdminCredentialsVerifier::class);

        $dbName = $this->dm->getConfiguration()->getDefaultDB();
        self::assertSame(
            'vrchessindo_test',
            $dbName,
            "Refusing to run: MONGODB_DB must be the dedicated test database, not '{$dbName}'. " .
            'This test wipes its database before every run.',
        );

        foreach ([Admin::class, Setting::class] as $class) {
            $this->dm->getDocumentCollection($class)->deleteMany([]);
        }
        $this->dm->clear();
    }

    public function testBcryptPasswordVerifiesDirectly(): void
    {
        $admin = new Admin('bcryptuser', password_hash('correct horse', PASSWORD_BCRYPT));
        $this->dm->persist($admin);
        $this->dm->flush();
        $this->dm->clear();

        $result = $this->verifier->verify('bcryptuser', 'correct horse', '10.0.0.1');

        self::assertNotNull($result);
        self::assertSame('bcryptuser', $result->getUserIdentifier());
    }

    public function testWrongPasswordReturnsNull(): void
    {
        $admin = new Admin('bcryptuser', password_hash('correct horse', PASSWORD_BCRYPT));
        $this->dm->persist($admin);
        $this->dm->flush();
        $this->dm->clear();

        self::assertNull($this->verifier->verify('bcryptuser', 'wrong password', '10.0.0.2'));
    }

    public function testUnknownUsernameReturnsNullWithoutBootstrapping(): void
    {
        // An admin already exists (just not this one) — must NOT trigger
        // bootstrapFirstAdminIfNone(), which only fires when the collection
        // is completely empty.
        $admin = new Admin('someoneelse', password_hash('whatever', PASSWORD_BCRYPT));
        $this->dm->persist($admin);
        $this->dm->flush();
        $this->dm->clear();

        self::assertNull($this->verifier->verify('nosuchuser', 'anything', '10.0.0.3'));
        self::assertCount(1, $this->dm->getRepository(Admin::class)->findAll());
    }

    public function testLegacyPlaintextPasswordVerifiesAndIsRehashedToBcrypt(): void
    {
        // Pre-hashing-era record: password stored as raw plaintext, exactly
        // as MongoDBDatabaseManager's old format did.
        $admin = new Admin('legacyuser', 'plaintext-legacy-pass');
        $this->dm->persist($admin);
        $this->dm->flush();
        $this->dm->clear();

        $result = $this->verifier->verify('legacyuser', 'plaintext-legacy-pass', '10.0.0.4');

        self::assertNotNull($result);
        self::assertSame('legacyuser', $result->getUserIdentifier());

        // Re-fetch from the database rather than trusting the in-memory
        // object, to prove the rehash was actually persisted, not just
        // mutated on the object returned to the caller.
        $this->dm->clear();
        $refetched = $this->dm->getRepository(Admin::class)->findOneBy(['username' => 'legacyuser']);
        self::assertStringStartsWith('$2y$', $refetched->getPassword());
        self::assertTrue(password_verify('plaintext-legacy-pass', $refetched->getPassword()));
    }

    public function testBootstrapsFirstAdminFromLegacySettingWhenNoAdminsExist(): void
    {
        // Legacy admin_password setting stored as plaintext (its original
        // format) — verify() must both bootstrap the admin from it AND take
        // the plaintext-fallback branch to accept the first login.
        $setting = new Setting('admin_password', 'from-legacy-setting');
        $this->dm->persist($setting);
        $this->dm->flush();
        $this->dm->clear();

        self::assertCount(0, $this->dm->getRepository(Admin::class)->findAll());

        $result = $this->verifier->verify('admin', 'from-legacy-setting', '10.0.0.5');

        self::assertNotNull($result);
        self::assertSame('admin', $result->getUserIdentifier());
        self::assertCount(1, $this->dm->getRepository(Admin::class)->findAll());
    }

    public function testBootstrapsFirstAdminFromEnvPasswordWhenNothingElseExists(): void
    {
        // No admins, no legacy setting — falls back to ADMIN_PASSWORD ('admin' in .env).
        self::assertCount(0, $this->dm->getRepository(Admin::class)->findAll());

        $result = $this->verifier->verify('admin', 'admin', '10.0.0.6');

        self::assertNotNull($result);
        self::assertSame('admin', $result->getUserIdentifier());
    }

    public function testRepeatedFailuresFromSameIpAreThrottledRegardlessOfUsername(): void
    {
        $admin = new Admin('bcryptuser', password_hash('correct horse', PASSWORD_BCRYPT));
        $this->dm->persist($admin);
        $this->dm->flush();
        $this->dm->clear();

        // config/packages/rate_limiter.yaml: 5 attempts per 5 minutes.
        for ($i = 0; $i < 5; $i++) {
            self::assertNull($this->verifier->verify('bcryptuser', 'wrong', '10.0.0.7'));
        }

        // 6th attempt from the same IP is throttled even with the correct
        // password — proves the limiter, not the credential check, is what
        // rejects it.
        self::assertNull($this->verifier->verify('bcryptuser', 'correct horse', '10.0.0.7'));

        // A different IP is unaffected — the limit is per-IP, not global.
        self::assertNotNull($this->verifier->verify('bcryptuser', 'correct horse', '10.0.0.8'));
    }

    public function testSuccessfulLoginResetsTheThrottleForThatIp(): void
    {
        $admin = new Admin('bcryptuser', password_hash('correct horse', PASSWORD_BCRYPT));
        $this->dm->persist($admin);
        $this->dm->flush();
        $this->dm->clear();

        for ($i = 0; $i < 4; $i++) {
            self::assertNull($this->verifier->verify('bcryptuser', 'wrong', '10.0.0.9'));
        }
        // 5th attempt succeeds and must reset the counter...
        self::assertNotNull($this->verifier->verify('bcryptuser', 'correct horse', '10.0.0.9'));
        // ...so a 6th attempt right after is still accepted, not throttled.
        self::assertNotNull($this->verifier->verify('bcryptuser', 'correct horse', '10.0.0.9'));
    }
}

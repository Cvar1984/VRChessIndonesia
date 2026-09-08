<?php

declare(strict_types=1);

namespace VRchessIndo\Security;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use VRchessIndo\Document\Admin;
use VRchessIndo\Repository\AdminRepository;
use VRchessIndo\Repository\SettingRepository;

/**
 * Ports MongoDBDatabaseManager::verifyAdminLogin() verbatim: bcrypt is the
 * real check, with a one-time plaintext-password fallback (auto-rehashed to
 * bcrypt on success) for accounts migrated from an older, pre-hashing
 * storage format. Shared by AdminLoginAuthenticator and
 * AdminHeaderAuthenticator since both need the exact same verification —
 * including the per-IP rate limit below, so an attacker can't dodge it by
 * switching between the login endpoint and header auth.
 */
class AdminCredentialsVerifier
{
    public function __construct(
        private readonly AdminRepository $admins,
        private readonly SettingRepository $settings,
        private readonly DocumentManager $dm,
        #[Autowire(service: 'limiter.admin_login')] private readonly RateLimiterFactory $loginLimiter,
    ) {
    }

    /**
     * $clientIp keys the brute-force limiter (see config/packages/rate_limiter.yaml)
     * — 5 attempts per 5 minutes, consumed on every call regardless of
     * outcome and reset on success, so a legitimate admin who mistypes their
     * password a couple of times isn't left locked out after finally getting
     * it right.
     */
    public function verify(string $username, string $password, string $clientIp): ?Admin
    {
        $limiter = $this->loginLimiter->create($clientIp);
        if (!$limiter->consume(1)->isAccepted()) {
            return null;
        }

        $this->bootstrapFirstAdminIfNone();

        $admin = $this->admins->findOneByUsername(trim($username));
        if ($admin === null) {
            return null;
        }

        if (password_verify($password, $admin->getPassword())) {
            $limiter->reset();
            return $admin;
        }

        if (hash_equals($admin->getPassword(), $password)) {
            $admin->setPassword(password_hash($password, PASSWORD_BCRYPT));
            $this->dm->flush();
            $limiter->reset();
            return $admin;
        }

        return null;
    }

    /**
     * Legacy fallback: if the admins collection is empty, bootstrap a first
     * 'admin' account from either the legacy `admin_password` setting or the
     * ADMIN_PASSWORD env var (defaulting to 'admin').
     */
    private function bootstrapFirstAdminIfNone(): void
    {
        if (\count($this->admins->findAll()) > 0) {
            return;
        }

        $legacySetting = $this->settings->findOneByKey('admin_password');
        if ($legacySetting !== null) {
            $admin = new Admin('admin', $legacySetting->getValue());
            $this->dm->persist($admin);
            $this->dm->flush();
            return;
        }

        $envPassword = $_ENV['ADMIN_PASSWORD'] ?? $_SERVER['ADMIN_PASSWORD'] ?? getenv('ADMIN_PASSWORD') ?: 'admin';
        $admin = new Admin('admin', password_hash((string) $envPassword, PASSWORD_BCRYPT));
        $this->dm->persist($admin);
        $this->dm->flush();
    }
}

<?php

declare(strict_types=1);

namespace VRchessIndo\Security;

use Doctrine\ODM\MongoDB\DocumentManager;
use VRchessIndo\Document\Admin;
use VRchessIndo\Repository\AdminRepository;
use VRchessIndo\Repository\SettingRepository;

/**
 * Ports MongoDBDatabaseManager::verifyAdminLogin() verbatim: bcrypt is the
 * real check, with a one-time plaintext-password fallback (auto-rehashed to
 * bcrypt on success) for accounts migrated from an older, pre-hashing
 * storage format. Shared by AdminLoginAuthenticator and
 * AdminHeaderAuthenticator since both need the exact same verification.
 */
class AdminCredentialsVerifier
{
    public function __construct(
        private readonly AdminRepository $admins,
        private readonly SettingRepository $settings,
        private readonly DocumentManager $dm,
    ) {
    }

    public function verify(string $username, string $password): ?Admin
    {
        $this->bootstrapFirstAdminIfNone();

        $admin = $this->admins->findOneByUsername(trim($username));
        if ($admin === null) {
            return null;
        }

        if (password_verify($password, $admin->getPassword())) {
            return $admin;
        }

        if (hash_equals($admin->getPassword(), $password)) {
            $admin->setPassword(password_hash($password, PASSWORD_BCRYPT));
            $this->dm->flush();
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

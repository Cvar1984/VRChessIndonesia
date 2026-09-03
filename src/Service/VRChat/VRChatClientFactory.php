<?php

declare(strict_types=1);

namespace VRchessIndo\Service\VRChat;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use VRchessIndo\Repository\SettingRepository;

/**
 * Builds a VRChatClient from VRCHAT_* env vars. A real container service
 * (unlike VRChatClient itself), matching legacy's two-step
 * VRChatClient::fromEnv() + buildVrchatClient($db): controllers call
 * build() explicitly, inside their own try/catch, so a misconfigured/absent
 * VRChat account only breaks the specific VRChat-dependent actions rather
 * than failing DI construction for the whole controller.
 */
class VRChatClientFactory
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SettingRepository $settings,
        private readonly DocumentManager $dm,
        #[Autowire(env: 'VRCHAT_USERNAME')] private readonly string $username,
        #[Autowire(env: 'VRCHAT_PASSWORD')] private readonly string $password,
        #[Autowire(env: 'VRCHAT_TOTP_SECRET')] private readonly string $totpSecret,
        #[Autowire(env: 'VRCHAT_CONTACT')] private readonly string $contact,
        #[Autowire(env: 'float:VRCHAT_RATE_LIMIT_SECONDS')] private readonly float $rateLimitSeconds,
    ) {
    }

    /**
     * @throws \Exception if VRCHAT_USERNAME/VRCHAT_PASSWORD aren't configured
     */
    public function build(): VRChatClient
    {
        $userAgent = 'VRchessIndo/1.0' . ($this->contact !== '' ? " ({$this->contact})" : '');

        return new VRChatClient(
            $this->httpClient,
            $this->settings,
            $this->dm,
            $this->username,
            $this->password,
            $this->totpSecret !== '' ? $this->totpSecret : null,
            $userAgent,
            $this->rateLimitSeconds,
        );
    }
}

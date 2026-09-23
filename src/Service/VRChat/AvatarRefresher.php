<?php

declare(strict_types=1);

namespace VRchessIndo\Service\VRChat;

use Doctrine\ODM\MongoDB\DocumentManager;
use VRchessIndo\Repository\PlayerRepository;

/**
 * Re-fetches the cached VRChat avatar URL of every linked player whose cache
 * is older than 24h. Shared by the admin "refresh avatars" button and the
 * daily app:vrchat:refresh-avatars run (docker/start.sh).
 */
class AvatarRefresher
{
    private const int TTL_SECONDS = 24 * 60 * 60;

    public function __construct(
        private readonly VRChatClientFactory $clientFactory,
        private readonly PlayerRepository $players,
        private readonly DocumentManager $dm,
    ) {
    }

    /**
     * @return array{refreshed: int, skipped: int, failed: int}
     * @throws \Throwable if the VRChat client can't be built (not configured)
     */
    public function refresh(bool $force = false): array
    {
        $client = $this->clientFactory->build();
        $refreshed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($this->players->findAll() as $player) {
            if ($player->getVrchatUserId() === null) {
                continue;
            }

            $cachedAt = $player->getAvatarCachedAt() !== null ? strtotime($player->getAvatarCachedAt()) : false;
            if (!$force && $cachedAt !== false && (time() - $cachedAt) < self::TTL_SECONDS) {
                $skipped++;
                continue;
            }

            try {
                $vrchatUser = $client->getUser($player->getVrchatUserId());
                $player->updateAvatarCache($vrchatUser !== null ? $vrchatUser['avatarUrl'] : null);
                $refreshed++;
            } catch (\Throwable) {
                $failed++;
            }
        }

        $this->dm->flush();

        return ['refreshed' => $refreshed, 'skipped' => $skipped, 'failed' => $failed];
    }
}

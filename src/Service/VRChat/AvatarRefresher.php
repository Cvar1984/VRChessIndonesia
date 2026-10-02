<?php

declare(strict_types=1);

namespace VRchessIndo\Service\VRChat;

use Doctrine\ODM\MongoDB\DocumentManager;
use VRchessIndo\Document\Player;
use VRchessIndo\Repository\PlayerRepository;

/**
 * Re-fetches the VRChat profile (avatar URL and display name) of every linked
 * player whose cache is older than 24h, and makes their username follow the
 * VRChat display name. Shared by the admin "refresh avatars" button and the
 * daily app:vrchat:refresh-avatars run (src/Scheduler/DefaultSchedule.php).
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
     * @return array{refreshed: int, skipped: int, failed: int, renamed: int, conflicts: string[]}
     * @throws \Throwable if the VRChat client can't be built (not configured)
     */
    public function refresh(bool $force = false): array
    {
        $client = $this->clientFactory->build();
        $refreshed = 0;
        $skipped = 0;
        $failed = 0;
        $renamed = 0;
        $conflicts = [];

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
                $player->updateVrchatProfile($vrchatUser['displayName'] ?? null, $vrchatUser['avatarUrl'] ?? null);
                $status = $this->syncUsername($player);
                if ($status === 'renamed') {
                    $renamed++;
                } elseif ($status === 'conflict') {
                    $conflicts[] = "{$player->getUsername()} → {$player->getVrchatDisplayName()}";
                }
                $refreshed++;
            } catch (\Throwable) {
                $failed++;
            }
        }

        $this->dm->flush();

        return ['refreshed' => $refreshed, 'skipped' => $skipped, 'failed' => $failed, 'renamed' => $renamed, 'conflicts' => $conflicts];
    }

    /**
     * Makes a linked player's username follow their VRChat display name; the old
     * one goes to username_history. Skipped as 'conflict' when another player
     * already holds that name — almost always an unlinked duplicate of the same
     * person, which an admin should merge rather than have guessed at here.
     *
     * @return 'renamed'|'unchanged'|'conflict'
     */
    public function syncUsername(Player $player): string
    {
        $name = trim((string) $player->getVrchatDisplayName());
        if ($name === '' || $name === $player->getUsername()) {
            return 'unchanged';
        }
        if ($this->players->isUsernameTaken($name)) {
            return 'conflict';
        }

        $player->rename($name, 'vrchat');

        return 'renamed';
    }
}

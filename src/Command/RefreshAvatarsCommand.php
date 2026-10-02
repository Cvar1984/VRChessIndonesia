<?php

declare(strict_types=1);

namespace VRchessIndo\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use VRchessIndo\Service\VRChat\AvatarRefresher;

#[AsCommand(name: 'app:vrchat:refresh-avatars', description: 'Re-fetch VRChat avatars cached more than 24h ago')]
class RefreshAvatarsCommand extends Command
{
    public function __construct(private readonly AvatarRefresher $refresher)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->refresher->refresh();
        } catch (\Throwable $e) {
            $output->writeln("<error>VRChat avatar refresh failed: {$e->getMessage()}</error>");

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            'VRChat avatars: %d refreshed, %d skipped (still fresh), %d failed, %d renamed to their VRChat name',
            $result['refreshed'],
            $result['skipped'],
            $result['failed'],
            $result['renamed'],
        ));
        foreach ($result['conflicts'] as $conflict) {
            $output->writeln("<comment>Not renamed, name already taken by another player: {$conflict}</comment>");
        }

        return Command::SUCCESS;
    }
}

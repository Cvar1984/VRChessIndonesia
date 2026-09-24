<?php

declare(strict_types=1);

namespace VRchessIndo\Scheduler;

use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Fired by `php bin/console messenger:consume scheduler_default`.
 *
 * The worker is short-lived on shared hosting (cron restarts it every
 * minute), so the schedule is stateful — its last run lives in cache.app and
 * survives restarts — and locked, so two overlapping workers can't both fire it.
 */
#[AsSchedule('default')]
class DefaultSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly LockFactory $lockFactory,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            // Anchored to 03:00 so restarts don't shift the daily grid. Safe to
            // run twice: the command skips avatars cached within the last 24h.
            ->add(RecurringMessage::every('1 day', new RunCommandMessage('app:vrchat:refresh-avatars'), from: '03:00'))
            ->stateful($this->cache)
            ->lock($this->lockFactory->createLock('scheduler_default'));
    }
}

<?php

declare(strict_types=1);

namespace VRchessIndo\Tests\Scheduler;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Generator\MessageContext;
use VRchessIndo\Scheduler\DefaultSchedule;

class DefaultScheduleTest extends KernelTestCase
{
    public function testEveryScheduledCommandExists(): void
    {
        self::bootKernel();
        $schedule = self::getContainer()->get(DefaultSchedule::class)->getSchedule();
        $application = new Application(self::$kernel);

        $commands = [];
        foreach ($schedule->getRecurringMessages() as $recurring) {
            $context = new MessageContext('default', $recurring->getId(), $recurring->getTrigger(), new \DateTimeImmutable());
            foreach ($recurring->getMessages($context) as $message) {
                self::assertInstanceOf(RunCommandMessage::class, $message);
                // A renamed or deleted command would otherwise only fail at run time, unnoticed.
                self::assertTrue($application->has(strtok($message->input, ' ')), "Scheduled command '{$message->input}' does not exist");
                $commands[] = $message->input;
            }
        }

        self::assertSame(['app:vrchat:refresh-avatars'], $commands);
    }
}

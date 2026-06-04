<?php

namespace yellowrobot\courier\tests\unit;

use PHPUnit\Framework\TestCase;
use yellowrobot\courier\services\Scheduler;

class SchedulerTest extends TestCase
{
    public function testBeforeOffsetSubtractsDays(): void
    {
        $sendAt = Scheduler::computeSendAt(new \DateTime('2026-06-20 15:30:00'), -3, '09:00');

        $this->assertSame('2026-06-17 09:00', $sendAt->format('Y-m-d H:i'));
    }

    public function testAfterOffsetAddsDays(): void
    {
        $sendAt = Scheduler::computeSendAt(new \DateTime('2026-06-20 15:30:00'), 7, '14:45');

        $this->assertSame('2026-06-27 14:45', $sendAt->format('Y-m-d H:i'));
    }

    public function testZeroOffsetSendsOnTheDate(): void
    {
        $sendAt = Scheduler::computeSendAt(new \DateTime('2026-06-20 23:59:00'), 0, '08:00');

        $this->assertSame('2026-06-20 08:00', $sendAt->format('Y-m-d H:i'));
    }

    public function testSendTimeDefaultsToNineAm(): void
    {
        $sendAt = Scheduler::computeSendAt(new \DateTime('2026-06-20 15:30:00'), -1, null);

        $this->assertSame('09:00', $sendAt->format('H:i'));
    }

    public function testMonthBoundaryRollsCorrectly(): void
    {
        $sendAt = Scheduler::computeSendAt(new \DateTime('2026-07-02 12:00:00'), -3, '09:00');

        $this->assertSame('2026-06-29 09:00', $sendAt->format('Y-m-d H:i'));
    }
}

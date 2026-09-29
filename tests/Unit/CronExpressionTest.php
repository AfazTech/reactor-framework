<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Core\Schedule\CronExpression;

class CronExpressionTest extends TestCase
{
    /** @test */
    public function it_parses_every_minute()
    {
        $cron = new CronExpression('* * * * *');
        $time = new \DateTimeImmutable('2026-07-24 10:30:00');
        $this->assertTrue($cron->isDue($time));
    }

    /** @test */
    public function it_parses_specific_minute()
    {
        $cron = new CronExpression('30 * * * *');
        $time = new \DateTimeImmutable('2026-07-24 10:30:00');
        $this->assertTrue($cron->isDue($time));

        $time2 = new \DateTimeImmutable('2026-07-24 10:31:00');
        $this->assertFalse($cron->isDue($time2));
    }

    /** @test */
    public function it_parses_every_five_minutes()
    {
        $cron = new CronExpression('*/5 * * * *');
        $time = new \DateTimeImmutable('2026-07-24 10:05:00');
        $this->assertTrue($cron->isDue($time));

        $time2 = new \DateTimeImmutable('2026-07-24 10:06:00');
        $this->assertFalse($cron->isDue($time2));

        $time3 = new \DateTimeImmutable('2026-07-24 10:10:00');
        $this->assertTrue($cron->isDue($time3));
    }

    /** @test */
    public function it_parses_hour_range()
    {
        $cron = new CronExpression('0 9-17 * * *');
        $time = new \DateTimeImmutable('2026-07-24 12:00:00');
        $this->assertTrue($cron->isDue($time));

        $time2 = new \DateTimeImmutable('2026-07-24 08:00:00');
        $this->assertFalse($cron->isDue($time2));

        $time3 = new \DateTimeImmutable('2026-07-24 18:00:00');
        $this->assertFalse($cron->isDue($time3));
    }

    /** @test */
    public function it_parses_day_of_month_range()
    {
        $cron = new CronExpression('0 0 1-15 * *');
        $time = new \DateTimeImmutable('2026-07-10 00:00:00');
        $this->assertTrue($cron->isDue($time));

        $time2 = new \DateTimeImmutable('2026-07-20 00:00:00');
        $this->assertFalse($cron->isDue($time2));
    }

    /** @test */
    public function it_parses_month_range()
    {
        $cron = new CronExpression('0 0 1 6-8 *');
        $time = new \DateTimeImmutable('2026-07-01 00:00:00');
        $this->assertTrue($cron->isDue($time));

        $time2 = new \DateTimeImmutable('2026-09-01 00:00:00');
        $this->assertFalse($cron->isDue($time2));
    }

    /** @test */
    public function it_parses_day_of_week_specific_values()
    {
        $cron = new CronExpression('0 0 * * 1');
        $time = new \DateTimeImmutable('2026-07-27 00:00:00');
        $this->assertTrue($cron->isDue($time));

        $time2 = new \DateTimeImmutable('2026-07-28 00:00:00');
        $this->assertFalse($cron->isDue($time2));
    }

    /** @test */
    public function it_parses_comma_separated_values()
    {
        $cron = new CronExpression('0 0 1,15 * *');
        $time = new \DateTimeImmutable('2026-07-01 00:00:00');
        $this->assertTrue($cron->isDue($time));

        $time2 = new \DateTimeImmutable('2026-07-15 00:00:00');
        $this->assertTrue($cron->isDue($time2));

        $time3 = new \DateTimeImmutable('2026-07-10 00:00:00');
        $this->assertFalse($cron->isDue($time3));
    }

    /** @test */
    public function it_parses_step_with_range()
    {
        $cron = new CronExpression('0 9-17/2 * * *');
        // Should match 9, 11, 13, 15, 17
        $time1 = new \DateTimeImmutable('2026-07-24 09:00:00');
        $this->assertTrue($cron->isDue($time1));

        $time2 = new \DateTimeImmutable('2026-07-24 11:00:00');
        $this->assertTrue($cron->isDue($time2));

        $time3 = new \DateTimeImmutable('2026-07-24 13:00:00');
        $this->assertTrue($cron->isDue($time3));

        $time4 = new \DateTimeImmutable('2026-07-24 15:00:00');
        $this->assertTrue($cron->isDue($time4));

        $time5 = new \DateTimeImmutable('2026-07-24 17:00:00');
        $this->assertTrue($cron->isDue($time5));

        // Should NOT match 10, 12, 14, 16
        $time6 = new \DateTimeImmutable('2026-07-24 10:00:00');
        $this->assertFalse($cron->isDue($time6));

        $time7 = new \DateTimeImmutable('2026-07-24 12:00:00');
        $this->assertFalse($cron->isDue($time7));

        // Outside range
        $time8 = new \DateTimeImmutable('2026-07-24 08:00:00');
        $this->assertFalse($cron->isDue($time8));

        $time9 = new \DateTimeImmutable('2026-07-24 18:00:00');
        $this->assertFalse($cron->isDue($time9));
    }

    /** @test */
    public function it_throws_exception_for_invalid_expression()
    {
        $this->expectException(\InvalidArgumentException::class);
        new CronExpression('* * * *');
    }

    /** @test */
    public function it_parses_daily_at_specific_time()
    {
        $cron = new CronExpression('30 14 * * *');
        $time = new \DateTimeImmutable('2026-07-24 14:30:00');
        $this->assertTrue($cron->isDue($time));

        $time2 = new \DateTimeImmutable('2026-07-24 14:29:00');
        $this->assertFalse($cron->isDue($time2));
    }

    /** @test */
    public function it_parses_hourly_at_specific_minute()
    {
        $cron = new CronExpression('15 * * * *');
        $time = new \DateTimeImmutable('2026-07-24 10:15:00');
        $this->assertTrue($cron->isDue($time));

        $time2 = new \DateTimeImmutable('2026-07-24 11:15:00');
        $this->assertTrue($cron->isDue($time2));

        $time3 = new \DateTimeImmutable('2026-07-24 10:14:00');
        $this->assertFalse($cron->isDue($time3));
    }
}

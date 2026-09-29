<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Config;
use ReflectionMethod;
use Spiritix\LadaCache\LadaCacheServiceProvider;
use Spiritix\LadaCache\Tests\TestCase;

class CalibrateScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['lada-cache.enable_debugbar' => false]);
    }

    public function testScheduleIntervalRegistersDailyCalibrationWhenEnabled(): void
    {
        Config::set('lada-cache.calibration.enabled', true);
        Config::set('lada-cache.calibration.schedule_interval', 7);

        $events = $this->resolveScheduledCalibrationEvents();

        $this->assertCount(1, $events, 'Calibrate command must be scheduled exactly once.');
        $this->assertSame('0 3 * * *', $events[0]->expression);
    }

    public function testScheduleIsNotRegisteredWhenCalibrationDisabled(): void
    {
        Config::set('lada-cache.calibration.enabled', false);
        Config::set('lada-cache.calibration.schedule_interval', 7);

        $this->assertCount(
            0,
            $this->resolveScheduledCalibrationEvents(),
            'Disabled calibration must not register the schedule.',
        );
    }

    public function testScheduleIsNotRegisteredWhenScheduleIntervalIsZero(): void
    {
        Config::set('lada-cache.calibration.enabled', true);
        Config::set('lada-cache.calibration.schedule_interval', 0);

        $this->assertCount(
            0,
            $this->resolveScheduledCalibrationEvents(),
            'Zero schedule interval must opt out of auto-schedule.',
        );
    }

    public function testScheduleIntervalGateMatchesEveryNthDay(): void
    {
        Config::set('lada-cache.calibration.enabled', true);
        Config::set('lada-cache.calibration.schedule_interval', 3);

        $events = $this->resolveScheduledCalibrationEvents();

        $this->assertCount(1, $events);
        $this->assertSame('0 3 * * *', $events[0]->expression);

        $method = new ReflectionMethod(LadaCacheServiceProvider::class, 'calibrationScheduleIntervalMatches');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(null, 3, 3 * 86400));
        $this->assertFalse($method->invoke(null, 3, 4 * 86400));
        $this->assertTrue($method->invoke(null, 1, 4 * 86400));
    }

    /**
     * Resolve the Schedule fresh after applying config — `callAfterResolving` fires
     * once per container resolution, so we forget any cached binding first.
     *
     * @return array<int, \Illuminate\Console\Scheduling\Event>
     */
    private function resolveScheduledCalibrationEvents(): array
    {
        $this->app->forgetInstance(Schedule::class);
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        return array_values(array_filter(
            $schedule->events(),
            static fn ($event): bool => str_contains((string) $event->command, 'lada-cache:calibrate'),
        ));
    }
}

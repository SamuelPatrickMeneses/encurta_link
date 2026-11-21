<?php

declare(strict_types=1);

namespace Tests\Feature\Links;

use App\Console\LinkStreamSchedule;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class LinkStreamScheduleTest extends TestCase
{
    public function testAgendaOConsumidorNoIntervaloConfigurado(): void
    {
        config()->set('links.stream.consume_interval_minutes', 5);

        $schedule = new Schedule();
        LinkStreamSchedule::register($schedule);

        $events = $schedule->events();

        $this->assertCount(1, $events);
        $this->assertStringContainsString('links:consume --once', (string) $events[0]->command);
        $this->assertSame('*/5 * * * *', $events[0]->expression);
    }

    public function testIntervaloInvalidoNaoAgendaOConsumidor(): void
    {
        config()->set('links.stream.consume_interval_minutes', 0);

        $schedule = new Schedule();
        LinkStreamSchedule::register($schedule);

        $this->assertCount(0, $schedule->events());
    }
}

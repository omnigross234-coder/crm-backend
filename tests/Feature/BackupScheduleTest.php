<?php

namespace Tests\Feature;

use Tests\TestCase;

class BackupScheduleTest extends TestCase
{
    public function test_database_backup_is_scheduled_daily_at_six_pm(): void
    {
        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));

        $this->assertIsString($bootstrap);
        $this->assertStringContainsString("\$schedule->command('db:backup')", $bootstrap);
        $this->assertStringContainsString("->dailyAt('18:00')", $bootstrap);
        $this->assertStringContainsString('->withoutOverlapping(120)', $bootstrap);
    }
}

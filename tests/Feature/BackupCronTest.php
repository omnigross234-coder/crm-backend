<?php

namespace Tests\Feature;

use App\Services\BackupService;
use Mockery;
use Tests\TestCase;

class BackupCronTest extends TestCase
{
    public function test_backup_cron_rejects_an_invalid_key(): void
    {
        config(['services.cron.backup_key' => 'correct-key']);

        $this->getJson('/api/run-backup?key=wrong-key')
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'Unauthorized.',
            ]);
    }

    public function test_backup_cron_runs_with_the_configured_key(): void
    {
        config(['services.cron.backup_key' => 'correct-key']);

        $backup = Mockery::mock(BackupService::class);
        $backup->shouldReceive('run')->once()->andReturn([
            'success' => true,
            'filename' => 'crm_backup_test.sql',
            'message' => 'Backup uploaded to Google Drive',
        ]);
        $this->app->instance(BackupService::class, $backup);

        $this->getJson('/api/run-backup?key=correct-key')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Backup uploaded to Google Drive',
                'data' => ['filename' => 'crm_backup_test.sql'],
            ]);
    }
}

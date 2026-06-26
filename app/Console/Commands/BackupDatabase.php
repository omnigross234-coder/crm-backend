<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

class BackupDatabase extends Command
{
    protected $signature   = 'db:backup';
    protected $description = 'Backup CRM database to Google Drive';

    public function handle(BackupService $backup): int
    {
        $this->info('Running backup...');
        $result = $backup->run();
        $result['success'] ? $this->info('✓ ' . $result['message']) : $this->error('✗ ' . $result['message']);
        return $result['success'] ? Command::SUCCESS : Command::FAILURE;
    }
}
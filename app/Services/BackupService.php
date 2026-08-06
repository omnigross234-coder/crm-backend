<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class BackupService
{
    public function run(): array
    {
        $timestamp = Carbon::now()->format('Y-m-d_H-i-s');
        $filename = "crm_backup_{$timestamp}.sql";
        $localPath = storage_path("app/backups/{$filename}");

        $backupDirectory = storage_path('app/backups');

        if (! is_dir($backupDirectory) && ! mkdir($backupDirectory, 0755, true) && ! is_dir($backupDirectory)) {
            throw new RuntimeException('Unable to create the local backup directory.');
        }

        $this->dumpDatabase($localPath);

        $contents = file_get_contents($localPath);

        if ($contents === false) {
            throw new RuntimeException('Unable to read the generated database backup.');
        }

        $uploaded = Storage::disk('google')->put($filename, $contents);

        if (! $uploaded) {
            throw new RuntimeException(
                "Google Drive upload failed. The local backup was retained at {$localPath}."
            );
        }

        if (! unlink($localPath)) {
            logger()->warning("Uploaded backup could not be removed locally: {$localPath}");
        }

        $this->pruneOldBackups();

        return [
            'success' => (bool) $uploaded,
            'filename' => $filename,
            'message' => $uploaded ? 'Backup uploaded to Google Drive' : 'Upload failed',
        ];
    }

    private function dumpDatabase(string $outputPath): void
    {
        if (! function_exists('exec')) {
            throw new RuntimeException('PHP exec() is disabled on this server. Database backup cannot run mysqldump.');
        }

        $db = config('database.connections.'.config('database.default'));
        $host = $db['host'];
        $port = $db['port'] ?? 3306;
        $database = $db['database'];
        $username = $db['username'];
        $password = (string) ($db['password'] ?? '');
        $dumpPath = config('services.mysqldump.path', 'mysqldump');

        $passwordOption = $password !== ''
            ? ' --password='.escapeshellarg($password)
            : '';

        $command = sprintf(
            '%s --host=%s --port=%s --user=%s%s --single-transaction --quick %s --result-file=%s 2>&1',
            escapeshellarg($dumpPath),
            escapeshellarg($host),
            escapeshellarg((string) $port),
            escapeshellarg($username),
            $passwordOption,
            escapeshellarg($database),
            escapeshellarg($outputPath)
        );

        exec($command, $output, $code);

        if ($code !== 0) {
            $message = trim(implode("\n", $output));
            throw new RuntimeException('mysqldump failed'.($message !== '' ? ': '.$message : '.'));
        }

        if (! is_file($outputPath) || filesize($outputPath) === 0) {
            throw new RuntimeException('mysqldump failed: dump file was not created or is empty.');
        }
    }

    private function pruneOldBackups(int $keep = 30): void
    {
        try {
            $files = collect(Storage::disk('google')->files())
                ->filter(fn ($f) => str_starts_with(basename($f), 'crm_backup_'))
                ->sort()
                ->values();

            if ($files->count() > $keep) {
                foreach ($files->slice(0, $files->count() - $keep) as $file) {
                    Storage::disk('google')->delete($file);
                }
            }
        } catch (\Throwable $e) {
            logger()->warning('Prune failed: '.$e->getMessage());
        }
    }

    public function listBackups(): array
    {
        $files = Storage::disk('google')->files();

        return collect($files)
            ->filter(fn ($f) => str_starts_with(basename($f), 'crm_backup_'))
            ->sort()
            ->reverse()
            ->values()
            ->map(function ($f) {
                return [
                    'name' => basename($f),
                    'size' => Storage::disk('google')->size($f),
                    'modified' => Carbon::createFromTimestamp(
                        Storage::disk('google')->lastModified($f),
                        'UTC'
                    )->setTimezone(config('app.timezone'))->toDateTimeString(),
                ];
            })
            ->toArray();
    }
}

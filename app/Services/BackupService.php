<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class BackupService
{
    public function run(): array
    {
        $timestamp = Carbon::now()->format('Y-m-d_H-i-s');
        $filename  = "crm_backup_{$timestamp}.sql";
        $localPath = storage_path("app/backups/{$filename}");

        if (!is_dir(storage_path('app/backups'))) {
            mkdir(storage_path('app/backups'), 0755, true);
        }

        $this->dumpDatabase($localPath);

        $contents = file_get_contents($localPath);
        $uploaded = Storage::disk('google')->put($filename, $contents);

        @unlink($localPath);

        $this->pruneOldBackups();

        return [
            'success'  => (bool) $uploaded,
            'filename' => $filename,
            'message'  => $uploaded ? 'Backup uploaded to Google Drive' : 'Upload failed',
        ];
    }

    private function dumpDatabase(string $outputPath): void
    {
        if (!function_exists('exec')) {
            throw new \RuntimeException('PHP exec() is disabled on this server. Database backup cannot run mysqldump.');
        }

        $db       = config('database.connections.' . config('database.default'));
        $host     = $db['host'];
        $port     = $db['port'] ?? 3306;
        $database = $db['database'];
        $username = $db['username'];
        $password = (string) ($db['password'] ?? '');
        $dumpPath = config('services.mysqldump.path', 'mysqldump');

        $passwordOption = $password !== ''
            ? ' --password=' . escapeshellarg($password)
            : '';

        $command = sprintf(
            '%s --host=%s --port=%s --user=%s%s %s --result-file=%s 2>&1',
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
            throw new \RuntimeException('mysqldump failed' . ($message !== '' ? ': ' . $message : '.'));
        }

        if (!is_file($outputPath) || filesize($outputPath) === 0) {
            throw new \RuntimeException('mysqldump failed: dump file was not created or is empty.');
        }
    }

    private function pruneOldBackups(int $keep = 30): void
    {
        try {
            $files = collect(Storage::disk('google')->files())
                ->filter(fn($f) => str_starts_with(basename($f), 'crm_backup_'))
                ->sort()
                ->values();

            if ($files->count() > $keep) {
                foreach ($files->slice(0, $files->count() - $keep) as $file) {
                    Storage::disk('google')->delete($file);
                }
            }
        } catch (\Throwable $e) {
            logger()->warning('Prune failed: ' . $e->getMessage());
        }
    }

    public function listBackups(): array
    {
        $files = Storage::disk('google')->files();

        return collect($files)
            ->filter(fn($f) => str_starts_with(basename($f), 'crm_backup_'))
            ->sort()
            ->reverse()
            ->values()
            ->map(function ($f) {
                return [
                    'name'     => basename($f),
                    'size'     => Storage::disk('google')->size($f),
                    'modified' => Carbon::createFromTimestamp(
                        Storage::disk('google')->lastModified($f),
                        'UTC'
                    )->setTimezone(config('app.timezone'))->toDateTimeString(),
                ];
            })
            ->toArray();
    }
}

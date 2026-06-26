<?php

namespace App\Http\Controllers;

use App\Services\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BackupController extends Controller
{
    public function __construct(private BackupService $backup) {}

    public function run(): JsonResponse
    {
        try {
            $result = $this->backup->run();
            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'],
                'data'    => ['filename' => $result['filename']],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Backup failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function runFromCron(Request $request): JsonResponse
    {
        $expectedKey = (string) config('services.cron.backup_key', '');
        $providedKey = (string) $request->query('key', '');

        if ($expectedKey === '' || ! hash_equals($expectedKey, $providedKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 403);
        }

        return $this->run();
    }

    public function list(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->backup->listBackups(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}

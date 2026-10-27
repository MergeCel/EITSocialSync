<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ImportGoogleDriveMediaJob;
use App\Models\MediaTask;
use App\Services\AppStateService;
use App\Services\GoogleDriveService;
use App\Services\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class GoogleDriveController extends Controller
{
    public function __construct(
        private readonly GoogleDriveService $drive,
        private readonly AppStateService $state,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:180'],
        ]);

        try {
            $files = $this->drive->listFiles($validated['search'] ?? null);
        } catch (Throwable $exception) {
            return $this->driveError($exception);
        }

        return response()->json(['files' => $files]);
    }

    public function import(Request $request): JsonResponse
    {
        $this->requireEditor($request);

        $validated = $request->validate([
            'fileId' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['nullable', 'string', 'max:180'],
            'folderId' => ['nullable', 'uuid', 'exists:folders,id'],
        ]);

        try {
            $file = $this->drive->file($validated['fileId']);
            $allowed = array_merge(MediaService::IMAGE_MIMES, MediaService::VIDEO_MIMES);

            if (! in_array($file['mimeType'], $allowed, true)) {
                return response()->json(['message' => 'File Google Drive ini bukan foto atau video yang didukung.'], 422);
            }

            if ($file['size'] > (int) config('media.max_size', 512 * 1024 * 1024)) {
                return response()->json(['message' => 'File Google Drive melebihi batas 512 MB.'], 422);
            }

            $name = trim((string) ($validated['name'] ?? '')) ?: pathinfo($file['name'], PATHINFO_FILENAME);
            $task = MediaTask::query()->create([
                'user_id' => $request->user()->id,
                'source' => 'google-drive',
                'status' => 'queued',
                'progress' => 5,
                'name' => Str::limit($name, 180, ''),
            ]);

            ImportGoogleDriveMediaJob::dispatch(
                $task,
                $file['id'],
                $file['name'],
                $file['mimeType'],
                $validated['folderId'] ?? null,
            );

            return response()->json(['task' => $this->state->mediaTask($task)], 202);
        } catch (Throwable $exception) {
            return $this->driveError($exception);
        }
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $this->drive->disconnect();

        activity()
            ->causedBy($request->user())
            ->withProperties(['subject' => 'Google Drive'])
            ->log('memutus Google Drive');

        return response()->json(['message' => 'Google Drive diputus dan token dihapus.']);
    }

    private function driveError(Throwable $exception): JsonResponse
    {
        report($exception);

        $message = $exception->getMessage();
        $reconnect = str_contains($message, 'berakhir')
            || str_contains($message, 'belum terhubung')
            || str_contains($message, 'Hubungkan ulang');

        return response()->json([
            'message' => $message,
            'reconnect' => $reconnect,
        ], 422);
    }
}

<?php

namespace App\Jobs;

use App\Models\AppNotification;
use App\Models\MediaTask;
use App\Services\GoogleDriveService;
use App\Services\MediaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ImportGoogleDriveMediaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 840;

    public function __construct(
        public MediaTask $task,
        public string $driveFileId,
        public string $originalName,
        public string $mimeType,
        public ?string $folderId,
    ) {}

    public function handle(GoogleDriveService $drive, MediaService $mediaService): void
    {
        $directory = storage_path('app/private/google-drive');
        $temporaryPath = $directory.DIRECTORY_SEPARATOR.Str::uuid().'.'.$this->extension();

        File::ensureDirectoryExists($directory);
        $this->task->update(['status' => 'processing', 'progress' => 10, 'error_message' => null]);

        try {
            $lastProgress = 10;
            $drive->download(
                $this->driveFileId,
                $temporaryPath,
                function (int $total, int $downloaded) use (&$lastProgress): void {
                    if ($total <= 0) {
                        return;
                    }

                    $progress = min(70, 10 + (int) floor(($downloaded / $total) * 60));

                    if ($progress >= $lastProgress + 2) {
                        $this->task->update(['progress' => $progress]);
                        $lastProgress = $progress;
                    }
                },
            );

            if (! File::exists($temporaryPath)) {
                throw new RuntimeException('File Google Drive tidak berhasil diunduh.');
            }

            if (File::size($temporaryPath) > (int) config('media.max_size', 512 * 1024 * 1024)) {
                throw new RuntimeException('File Google Drive melebihi batas 512 MB.');
            }

            $this->task->update(['progress' => 80]);
            $uploadedFile = new UploadedFile(
                $temporaryPath,
                $this->originalName,
                $this->mimeType,
                null,
                true,
            );

            $media = $mediaService->storeUploadedFile(
                $uploadedFile,
                (string) $this->task->user_id,
                $this->task->name,
                $this->folderId,
                true,
            );

            $this->task->update([
                'media_file_id' => $media->id,
                'status' => 'completed',
                'progress' => 100,
            ]);

            AppNotification::query()->create([
                'user_id' => $this->task->user_id,
                'type' => 'success',
                'title' => 'Impor Google Drive selesai',
                'message' => "{$this->task->name} sudah siap digunakan.",
            ]);

            if ($this->task->user !== null) {
                activity()
                    ->causedBy($this->task->user)
                    ->withProperties(['subject' => $media->custom_name])
                    ->log('mengimpor media dari Google Drive');
            }
        } finally {
            File::delete($temporaryPath);
        }
    }

    public function backoff(): array
    {
        return [15, 60];
    }

    public function failed(?Throwable $exception): void
    {
        $task = MediaTask::query()->find($this->task->id);

        if ($task === null) {
            return;
        }

        $task->update([
            'status' => 'failed',
            'error_message' => 'Impor Google Drive gagal. Silakan coba kembali.',
        ]);

        AppNotification::query()->create([
            'user_id' => $task->user_id,
            'type' => 'error',
            'title' => 'Impor Google Drive gagal',
            'message' => "{$task->name} tidak dapat diimpor.",
        ]);
    }

    private function extension(): string
    {
        return match ($this->mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            default => 'bin',
        };
    }
}

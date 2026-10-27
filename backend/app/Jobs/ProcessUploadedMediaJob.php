<?php

namespace App\Jobs;

use App\Models\AppNotification;
use App\Models\MediaTask;
use App\Services\MediaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

class ProcessUploadedMediaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 840;

    public function __construct(
        public MediaTask $task,
        public string $pendingPath,
        public string $originalName,
        public string $mimeType,
        public ?string $folderId,
    ) {}

    public function handle(MediaService $mediaService): void
    {
        $this->task->update(['status' => 'processing', 'progress' => 70, 'error_message' => null]);
        $attemptPath = dirname($this->pendingPath).DIRECTORY_SEPARATOR.Str::uuid().'.processing';

        if (! File::exists($this->pendingPath) || ! File::copy($this->pendingPath, $attemptPath)) {
            throw new \RuntimeException('File upload sementara tidak tersedia.');
        }

        $uploadedFile = new UploadedFile(
            $attemptPath,
            $this->originalName,
            $this->mimeType,
            null,
            true,
        );

        try {
            $this->task->update(['progress' => 80]);
            $media = $mediaService->storeUploadedFile(
                $uploadedFile,
                (string) $this->task->user_id,
                $this->task->name,
                $this->folderId,
                true,
            );
        } finally {
            File::delete($attemptPath);
        }

        $this->task->update([
            'media_file_id' => $media->id,
            'status' => 'completed',
            'progress' => 100,
        ]);

        AppNotification::query()->create([
            'user_id' => $this->task->user_id,
            'type' => 'success',
            'title' => 'Upload media selesai',
            'message' => "{$this->task->name} sudah siap digunakan.",
        ]);

        if ($this->task->user !== null) {
            activity()
                ->causedBy($this->task->user)
                ->withProperties(['subject' => $media->custom_name])
                ->log('mengunggah media');
        }

        File::delete($this->pendingPath);
    }

    public function backoff(): array
    {
        return [10, 30];
    }

    public function failed(?Throwable $exception): void
    {
        $task = MediaTask::query()->find($this->task->id);

        if ($task === null) {
            File::delete($this->pendingPath);

            return;
        }

        $task->update([
            'status' => 'failed',
            'error_message' => 'Pemrosesan media gagal. Silakan coba upload kembali.',
        ]);

        AppNotification::query()->create([
            'user_id' => $task->user_id,
            'type' => 'error',
            'title' => 'Upload media gagal',
            'message' => "{$task->name} tidak dapat diproses.",
        ]);

        File::delete($this->pendingPath);
    }
}

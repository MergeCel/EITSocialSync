<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessUploadedMediaJob;
use App\Models\MediaFile;
use App\Models\MediaTask;
use App\Services\AppStateService;
use App\Services\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class MediaController extends Controller
{
    public function __construct(
        private readonly MediaService $mediaService,
        private readonly AppStateService $state,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $this->requireEditor($request);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:524288', 'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/quicktime'],
            'name' => ['required', 'string', 'max:180'],
            'folderId' => ['nullable', 'uuid', 'exists:folders,id'],
        ]);

        $mimeType = $validated['file']->getMimeType() ?: $validated['file']->getClientMimeType();

        if (in_array($mimeType, MediaService::VIDEO_MIMES, true)) {
            $originalName = $validated['file']->getClientOriginalName();
            $task = MediaTask::query()->create([
                'user_id' => $request->user()->id,
                'source' => 'upload',
                'status' => 'queued',
                'progress' => 60,
                'name' => trim($validated['name']),
            ]);
            $directory = storage_path('app/private/pending-media');
            $extension = $mimeType === 'video/quicktime' ? 'mov' : 'mp4';
            $pendingPath = $directory.DIRECTORY_SEPARATOR."{$task->id}.{$extension}";

            File::ensureDirectoryExists($directory);
            $validated['file']->move($directory, basename($pendingPath));

            ProcessUploadedMediaJob::dispatch(
                $task,
                $pendingPath,
                $originalName,
                $mimeType,
                $validated['folderId'] ?? null,
            );

            return response()->json(['task' => $this->state->mediaTask($task)], 202);
        }

        $media = $this->mediaService->storeUploadedFile(
            $validated['file'],
            $request->user()->id,
            $validated['name'],
            $validated['folderId'] ?? null,
        );

        $this->log($request, 'mengunggah media', $media->custom_name);

        return response()->json($this->state->media($media), 201);
    }

    public function initChunkedUpload(Request $request): JsonResponse
    {
        $this->requireEditor($request);

        $validated = $request->validate([
            'fileName' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1', 'max:536870912'],
            'mimeType' => ['required', 'string', 'in:image/jpeg,image/png,image/webp,video/mp4,video/quicktime'],
            'name' => ['required', 'string', 'max:180'],
            'folderId' => ['nullable', 'uuid', 'exists:folders,id'],
        ]);

        return response()->json($this->mediaService->initialiseChunkedUpload(
            $request->user()->id,
            $validated['fileName'],
            $validated['mimeType'],
            (int) $validated['size'],
            $validated['folderId'] ?? null,
            $validated['name'],
        ), 201);
    }

    public function uploadChunk(Request $request, string $uploadId): JsonResponse
    {
        $this->requireEditor($request);

        $validated = $request->validate([
            'index' => ['required', 'integer', 'min:0'],
            'chunk' => ['required', 'file', 'max:6144'],
        ]);

        $this->mediaService->storeChunk(
            $request->user()->id,
            $uploadId,
            (int) $validated['index'],
            $validated['chunk'],
        );

        return response()->json(['message' => 'Chunk diterima.']);
    }

    public function completeChunkedUpload(Request $request, string $uploadId): JsonResponse
    {
        $this->requireEditor($request);

        $media = $this->mediaService->completeChunkedUpload($request->user()->id, $uploadId);
        $this->log($request, 'mengunggah media', $media->custom_name);

        return response()->json($this->state->media($media), 201);
    }

    public function update(Request $request, MediaFile $mediaFile): JsonResponse
    {
        $this->requireEditor($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:180'],
            'folderId' => ['sometimes', 'nullable', 'uuid', 'exists:folders,id'],
        ]);

        $data = [];

        if (array_key_exists('name', $validated)) {
            $data['custom_name'] = trim($validated['name']);
        }

        if (array_key_exists('folderId', $validated)) {
            $data['folder_id'] = $validated['folderId'];
        }

        $mediaFile->update($data);

        return response()->json($this->state->media($mediaFile->fresh()));
    }

    public function destroy(Request $request, MediaFile $mediaFile): JsonResponse
    {
        $this->requireEditor($request);

        $used = $mediaFile->posts()
            ->whereIn('status', ['scheduled', 'publishing'])
            ->exists();

        if ($used) {
            return response()->json([
                'message' => 'Media masih digunakan oleh post terjadwal atau yang sedang dipublikasikan.',
            ], 422);
        }

        $name = $mediaFile->custom_name;
        $this->mediaService->deleteFiles($mediaFile);
        $mediaFile->delete();
        $this->log($request, 'menghapus media', $name);

        return response()->json(['message' => 'Media dihapus.']);
    }

    private function log(Request $request, string $action, string $subject): void
    {
        activity()
            ->causedBy($request->user())
            ->withProperties(['subject' => $subject])
            ->log($action);
    }
}

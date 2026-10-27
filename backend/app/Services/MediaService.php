<?php

namespace App\Services;

use App\Models\MediaFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class MediaService
{
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public const VIDEO_MIMES = ['video/mp4', 'video/quicktime'];

    /**
     * @return array{uploadId: string, chunkSize: int, totalChunks: int}
     */
    public function initialiseChunkedUpload(
        string $userId,
        string $originalName,
        string $mimeType,
        int $size,
        ?string $folderId,
        string $name,
    ): array {
        $chunkSize = 5 * 1024 * 1024;
        $uploadId = (string) Str::uuid();

        Cache::put("media-upload:{$uploadId}", [
            'user_id' => $userId,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'size' => $size,
            'folder_id' => $folderId,
            'name' => $name,
            'chunk_size' => $chunkSize,
            'total_chunks' => (int) ceil($size / $chunkSize),
        ], now()->addHours(24));

        File::ensureDirectoryExists($this->chunkDirectory($uploadId));

        return [
            'uploadId' => $uploadId,
            'chunkSize' => $chunkSize,
            'totalChunks' => (int) ceil($size / $chunkSize),
        ];
    }

    public function storeChunk(string $userId, string $uploadId, int $index, UploadedFile $chunk): void
    {
        $meta = $this->uploadMeta($uploadId, $userId);

        abort_if($index < 0 || $index >= $meta['total_chunks'], 422, 'Indeks chunk tidak valid.');

        $chunk->move($this->chunkDirectory($uploadId), "{$index}.part");
    }

    public function completeChunkedUpload(string $userId, string $uploadId): MediaFile
    {
        $meta = $this->uploadMeta($uploadId, $userId);
        $directory = $this->chunkDirectory($uploadId);
        $assembled = $directory.DIRECTORY_SEPARATOR.'assembled';

        $handle = fopen($assembled, 'wb');

        try {
            for ($index = 0; $index < $meta['total_chunks']; $index++) {
                $part = $directory.DIRECTORY_SEPARATOR."{$index}.part";

                if (! File::exists($part)) {
                    abort(422, "Chunk {$index} belum diterima.");
                }

                $source = fopen($part, 'rb');
                stream_copy_to_stream($source, $handle);
                fclose($source);
            }
        } finally {
            fclose($handle);
        }

        $uploaded = new UploadedFile(
            $assembled,
            $meta['original_name'],
            $meta['mime_type'],
            null,
            true,
        );

        $media = $this->storeUploadedFile(
            $uploaded,
            $userId,
            $meta['name'],
            $meta['folder_id'],
            true,
        );

        File::deleteDirectory($directory);
        Cache::forget("media-upload:{$uploadId}");

        return $media;
    }

    public function storeUploadedFile(
        UploadedFile $file,
        string $userId,
        string $name,
        ?string $folderId = null,
        bool $alreadyValidated = false,
    ): MediaFile {
        $mimeType = $file->getMimeType() ?: $file->getClientMimeType();
        $isImage = in_array($mimeType, self::IMAGE_MIMES, true);
        $isVideo = in_array($mimeType, self::VIDEO_MIMES, true);

        abort_unless($isImage || $isVideo, 422, 'Tipe file tidak didukung.');

        $id = (string) Str::uuid();
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: ($isImage ? 'jpg' : 'mp4'));
        $filename = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'media';
        $relativePath = "originals/{$id}/{$filename}.{$extension}";
        $absolutePath = Storage::disk('media')->path($relativePath);

        File::ensureDirectoryExists(dirname($absolutePath));
        File::move($file->getRealPath(), $absolutePath);

        $size = File::size($absolutePath);
        $metadata = $isImage ? $this->inspectImage($absolutePath) : $this->inspectVideo($absolutePath);
        $thumbnailPath = $isImage
            ? $this->createImageThumbnail($absolutePath, $id)
            : $this->createVideoThumbnail($absolutePath, $id);

        return MediaFile::query()->create([
            'folder_id' => $folderId,
            'original_filename' => $file->getClientOriginalName(),
            'custom_name' => trim($name),
            'path' => $relativePath,
            'thumbnail_path' => $thumbnailPath,
            'mime_type' => $mimeType,
            'size' => $size,
            'kind' => $isImage ? 'image' : 'video',
            'duration' => $metadata['duration'] ?? null,
            'width' => $metadata['width'] ?? null,
            'height' => $metadata['height'] ?? null,
            'uploaded_by' => $userId,
        ]);
    }

    public function deleteFiles(MediaFile $media): void
    {
        $disk = Storage::disk('media');

        if ($media->path) {
            $disk->delete($media->path);
        }

        if ($media->thumbnail_path) {
            $disk->delete($media->thumbnail_path);
        }
    }

    /**
     * @return array{duration?: int|null, width?: int|null, height?: int|null}
     */
    private function inspectImage(string $absolutePath): array
    {
        $size = @getimagesize($absolutePath);

        if (! $size) {
            return [];
        }

        return ['width' => $size[0], 'height' => $size[1], 'duration' => null];
    }

    /**
     * @return array{duration?: int|null, width?: int|null, height?: int|null}
     */
    private function inspectVideo(string $absolutePath): array
    {
        $binary = (string) config('media.ffprobe', 'ffprobe');

        $process = new Process([
            $binary,
            '-v', 'quiet',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            $absolutePath,
        ]);
        $process->setTimeout(30);

        try {
            $process->run();
        } catch (\Throwable) {
            return [];
        }

        if (! $process->isSuccessful()) {
            return [];
        }

        $data = json_decode($process->getOutput(), true);
        $stream = collect($data['streams'] ?? [])->firstWhere('codec_type', 'video');

        if (! $stream) {
            return [];
        }

        return [
            'width' => isset($stream['width']) ? (int) $stream['width'] : null,
            'height' => isset($stream['height']) ? (int) $stream['height'] : null,
            'duration' => isset($data['format']['duration']) ? (int) round((float) $data['format']['duration']) : null,
        ];
    }

    private function createImageThumbnail(string $absolutePath, string $id): ?string
    {
        $contents = @file_get_contents($absolutePath);
        $image = $contents ? @imagecreatefromstring($contents) : false;

        if (! $image) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $maxWidth = (int) config('media.thumbnail_width', 720);

        if ($width > $maxWidth) {
            $newWidth = $maxWidth;
            $newHeight = (int) round($height * ($maxWidth / $width));
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagefill($resized, 0, 0, imagecolorallocate($resized, 249, 247, 247));
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        $relativePath = "thumbnails/{$id}.jpg";
        $target = Storage::disk('media')->path($relativePath);
        File::ensureDirectoryExists(dirname($target));
        imagejpeg($image, $target, 82);
        imagedestroy($image);

        return $relativePath;
    }

    private function createVideoThumbnail(string $absolutePath, string $id): ?string
    {
        $binary = (string) config('media.ffmpeg', 'ffmpeg');
        $relativePath = "thumbnails/{$id}.jpg";
        $target = Storage::disk('media')->path($relativePath);
        File::ensureDirectoryExists(dirname($target));

        $process = new Process([
            $binary,
            '-ss', '0.5',
            '-i', $absolutePath,
            '-frames:v', '1',
            '-vf', 'scale=720:-2',
            '-y',
            $target,
        ]);
        $process->setTimeout(60);

        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        return $process->isSuccessful() && File::exists($target) ? $relativePath : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadMeta(string $uploadId, string $userId): array
    {
        $meta = Cache::get("media-upload:{$uploadId}");

        abort_if(! is_array($meta) || $meta['user_id'] !== $userId, 404, 'Sesi upload tidak ditemukan atau sudah kedaluwarsa.');

        return $meta;
    }

    private function chunkDirectory(string $uploadId): string
    {
        return storage_path("app/private/chunks/{$uploadId}");
    }
}

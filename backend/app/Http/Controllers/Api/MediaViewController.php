<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaViewController extends Controller
{
    public function __invoke(Request $request, MediaFile $mediaFile): BinaryFileResponse
    {
        $variant = $request->query('variant', 'original');
        $path = $variant === 'thumb' && $mediaFile->thumbnail_path
            ? $mediaFile->thumbnail_path
            : $mediaFile->path;

        abort_unless($path && Storage::disk('media')->exists($path), 404);

        $absolutePath = Storage::disk('media')->path($path);
        $mimeType = $variant === 'thumb' && $mediaFile->thumbnail_path
            ? 'image/jpeg'
            : $mediaFile->mime_type;

        return response()->file($absolutePath, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="'.basename($path).'"',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

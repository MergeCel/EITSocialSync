<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Folder;
use App\Models\MediaFile;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\AppStateService;
use App\Services\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Spatie\Activitylog\Models\Activity;

class SettingsController extends Controller
{
    public function __construct(
        private readonly AppStateService $state,
        private readonly MediaService $mediaService,
    ) {}

    public function export(Request $request): JsonResponse
    {
        return response()->json(
            $this->state->export($request->user()),
            200,
            ['Content-Disposition' => 'attachment; filename="socialsync-backup-'.now()->format('Y-m-d').'.json"'],
        );
    }

    public function reset(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        abort_unless(app()->environment('local', 'testing'), 403, 'Reset hanya diizinkan pada lingkungan lokal.');

        DB::transaction(function (): void {
            $media = MediaFile::query()->get();

            foreach ($media as $file) {
                $this->mediaService->deleteFiles($file);
            }

            DB::table('post_media')->delete();
            DB::table('engagement_stats')->delete();
            DB::table('post_platforms')->delete();
            Post::query()->delete();
            MediaFile::query()->delete();
            Folder::query()->delete();
            SocialAccount::query()->delete();
            AppNotification::query()->delete();
            Activity::query()->delete();
            File::deleteDirectory(storage_path('app/private/chunks'));
        });

        collect(['Kampanye', 'Behind The Scene', 'Produk & Katalog'])
            ->each(fn (string $name) => Folder::query()->create([
                'name' => $name,
                'created_by' => $request->user()->id,
            ]));

        AppNotification::query()->create([
            'user_id' => $request->user()->id,
            'type' => 'success',
            'title' => 'Workspace direset',
            'message' => 'Seluruh data operasional telah dibersihkan. Folder contoh siap digunakan.',
        ]);

        activity()
            ->causedBy($request->user())
            ->withProperties(['subject' => 'Workspace'])
            ->log('mereset workspace');

        return response()->json($this->state->forUser($request->user()->fresh('roles')));
    }
}

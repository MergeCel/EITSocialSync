<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Folder;
use App\Services\AppStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FolderController extends Controller
{
    public function __construct(private readonly AppStateService $state) {}

    public function store(Request $request): JsonResponse
    {
        $this->requireEditor($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'parentId' => ['nullable', 'uuid', 'exists:folders,id'],
        ]);

        $folder = Folder::query()->create([
            'name' => trim($validated['name']),
            'parent_id' => $validated['parentId'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $this->log($request, 'membuat folder', $folder->name);

        return response()->json($this->state->folder($folder), 201);
    }

    public function update(Request $request, Folder $folder): JsonResponse
    {
        $this->requireEditor($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $folder->update(['name' => trim($validated['name'])]);
        $this->log($request, 'mengubah folder', $folder->name);

        return response()->json($this->state->folder($folder));
    }

    public function destroy(Request $request, Folder $folder): JsonResponse
    {
        $this->requireEditor($request);

        $folder->media()->update(['folder_id' => null]);
        $name = $folder->name;
        $folder->delete();
        $this->log($request, 'menghapus folder', $name);

        return response()->json(['message' => 'Folder dihapus.']);
    }

    private function log(Request $request, string $action, string $subject): void
    {
        activity()
            ->causedBy($request->user())
            ->withProperties(['subject' => $subject])
            ->log($action);
    }
}

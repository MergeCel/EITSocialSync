<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AppStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(private readonly AppStateService $state) {}

    public function update(Request $request, User $user): JsonResponse
    {
        $this->requireAdmin($request);

        $validated = $request->validate([
            'active' => ['sometimes', 'boolean'],
            'role' => ['sometimes', Rule::in(['Admin', 'Editor', 'Viewer'])],
        ]);

        if (array_key_exists('active', $validated)) {
            if ($user->id === $request->user()->id) {
                return response()->json(['message' => 'Anda tidak dapat menonaktifkan akun sendiri.'], 422);
            }

            if (! $validated['active'] && $user->hasRole('Admin')) {
                $remainingAdmins = User::query()->role('Admin')->whereKeyNot($user->id)->count();

                if ($remainingAdmins === 0) {
                    return response()->json(['message' => 'Workspace harus memiliki minimal satu Admin aktif.'], 422);
                }
            }

            $user->update(['active' => (bool) $validated['active']]);
        }

        if (array_key_exists('role', $validated)) {
            if ($user->hasRole('Admin') && $validated['role'] !== 'Admin') {
                $remainingAdmins = User::query()->role('Admin')->whereKeyNot($user->id)->count();

                if ($remainingAdmins === 0) {
                    return response()->json(['message' => 'Workspace harus memiliki minimal satu Admin.'], 422);
                }
            }

            $user->syncRoles([$validated['role']]);
        }

        activity()
            ->causedBy($request->user())
            ->withProperties(['subject' => $user->name])
            ->log('memperbarui pengguna');

        return response()->json($this->state->user($user->fresh('roles')));
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AppStateService;
use App\Services\BufferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class BufferController extends Controller
{
    public function __construct(
        private readonly BufferService $buffer,
        private readonly AppStateService $state,
    ) {}

    public function sync(Request $request): JsonResponse
    {
        $connection = $request->user()?->bufferConnection;

        if (! $connection || $connection->status !== 'active') {
            return response()->json([
                'message' => 'Akun ini belum diprovisikan dengan API key Buffer. Hubungi pengelola server.',
            ], 422);
        }

        try {
            $accounts = $this->buffer->syncChannels($connection);

            activity()
                ->causedBy($request->user())
                ->withProperties(['subject' => count($accounts).' channel Buffer'])
                ->log('menyinkronkan channel Buffer');

            return response()->json([
                'accounts' => collect($accounts)->map(fn ($account): array => $this->state->account($account))->values()->all(),
                'bufferConnection' => $this->state->bufferConnection($request->user()),
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $connection->update([
                'last_error' => mb_substr($exception->getMessage(), 0, 1000),
            ]);

            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}

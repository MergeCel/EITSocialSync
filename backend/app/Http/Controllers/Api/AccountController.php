<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Services\AppStateService;
use App\Services\Social\Exceptions\DisconnectException;
use App\Services\Social\Exceptions\RefreshTokenException;
use App\Services\Social\SocialManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function __construct(
        private readonly SocialManager $manager,
        private readonly AppStateService $state,
    ) {}

    public function refresh(Request $request, SocialAccount $socialAccount): JsonResponse
    {
        $this->requireAdmin($request);

        try {
            $refreshed = $this->manager->adapter($socialAccount->platform)->refresh($socialAccount);

            if (! $refreshed) {
                $socialAccount->update([
                    'status' => 'connected',
                    'token_expires_at' => now()->addDays(59),
                ]);
            }

            $followers = $this->manager->adapter($socialAccount->platform)->followers($socialAccount->fresh());

            if ($followers !== null) {
                $socialAccount->update(['followers' => $followers]);
            }
        } catch (RefreshTokenException $exception) {
            $socialAccount->update(['status' => 'expired']);

            return response()->json([
                'message' => 'Token platform perlu diperbarui melalui OAuth ulang.',
                'reconnect' => true,
                'account' => $this->state->account($socialAccount->fresh()),
            ], 422);
        } catch (DisconnectException $exception) {
            $socialAccount->update(['status' => 'disconnected', 'access_token' => null, 'refresh_token' => null]);

            return response()->json([
                'message' => $exception->getMessage(),
                'reconnect' => true,
                'account' => $this->state->account($socialAccount->fresh()),
            ], 422);
        } catch (\Throwable $exception) {
            $socialAccount->update(['status' => 'error']);

            return response()->json([
                'message' => 'Gagal memperbarui token: '.$exception->getMessage(),
                'account' => $this->state->account($socialAccount->fresh()),
            ], 422);
        }

        activity()
            ->causedBy($request->user())
            ->withProperties(['subject' => $socialAccount->account_name])
            ->log('memperbarui token akun');

        return response()->json($this->state->account($socialAccount->fresh()));
    }

    public function disconnect(Request $request, SocialAccount $socialAccount): JsonResponse
    {
        $this->requireAdmin($request);

        $socialAccount->update([
            'access_token' => null,
            'refresh_token' => null,
            'status' => 'disconnected',
            'token_expires_at' => null,
        ]);

        activity()
            ->causedBy($request->user())
            ->withProperties(['subject' => $socialAccount->account_name])
            ->log('memutus akun');

        return response()->json([
            'message' => 'Koneksi akun diputus dan token dihapus.',
            'account' => $this->state->account($socialAccount->fresh()),
        ]);
    }
}

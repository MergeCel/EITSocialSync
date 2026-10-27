<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function markRead(Request $request, AppNotification $notification): JsonResponse
    {
        abort_unless(
            $notification->user_id === null || $notification->user_id === $request->user()->id,
            403,
        );

        $notification->update(['read_at' => now()]);

        return response()->json(['message' => 'Notifikasi dibaca.']);
    }

    public function markAll(Request $request): JsonResponse
    {
        AppNotification::query()
            ->where(fn ($query) => $query->where('user_id', $request->user()->id)->orWhereNull('user_id'))
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Semua notifikasi dibaca.']);
    }
}

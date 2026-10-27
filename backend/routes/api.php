<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BootstrapController;
use App\Http\Controllers\Api\BufferController;
use App\Http\Controllers\Api\FolderController;
use App\Http\Controllers\Api\GoogleDriveController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\MediaViewController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OAuthController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/media/{mediaFile}/view', MediaViewController::class)->middleware('signed')->name('media.public');

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::get('/oauth/{platform}/callback', [OAuthController::class, 'callback'])->name('oauth.callback');

Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
    Route::get('/bootstrap', BootstrapController::class);

    Route::post('/folders', [FolderController::class, 'store']);
    Route::patch('/folders/{folder}', [FolderController::class, 'update']);
    Route::delete('/folders/{folder}', [FolderController::class, 'destroy']);

    Route::post('/media', [MediaController::class, 'store']);
    Route::post('/media/upload/init', [MediaController::class, 'initChunkedUpload']);
    Route::post('/media/upload/{uploadId}/chunk', [MediaController::class, 'uploadChunk']);
    Route::post('/media/upload/{uploadId}/complete', [MediaController::class, 'completeChunkedUpload']);
    Route::patch('/media/{mediaFile}', [MediaController::class, 'update']);
    Route::delete('/media/{mediaFile}', [MediaController::class, 'destroy']);

    Route::post('/posts', [PostController::class, 'store']);
    Route::put('/posts/{post}', [PostController::class, 'update']);
    Route::delete('/posts/{post}', [PostController::class, 'destroy']);
    Route::post('/posts/{post}/cancel', [PostController::class, 'cancel']);
    Route::post('/posts/{post}/publish', [PostController::class, 'publish']);

    Route::get('/oauth/{platform}/redirect', [OAuthController::class, 'redirect']);
    Route::post('/accounts/{socialAccount}/refresh', [AccountController::class, 'refresh']);
    Route::post('/accounts/{socialAccount}/disconnect', [AccountController::class, 'disconnect']);

    Route::patch('/users/{user}', [UserController::class, 'update']);

    Route::post('/buffer/sync', [BufferController::class, 'sync']);

    Route::patch('/notifications/{notification}', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAll']);

    Route::get('/settings/export', [SettingsController::class, 'export']);
    Route::post('/settings/reset', [SettingsController::class, 'reset']);

    Route::get('/drive/files', [GoogleDriveController::class, 'index']);
    Route::post('/drive/import', [GoogleDriveController::class, 'import']);
    Route::post('/drive/disconnect', [GoogleDriveController::class, 'disconnect']);
});

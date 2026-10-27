<?php

use App\Http\Controllers\Api\GoogleAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/app/'));

Route::get('/api/auth/google/callback', [GoogleAuthController::class, 'callback']);
Route::get('/api/drive/google/redirect', [GoogleAuthController::class, 'driveRedirect']);

Route::get('/app/{any?}', function () {
    $index = public_path('app/index.html');

    abort_unless(file_exists($index), 503, 'Frontend build belum tersedia. Jalankan npm run build.');

    return response()->file($index, ['Content-Type' => 'text/html']);
})->where('any', '.*');

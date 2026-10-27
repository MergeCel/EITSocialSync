<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    protected function requireAdmin(Request $request): void
    {
        abort_unless($request->user()?->hasRole('Admin'), 403, 'Aksi ini hanya tersedia untuk Admin.');
    }

    protected function requireEditor(Request $request): void
    {
        abort_unless(
            $request->user()?->hasAnyRole(['Admin', 'Editor']),
            403,
            'Aksi ini hanya tersedia untuk Admin atau Editor.',
        );
    }
}

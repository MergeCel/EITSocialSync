<?php

namespace Database\Seeders;

use App\Models\AppNotification;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Admin', 'Editor', 'Viewer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $users = [
            ['name' => 'Nadia Putri', 'email' => 'nadia@socialsync.id', 'role' => 'Admin'],
            ['name' => 'Raka Pratama', 'email' => 'raka@socialsync.id', 'role' => 'Editor'],
            ['name' => 'Maya Anindita', 'email' => 'maya@socialsync.id', 'role' => 'Viewer'],
        ];

        foreach ($users as $item) {
            $user = User::query()->updateOrCreate(
                ['email' => $item['email']],
                [
                    'name' => $item['name'],
                    'password' => Hash::make('SocialSync#2026'),
                    'active' => true,
                    'initials' => collect(explode(' ', $item['name']))
                        ->map(fn (string $part): string => mb_substr($part, 0, 1))
                        ->take(2)
                        ->implode(''),
                ],
            );
            $user->syncRoles([$item['role']]);
        }

        $admin = User::query()->where('email', 'nadia@socialsync.id')->first();

        foreach (['Kampanye', 'Behind The Scene', 'Produk & Katalog'] as $name) {
            Folder::query()->firstOrCreate(
                ['name' => $name, 'created_by' => $admin->id],
                ['parent_id' => null],
            );
        }

        AppNotification::query()->firstOrCreate(
            ['user_id' => $admin->id, 'title' => 'Workspace SocialSync siap'],
            [
                'type' => 'info',
                'message' => 'Hubungkan akun Instagram, TikTok, dan Facebook untuk mulai menjadwalkan konten.',
            ],
        );
    }
}

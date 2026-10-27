<?php

namespace App\Console\Commands;

use App\Models\BufferConnection;
use App\Models\User;
use App\Services\BufferService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Throwable;

#[Signature('socialsync:user-provision
    {--email= : Email kerja pengguna}
    {--name= : Nama lengkap pengguna baru}
    {--role=Editor : Admin, Editor, atau Viewer}
    {--password-file= : File privat yang berisi password pengguna}
    {--buffer-key-file= : File privat yang berisi personal API key Buffer}
    {--without-buffer : Buat atau perbarui pengguna tanpa koneksi Buffer}')]
#[Description('Buat/perbarui pengguna SocialSync dan simpan personal API key Buffer secara terenkripsi')]
class ProvisionSocialSyncUser extends Command
{
    public function handle(BufferService $buffer): int
    {
        try {
            $email = strtolower(trim((string) ($this->option('email') ?: $this->ask('Email kerja'))));
            $existing = User::query()->where('email', $email)->first();
            $name = trim((string) ($this->option('name') ?: ($existing?->name ?? $this->ask('Nama lengkap'))));
            $role = ucfirst(strtolower(trim((string) $this->option('role'))));

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Email tidak valid.');
            }

            if ($name === '' || mb_strlen($name) > 120) {
                throw new RuntimeException('Nama wajib diisi dan maksimal 120 karakter.');
            }

            if (! in_array($role, ['Admin', 'Editor', 'Viewer'], true)) {
                throw new RuntimeException('Role harus Admin, Editor, atau Viewer.');
            }

            $password = $this->secretFromFileOrPrompt('password-file', 'Password pengguna (minimal 12 karakter)', $existing !== null);

            if ($password !== null && mb_strlen($password) < 12) {
                throw new RuntimeException('Password minimal 12 karakter.');
            }

            if (! $existing && $password === null) {
                throw new RuntimeException('Password wajib diberikan untuk pengguna baru.');
            }

            $withoutBuffer = (bool) $this->option('without-buffer');
            $apiKey = $withoutBuffer ? null : $this->secretFromFileOrPrompt('buffer-key-file', 'Personal API key Buffer', false);

            if (! $withoutBuffer && blank($apiKey)) {
                throw new RuntimeException('API key Buffer wajib diberikan, atau gunakan --without-buffer.');
            }

            $profile = $apiKey ? $buffer->profile($apiKey) : null;
            $fingerprint = $apiKey ? hash('sha256', $apiKey) : null;

            if ($profile) {
                $duplicate = BufferConnection::query()
                    ->where(fn ($query) => $query
                        ->where('buffer_account_id', $profile['account_id'])
                        ->orWhere('api_key_fingerprint', $fingerprint))
                    ->when($existing, fn ($query) => $query->where('user_id', '!=', $existing->id))
                    ->first();

                if ($duplicate) {
                    throw new RuntimeException('Akun atau API key Buffer sudah dipakai oleh pengguna SocialSync lain.');
                }
            }

            $user = DB::transaction(function () use ($existing, $name, $email, $role, $password, $apiKey, $fingerprint, $profile): User {
                $attributes = [
                    'name' => $name,
                    'email' => $email,
                    'active' => true,
                    'initials' => $this->initials($name),
                ];

                if ($password !== null) {
                    $attributes['password'] = $password;
                }

                $user = $existing ?? new User;
                $user->fill($attributes)->save();
                Role::findOrCreate($role, 'web');
                $user->syncRoles([$role]);

                if ($apiKey && $profile && $fingerprint) {
                    BufferConnection::query()->updateOrCreate(
                        ['user_id' => $user->id],
                        [
                            'api_key' => $apiKey,
                            'api_key_fingerprint' => $fingerprint,
                            'buffer_account_id' => $profile['account_id'],
                            'account_name' => $profile['account_name'],
                            'account_email' => $profile['account_email'],
                            'organization_id' => $profile['organization_id'],
                            'organization_name' => $profile['organization_name'],
                            'status' => 'active',
                            'last_error' => null,
                        ],
                    );
                }

                return $user;
            });

            activity()
                ->withProperties(['subject' => $user->email, 'role' => $role, 'buffer' => $profile !== null])
                ->log($existing ? 'memprovisikan ulang pengguna via CLI' : 'menambahkan pengguna via CLI');

            $this->info($existing ? 'Pengguna berhasil diperbarui.' : 'Pengguna berhasil dibuat.');
            $this->table(['Email', 'Role', 'Buffer'], [[$user->email, $role, $profile ? 'Terhubung' : 'Tidak dikonfigurasi']]);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function secretFromFileOrPrompt(string $option, string $label, bool $optional): ?string
    {
        $path = trim((string) $this->option($option));

        if ($path !== '') {
            if (! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException("File untuk --{$option} tidak dapat dibaca.");
            }

            $value = trim((string) file_get_contents($path));

            return $value !== '' ? $value : null;
        }

        if (! $this->input->isInteractive()) {
            return null;
        }

        $suffix = $optional ? ' (kosongkan untuk mempertahankan yang lama)' : '';
        $value = trim((string) $this->secret($label.$suffix));

        return $value !== '' ? $value : null;
    }

    private function initials(string $name): string
    {
        return Str::of($name)
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdminUser extends Command
{
    protected $signature = 'nadi:create-admin
        {email : Email administrator}
        {--name=Administrator NADI : Nama administrator}
        {--password= : Password kuat; jika kosong akan diminta secara tersembunyi}
        {--force-password-change : Tandai password sebagai sementara dan wajib diganti saat login pertama}';

    protected $description = 'Membuat akun Pimpinan/Administrator awal tanpa menjalankan seeder demo.';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        if (User::query()->where('email', $email)->exists()) {
            $this->error('Email sudah digunakan.');
            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?: $this->secret('Masukkan password administrator'));
        $confirmation = $this->option('password') ? $password : (string) $this->secret('Konfirmasi password administrator');

        $validator = Validator::make([
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            return self::FAILURE;
        }

        $department = Department::query()->firstOrCreate(
            ['code' => 'leadership'],
            ['name' => 'Pimpinan', 'color' => '#e9b949', 'description' => 'Kendali strategi, kepatuhan, dan keputusan lintas fungsi.'],
        );

        $forcePasswordChange = (bool) $this->option('force-password-change');

        $user = User::create([
            'name' => (string) $this->option('name'),
            'email' => $email,
            'department_id' => $department->id,
            'role' => 'director',
            'position' => 'Administrator NADI',
            'password' => $password,
            'is_active' => true,
            'must_change_password' => $forcePasswordChange,
            'password_changed_at' => $forcePasswordChange ? null : now(),
        ]);

        $suffix = $forcePasswordChange ? " Password ditandai sementara dan wajib diganti." : "";
        $this->info("Administrator {$user->email} berhasil dibuat.{$suffix}");
        return self::SUCCESS;
    }
}

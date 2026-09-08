<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = strtolower(trim((string) env('SUPER_ADMIN_EMAIL', '')));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException(
                'SUPER_ADMIN_EMAIL must be set to a valid email address before first deployment.'
            );
        }

        $existing = User::where('email', $email)->first();

        if ($existing !== null) {
            // A normal redeploy must never silently reset an existing administrator password.
            if ($existing->role !== 'super-admin') {
                $existing->forceFill(['role' => 'super-admin'])->save();
            }

            return;
        }

        $password = (string) env('SUPER_ADMIN_PASSWORD', '');

        if (strlen($password) < 12) {
            throw new RuntimeException(
                'SUPER_ADMIN_PASSWORD must be at least 12 characters. In Coolify it is supplied automatically by SERVICE_PASSWORD_64_SUPERADMIN.'
            );
        }

        $name = trim((string) env('SUPER_ADMIN_NAME', 'Super Admin'));
        $phone = trim((string) env('SUPER_ADMIN_PHONE', ''));

        User::create([
            'name' => $name !== '' ? $name : 'Super Admin',
            'email' => $email,
            'password' => Hash::make($password),
            'phone' => $phone !== '' ? $phone : null,
            'role' => 'super-admin',
            'email_verified_at' => now(),
        ]);
    }
}

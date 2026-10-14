<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $this->upsert('admin@dev.com', 'Admin', UserRole::Admin);
        $this->upsert('user@dev.com', 'User', UserRole::User);
    }

    /**
     * `role` is intentionally excluded from #[Fillable] on User (see that
     * class), so a plain updateOrCreate() silently drops it. forceFill()
     * bypasses the guard for this trusted, server-only write.
     */
    private function upsert(string $email, string $name, UserRole $role): void
    {
        $user = User::query()->firstOrNew(['email' => $email]);

        $user->fill([
            'name' => $name,
            'password' => Hash::make($email),
            'email_verified_at' => now(),
        ]);

        $user->forceFill(['role' => $role]);

        $user->save();
    }
}

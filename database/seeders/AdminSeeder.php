<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

use App\Models\Admin;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');
        if (!$email || !$password) {
            $this->command?->warn('Admin creation skipped: set ADMIN_EMAIL and ADMIN_PASSWORD to provision an administrator.');
            return;
        }
        if (strlen($password) < 12) {
            throw new \InvalidArgumentException('ADMIN_PASSWORD must have at least 12 characters.');
        }

        $admin = Admin::firstOrCreate(
        ['email' => $email],
        [
            'name' => env('ADMIN_NAME', 'Administrator'),
            'password' => $password,
        ]
        );

        $admin->assignRole('super-admin');
    }
}

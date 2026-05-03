<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create default admin user
        $admin = User::updateOrCreate(
            ['email' => 'admin@kayraaura.com'],
            [
                'name' => 'Super Admin',
                'email' => 'admin@kayraaura.com',
                'password' => Hash::make('admin123'),
                // 'phone' => '+1234567890',
                'role' => 'admin',
                'email_verified_at' => now(),
            ]
        );

        $this->command->info('✓ Admin user created successfully:');
        $this->command->info('  Email: admin@kayraaura.com');
        $this->command->info('  Password: admin123');
        $this->command->info('  Role: admin');

    }
}

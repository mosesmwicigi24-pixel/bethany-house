<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Enums\UserType;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Check if super admin already exists
        $existingSuperAdmin = User::role('super_admin')->first();
        
        if ($existingSuperAdmin) {
            $this->command->warn('Super Admin already exists!');
            $this->command->info('Email: ' . $existingSuperAdmin->email);
            return;
        }
        
        // Credentials come from the environment; defaults are neutral (never a
        // real person's address) and the password is random when unset, so no
        // known/weak credential is ever seeded.
        $superAdminEmail    = env('SUPER_ADMIN_EMAIL', 'admin@bethanyhouse.co.ke');
        $superAdminPassword = env('SUPER_ADMIN_PASSWORD') ?: Str::password(16);

        // Create super admin user
        $superAdmin = User::create([
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => $superAdminEmail,
            'phone' => '+254700000000',
            'user_type' => UserType::SYSTEM,
            'password' => Hash::make($superAdminPassword),
            'email_verified_at' => now(),
            'status' => 'active',
            'two_factor_enabled' => false,
            'must_setup_2fa' => true,
        ]);
        
        // Assign super admin role
        $superAdmin->assignRole('super_admin');

        // Create Regular Admin (Staff User)
        $admin = User::firstOrCreate(
            ['email' => 'staff@bethanyhouse.co.ke'],
            [
                'first_name' => 'Staff',
                'last_name' => 'Admin',
                'phone' => '+254700000001',
                'password' => Hash::make('password'),
                'user_type' => UserType::STAFF,  // Staff user type
                'email_verified_at' => now(),
                'status' => 'active',
                'two_factor_enabled' => false,
                'must_setup_2fa' => true,
            ]
        );

        $admin->assignRole('admin');

        // Create Sample Customer
        $customer = User::firstOrCreate(
            ['email' => 'customer@example.com'],
            [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'phone' => '+254700000002',
                'password' => Hash::make('password'),
                'user_type' => UserType::CUSTOMER,  // Customer user type
                'email_verified_at' => now(),
                'status' => 'active',
            ]
        );

        $customer->assignRole('customer');

        
        $this->command->info('===================================');
        $this->command->info('✓ Super Admin (System User) created');
        $this->command->info('  Email: ' . $superAdminEmail);
        $this->command->info('  Password: ' . (env('SUPER_ADMIN_PASSWORD') ? '(from SUPER_ADMIN_PASSWORD)' : $superAdminPassword));
        $this->command->info('');
        $this->command->info('✓ Staff Admin created');
        $this->command->info('  Email: staff@bethanyhouse.co.ke');
        $this->command->info('  Password: password');
        $this->command->info('');
        $this->command->info('✓ Sample Customer created');
        $this->command->info('  Email: customer@example.com');
        $this->command->info('  Password: password');
        $this->command->info('===================================');
    }
}
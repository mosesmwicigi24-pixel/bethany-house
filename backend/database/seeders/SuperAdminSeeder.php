<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
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
        
        // Create super admin user
        $superAdmin = User::create([
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => 'nyorojnr@gmail.com',
            'phone' => '+254700000000',
            'user_type' => UserType::SYSTEM,
            'password' => Hash::make('Admin@123!'),
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
        $this->command->info('  Email: nyorojnr@gmail.com');
        $this->command->info('  Password: Admin@123!');
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
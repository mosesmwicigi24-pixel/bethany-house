<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Enums\UserType;

class PermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        
        // Define all permissions
        $permissions = [
            // User Management
            'view users',
            'create users',
            'edit users',
            'delete users',
            
            // Role Management
            'view roles',
            'create roles',
            'edit roles',
            'delete roles',
            'assign roles',
            
            // Product Management
            'view products',
            'create products',
            'edit products',
            'delete products',
            'manage product categories',
            
            // Order Management
            'view orders',
            'create orders',
            'edit orders',
            'delete orders',
            'process orders',
            
            // Inventory Management
            'view inventory',
            'adjust inventory',
            'transfer inventory',
            
            // POS
            'access pos',
            'process sales',
            'void sales',
            'manage cash register',
            
            // Production
            'view production',
            'create production orders',
            'manage production',
            'view production reports',
            
            // Procurement
            'view suppliers',
            'manage suppliers',
            'view purchase orders',
            'create purchase orders',
            'approve purchase orders',
            
            // Reports
            'view sales reports',
            'view inventory reports',
            'view financial reports',
            'view customer reports',
            'export reports',
            
            // Settings
            'manage settings',
            'manage payment gateways',
            'manage shipping',
            'manage taxes',
            
            // System
            'view audit logs',
            'manage backups',
            'view system health',
        ];
        
        // Create all permissions
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }
        
        // Create roles and assign permissions
        
        // 1. Super Admin - all permissions
        $superAdmin = Role::firstOrCreate(
            ['name' => 'super_admin'],
            [
                'user_type' => UserType::SYSTEM->value,
                'description' => 'Super Administrator with full system access',
                'is_active' => true,
            ]
        );
        $superAdmin->syncPermissions(Permission::all());

        // System Administrator
        $systemAdmin = Role::firstOrCreate(
            ['name' => 'system_admin'],
            [
                'user_type' => UserType::SYSTEM->value,
                'description' => 'System administrator with high-level access',
                'is_active' => true,
            ]
        );
        $systemAdmin->syncPermissions([
            'view users', 'create users', 'edit users',
            'view roles', 'create roles', 'edit roles', 'delete roles', 'assign roles',
            'view products', 'create products', 'edit products', 'delete products',
            'view orders', 'edit orders',
            'view inventory',
            'manage settings', 'view audit logs',
        ]);
        
        // 2. Admin - most permissions except critical system ones
        $admin = Role::firstOrCreate(
            ['name' => 'admin'],
            [
                'user_type' => UserType::STAFF->value,
                'description' => 'Administrator with most permissions',
                'is_active' => true,
            ]
        );
        $admin->syncPermissions([
            'view users', 'create users', 'edit users',
            'view roles',
            'view products', 'create products', 'edit products', 'delete products', 'manage product categories',
            'view orders', 'create orders', 'edit orders', 'process orders',
            'view inventory', 'adjust inventory', 'transfer inventory',
            'view production', 'create production orders', 'manage production', 'view production reports',
            'view suppliers', 'manage suppliers', 'view purchase orders', 'create purchase orders',
            'view sales reports', 'view inventory reports', 'view customer reports', 'export reports',
            'access pos', 'process sales', 'manage cash register',
        ]);
        
        // 3. Outlet Manager - outlet-specific operations
        $outletManager = Role::firstOrCreate(
            ['name' => 'outlet_manager'],
            [
                'user_type' => UserType::STAFF->value,
                'description' => 'Manages a specific outlet/store',
                'is_active' => true,
            ]
        );
        $outletManager->syncPermissions([
            'view products',
            'view orders', 'create orders', 'edit orders', 'process orders',
            'view inventory', 'adjust inventory',
            'access pos', 'process sales', 'void sales', 'manage cash register',
            'view sales reports', 'view inventory reports',
        ]);
        
        // 4. POS Clerk - point of sale only
        $posClerk = Role::firstOrCreate(
            ['name' => 'pos_clerk'],
            [
                'user_type' => UserType::STAFF->value,
                'description' => 'Point of Sale operator',
                'is_active' => true,
            ]
        );
        $posClerk->syncPermissions([
            'view products',
            'access pos', 'process sales',
        ]);
        
        // 5. Tailor - production only
        $tailor = Role::firstOrCreate(
            ['name' => 'tailor'],
            [
                'user_type' => UserType::STAFF->value,
                'description' => 'Production/Manufacturing staff',
                'is_active' => true,
            ]
        );
        $tailor->syncPermissions([
            'view production',
        ]);

        // Accountant
        $accountant = Role::firstOrCreate(
            ['name' => 'accountant'],
            [
                'user_type' => UserType::STAFF->value,
                'description' => 'Financial and accounting staff',
                'is_active' => true,
            ]
        );
        $accountant->syncPermissions([
            'view orders',
            'view inventory',
        ]);
        
        // 6. Procurement Officer
        $procurementOfficer = Role::firstOrCreate(
            ['name' => 'procurement_officer'],
            [
                'user_type' => UserType::STAFF->value,
                'description' => 'Handles procurement and supplier management',
                'is_active' => true,
            ]
        );
        $procurementOfficer->syncPermissions([
            'view products',
            'view suppliers', 'manage suppliers',
            'view purchase orders', 'create purchase orders',
            'view inventory',
        ]);
        
        // 7. Customer - basic customer permissions (no admin access)
        $customer = Role::firstOrCreate(
            ['name' => 'customer'],
            [
                'user_type' => UserType::CUSTOMER->value,
                'description' => 'Regular customer account',
                'is_active' => true,
            ]
        );
        // Customers get no admin permissions
        
        $this->command->info('✓ Permissions created');
        $this->command->info('✓ System roles created (super_admin, system_admin)');
        $this->command->info('✓ Staff roles created (admin, outlet_manager, pos_clerk, tailor, accountant)');
        $this->command->info('✓ Customer roles created (customer)');

    }
}
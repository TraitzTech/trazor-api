<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Define permissions
        $permissions = [
            'view users',
            'create users',
            'edit users',
            'delete users',
            'view reports',
            'manage settings',
            'assign tasks',
            'review tasks',
        ];

        // Create permissions
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Define roles and their permissions
        $roles = [
            'admin' => [
                'view users',
                'create users',
                'edit users',
                'delete users',
                'view reports',
                'manage settings',
                'assign tasks',
                'review tasks',
            ],
            'supervisor' => [
                'view users',
                'edit users',
                'view reports',
                'assign tasks',
                'review tasks',
            ],
            'intern' => [
                'view users',
                'view reports',
            ],
        ];

        // Create roles and assign permissions
        foreach ($roles as $roleName => $rolePermissions) {
            $role = Role::firstOrCreate(['name' => $roleName]);
            $role->syncPermissions($rolePermissions);
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create the admin role if it doesn't exist
        $adminRole = Role::firstOrCreate(['name' => 'admin']);

        // Create a user
        $user = User::firstOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'name' => 'Admin User',

                'location' => 'HQ',
                'phone' => '677802114',
                'avatar' => null,
                'bio' => 'System administrator',
                'is_active' => true,
                'last_login' => now(),
                'password' => Hash::make('password'),
            ]
        );

        // Assign the admin role to the user
        $user->assignRole($adminRole);

        // Create the createadmin record
        Admin::firstOrCreate([
            'user_id' => $user->id,
            'permissions' => json_encode(['all']),
        ]);
    }
}

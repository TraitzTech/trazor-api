<?php

namespace Database\Seeders;

use App\Models\Specialty;
use App\Models\Supervisor;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class SupervisorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $supervisorRole = Role::firstOrCreate(['name' => 'supervisor']);

        $user = User::firstOrCreate(
            ['email' => 'supervisor@gmail.com'],
            [
                'name' => 'Ngu Nguyen',
                'password' => Hash::make('password'),
                'last_login' => now(),
            ]
        );
        $user->assignRole($supervisorRole);

        Supervisor::firstOrCreate([
            'user_id' => $user->id,
            'specialty_id' => Specialty::first()->id,
        ]);
    }
}

<?php

namespace Database\Seeders;

use App\Models\Intern;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class InternSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $internRole = Role::firstOrCreate(['name' => 'intern']);
        $specialty = Specialty::inRandomOrder()->first();

        $user = User::firstOrCreate(
            ['email' => 'intern@gmail.com'],
            [
                'name' => 'Miltech',
                'password' => Hash::make('password'),
                'last_login' => now(),
            ],
        );

        $user->assignRole($internRole);

        Intern::create([
            'user_id' => $user->id,
            'specialty_id' => $specialty->id,
            'institution' => 'NAHPI',
            'hort_number' => '4.0.0',
        ]);

        User::factory(100)->create()->each(function ($user) use ($internRole, $specialty) {
            $user->assignRole($internRole);
            \App\Models\Intern::factory()->create([
                'user_id' => $user->id,
                'specialty_id' => $specialty->id,
                'hort_number' => '4.0.0',
            ]);
        });
    }
}

<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            SpecialtySeeder::class,
            AdminSeeder::class,
            InternSeeder::class,
            SupervisorSeeder::class,
        ]);
    }
}

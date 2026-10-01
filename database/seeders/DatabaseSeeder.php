<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database (Areas, Buildings, Collectors - Excludes users).
     */
    public function run(): void
    {
        $this->call(LocationCollectorSeeder::class);
    }
}

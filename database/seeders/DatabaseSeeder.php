<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database. Gates themselves are created by their migration.
     */
    public function run(): void
    {
        $this->call(GateRestrictionSeeder::class);
    }
}

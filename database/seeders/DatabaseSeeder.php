<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(DomainCatalogSeeder::class);

        if (app()->environment('local') && config('app.demo_mode')) {
            $this->call(DemoDataSeeder::class);
        }
    }
}

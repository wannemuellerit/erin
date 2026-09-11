<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(DomainCatalogSeeder::class);

        if (! app()->isProduction() && config('app.demo_mode')) {
            $this->call(DemoDataSeeder::class);
            // Seeding deliberately suppresses model events, including Scout updates.
            if (config('scout.driver') === 'meilisearch'
                && Artisan::call('erin:search:rebuild-candidates') !== 0) {
                throw new RuntimeException('Demo-Daten wurden angelegt, aber der Fachkräfte-Suchindex konnte nicht synchronisiert werden.');
            }
        }
    }
}

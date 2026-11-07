<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(StoreSeeder::class);
        $this->call(ContentSeeder::class);
        $this->call(HeroSlideSeeder::class);
    }
}

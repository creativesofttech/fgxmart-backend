<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class GroceriesCategorySeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CategorySeeder::class);
    }
}

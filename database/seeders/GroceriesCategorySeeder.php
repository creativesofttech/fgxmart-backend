<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GroceriesCategorySeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $groceries = Category::where('slug', 'groceries')->first();
            $legacy = Category::where('slug', 'packed-food')->first();

            if ($groceries && $legacy) {
                throw new \RuntimeException('Both Groceries and Packed Food exist. Resolve the duplicate before running this seeder.');
            }

            // Rename in place so products, children and uploaded images keep their associations.
            $category = $groceries ?? $legacy ?? new Category([
                'code' => 'FOOD', 'sorting_order' => 10, 'status' => true,
            ]);
            $category->fill([
                'name' => 'Groceries',
                'slug' => 'groceries',
                'short_description' => 'Everyday groceries, pantry staples, snacks & more',
            ])->save();
        });
    }
}

<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $this->call(GroceriesCategorySeeder::class);
        DB::transaction(function () {
            $definitions = [
                ['Groceries', 'groceries', 'FOOD', 'Everyday groceries, pantry staples, snacks & more'],
                ['Clothing', 'clothing', 'CLT', 'Everyday style for everyone'],
                ['Electronics', 'electronics', 'ELE', 'Smart finds for modern living'],
                ['Kitchen Utensils', 'kitchen-utensils', 'KIT', 'Good things for your kitchen'],
            ];
            foreach ($definitions as $index => [$name, $slug, $code, $description]) {
                $category = Category::firstOrCreate(['slug' => $slug], [
                    'name' => $name, 'code' => $code, 'short_description' => $description,
                    'sorting_order' => ($index + 1) * 10, 'status' => true,
                ]);
                // Preserve admin-managed names, images, descriptions and status on reruns.
                if (!$category->short_description) $category->update(['short_description' => $description]);
                if ($category->wasRecentlyCreated) {
                    $source = database_path('seeders/assets/categories/'.$slug.'.webp');
                    if (is_file($source)) {
                        $image = 'categories/seeded-'.$slug.'.webp';
                        Storage::disk('public')->put($image, file_get_contents($source));
                        $category->update(['image' => $image]);
                    }
                }
            }
            $food = Category::where('slug', 'groceries')->firstOrFail();
            Category::whereIn('slug', ['spices', 'dry-fruits', 'pulses', 'masalas'])
                ->whereNull('parent_id')->update(['parent_id' => $food->id]);
        });
    }
}

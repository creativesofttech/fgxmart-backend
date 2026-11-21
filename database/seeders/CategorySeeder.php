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
        DB::transaction(function () {
            // Repair the old rename while preserving the original FOOD record and associations.
            $renamedFood = Category::where('slug', 'groceries')->where('code', 'FOOD')->first();
            if ($renamedFood && !Category::where('slug', 'packed-food')->exists()) {
                $renamedFood->update(['name' => 'Packed Food', 'slug' => 'packed-food', 'short_description' => 'Pantry favourites, snacks & more']);
            }
            $definitions = [
                ['Groceries', 'groceries', 'GROC', 'Everyday groceries, pantry staples, snacks & more'],
                ['Packed Food', 'packed-food', 'FOOD', 'Pantry favourites, snacks & more'],
                ['Clothing', 'clothing', 'CLT', 'Everyday style for everyone'],
                ['Electronics', 'electronics', 'ELE', 'Smart finds for modern living'],
                ['Kitchen Utensils', 'kitchen-utensils', 'KIT', 'Good things for your kitchen'],
            ];
            foreach ($definitions as $index => [$name, $slug, $code, $description]) {
                $category = Category::firstOrCreate(['slug' => $slug], [
                    'name' => $name, 'code' => $code, 'short_description' => $description,
                    'sorting_order' => ($index + 1) * 10, 'status' => true,
                ]);
                // Apply the requested order; preserve existing images, descriptions and associations.
                $category->sorting_order = ($index + 1) * 10;
                $category->save();
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
            $food = Category::where('slug', 'packed-food')->firstOrFail();
            Category::whereIn('slug', ['spices', 'dry-fruits', 'pulses', 'masalas'])
                ->whereNull('parent_id')->update(['parent_id' => $food->id]);
        });
    }
}

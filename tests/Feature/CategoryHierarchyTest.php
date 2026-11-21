<?php

use App\Models\Admin;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $admin = Admin::create(['name' => 'Category Admin', 'email' => 'category-test@example.test', 'password' => 'test-password-123']);
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin']);
    $admin->roles()->attach($role);
    $token = $admin->createToken('category-test', ['admin'], now()->addHour())->plainTextToken;
    $this->withToken($token);
});

it('stores optional HSN codes and dependent category selections without changing legacy assignments', function () {
    $root = Category::create(['name' => 'Food', 'slug' => 'food']);
    $child = Category::create(['name' => 'Snacks', 'slug' => 'snacks', 'parent_id' => $root->id]);
    $payload = ['name' => 'Snack', 'base_price' => 10, 'stock_quantity' => 2, 'main_category_id' => $root->id, 'sub_category_id' => $child->id, 'hsn_code' => '001234'];
    $created = $this->postJson('/api/v1/admin/products', $payload)->assertCreated()
        ->assertJsonPath('data.category_id', $child->id)->assertJsonPath('data.hsn_code', '001234')->json('data');
    $this->getJson('/api/v1/admin/products/'.$created['id'])->assertOk()->assertJsonPath('data.hsn_code', '001234');
    $this->putJson('/api/v1/admin/products/'.$created['id'], array_merge($payload, ['sub_category_id' => null, 'hsn_code' => null]))
        ->assertOk()->assertJsonPath('data.category_id', $root->id)->assertJsonPath('data.hsn_code', null);
    unset($payload['main_category_id'], $payload['sub_category_id'], $payload['hsn_code']);
    $this->postJson('/api/v1/admin/products', array_merge($payload, ['category_id' => $child->id]))
        ->assertCreated()->assertJsonPath('data.category_id', $child->id)->assertJsonPath('data.hsn_code', null);
});

it('rejects mismatched category levels and invalid HSN codes before storing products', function () {
    $root = Category::create(['name' => 'Food', 'slug' => 'food']);
    $other = Category::create(['name' => 'Clothing', 'slug' => 'clothing']);
    $child = Category::create(['name' => 'Shirts', 'slug' => 'shirts', 'parent_id' => $other->id]);
    $payload = ['name' => 'Product', 'base_price' => 10, 'stock_quantity' => 2];
    $this->postJson('/api/v1/admin/products', $payload + ['main_category_id' => $root->id, 'sub_category_id' => $child->id])
        ->assertUnprocessable()->assertJsonValidationErrors('sub_category_id');
    $this->postJson('/api/v1/admin/products', $payload + ['main_category_id' => $child->id])
        ->assertUnprocessable()->assertJsonValidationErrors('main_category_id');
    $this->postJson('/api/v1/admin/products', $payload + ['sub_category_id' => $child->id])
        ->assertUnprocessable()->assertJsonValidationErrors('main_category_id');
    $this->postJson('/api/v1/admin/products', $payload + ['hsn_code' => str_repeat('1', 21)])
        ->assertUnprocessable()->assertJsonValidationErrors('hsn_code');
    expect(Product::count())->toBe(0);
});

it('creates main categories and subcategories with descriptions and images', function () {
    Storage::fake('public');
    $root = $this->postJson('/api/v1/admin/categories', ['name' => 'Clothing', 'short_description' => 'Everyday style', 'code' => 'clt'])->assertCreated()->json('data');
    $child = $this->post('/api/v1/admin/categories', [
        'name' => 'Shirts', 'parent_id' => $root['id'], 'short_description' => 'Cotton shirts',
        'image' => UploadedFile::fake()->image('shirts.png', 32, 32),
    ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.parent.name', 'Clothing')->json('data');
    Storage::disk('public')->assertExists($child['image']);
    expect($child['short_description'])->toBe('Cotton shirts');
    $this->post('/api/v1/admin/categories/'.$child['id'], ['_method' => 'PUT', 'name' => 'Shirts', 'parent_id' => $root['id'], 'remove_image' => 1], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.image', null);
    Storage::disk('public')->assertMissing($child['image']);
});

it('rejects cycles deeper nesting long descriptions and deleting occupied categories', function () {
    $root = Category::create(['name' => 'Packed Food', 'slug' => 'packed-food']);
    $child = Category::create(['name' => 'Snacks', 'slug' => 'snacks', 'parent_id' => $root->id]);
    $this->putJson('/api/v1/admin/categories/'.$root->id, ['name' => 'Packed Food', 'parent_id' => $child->id])->assertUnprocessable();
    $this->putJson('/api/v1/admin/categories/'.$child->id, ['name' => 'Snacks', 'parent_id' => $child->id])->assertUnprocessable();
    $this->postJson('/api/v1/admin/categories', ['name' => 'Chips', 'parent_id' => $child->id])->assertUnprocessable();
    $this->postJson('/api/v1/admin/categories', ['name' => 'Other', 'short_description' => str_repeat('a', 256)])->assertUnprocessable();
    $this->deleteJson('/api/v1/admin/categories/'.$root->id)->assertUnprocessable();
    Product::create(['name' => 'Snack', 'slug' => 'snack', 'sku' => 'SN', 'category_id' => $child->id, 'base_price' => 1]);
    $this->deleteJson('/api/v1/admin/categories/'.$child->id)->assertUnprocessable();
});

it('hides inactive categories and children while including child products in parent discovery', function () {
    $root = Category::create(['name' => 'Electronics', 'slug' => 'electronics', 'status' => true]);
    $child = Category::create(['name' => 'Audio', 'slug' => 'audio', 'parent_id' => $root->id, 'status' => true]);
    $hidden = Category::create(['name' => 'Draft', 'slug' => 'draft', 'parent_id' => $root->id, 'status' => false]);
    Product::create(['name' => 'Speaker', 'slug' => 'speaker', 'sku' => 'SP', 'category_id' => $child->id, 'base_price' => 10, 'status' => true]);
    Product::create(['name' => 'Hidden', 'slug' => 'hidden', 'sku' => 'HI', 'category_id' => $hidden->id, 'base_price' => 10, 'status' => true]);
    $this->getJson('/api/v1/categories?all=1&roots=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonCount(1, 'data.0.children');
    $this->getJson('/api/v1/categories/draft')->assertNotFound();
    $this->getJson('/api/v1/products?category_id='.$root->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'speaker');
    $root->update(['status' => false]);
    $this->getJson('/api/v1/categories/audio')->assertNotFound();
});

it('adds groceries separately while preserving packed food images and product and child assignments', function () {
    Storage::fake('public');
    $root = Category::create(['name' => 'Packed Food', 'slug' => 'packed-food', 'image' => 'categories/uploaded.webp', 'status' => false]);
    $child = Category::create(['name' => 'Snacks', 'slug' => 'snacks', 'parent_id' => $root->id]);
    $product = Product::create(['name' => 'Snack', 'slug' => 'snack', 'sku' => 'GROC', 'category_id' => $root->id, 'base_price' => 10]);
    $this->seed(\Database\Seeders\GroceriesCategorySeeder::class);
    $this->seed(\Database\Seeders\GroceriesCategorySeeder::class);
    expect($root->fresh()->name)->toBe('Packed Food');
    expect($root->fresh()->slug)->toBe('packed-food');
    expect(Category::where('slug', 'groceries')->firstOrFail()->short_description)->toBe('Everyday groceries, pantry staples, snacks & more');
    expect($root->fresh()->image)->toBe('categories/uploaded.webp');
    expect($root->fresh()->status)->toBeFalse();
    expect($child->fresh()->parent_id)->toBe($root->id);
    expect($product->fresh()->category_id)->toBe($root->id);
    expect(Category::where('slug', 'packed-food')->exists())->toBeTrue();
    expect(Category::where('slug', 'groceries')->count())->toBe(1);
});

it('repairs the previous groceries rename without moving products or uploaded images', function () {
    Storage::fake('public');
    $original = Category::create(['name' => 'Groceries', 'slug' => 'groceries', 'code' => 'FOOD', 'image' => 'categories/original.webp']);
    $child = Category::create(['name' => 'Pulses', 'slug' => 'pulses', 'parent_id' => $original->id]);
    $this->seed(\Database\Seeders\CategorySeeder::class);
    $this->seed(\Database\Seeders\CategorySeeder::class);
    expect($original->fresh()->slug)->toBe('packed-food');
    expect($original->fresh()->image)->toBe('categories/original.webp');
    expect($child->fresh()->parent_id)->toBe($original->id);
    $groceries = Category::where('slug', 'groceries')->firstOrFail();
    expect($groceries->id)->not->toBe($original->id);
    expect($groceries->code)->toBe('GROC');
    expect($groceries->image)->toBeNull();
    expect(Category::whereNull('parent_id')->count())->toBe(5);
});

it('seeds the five roots in the requested order idempotently and preserves legacy product assignments', function () {
    Storage::fake('public');
    $legacy = Category::create(['name' => 'Spices', 'slug' => 'spices']);
    $product = Product::create(['name' => 'Spice', 'slug' => 'spice', 'sku' => 'SP', 'category_id' => $legacy->id, 'base_price' => 10]);
    $this->seed(\Database\Seeders\CategorySeeder::class);
    $this->seed(\Database\Seeders\CategorySeeder::class);
    expect(Category::whereNull('parent_id')->orderBy('sorting_order')->pluck('name')->all())->toBe(['Groceries', 'Packed Food', 'Clothing', 'Electronics', 'Kitchen Utensils']);
    expect($legacy->fresh()->parent->slug)->toBe('packed-food');
    expect($product->fresh()->category_id)->toBe($legacy->id);
    $food = Category::where('slug', 'groceries')->firstOrFail();
    expect($food->image)->toBeNull();
    $food->update(['image' => null]);
    $this->seed(\Database\Seeders\CategorySeeder::class);
    expect($food->fresh()->image)->toBeNull();
});

it('retires hero slider routes and schema', function () {
    expect(\Illuminate\Support\Facades\Schema::hasTable('hero_slides'))->toBeFalse();
    $this->getJson('/api/v1/hero-slides')->assertNotFound();
    $this->getJson('/api/v1/admin/hero-slides')->assertNotFound();
});

it('filters multiple main and child categories together while preserving collection flags', function () {
    $root = Category::create(['name' => 'Food', 'slug' => 'filter-food', 'status' => true]);
    $child = Category::create(['name' => 'Snacks', 'slug' => 'filter-snacks', 'parent_id' => $root->id, 'status' => true]);
    $other = Category::create(['name' => 'Clothing', 'slug' => 'filter-clothing', 'status' => true]);
    $hidden = Category::create(['name' => 'Hidden', 'slug' => 'filter-hidden', 'parent_id' => $root->id, 'status' => false]);
    foreach ([$root, $child, $other, $hidden] as $category) {
        Product::create(['name' => $category->name, 'slug' => 'filter-'.$category->id, 'sku' => 'FILTER-'.$category->id, 'category_id' => $category->id, 'base_price' => 10, 'is_hot_deal' => true, 'status' => true]);
    }
    $this->getJson('/api/v1/products?'.http_build_query(['category_ids' => [$root->id]]))->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/products?'.http_build_query(['category_ids' => [$child->id, $other->id], 'is_hot_deal' => 1]))->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/products?'.http_build_query(['category_ids' => [$hidden->id]]))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/products?'.http_build_query(['category_ids' => [$child->id], 'is_hot_deal' => 0]))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/products?category_ids=bad')->assertUnprocessable();
    $this->getJson('/api/v1/products?category_ids[]=99999')->assertUnprocessable();
    $root->update(['status' => false]);
    $this->getJson('/api/v1/products?'.http_build_query(['category_ids' => [$child->id]]))->assertOk()->assertJsonCount(0, 'data');
});

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

it('seeds the four new roots idempotently and preserves legacy product assignments', function () {
    Storage::fake('public');
    $legacy = Category::create(['name' => 'Spices', 'slug' => 'spices']);
    $product = Product::create(['name' => 'Spice', 'slug' => 'spice', 'sku' => 'SP', 'category_id' => $legacy->id, 'base_price' => 10]);
    $this->seed(\Database\Seeders\CategorySeeder::class);
    $this->seed(\Database\Seeders\CategorySeeder::class);
    expect(Category::whereNull('parent_id')->count())->toBe(4);
    expect($legacy->fresh()->parent->slug)->toBe('packed-food');
    expect($product->fresh()->category_id)->toBe($legacy->id);
    $food = Category::where('slug', 'packed-food')->firstOrFail();
    Storage::disk('public')->assertExists($food->image);
    $food->update(['image' => null]);
    $this->seed(\Database\Seeders\CategorySeeder::class);
    expect($food->fresh()->image)->toBeNull();
});

it('retires hero slider routes and schema', function () {
    expect(\Illuminate\Support\Facades\Schema::hasTable('hero_slides'))->toBeFalse();
    $this->getJson('/api/v1/hero-slides')->assertNotFound();
    $this->getJson('/api/v1/admin/hero-slides')->assertNotFound();
});

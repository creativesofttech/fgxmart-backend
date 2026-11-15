<?php

use App\Models\Admin;
use App\Models\Product;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Product::create(['name' => 'Featured Active', 'slug' => 'featured-active', 'sku' => 'FA', 'base_price' => 10, 'is_featured' => true, 'status' => true]);
    Product::create(['name' => 'Regular Active', 'slug' => 'regular-active', 'sku' => 'RA', 'base_price' => 20, 'is_featured' => false, 'status' => true]);
    Product::create(['name' => 'Featured Draft', 'slug' => 'featured-draft', 'sku' => 'FD', 'base_price' => 30, 'is_featured' => true, 'status' => false]);
});

it('returns only active featured products for the homepage', function () {
    $this->getJson('/api/v1/products?is_featured=1&per_page=4')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'featured-active');
});

it('excludes drafts from the public catalogue and validates the featured filter', function () {
    $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/products?is_featured=0')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'regular-active');
    $this->getJson('/api/v1/products?is_featured=invalid')->assertUnprocessable();
});

it('retains draft visibility in the protected admin catalogue', function () {
    $admin = Admin::create(['name' => 'Admin', 'email' => 'catalog-admin@example.test', 'password' => 'test-password-123']);
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin']);
    $admin->roles()->attach($role);
    $token = $admin->createToken('test-admin', ['admin'], now()->addHour())->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/admin/products')->assertOk()->assertJsonCount(3, 'data');
    $this->withToken($token)->getJson('/api/v1/admin/products?is_featured=1')->assertOk()->assertJsonCount(2, 'data');
});

it('returns only active products with a genuine discount for hot deals', function () {
    Product::where('slug', 'regular-active')->update(['discount_price' => 15]);
    Product::where('slug', 'featured-active')->update(['discount_price' => 10]);
    Product::where('slug', 'featured-draft')->update(['discount_price' => 5]);
    $this->getJson('/api/v1/products?on_sale=1')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'regular-active');
    $this->getJson('/api/v1/products?on_sale=0')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/products?on_sale=invalid')->assertUnprocessable();
});

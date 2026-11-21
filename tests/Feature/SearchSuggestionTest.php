<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\ProductSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::forget(ProductSearch::CACHE_KEY);
    $this->brand = Brand::create(['name' => 'Tata Sampann', 'slug' => 'tata-sampann', 'status' => true]);
    $this->parent = Category::create(['name' => 'Packed Food', 'slug' => 'packed-food', 'status' => true]);
    $this->category = Category::create(['name' => 'Pulses', 'slug' => 'pulses', 'parent_id' => $this->parent->id, 'status' => true]);
    $this->product = Product::create(['name' => 'Tata Sampann Toor Dal', 'slug' => 'tata-toor-dal', 'sku' => 'PUL-TOO-500', 'base_price' => 100, 'discount_price' => 85, 'brand_id' => $this->brand->id, 'category_id' => $this->category->id, 'status' => true]);
});

it('requires three characters and validates bounded plain-text search input', function () {
    foreach (['sa', str_repeat('a', 101), ['invalid']] as $term) {
        $this->getJson('/api/v1/search/suggestions?'.http_build_query(['q' => $term]))->assertUnprocessable();
    }
    $this->getJson('/api/v1/search/suggestions')->assertUnprocessable();
    $this->getJson('/api/v1/search/suggestions?q=sam')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/products?search=sa')->assertUnprocessable();
});

it('finds names, SKUs, brands, child categories and main categories without authentication', function () {
    foreach (['toor dal', 'PUL-TOO-500', 'PULTOO500', 'sampann', 'pulses', 'packed food'] as $term) {
        $this->getJson('/api/v1/search/suggestions?'.http_build_query(['q' => $term]))->assertOk()
            ->assertJsonPath('data.0.id', $this->product->id)->assertJsonPath('data.0.discount_price', '85.00')
            ->assertJsonPath('data.0.brand.name', 'Tata Sampann')->assertJsonMissingPath('data.0.description');
    }
});

it('shares typo and transposition matching between suggestions and full filtered results', function () {
    foreach (['tata smapann', 'toor dall'] as $term) {
        $this->getJson('/api/v1/search/suggestions?'.http_build_query(['q' => $term]))->assertOk()->assertJsonPath('data.0.id', $this->product->id)->assertJsonPath('meta.has_typo_matches', true);
        $this->getJson('/api/v1/products?'.http_build_query(['search' => $term, 'brand_id' => $this->brand->id]))->assertOk()->assertJsonPath('data.0.id', $this->product->id);
    }
    $this->getJson('/api/v1/products?search=tata%20smapann&max_price=10')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/search/suggestions?q=zzzxxyy')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/search/suggestions?q=%25%25%25')->assertOk()->assertJsonCount(0, 'data');
});

it('ranks exact matches first, limits suggestions to six and paginates the full result set', function () {
    foreach (range(1, 8) as $index) Product::create(['name' => 'Rice Product '.$index, 'slug' => 'rice-'.$index, 'sku' => 'RICE-'.$index, 'base_price' => 10, 'status' => true]);
    $exact = Product::create(['name' => 'Rice', 'slug' => 'rice-exact', 'sku' => 'EXACT', 'base_price' => 10, 'status' => true]);
    Product::create(['name' => 'Rice Secret Draft', 'slug' => 'rice-secret', 'sku' => 'SECRET', 'base_price' => 10, 'status' => false]);
    $this->getJson('/api/v1/search/suggestions?q=rice')->assertOk()->assertJsonCount(6, 'data')->assertJsonPath('data.0.id', $exact->id)->assertJsonPath('meta.total', 9);
    $this->getJson('/api/v1/products?search=rice&per_page=3')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('total', 9)->assertJsonPath('last_page', 3)->assertJsonPath('data.0.id', $exact->id)->assertJsonMissing(['slug' => 'rice-secret']);
});

it('invalidates cached metadata when products, brands or categories change', function () {
    $this->getJson('/api/v1/search/suggestions?q=toor')->assertJsonCount(1, 'data');
    expect(Cache::has(ProductSearch::CACHE_KEY))->toBeTrue();
    $this->product->update(['name' => 'Premium Rice', 'sku' => 'RICE-001']);
    $this->getJson('/api/v1/search/suggestions?q=toor')->assertJsonCount(0, 'data');
    $this->brand->update(['name' => 'Aashirvaad']);
    $this->getJson('/api/v1/search/suggestions?q=aashirvaad')->assertJsonCount(1, 'data');
    $this->parent->update(['name' => 'Pantry Essentials']);
    $this->getJson('/api/v1/search/suggestions?q=pantry')->assertJsonCount(1, 'data');
    $this->product->update(['status' => false]);
    $this->getJson('/api/v1/search/suggestions?q=premium')->assertJsonCount(0, 'data');
    $this->product->update(['status' => true]);
    $this->product->delete();
    $this->getJson('/api/v1/search/suggestions?q=premium')->assertJsonCount(0, 'data');
});

it('honours explicit sorting while relevance remains the default for search results', function () {
    $this->product->forceFill(['name' => 'Rice', 'created_at' => now()->subDay()])->save();
    $new = Product::create(['name' => 'Rice Cooker', 'slug' => 'rice-cooker', 'sku' => 'COOKER', 'base_price' => 20, 'status' => true]);
    $this->getJson('/api/v1/products?search=rice')->assertJsonPath('data.0.id', $this->product->id);
    $this->getJson('/api/v1/products?search=rice&sort=price_asc')->assertJsonPath('data.0.id', $new->id);
    $this->getJson('/api/v1/products?search=rice&sort=price_desc')->assertJsonPath('data.0.id', $this->product->id);
    $this->getJson('/api/v1/products?search=rice&sort=newest')->assertJsonPath('data.0.id', $new->id);
});

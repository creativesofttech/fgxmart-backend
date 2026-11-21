<?php

use App\Models\Admin;
use App\Models\Product;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns up to four other active products from the exact same category', function () {
    $parent = \App\Models\Category::create(['name' => 'Food', 'slug' => 'food', 'status' => true]);
    $category = \App\Models\Category::create(['name' => 'Pulses', 'slug' => 'pulses', 'parent_id' => $parent->id, 'status' => true]);
    $source = Product::where('slug', 'featured-active')->first();
    $source->update(['category_id' => $category->id]);
    Product::where('slug', 'featured-draft')->update(['category_id' => $category->id]);
    Product::where('slug', 'regular-active')->update(['category_id' => $parent->id]);
    $peers = collect(range(1, 6))->map(fn ($index) => Product::create(['name' => 'Related '.$index, 'slug' => 'related-'.$index, 'sku' => 'REL-'.$index, 'base_price' => 12, 'category_id' => $category->id, 'status' => true]));
    $response = $this->getJson('/api/v1/products/'.$source->id.'/related')->assertOk()->assertJsonCount(4, 'data')
        ->assertJsonPath('data.0.category.id', $category->id)->assertJsonPath('data.0.reviews_count', 0);
    expect(array_column($response->json('data'), 'id'))->toBe($peers->reverse()->take(4)->pluck('id')->values()->all());
    expect(array_column($response->json('data'), 'id'))->not->toContain($source->id);
});

it('does not substitute unrelated products and hides related lists for unavailable source products', function () {
    $source = Product::where('slug', 'featured-active')->first();
    $this->getJson('/api/v1/products/'.$source->id.'/related')->assertOk()->assertJsonCount(0, 'data');
    $category = \App\Models\Category::create(['name' => 'Empty Category', 'slug' => 'empty-category', 'status' => true]);
    $source->update(['category_id' => $category->id]);
    $this->getJson('/api/v1/products/'.$source->id.'/related')->assertOk()->assertJsonCount(0, 'data');
    $source->update(['status' => false]);
    $this->getJson('/api/v1/products/'.$source->id.'/related')->assertNotFound();
    $this->getJson('/api/v1/products/999999/related')->assertNotFound();
});

it('filters products by an active brand and combines brand selection with other filters', function () {
    $brand = \App\Models\Brand::create(['name' => 'Selected Brand', 'slug' => 'selected-brand', 'status' => true]);
    $other = \App\Models\Brand::create(['name' => 'Other Brand', 'slug' => 'other-brand', 'status' => true]);
    Product::whereIn('slug', ['featured-active', 'featured-draft'])->update(['brand_id' => $brand->id]);
    Product::where('slug', 'regular-active')->update(['brand_id' => $other->id]);
    $this->getJson('/api/v1/products?brand_id='.$brand->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'featured-active');
    $this->getJson('/api/v1/products?brand_id='.$brand->id.'&is_featured=0')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/products?brand_id='.$brand->id.'&min_price=15')->assertOk()->assertJsonCount(0, 'data');
    $brand->update(['status' => false]);
    $this->getJson('/api/v1/products?brand_id='.$brand->id)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/products?brand_id=invalid')->assertUnprocessable();
    $this->getJson('/api/v1/products?brand_id=999999')->assertUnprocessable();
});

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
    foreach (['', '   '] as $term) {
        $this->withToken($token)->getJson('/api/v1/admin/products?'.http_build_query(['search' => $term]))
            ->assertOk()->assertJsonCount(3, 'data');
    }
    $this->withToken($token)->getJson('/api/v1/admin/products?search=Regular')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'regular-active');
    $this->withToken($token)->getJson('/api/v1/admin/products?search=FD')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'featured-draft');
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

it('filters explicit homepage flags and excludes inactive flagged products', function () {
    foreach (['is_featured', 'is_hot_deal', 'is_new_arrival', 'is_best_seller'] as $flag) {
        Product::where('slug', 'featured-active')->update([$flag => true]);
        Product::where('slug', 'featured-draft')->update([$flag => true]);
        $this->getJson('/api/v1/products?'.$flag.'=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'featured-active')->assertJsonPath('data.0.'.$flag, true);
        $this->getJson('/api/v1/products?'.$flag.'=0')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'regular-active');
        $this->getJson('/api/v1/products?'.$flag.'=invalid')->assertUnprocessable();
    }
    // Hot Deal is an explicit editorial choice; it does not require a discount.
    $this->getJson('/api/v1/products?is_hot_deal=1&is_best_seller=1')->assertOk()->assertJsonCount(1, 'data');
});

it('persists independent homepage switches on create and edit and preserves omitted flags', function () {
    $admin = Admin::create(['name' => 'Admin', 'email' => 'collections@example.test', 'password' => 'test-password-123']);
    $role = Role::create(['name' => 'Super Admin', 'slug' => 'super-admin']);
    $admin->roles()->attach($role);
    $this->withToken($admin->createToken('collections', ['admin'], now()->addHour())->plainTextToken);
    $payload = ['name' => 'Collection Product', 'base_price' => 15, 'stock_quantity' => 2, 'is_featured' => true, 'is_hot_deal' => true, 'is_new_arrival' => false, 'is_best_seller' => true];
    $created = $this->postJson('/api/v1/admin/products', $payload)->assertCreated()->assertJsonPath('data.is_featured', true)->assertJsonPath('data.is_hot_deal', true)->assertJsonPath('data.is_new_arrival', false)->assertJsonPath('data.is_best_seller', true)->json('data');
    $this->putJson('/api/v1/admin/products/'.$created['id'], array_merge($payload, ['is_featured' => false, 'is_hot_deal' => false, 'is_new_arrival' => true, 'is_best_seller' => false]))
        ->assertOk()->assertJsonPath('data.is_featured', false)->assertJsonPath('data.is_hot_deal', false)->assertJsonPath('data.is_new_arrival', true)->assertJsonPath('data.is_best_seller', false);
    $this->putJson('/api/v1/admin/products/'.$created['id'], ['name' => 'Same Product', 'base_price' => 15, 'stock_quantity' => 2])
        ->assertOk()->assertJsonPath('data.is_featured', false)->assertJsonPath('data.is_new_arrival', true);
    $this->postJson('/api/v1/admin/products', array_merge($payload, ['is_hot_deal' => 'invalid']))->assertUnprocessable()->assertJsonValidationErrors('is_hot_deal');
});

it('includes the main category in product details for subcategory breadcrumbs', function () {
    $parent = \App\Models\Category::create(['name' => 'Packed Food', 'slug' => 'packed-food', 'status' => true]);
    $child = \App\Models\Category::create(['name' => 'Pulses', 'slug' => 'pulses', 'parent_id' => $parent->id, 'status' => true]);
    $product = Product::where('slug', 'regular-active')->first();
    $product->update(['category_id' => $child->id]);
    $this->getJson('/api/v1/products/regular-active')->assertOk()->assertJsonPath('data.category.name', 'Pulses')->assertJsonPath('data.category.parent.slug', 'packed-food');
    $product->update(['category_id' => $parent->id]);
    $this->getJson('/api/v1/products/regular-active')->assertOk()->assertJsonPath('data.category.name', 'Packed Food')->assertJsonPath('data.category.parent', null);
});

it('indexes the existing featured flag without changing saved selections', function () {
    $migration = require database_path('migrations/2026_11_19_000002_index_featured_product_collection.php');
    $before = Product::orderBy('id')->pluck('is_featured', 'id')->all();
    $migration->down();
    $migration->up();
    expect(\Illuminate\Support\Facades\Schema::hasIndex('products', 'products_status_featured_index'))->toBeTrue();
    expect(Product::orderBy('id')->pluck('is_featured', 'id')->all())->toBe($before);
});

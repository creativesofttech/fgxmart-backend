<?php

use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->product = Product::create(['name' => 'Review Product', 'slug' => 'review-product', 'sku' => 'RV', 'base_price' => 10, 'status' => true]);
});

it('returns an honest empty summary and rejects anonymous or admin submissions', function () {
    $url = '/api/v1/products/'.$this->product->id.'/reviews';
    $this->getJson($url)->assertOk()->assertJsonPath('summary.count', 0)->assertJsonPath('summary.average', null)->assertJsonCount(0, 'data');
    $this->postJson($url, ['rating' => 5, 'review' => 'A useful product.'])->assertUnauthorized();
    $this->getJson('/api/v1/products/'.$this->product->id.'/review')->assertUnauthorized();
    $admin = Admin::create(['name' => 'Admin', 'email' => 'review-admin@example.test', 'password' => 'password-123']);
    $this->withToken($admin->createToken('admin', ['admin'])->plainTextToken)->postJson($url, ['rating' => 5, 'review' => 'An admin review.'])->assertForbidden();
});

it('saves and edits only the signed in customers review without trusting ownership or approval fields', function () {
    $customer = User::factory()->create();
    $other = User::factory()->create();
    $url = '/api/v1/products/'.$this->product->id;
    $this->withToken($customer->createToken('customer')->plainTextToken);
    $this->postJson($url.'/reviews', ['rating' => 5, 'review' => 'Very useful and good quality.', 'user_id' => $other->id, 'is_verified_purchase' => true])->assertOk();
    $this->assertDatabaseHas('product_reviews', ['user_id' => $customer->id, 'rating' => 5, 'is_verified_purchase' => false]);
    $this->getJson($url.'/review')->assertOk()->assertJsonPath('data.rating', 5);
    $this->postJson($url.'/reviews', ['rating' => 4, 'review' => 'Updated after using it for longer.'])->assertOk();
    expect(ProductReview::count())->toBe(1);
    $this->getJson($url.'/reviews')->assertJsonPath('summary.count', 1)->assertJsonPath('summary.average', 4);
    ProductReview::first()->update(['is_approved' => false]);
    $this->postJson($url.'/reviews', ['rating' => 5, 'review' => 'Editing must not bypass moderation.', 'is_approved' => true])->assertOk()->assertJsonPath('data.is_approved', false);
    $this->getJson($url.'/reviews')->assertJsonPath('summary.count', 0);
});

it('validates stars and review length and hides reviews for inactive or missing products', function () {
    $this->withToken(User::factory()->create()->createToken('customer')->plainTextToken);
    $url = '/api/v1/products/'.$this->product->id.'/reviews';
    foreach ([0, 6, 4.5, 'invalid'] as $rating) $this->postJson($url, ['rating' => $rating, 'review' => 'A valid review comment.'])->assertUnprocessable();
    $this->postJson($url, ['rating' => 5, 'review' => 'short'])->assertUnprocessable();
    $this->postJson($url, ['rating' => 5, 'review' => str_repeat('x', 2001)])->assertUnprocessable();
    $this->product->update(['status' => false]);
    $this->getJson($url)->assertNotFound();
    $this->postJson($url, ['rating' => 5, 'review' => 'A valid review comment.'])->assertNotFound();
    $this->getJson('/api/v1/products/999999/reviews')->assertNotFound();
});

it('calculates approved product specific ratings with pagination and no private customer fields', function () {
    $otherProduct = Product::create(['name' => 'Other', 'slug' => 'other', 'sku' => 'OTHER', 'base_price' => 10, 'status' => true]);
    foreach ([5,5,4,4,3,2,1] as $rating) ProductReview::create(['product_id' => $this->product->id, 'user_id' => User::factory()->create()->id, 'rating' => $rating, 'review' => 'Useful customer feedback.', 'is_approved' => true]);
    ProductReview::create(['product_id' => $this->product->id, 'user_id' => User::factory()->create()->id, 'rating' => 1, 'review' => 'Hidden feedback.', 'is_approved' => false]);
    ProductReview::create(['product_id' => $otherProduct->id, 'user_id' => User::factory()->create()->id, 'rating' => 1, 'review' => 'Other product feedback.']);
    $response = $this->getJson('/api/v1/products/'.$this->product->id.'/reviews')->assertOk()->assertJsonCount(6, 'data')->assertJsonPath('summary.count', 7)->assertJsonPath('summary.average', 3.4)->assertJsonPath('summary.distribution.0.count', 2)->assertJsonPath('meta.last_page', 2);
    expect(array_keys($response->json('data.0')))->toBe(['id','name','rating','review','created_at']);
    $this->getJson('/api/v1/products/'.$this->product->id.'/reviews?page=2')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/products/'.$this->product->id.'/reviews?page=bad')->assertUnprocessable();
    $this->getJson('/api/v1/products')->assertOk()->assertJsonFragment(['reviews_count' => 7]);
});

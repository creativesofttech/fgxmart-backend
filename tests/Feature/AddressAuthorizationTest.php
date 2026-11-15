<?php

use App\Models\CartItem;
use App\Models\Country;
use App\Models\Product;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not accept a client-supplied address owner on creation or update', function () {
    $customer = User::factory()->create();
    $other = User::factory()->create();
    $country = Country::create(['name' => 'Test Country', 'code' => 'TC', 'status' => true]);
    $payload = [
        'name' => 'Customer', 'phone_number' => '1234567890', 'type' => 'Home',
        'address_line_1' => 'Test Street', 'city' => 'Test City', 'state' => 'Test State',
        'country_id' => $country->id, 'zipcode' => '12345', 'user_id' => $other->id,
    ];
    $token = $customer->createToken('customer')->plainTextToken;
    $response = $this->withToken($token)->postJson('/api/v1/addresses', $payload)->assertCreated();
    $id = $response->json('data.id');
    $this->assertDatabaseHas('user_addresses', ['id' => $id, 'user_id' => $customer->id]);
    $this->withToken($token)->putJson("/api/v1/addresses/{$id}", $payload)->assertOk();
    $this->assertDatabaseHas('user_addresses', ['id' => $id, 'user_id' => $customer->id]);
});

it('rejects an order using another customers address', function () {
    $customer = User::factory()->create();
    $other = User::factory()->create();
    $country = Country::create(['name' => 'Test Country', 'code' => 'TC', 'status' => true]);
    $address = UserAddress::create([
        'user_id' => $other->id, 'name' => 'Other Customer', 'phone_number' => '1234567890', 'type' => 'Home',
        'address_line_1' => 'Private Street', 'city' => 'Test City', 'state' => 'Test State',
        'country_id' => $country->id, 'zipcode' => '12345',
    ]);
    $product = Product::create(['name' => 'Product', 'slug' => 'product', 'sku' => 'TEST', 'base_price' => 10, 'discount_price' => 8, 'stock_quantity' => 10]);
    CartItem::create(['user_id' => $customer->id, 'product_id' => $product->id, 'quantity' => 1]);
    $this->withToken($customer->createToken('customer')->plainTextToken)
        ->postJson('/api/v1/orders', ['address_id' => $address->id])->assertNotFound();
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('cart_items', 1);
});

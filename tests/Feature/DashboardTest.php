<?php

use App\Models\Admin;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function dashboardAdminToken(bool $super = true): string {
    $admin = Admin::create(['name' => 'Dashboard Admin', 'email' => fake()->unique()->safeEmail(), 'password' => 'test-password-123']);
    $role = Role::create(['name' => $super ? 'Super Admin' : 'Products Only', 'slug' => $super ? 'super-admin' : 'products-only']);
    if (!$super) $role->permissions()->attach(Permission::firstOrCreate(['slug' => 'products.index'], ['name' => 'View products']));
    $admin->roles()->attach($role);
    return $admin->createToken('dashboard-test', ['admin'], now()->addHour())->plainTextToken;
}

beforeEach(function () { Carbon::setTestNow('2026-11-15 12:00:00'); });
afterEach(function () { Carbon::setTestNow(); });

it('aggregates complete period totals paid revenue daily points and inventory growth', function () {
    $this->withToken(dashboardAdminToken());
    $customer = User::factory()->create(['created_at' => now()->subDays(60)]);
    User::factory()->create(['created_at' => now()->subDays(2)]);
    $product = Product::forceCreate(['name' => 'Pantry Product', 'slug' => 'pantry-product', 'sku' => 'P1', 'base_price' => 10, 'created_at' => now()->subDays(60)]);
    Product::forceCreate(['name' => 'New Product', 'slug' => 'new-product', 'sku' => 'P2', 'base_price' => 10, 'created_at' => now()->subDays(2)]);
    foreach ([
        ['NOW-PAID', 100, 'paid', 'delivered', now(), 5],
        ['OTHER-PAID', 50, 'paid', 'processing', now()->subDays(3), 3],
        ['UNPAID', 80, 'unpaid', 'pending', now(), 2],
        ['CANCELLED', 500, 'paid', 'cancelled', now(), 50],
        ['REFUNDED', 90, 'refunded', 'delivered', now(), 9],
        ['PRIOR', 50, 'paid', 'delivered', now()->subDays(40), 7],
        ['FUTURE', 900, 'paid', 'pending', now()->addDay(), 90],
    ] as [$number, $amount, $payment, $status, $date, $quantity]) {
        $order = Order::forceCreate(['order_number' => $number, 'view_hash' => \Illuminate\Support\Str::random(32), 'user_id' => $customer->id, 'subtotal' => $amount, 'total_amount' => $amount, 'status' => $status, 'payment_status' => $payment, 'shipping_address' => [], 'created_at' => $date]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Pantry Product', 'price' => $amount / $quantity, 'quantity' => $quantity, 'subtotal' => $amount]);
    }
    $response = $this->getJson('/api/v1/admin/dashboard?days=30')->assertOk()
        ->assertJsonPath('data.metrics.orders.value', 5)->assertJsonPath('data.metrics.orders.previous', 1)
        ->assertJsonPath('data.metrics.revenue.value', 150)->assertJsonPath('data.metrics.revenue.change_percent', 200)
        ->assertJsonPath('data.metrics.products.value', 2)->assertJsonPath('data.metrics.products.previous', 1)
        ->assertJsonPath('data.metrics.customers.value', 2)->assertJsonPath('data.sales.paid_orders', 2)
        ->assertJsonPath('data.sales.average_order_value', 75)->assertJsonCount(30, 'data.sales.series')
        ->assertJsonCount(5, 'data.recent_orders')->assertJsonPath('data.top_products.0.quantity', 8)
        ->assertJsonPath('data.top_products.0.revenue', 150)->assertJsonCount(6, 'data.statuses');
    expect(array_sum(array_column($response->json('data.sales.series'), 'revenue')))->toEqual(150);
    expect(array_sum(array_column($response->json('data.statuses'), 'count')))->toBe(5);
    $this->getJson('/api/v1/admin/dashboard?days=7')->assertOk()->assertJsonCount(7, 'data.sales.series');
    $this->getJson('/api/v1/admin/dashboard?days=90')->assertOk()->assertJsonCount(90, 'data.sales.series');
});

it('returns zeroes for empty periods and rejects unsupported ranges', function () {
    $this->withToken(dashboardAdminToken());
    $this->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonPath('data.metrics.orders.value', 0)
        ->assertJsonPath('data.metrics.revenue.value', 0)->assertJsonPath('data.metrics.revenue.change_percent', 0)
        ->assertJsonPath('data.sales.average_order_value', 0)->assertJsonCount(0, 'data.recent_orders')->assertJsonCount(0, 'data.top_products');
    foreach (['0', '365', 'invalid'] as $days) $this->getJson('/api/v1/admin/dashboard?days='.$days)->assertUnprocessable();
});

it('does not expose order revenue or customer data to a product-only admin', function () {
    $this->withToken(dashboardAdminToken(false));
    $this->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonPath('data.permissions.orders', false)
        ->assertJsonPath('data.permissions.customers', false)->assertJsonMissingPath('data.metrics.orders')
        ->assertJsonMissingPath('data.metrics.revenue')->assertJsonMissingPath('data.metrics.customers')
        ->assertJsonPath('data.metrics.products.value', 0)->assertJsonPath('data.sales', null)
        ->assertJsonPath('data.statuses', null)->assertJsonPath('data.recent_orders', null)->assertJsonPath('data.top_products', null);
});

it('requires an authenticated administrator for dashboard aggregates', function () {
    $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
    $token = User::factory()->create()->createToken('customer')->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/admin/dashboard')->assertForbidden();
});

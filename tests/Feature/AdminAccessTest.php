<?php

use App\Models\Admin;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

function adminAccessToken(array $permissions = [], bool $superAdmin = false): string
{
    $admin = Admin::create(['name' => 'Test Admin', 'email' => fake()->unique()->safeEmail(), 'password' => 'test-password-123']);
    $role = Role::create(['name' => $superAdmin ? 'Super Admin' : 'Catalog Viewer', 'slug' => $superAdmin ? 'super-admin' : 'catalog-viewer']);
    $admin->roles()->attach($role);
    foreach ($permissions as $slug) {
        $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        $role->permissions()->attach($permission);
    }
    return $admin->createToken('test-admin', ['admin'], now()->addHour())->plainTextToken;
}

it('rejects a customer bearer token on every protected admin route', function () {
    $token = User::factory()->create()->createToken('customer')->plainTextToken;
    $routes = collect(Route::getRoutes())->filter(fn ($route) =>
        str_starts_with($route->uri(), 'api/v1/admin/') && !str_ends_with($route->uri(), '/secure-admin-fgx')
    );
    expect($routes->count())->toBeGreaterThan(50);

    foreach ($routes as $route) {
        $uri = '/' . preg_replace('/\{[^}]+\}/', '1', $route->uri());
        $this->withToken($token)->json($route->methods()[0], $uri)->assertForbidden();
    }
});

it('requires authentication for admin data', function () {
    $this->getJson('/api/v1/admin/orders')->assertUnauthorized();
});

it('requires the admin token ability even for an admin model', function () {
    $admin = Admin::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'test-password-123']);
    $token = $admin->createToken('other-purpose', ['catalog'])->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/admin/user')->assertForbidden();
});

it('allows super administrators to read protected data', function () {
    $token = adminAccessToken(superAdmin: true);
    $this->withToken($token)->getJson('/api/v1/admin/orders')->assertOk();
});

it('enforces resource permissions and fails closed for mutations', function () {
    $token = adminAccessToken(['products.index']);
    $this->withToken($token)->getJson('/api/v1/admin/products')->assertOk();
    $this->withToken($token)->postJson('/api/v1/admin/products', [])->assertForbidden();
    $this->withToken($token)->getJson('/api/v1/admin/orders')->assertForbidden();
    $this->withToken($token)->putJson('/api/v1/admin/company-settings', [])->assertStatus(405);
    $this->withToken($token)->postJson('/api/v1/admin/company-settings', [])->assertForbidden();
});

it('exposes only the current admin profile and allows token revocation without resource permissions', function () {
    $token = adminAccessToken();
    $this->withToken($token)->getJson('/api/v1/admin/user')
        ->assertOk()->assertJsonPath('data.roles.0', 'catalog-viewer')->assertJsonMissingPath('data.password');
    $this->withToken($token)->postJson('/api/v1/admin/logout')->assertOk();
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('does not accept admin credentials on customer APIs', function () {
    $this->withToken(adminAccessToken(superAdmin: true))->getJson('/api/v1/user')->assertForbidden();
});

it('rejects expired admin tokens', function () {
    $admin = Admin::create(['name' => 'Admin', 'email' => 'expired@example.com', 'password' => 'test-password-123']);
    $token = $admin->createToken('expired', ['admin'], now()->subMinute())->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/admin/user')->assertUnauthorized();
});

it('requires legacy wildcard non-expiring tokens to sign in again', function () {
    $admin = Admin::create(['name' => 'Legacy Admin', 'email' => 'legacy@example.com', 'password' => 'test-password-123']);
    $token = $admin->createToken('legacy-admin')->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/admin/user')->assertForbidden();
});

it('issues scoped expiring tokens and throttles admin login', function () {
    Admin::create(['name' => 'Admin', 'email' => 'login@example.com', 'password' => 'test-password-123']);
    $response = $this->postJson('/api/v1/admin/secure-admin-fgx', ['email' => 'login@example.com', 'password' => 'test-password-123'])->assertOk();
    $token = \Laravel\Sanctum\PersonalAccessToken::findToken($response->json('data.token'));
    expect($token->abilities)->toBe(['admin']);
    expect($token->expires_at)->not->toBeNull();
    for ($attempt = 0; $attempt < 4; $attempt++) {
        $this->postJson('/api/v1/admin/secure-admin-fgx', ['email' => 'login@example.com', 'password' => 'wrong'])->assertUnprocessable();
    }
    $this->postJson('/api/v1/admin/secure-admin-fgx', ['email' => 'login@example.com', 'password' => 'wrong'])->assertStatus(429);
});

it('records status changes against the admin table and rejects invalid statuses', function () {
    $user = User::factory()->create();
    $order = Order::create([
        'order_number' => 'FGX-TEST-ADMIN', 'view_hash' => 'test-hash', 'user_id' => $user->id,
        'subtotal' => 10, 'total_amount' => 10, 'shipping_address' => [],
        'status' => 'pending', 'payment_status' => 'unpaid',
    ]);
    $token = adminAccessToken(superAdmin: true);
    $this->withToken($token)->putJson("/api/v1/admin/orders/{$order->order_number}/status", ['status' => 'confirmed'])->assertOk();
    $log = $order->statusLogs()->firstOrFail();
    expect($log->user)->toBeInstanceOf(Admin::class);
    expect($log->admin_id)->toBe(Admin::where('name', 'Test Admin')->firstOrFail()->id);
    $this->withToken($token)->putJson("/api/v1/admin/orders/{$order->order_number}/status", ['status' => 'anything'])->assertUnprocessable();
});

<?php

use App\Models\Admin;
use App\Models\Brand;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function brandOrderToken(array $permissions = ['brands.index', 'brands.edit']): string
{
    $admin = Admin::create(['name' => 'Catalog Manager', 'email' => fake()->unique()->safeEmail(), 'password' => 'test-password-123']);
    $role = Role::create(['name' => 'Brand Manager', 'slug' => 'brand-manager']);
    $admin->roles()->attach($role);
    foreach ($permissions as $slug) {
        $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        $role->permissions()->attach($permission);
    }
    return $admin->createToken('brand-manager', ['admin'], now()->addHour())->plainTextToken;
}

function brandOrderFixtures(): array
{
    return collect(['Alpha', 'Beta', 'Gamma'])->map(function ($name, $index) {
        $brand = Brand::create(['name' => $name, 'slug' => strtolower($name), 'status' => $name !== 'Beta']);
        $brand->sort_order = $index + 1;
        $brand->save();
        return $brand;
    })->all();
}

it('saves the complete brand order and serves it consistently to admin and public lists', function () {
    [$alpha, $beta, $gamma] = brandOrderFixtures();
    $this->withToken(brandOrderToken())->putJson('/api/v1/admin/brands/reorder', ['brand_ids' => [$gamma->id, $beta->id, $alpha->id]])->assertOk();
    $this->getJson('/api/v1/admin/brands?all=1')->assertJsonPath('data.0.id', $gamma->id)->assertJsonPath('data.1.id', $beta->id)->assertJsonPath('data.2.id', $alpha->id);
    $this->getJson('/api/v1/brands?all=1')->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $gamma->id)->assertJsonPath('data.1.id', $alpha->id);
    $this->getJson('/api/v1/brands?per_page=1&page=2')->assertJsonPath('data.0.id', $alpha->id);
    expect($gamma->fresh()->sort_order)->toBe(1);
    expect($alpha->fresh()->sort_order)->toBe(3);
});

it('rejects malformed or stale orders without changing any positions', function () {
    [$alpha, $beta, $gamma] = brandOrderFixtures();
    $this->withToken(brandOrderToken());
    foreach ([[], [$alpha->id, $alpha->id, $gamma->id], [$alpha->id, $beta->id, 99999], ['bad', $beta->id, $gamma->id]] as $ids) {
        $this->putJson('/api/v1/admin/brands/reorder', ['brand_ids' => $ids])->assertUnprocessable();
    }
    $this->putJson('/api/v1/admin/brands/reorder', ['brand_ids' => [$gamma->id, $alpha->id]])->assertConflict();
    expect(Brand::orderBy('sort_order')->pluck('id')->all())->toBe([$alpha->id, $beta->id, $gamma->id]);
});

it('requires brand edit permission to rearrange brands', function () {
    [$alpha, $beta, $gamma] = brandOrderFixtures();
    $this->withToken(brandOrderToken(['brands.index']))->putJson('/api/v1/admin/brands/reorder', ['brand_ids' => [$gamma->id, $beta->id, $alpha->id]])->assertForbidden();
    expect($alpha->fresh()->sort_order)->toBe(1);
});

it('appends new brands and preserves their position when edited', function () {
    brandOrderFixtures();
    $this->withToken(brandOrderToken(['brands.index', 'brands.create', 'brands.edit']));
    $id = $this->postJson('/api/v1/admin/brands', ['name' => 'A New Brand', 'status' => true])->assertCreated()->assertJsonPath('data.sort_order', 4)->json('data.id');
    $this->putJson('/api/v1/admin/brands/'.$id, ['name' => 'Renamed Brand', 'status' => true])->assertOk()->assertJsonPath('data.sort_order', 4);
});

it('backfills existing brands in their original alphabetical order', function () {
    $migration = require database_path('migrations/2026_11_19_000001_add_sort_order_to_brands.php');
    $migration->down();
    foreach (['Zulu', 'Alpha', 'Middle'] as $name) {
        DB::table('brands')->insert(['name' => $name, 'slug' => strtolower($name), 'status' => true]);
    }
    $migration->up();
    expect(DB::table('brands')->orderBy('sort_order')->pluck('name')->all())->toBe(['Alpha', 'Middle', 'Zulu']);
    expect(DB::table('brands')->orderBy('sort_order')->pluck('sort_order')->all())->toBe([1, 2, 3]);
});

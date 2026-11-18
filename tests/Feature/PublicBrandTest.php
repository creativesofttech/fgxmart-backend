<?php

use App\Models\Admin;
use App\Models\Brand;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('provides all active brands in name order without requiring admin access', function () {
    foreach (range(12, 1) as $index) Brand::create(['name' => 'Brand '.str_pad($index, 2, '0', STR_PAD_LEFT), 'slug' => 'brand-'.$index, 'status' => true]);
    Brand::create(['name' => 'Hidden Brand', 'slug' => 'hidden-brand', 'status' => false]);
    $this->getJson('/api/v1/brands?all=1')->assertOk()->assertJsonCount(12, 'data')->assertJsonPath('data.0.name', 'Brand 01')->assertJsonPath('data.11.name', 'Brand 12')->assertJsonMissing(['name' => 'Hidden Brand'])->assertJsonPath('data.0.status', true);
    $this->getJson('/api/v1/brands?per_page=9')->assertOk()->assertJsonCount(9, 'data')->assertJsonPath('total', 12)->assertJsonPath('last_page', 2);
    $this->postJson('/api/v1/brands', ['name' => 'Not allowed'])->assertStatus(405);
});

it('keeps inactive brands available to authorised administrators', function () {
    Brand::create(['name' => 'Hidden Brand', 'slug' => 'hidden-brand', 'status' => false]);
    $admin = Admin::create(['name' => 'Admin', 'email' => 'brand-admin@example.test', 'password' => 'test-password-123']);
    $admin->roles()->attach(Role::create(['name' => 'Super Admin', 'slug' => 'super-admin']));
    $this->withToken($admin->createToken('admin', ['admin'], now()->addHour())->plainTextToken)->getJson('/api/v1/admin/brands?all=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', false);
});

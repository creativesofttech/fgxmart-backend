<?php

use App\Models\Admin;
use App\Models\Country;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Zipcode;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function zipcodeAdmin(array $permissions = ['zipcodes.index', 'zipcodes.create', 'zipcodes.edit', 'zipcodes.destroy']): string
{
    $admin = Admin::create(['name' => 'Postal Manager', 'email' => fake()->unique()->safeEmail(), 'password' => 'test-password-123']);
    $role = Role::create(['name' => 'Postal Manager', 'slug' => 'postal-manager']);
    $admin->roles()->attach($role);
    foreach ($permissions as $slug) {
        $role->permissions()->attach(Permission::firstOrCreate(['slug' => $slug], ['name' => $slug]));
    }
    return $admin->createToken('zipcodes', ['admin'], now()->addHour())->plainTextToken;
}

it('saves normalized comma-separated postal codes per country and preserves leading zeroes', function () {
    $this->withToken(zipcodeAdmin());
    $country = Country::create(['name' => 'United Kingdom', 'code' => 'GB']);
    $created = $this->postJson('/api/v1/admin/zipcodes', ['country_id' => $country->id, 'zipcodes' => ' 00123, sw1a   1aa, 00123, 12345-6789 '])
        ->assertCreated()->assertJsonPath('data.zipcodes', '00123, SW1A 1AA, 12345-6789')->json('data');
    $this->getJson('/api/v1/admin/zipcodes')->assertOk()->assertJsonPath('data.0.country.name', 'United Kingdom')
        ->assertJsonPath('data.0.zipcodes', '00123, SW1A 1AA, 12345-6789')->assertJsonPath('countries.0.code', 'GB');
    $this->putJson('/api/v1/admin/zipcodes/'.$created['id'], ['country_id' => $country->id, 'zipcodes' => '00001, 00002'])
        ->assertOk()->assertJsonPath('data.zipcodes', '00001, 00002');
    $this->deleteJson('/api/v1/admin/zipcodes/'.$created['id'])->assertOk();
    expect(Zipcode::count())->toBe(0)->and($country->fresh())->not->toBeNull();
});

it('rejects duplicate country lists, missing countries, invalid entries and oversized lists', function () {
    $this->withToken(zipcodeAdmin());
    $country = Country::create(['name' => 'India', 'code' => 'IN']);
    Zipcode::create(['country_id' => $country->id, 'zipcodes' => '560001']);
    $this->postJson('/api/v1/admin/zipcodes', ['country_id' => $country->id, 'zipcodes' => '560002'])->assertUnprocessable()->assertJsonValidationErrors('country_id');
    $this->postJson('/api/v1/admin/zipcodes', ['country_id' => 9999, 'zipcodes' => '00123'])->assertUnprocessable()->assertJsonValidationErrors('country_id');
    $other = Country::create(['name' => 'Other', 'code' => 'OT']);
    foreach (['', '00123,,00124', 'bad<script>', str_repeat('A', 21), implode(',', range(1, 1001))] as $invalid) {
        $this->postJson('/api/v1/admin/zipcodes', ['country_id' => $other->id, 'zipcodes' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('zipcodes');
    }
    expect(Zipcode::count())->toBe(1);
});

it('enforces zipcode permissions independently of country management permissions', function () {
    $this->withToken(zipcodeAdmin(['zipcodes.index']));
    $country = Country::create(['name' => 'India', 'code' => 'IN']);
    $row = Zipcode::create(['country_id' => $country->id, 'zipcodes' => '560001']);
    $this->getJson('/api/v1/admin/zipcodes')->assertOk()->assertJsonPath('countries.0.name', 'India');
    $this->getJson('/api/v1/admin/countries')->assertForbidden();
    $this->postJson('/api/v1/admin/zipcodes', ['country_id' => $country->id, 'zipcodes' => '00123'])->assertForbidden();
    $this->putJson('/api/v1/admin/zipcodes/'.$row->id, ['country_id' => $country->id, 'zipcodes' => '00123'])->assertForbidden();
    $this->deleteJson('/api/v1/admin/zipcodes/'.$row->id)->assertForbidden();
});

it('removes zipcode lists when their country is deleted', function () {
    $country = Country::create(['name' => 'India', 'code' => 'IN']);
    Zipcode::create(['country_id' => $country->id, 'zipcodes' => '560001']);
    $country->delete();
    expect(Zipcode::count())->toBe(0);
});

it('provides only configured zipcodes for active countries without requiring admin access', function () {
    $country = Country::create(['name' => 'India', 'code' => 'IN', 'status' => true]);
    $other = Country::create(['name' => 'Other', 'code' => 'OT', 'status' => true]);
    $hidden = Country::create(['name' => 'Hidden', 'code' => 'HI', 'status' => false]);
    Zipcode::create(['country_id' => $country->id, 'zipcodes' => '00123, SW1A 1AA']);
    Zipcode::create(['country_id' => $hidden->id, 'zipcodes' => 'SECRET']);
    $this->getJson('/api/v1/countries/'.$country->id.'/zipcodes')->assertOk()->assertExactJson(['data' => ['00123', 'SW1A 1AA']]);
    $this->getJson('/api/v1/countries/'.$other->id.'/zipcodes')->assertOk()->assertExactJson(['data' => []]);
    $this->getJson('/api/v1/countries/'.$hidden->id.'/zipcodes')->assertNotFound();
    $this->getJson('/api/v1/countries/999999/zipcodes')->assertNotFound();
    $this->postJson('/api/v1/countries/'.$country->id.'/zipcodes', ['zipcodes' => '99999'])->assertMethodNotAllowed();
});

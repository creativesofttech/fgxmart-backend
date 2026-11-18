<?php

use App\Models\Admin;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['legal-pages.path' => Storage::fake('legal-tests')->path('pages')]);
});

function legalPageAdminToken(array $permissions): string
{
    $admin = Admin::create(['name' => 'Policy Editor', 'email' => fake()->unique()->safeEmail(), 'password' => 'test-password-123']);
    $role = Role::create(['name' => 'Policy role '.$admin->id, 'slug' => 'policy-role-'.$admin->id]);
    $admin->roles()->attach($role);
    foreach ($permissions as $permission) {
        $role->permissions()->attach(Permission::firstOrCreate(['slug' => $permission], ['name' => $permission]));
    }
    return $admin->createToken('admin', ['admin'], now()->addHour())->plainTextToken;
}

it('serves both bundled JSON defaults publicly without creating runtime files', function () {
    foreach (['terms-and-conditions' => 'Terms & Conditions', 'privacy-policy' => 'Privacy Policy'] as $slug => $title) {
        $this->getJson('/api/v1/legal-pages/'.$slug)->assertOk()->assertJsonPath('data.title', $title)->assertJsonPath('data.slug', $slug)->assertJsonPath('data.updated_at', null)->assertHeader('Cache-Control', 'no-store, private');
        expect(File::exists(config('legal-pages.path').'/'.$slug.'.json'))->toBeFalse();
    }
    $this->getJson('/api/v1/legal-pages/unknown')->assertNotFound();
    $this->putJson('/api/v1/legal-pages/privacy-policy', ['title' => 'Changed', 'content' => '<p>Changed.</p>'])->assertStatus(405);
});

it('requires administrator resource permissions for reading and saving', function () {
    $url = '/api/v1/admin/legal-pages/privacy-policy';
    $this->getJson($url)->assertUnauthorized();
    $this->withToken(User::factory()->create()->createToken('customer')->plainTextToken)->putJson($url, ['title' => 'No', 'content' => '<p>No.</p>'])->assertForbidden();
    $this->app['auth']->forgetGuards();
    $this->withToken(legalPageAdminToken([]))->getJson($url)->assertForbidden();
    $this->app['auth']->forgetGuards();
    $this->withToken(legalPageAdminToken(['legal-pages.index']))->getJson($url)->assertOk();
    $this->putJson($url, ['title' => 'No', 'content' => '<p>No.</p>'])->assertForbidden();
    expect(File::exists(config('legal-pages.path').'/privacy-policy.json'))->toBeFalse();
});

it('saves safe rich text as JSON and serves the saved document instead of defaults', function () {
    $this->withToken(legalPageAdminToken(['legal-pages.index', 'legal-pages.edit']));
    $url = '/api/v1/admin/legal-pages/privacy-policy';
    $html = '<h2 style="font-size: 22px" onclick="bad()">Privacy café</h2><p style="color:red"><strong>Details</strong></p><script>secret()</script><img src=x onerror=bad()><iframe>unsafe</iframe><ul><li>Information</li></ul>';
    $response = $this->putJson($url, ['title' => 'Our Privacy', 'content' => $html, 'path' => '../../.env'])->assertOk()->assertJsonPath('data.title', 'Our Privacy');
    $content = $response->json('data.content');
    expect($content)->toContain('font-size: 22px', 'Privacy café', '<strong>Details</strong>', '<li>Information</li>')
        ->not->toContain('onclick', 'onerror', '<script', 'secret()', '<iframe', 'unsafe', 'color:red');
    $stored = json_decode(File::get(config('legal-pages.path').'/privacy-policy.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($stored['title'])->toBe('Our Privacy');
    expect($stored['content'])->toBe($content);
    expect($stored['updated_at'])->not->toBeNull();
    $this->getJson('/api/v1/legal-pages/privacy-policy')->assertOk()->assertJsonPath('data.content', $content);
    $this->getJson('/api/v1/legal-pages/terms-and-conditions')->assertJsonPath('data.title', 'Terms & Conditions');
    $this->putJson('/api/v1/admin/legal-pages/unknown', ['title' => 'Unknown', 'content' => '<p>Unknown.</p>'])->assertNotFound();
    expect(count(File::files(config('legal-pages.path'))))->toBe(1);
});

it('rejects blank or oversized content without replacing the last saved JSON', function () {
    $this->withToken(legalPageAdminToken(['legal-pages.edit']));
    $url = '/api/v1/admin/legal-pages/terms-and-conditions';
    $this->putJson($url, ['title' => 'Saved terms', 'content' => '<p>Saved content.</p>'])->assertOk();
    foreach ([['title' => '', 'content' => '<p>Content.</p>'], ['title' => 'Title', 'content' => '<p><br></p>'], ['title' => 'Title', 'content' => str_repeat('x', 100001)]] as $invalid) $this->putJson($url, $invalid)->assertUnprocessable();
    $this->getJson('/api/v1/legal-pages/terms-and-conditions')->assertJsonPath('data.title', 'Saved terms');
});

it('sanitizes manually edited JSON and fails clearly if a saved file is corrupt', function () {
    File::ensureDirectoryExists(config('legal-pages.path'));
    $path = config('legal-pages.path').'/privacy-policy.json';
    File::put($path, json_encode(['title' => 'Manual edit', 'content' => '<p>Manual content.</p><script>alert(1)</script>']));
    $this->getJson('/api/v1/legal-pages/privacy-policy')->assertOk()->assertJsonPath('data.content', '<p>Manual content.</p>');
    File::put($path, '{broken-json');
    $this->getJson('/api/v1/legal-pages/privacy-policy')->assertStatus(503);
});

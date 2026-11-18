<?php

use App\Models\Faq;
use Database\Seeders\FaqSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds ordered app specific FAQs once without duplicating them on reruns', function () {
    $this->seed(FaqSeeder::class);
    expect(Faq::count())->toBe(16);
    expect(Faq::where('status', true)->count())->toBe(16);
    expect(Faq::orderBy('sort_order')->pluck('sort_order')->all())->toBe(range(10, 160, 10));
    $this->seed(FaqSeeder::class);
    expect(Faq::count())->toBe(16);
    $this->getJson('/api/v1/faqs')->assertOk()->assertJsonPath('data.0.title', 'What is FGX MART?')->assertJsonCount(16, 'data');
    expect(Faq::where('title', 'How do I send my product enquiry on WhatsApp?')->first()->description)
        ->toContain('does not automatically attach or send the PDF');
});

it('preserves administrator edits and custom FAQs when seeding again', function () {
    $this->seed(FaqSeeder::class);
    $faq = Faq::where('title', 'What is FGX MART?')->firstOrFail();
    $faq->update(['description' => 'An answer edited by the administrator.', 'status' => false, 'sort_order' => 777]);
    $custom = Faq::create(['title' => 'A custom question', 'description' => 'A custom answer.', 'status' => true, 'sort_order' => 999]);
    $this->seed(FaqSeeder::class);
    expect(Faq::count())->toBe(17);
    expect($faq->fresh()->only(['description', 'status', 'sort_order']))->toBe([
        'description' => 'An answer edited by the administrator.', 'status' => false, 'sort_order' => 777,
    ]);
    expect($custom->fresh()->description)->toBe('A custom answer.');
});

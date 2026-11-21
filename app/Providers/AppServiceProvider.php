<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([\App\Models\Product::class, \App\Models\Brand::class, \App\Models\Category::class] as $model) {
            $invalidate = function () {
                \Illuminate\Support\Facades\Cache::forget(\App\Services\ProductSearch::CACHE_KEY);
                // A read during a write transaction must not leave an old index cached after commit.
                \Illuminate\Support\Facades\DB::afterCommit(fn () => \Illuminate\Support\Facades\Cache::forget(\App\Services\ProductSearch::CACHE_KEY));
            };
            $model::saved($invalidate);
            $model::deleted($invalidate);
        }
    }
}

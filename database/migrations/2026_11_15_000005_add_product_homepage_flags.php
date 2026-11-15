<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_hot_deal')->default(false)->index();
            $table->boolean('is_new_arrival')->default(false)->index();
            $table->boolean('is_best_seller')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            foreach (['is_hot_deal', 'is_new_arrival', 'is_best_seller'] as $column) {
                $table->dropIndex([$column]);
            }
            $table->dropColumn(['is_hot_deal', 'is_new_arrival', 'is_best_seller']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // is_featured already exists; retain all saved choices and index the public filter.
        Schema::table('products', function (Blueprint $table) {
            $table->index(['status', 'is_featured'], 'products_status_featured_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_status_featured_index');
        });
    }
};

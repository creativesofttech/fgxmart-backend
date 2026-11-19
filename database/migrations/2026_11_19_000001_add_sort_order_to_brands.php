<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->index();
        });

        // Preserve the existing alphabetical list until an administrator rearranges it.
        $ids = DB::table('brands')->orderBy('name')->orderBy('id')->pluck('id');
        foreach ($ids as $position => $id) {
            DB::table('brands')->where('id', $id)->update(['sort_order' => $position + 1]);
        }
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropIndex(['sort_order']);
            $table->dropColumn('sort_order');
        });
    }
};

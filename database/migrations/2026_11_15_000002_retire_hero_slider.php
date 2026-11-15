<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration {
    public function up(): void
    {
        $images = Schema::hasTable('hero_slides') ? DB::table('hero_slides')->pluck('image_path')->filter() : collect();
        Schema::dropIfExists('hero_slides');
        $permissions = DB::table('permissions')->where('slug', 'like', 'hero-slides.%')->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissions)->delete();
        DB::table('permissions')->whereIn('id', $permissions)->delete();
        foreach ($images as $path) {
            // Only remove files owned by the retired slider feature.
            if (str_starts_with($path, 'hero/')) {
                Storage::disk('public')->delete([$path, dirname($path).'/thumbnails/thumb_'.basename($path)]);
            }
        }
    }
    public function down(): void
    {
        // Feature retirement is permanent; removed content cannot be reconstructed.
    }
};

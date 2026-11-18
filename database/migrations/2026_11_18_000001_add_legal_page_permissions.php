<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['index' => 'View', 'edit' => 'Edit'] as $action => $label) {
            DB::table('permissions')->insertOrIgnore(['slug' => 'legal-pages.'.$action, 'name' => 'Legal Pages '.$label, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('slug', ['legal-pages.index','legal-pages.edit'])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};

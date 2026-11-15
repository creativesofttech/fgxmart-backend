<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zipcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('zipcodes');
            $table->timestamps();
        });
        foreach (['index' => 'View', 'create' => 'Create', 'edit' => 'Edit', 'destroy' => 'Delete'] as $action => $label) {
            DB::table('permissions')->insertOrIgnore(['slug' => 'zipcodes.'.$action, 'name' => 'Zipcodes '.$label, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('zipcodes');
        $ids = DB::table('permissions')->where('slug', 'like', 'zipcodes.%')->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};

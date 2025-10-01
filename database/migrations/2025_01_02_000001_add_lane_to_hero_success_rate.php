<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('hero_success_rate', function (Blueprint $table) {
            $table->string('lane')->nullable()->after('hero_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hero_success_rate', function (Blueprint $table) {
            $table->dropColumn('lane');
        });
    }
};

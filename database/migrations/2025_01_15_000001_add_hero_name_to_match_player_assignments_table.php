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
        Schema::table('match_player_assignments', function (Blueprint $table) {
            $table->string('hero_name')->nullable()->after('role');
            $table->index(['match_id', 'hero_name']);
            $table->index(['player_id', 'hero_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('match_player_assignments', function (Blueprint $table) {
            $table->dropIndex(['match_id', 'hero_name']);
            $table->dropIndex(['player_id', 'hero_name']);
            $table->dropColumn('hero_name');
        });
    }
};

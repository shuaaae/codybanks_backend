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
            // Add index to improve query performance for player-specific stats
            $table->index(['player_id', 'role', 'match_id'], 'idx_player_role_match');
            
            // Add index for fallback queries
            $table->index(['player_id', 'hero_name'], 'idx_player_hero');
            
            // Add index for role-based queries
            $table->index(['role', 'match_id'], 'idx_role_match');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('match_player_assignments', function (Blueprint $table) {
            $table->dropIndex('idx_player_role_match');
            $table->dropIndex('idx_player_hero');
            $table->dropIndex('idx_role_match');
        });
    }
};


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
            // Drop the unique constraint that includes hero_name
            $table->dropUnique(['match_id', 'role', 'player_id', 'hero_name']);
            
            // Drop the hero_name column
            $table->dropColumn('hero_name');
            
            // Recreate the original unique constraint without hero_name
            $table->unique(['match_id', 'role', 'player_id']);
            
            // Drop the index on player_id and hero_name
            $table->dropIndex(['player_id', 'hero_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('match_player_assignments', function (Blueprint $table) {
            // Add back the hero_name column
            $table->string('hero_name')->nullable()->after('role');
            
            // Drop the original unique constraint
            $table->dropUnique(['match_id', 'role', 'player_id']);
            
            // Recreate the unique constraint with hero_name
            $table->unique(['match_id', 'role', 'player_id', 'hero_name']);
            
            // Add back the index
            $table->index(['player_id', 'hero_name']);
        });
    }
};

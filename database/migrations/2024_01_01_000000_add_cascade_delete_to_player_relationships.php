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
        // For now, we'll skip the CASCADE migration and rely on explicit deletion in the controller
        // This migration can be run later when the database structure is more stable
        
        \Log::info('Migration add_cascade_delete_to_player_relationships: Skipping CASCADE setup for now');
        \Log::info('Player deletion will use explicit related record deletion in the controller');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Nothing to reverse for now
        \Log::info('Migration add_cascade_delete_to_player_relationships: No changes to reverse');
    }
};

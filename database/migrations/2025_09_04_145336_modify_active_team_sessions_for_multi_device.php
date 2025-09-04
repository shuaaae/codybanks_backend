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
        Schema::table('active_team_sessions', function (Blueprint $table) {
            // First drop the foreign key constraint on team_id
            $table->dropForeign(['team_id']);
            
            // Remove the unique constraint on team_id to allow multiple devices
            $table->dropUnique(['team_id']);
            
            // Add user_id to track which user the session belongs to
            $table->unsignedBigInteger('user_id')->nullable()->after('team_id');
            
            // Add device identifier for better tracking
            $table->string('device_id')->nullable()->after('user_id');
            
            // Add device info for better management
            $table->string('device_name')->nullable()->after('device_id');
            $table->string('device_type')->nullable()->after('device_name'); // mobile, desktop, tablet
            
            // Add foreign key for user_id
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            
            // Re-add foreign key for team_id
            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
            
            // Create a composite unique constraint for session_id and device_id
            $table->unique(['session_id', 'device_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('active_team_sessions', function (Blueprint $table) {
            // Drop the new unique constraint
            $table->dropUnique(['session_id', 'device_id']);
            
            // Drop foreign keys
            $table->dropForeign(['user_id']);
            $table->dropForeign(['team_id']);
            
            // Drop new columns
            $table->dropColumn(['user_id', 'device_id', 'device_name', 'device_type']);
            
            // Restore the original unique constraint on team_id
            $table->unique(['team_id']);
            
            // Re-add the original foreign key for team_id
            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
        });
    }
};

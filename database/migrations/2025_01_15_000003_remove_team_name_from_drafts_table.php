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
        Schema::table('drafts', function (Blueprint $table) {
            // Drop the index that includes team_name
            $table->dropIndex(['user_id', 'team_name', 'created_at']);
            
            // Drop the team_name column
            $table->dropColumn('team_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            // Add back the team_name column
            $table->string('team_name')->nullable()->after('user_id');
            
            // Add back the index
            $table->index(['user_id', 'team_name', 'created_at']);
        });
    }
};

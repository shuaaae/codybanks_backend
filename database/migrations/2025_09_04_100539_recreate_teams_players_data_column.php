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
        Schema::table('teams', function (Blueprint $table) {
            // Drop the existing column
            $table->dropColumn('players_data');
        });
        
        Schema::table('teams', function (Blueprint $table) {
            // Add the column back as JSON
            $table->json('players_data')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            // Drop the JSON column
            $table->dropColumn('players_data');
        });
        
        Schema::table('teams', function (Blueprint $table) {
            // Add back as longtext
            $table->longText('players_data')->nullable();
        });
    }
};

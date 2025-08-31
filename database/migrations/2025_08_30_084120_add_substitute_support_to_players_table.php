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
        Schema::table('players', function (Blueprint $table) {
            // Add substitute support
            $table->boolean('is_substitute')->default(false);
            $table->string('player_code')->unique()->nullable(); // Unique identifier for each player
            $table->text('notes')->nullable(); // For additional player info
            $table->unsignedBigInteger('primary_player_id')->nullable(); // Reference to primary player if this is a substitute
            $table->integer('substitute_order')->nullable(); // Order of substitutes (1st, 2nd, etc.)
            
            // Add foreign key for primary player reference
            $table->foreign('primary_player_id')->references('id')->on('players')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropForeign(['primary_player_id']);
            $table->dropColumn([
                'is_substitute',
                'player_code',
                'notes',
                'primary_player_id',
                'substitute_order'
            ]);
        });
    }
};

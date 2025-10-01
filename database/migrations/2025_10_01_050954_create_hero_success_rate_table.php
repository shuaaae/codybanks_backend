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
        Schema::create('hero_success_rate', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('player_id');
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('match_id');
            $table->string('hero_name');
            $table->string('match_type'); // 'scrim' or 'tournament'
            $table->boolean('is_win');
            $table->timestamp('match_date');
            $table->timestamps();
            
            // Foreign key constraints
            $table->foreign('player_id')->references('id')->on('players')->onDelete('cascade');
            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign('match_id')->references('id')->on('matches')->onDelete('cascade');
            
            // Indexes for better performance
            $table->index(['player_id', 'hero_name', 'match_type']);
            $table->index(['team_id', 'match_type']);
            $table->index('match_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hero_success_rate');
    }
};

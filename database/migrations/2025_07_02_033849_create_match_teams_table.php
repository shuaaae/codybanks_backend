<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->onDelete('cascade');
            $table->string('team'); // team name
            $table->enum('team_color', ['blue', 'red']);
            $table->string('banning_phase1');
            $table->string('picks1');
            $table->string('banning_phase2');
            $table->string('picks2');
            $table->timestamps();

            $table->unique(['match_id', 'team_color']); // Only one blue and one red per match
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_teams');
    }
};
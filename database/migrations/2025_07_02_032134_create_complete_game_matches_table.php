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
        Schema::create('game_matches', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('team_a');
            $table->string('team_b');
            $table->string('first_pick'); // team_a or team_b
            $table->string('winner');     // team_a or team_b

            // Banning and picking phases for both teams
            $table->string('team_a_banning_phase1'); // e.g. "Mathilda, Lou Yi, Moskov"
            $table->string('team_b_banning_phase1');
            $table->string('team_a_pick1');          // e.g. "Su You, Gatotcacha, Granger"
            $table->string('team_b_pick1');
            $table->string('team_a_banning_phase2'); // e.g. "Fanny, Hanzo"
            $table->string('team_b_banning_phase2');
            $table->string('team_a_pick2');          // e.g. "Cecillion, Phovues"
            $table->string('team_b_pick2');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_matches');
    }
};
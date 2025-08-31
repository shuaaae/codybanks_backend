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
        Schema::create('match_player_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('match_id');
            $table->unsignedBigInteger('player_id');
            $table->string('role'); // exp, mid, jungler, gold, roam
            $table->boolean('is_starting_lineup')->default(true); // true for starting 5, false for substitutes
            $table->integer('substitute_order')->nullable(); // Order if this is a substitute
            $table->timestamp('substituted_in_at')->nullable(); // When substitute entered
            $table->timestamp('substituted_out_at')->nullable(); // When substitute left
            $table->text('notes')->nullable(); // Additional match notes
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('match_id')->references('id')->on('matches')->onDelete('cascade');
            $table->foreign('player_id')->references('id')->on('players')->onDelete('cascade');
            
            // Unique constraint: one player per role per match
            $table->unique(['match_id', 'role', 'player_id']);
            
            // Indexes for performance
            $table->index(['match_id', 'role']);
            $table->index(['player_id', 'role']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('match_player_assignments');
    }
};

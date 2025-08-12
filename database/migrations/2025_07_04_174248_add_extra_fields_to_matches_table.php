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
        Schema::table('matches', function (Blueprint $table) {
            $table->string('turtle_taken')->nullable();
            $table->string('lord_taken')->nullable();
            $table->text('notes')->nullable();
            $table->string('playstyle')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn(['turtle_taken', 'lord_taken', 'notes', 'playstyle']);
        });
    }
};

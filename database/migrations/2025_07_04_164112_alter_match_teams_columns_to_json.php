<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('match_teams', function (Blueprint $table) {
            $table->json('banning_phase1')->change();
            $table->json('picks1')->change();
            $table->json('banning_phase2')->change();
            $table->json('picks2')->change();
        });
    }
 
    public function down()
    {
        Schema::table('match_teams', function (Blueprint $table) {
            $table->string('banning_phase1')->change();
            $table->string('picks1')->change();
            $table->string('banning_phase2')->change();
            $table->string('picks2')->change();
        });
    }
};

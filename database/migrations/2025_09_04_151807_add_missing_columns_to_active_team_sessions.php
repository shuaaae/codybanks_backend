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
            // Add missing columns that are being used in the code
            if (!Schema::hasColumn('active_team_sessions', 'ip_address')) {
                $table->string('ip_address', 45)->nullable()->after('browser_fingerprint');
            }
            
            if (!Schema::hasColumn('active_team_sessions', 'user_agent')) {
                $table->text('user_agent')->nullable()->after('ip_address');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('active_team_sessions', function (Blueprint $table) {
            $table->dropColumn(['ip_address', 'user_agent']);
        });
    }
};
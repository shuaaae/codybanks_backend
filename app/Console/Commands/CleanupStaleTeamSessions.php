<?php

namespace App\Console\Commands;

use App\Models\ActiveTeamSession;
use Illuminate\Console\Command;
use Carbon\Carbon;

class CleanupStaleTeamSessions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'teams:cleanup-sessions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up stale team sessions older than 5 minutes';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $cutoffTime = Carbon::now()->subMinutes(5);
        
        $deletedCount = ActiveTeamSession::where('last_activity', '<', $cutoffTime)->delete();
        
        $this->info("Cleaned up {$deletedCount} stale team sessions.");
        
        return 0;
    }
}

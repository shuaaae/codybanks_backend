<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\StatisticsSyncService;
use App\Models\Team;

class ScheduledDataIntegrityCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'data:integrity-scheduled';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scheduled data integrity check and cleanup';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting scheduled data integrity check...');
        
        $syncService = new StatisticsSyncService();
        $teams = Team::all();
        $totalIssues = 0;
        $totalCleaned = 0;
        
        foreach ($teams as $team) {
            $this->info("Checking team: {$team->name}");
            
            // Check tournament data
            $tournamentResult = $syncService->validateTeamDataIntegrity($team->id, 'tournament');
            if ($tournamentResult['status'] === 'issues_found') {
                $this->warn("Found tournament issues for {$team->name}, cleaning up...");
                $cleaned = $syncService->cleanupTeamDataIntegrity($team->id, 'tournament');
                $totalCleaned += $cleaned;
                $totalIssues += count($tournamentResult['issues']);
            }
            
            // Check scrim data
            $scrimResult = $syncService->validateTeamDataIntegrity($team->id, 'scrim');
            if ($scrimResult['status'] === 'issues_found') {
                $this->warn("Found scrim issues for {$team->name}, cleaning up...");
                $cleaned = $syncService->cleanupTeamDataIntegrity($team->id, 'scrim');
                $totalCleaned += $cleaned;
                $totalIssues += count($scrimResult['issues']);
            }
        }
        
        if ($totalIssues > 0) {
            $this->warn("Scheduled cleanup completed: {$totalIssues} issues found, {$totalCleaned} records cleaned");
        } else {
            $this->info("Scheduled check completed: No issues found");
        }
        
        return 0;
    }
}

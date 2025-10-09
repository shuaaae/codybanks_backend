<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\StatisticsSyncService;
use App\Models\Team;

class DataIntegrityCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'data:integrity-check 
                            {--team= : Check specific team by name}
                            {--match-type=tournament : Match type to check (tournament/scrim)}
                            {--fix : Automatically fix issues found}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check and optionally fix data integrity issues';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $teamName = $this->option('team');
        $matchType = $this->option('match-type');
        $shouldFix = $this->option('fix');
        
        $syncService = new StatisticsSyncService();
        
        if ($teamName) {
            // Check specific team
            $team = Team::where('name', $teamName)->first();
            if (!$team) {
                $this->error("Team '{$teamName}' not found!");
                return 1;
            }
            
            $this->checkTeam($syncService, $team, $matchType, $shouldFix);
        } else {
            // Check all teams
            $teams = Team::all();
            $this->info("Checking data integrity for all teams...");
            
            foreach ($teams as $team) {
                $this->checkTeam($syncService, $team, $matchType, $shouldFix);
            }
        }
        
        return 0;
    }
    
    private function checkTeam($syncService, $team, $matchType, $shouldFix)
    {
        $this->info("Checking team: {$team->name} (ID: {$team->id})");
        
        $result = $syncService->validateTeamDataIntegrity($team->id, $matchType);
        
        if ($result['status'] === 'clean') {
            $this->info("✅ {$team->name} data is clean");
        } else {
            $this->warn("⚠️  {$team->name} has data integrity issues:");
            
            foreach ($result['issues'] as $issue) {
                $this->line("  - {$issue['type']}: {$issue['player']} ({$issue['count']} issues)");
            }
            
            if ($shouldFix) {
                $this->info("Fixing issues for {$team->name}...");
                $cleaned = $syncService->cleanupTeamDataIntegrity($team->id, $matchType);
                $this->info("✅ Cleaned {$cleaned} records for {$team->name}");
            } else {
                $this->line("Run with --fix to automatically fix these issues");
            }
        }
        
        $this->line('');
    }
}

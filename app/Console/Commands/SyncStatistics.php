<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\StatisticsSyncService;

class SyncStatistics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'statistics:sync {--team= : Sync for specific team ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync hero success rate and H2H statistics from existing matches';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting statistics synchronization...');
        
        $syncService = new StatisticsSyncService();
        $syncService->syncAllStatistics();
        
        $this->info('Statistics synchronization completed successfully!');
        
        // Show some stats
        $heroStatsCount = \App\Models\HeroSuccessRate::count();
        $h2hStatsCount = \App\Models\H2HStatistics::count();
        
        $this->info("Created {$heroStatsCount} hero success rate records");
        $this->info("Created {$h2hStatsCount} H2H statistics records");
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\GameMatch;
use App\Models\MatchPlayerAssignment;
use App\Models\Player;
use App\Models\MatchTeam;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PopulateMissingPlayerAssignments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'assignments:populate-missing {--team-id= : Specific team ID to process} {--match-id= : Specific match ID to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Populate missing player assignments for existing matches to ensure proper player-specific statistics';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $teamId = $this->option('team-id');
        $matchId = $this->option('match-id');

        $this->info('Starting to populate missing player assignments...');

        try {
            DB::beginTransaction();

            $query = GameMatch::with(['teams', 'playerAssignments']);
            
            if ($matchId) {
                $query->where('id', $matchId);
            } elseif ($teamId) {
                $query->where('team_id', $teamId);
            }

            $matches = $query->get();
            $this->info("Processing {$matches->count()} matches...");

            $processed = 0;
            $created = 0;

            foreach ($matches as $match) {
                $processed++;
                
                // Get all players for this team
                $teamPlayers = Player::where('team_id', $match->team_id)->get();
                
                if ($teamPlayers->isEmpty()) {
                    $this->warn("No players found for team {$match->team_id} in match {$match->id}");
                    continue;
                }

                // Find our team in the match
                $ourTeam = $match->teams->first();
                if (!$ourTeam) {
                    $this->warn("No team data found for match {$match->id}");
                    continue;
                }

                // Get picks data
                $picks = array_merge($ourTeam->picks1 ?? [], $ourTeam->picks2 ?? []);
                
                // Group picks by lane
                $picksByLane = [];
                foreach ($picks as $pick) {
                    if (is_array($pick) && isset($pick['lane']) && isset($pick['hero'])) {
                        $picksByLane[strtolower($pick['lane'])] = $pick;
                    }
                }

                // Get existing assignments for this match
                $existingAssignments = $match->playerAssignments;
                $existingPlayerIds = $existingAssignments->pluck('player_id')->toArray();

                // Create missing assignments for players not yet assigned
                foreach ($teamPlayers as $player) {
                    // Skip if player already has an assignment for this match
                    if (in_array($player->id, $existingPlayerIds)) {
                        continue;
                    }

                    // Check if there's a pick for this player's role
                    $playerRole = strtolower($player->role);
                    if (!isset($picksByLane[$playerRole])) {
                        $this->warn("No pick found for player {$player->name} (role: {$player->role}) in match {$match->id}");
                        continue;
                    }

                    $pick = $picksByLane[$playerRole];
                    $heroName = $pick['hero'] ?? null;

                    // Create the assignment
                    MatchPlayerAssignment::create([
                        'match_id' => $match->id,
                        'player_id' => $player->id,
                        'role' => $player->role,
                        'hero_name' => $heroName,
                        'is_starting_lineup' => !$player->is_substitute,
                        'substitute_order' => $player->is_substitute ? 1 : null,
                        'notes' => 'Auto-generated assignment'
                    ]);

                    $created++;
                    $this->line("Created assignment for {$player->name} ({$player->role}) -> {$heroName} in match {$match->id}");
                }

                if ($processed % 10 === 0) {
                    $this->info("Processed {$processed} matches, created {$created} assignments so far...");
                }
            }

            DB::commit();

            $this->info("✅ Successfully processed {$processed} matches and created {$created} missing assignments!");
            
            Log::info('PopulateMissingPlayerAssignments completed', [
                'processed_matches' => $processed,
                'created_assignments' => $created,
                'team_id' => $teamId,
                'match_id' => $matchId
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("❌ Error: " . $e->getMessage());
            Log::error('PopulateMissingPlayerAssignments failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }

        return 0;
    }
}

<?php

namespace App\Services;

use App\Models\HeroSuccessRate;
use App\Models\H2HStatistics;
use App\Models\GameMatch;
use App\Models\MatchTeam;
use App\Models\Player;
use Illuminate\Support\Facades\Log;

class StatisticsSyncService
{
    /**
     * Sync all hero success rate and H2H statistics from existing matches
     */
    public function syncAllStatistics()
    {
        Log::info("Starting statistics sync for all matches");
        
        // Clear existing statistics
        HeroSuccessRate::truncate();
        H2HStatistics::truncate();
        
        // Get all matches
        $matches = GameMatch::with(['teams'])->get();
        
        foreach ($matches as $match) {
            $this->syncMatchStatistics($match);
        }
        
        Log::info("Statistics sync completed");
    }
    
    /**
     * Sync statistics for a specific match
     */
    public function syncMatchStatistics(GameMatch $match)
    {
        Log::info("Syncing statistics for match ID: {$match->id}");
        
        foreach ($match->teams as $matchTeam) {
            $this->processMatchTeam($match, $matchTeam);
        }
    }
    
    /**
     * Process a specific team's data from a match
     */
    private function processMatchTeam(GameMatch $match, MatchTeam $matchTeam)
    {
        $picks1 = $matchTeam->picks1 ?? [];
        $picks2 = $matchTeam->picks2 ?? [];
        
        // Process picks1 (our team)
        $this->processPicks($match, $matchTeam, $picks1, $picks2, true);
        
        // Process picks2 (enemy team) - for H2H data
        $this->processPicks($match, $matchTeam, $picks2, $picks1, false);
    }
    
    /**
     * Process picks data and create statistics records
     */
    private function processPicks(GameMatch $match, MatchTeam $matchTeam, array $ourPicks, array $enemyPicks, bool $isOurTeam)
    {
        foreach ($ourPicks as $pick) {
            if (!is_array($pick) || !isset($pick['hero']) || !isset($pick['lane'])) {
                continue;
            }
            
            $heroName = $pick['hero'];
            $lane = $pick['lane'];
            $playerName = $pick['player'] ?? null;
            
            // Handle case where player is an array (convert to string)
            if (is_array($playerName)) {
                $playerName = implode(' ', $playerName);
            }
            
            // Skip if no team_id
            if (!$match->team_id) {
                Log::warning("Match has no team_id, skipping", [
                    'match_id' => $match->id,
                    'player_name' => $playerName
                ]);
                continue;
            }

            // Find the player
            $player = $this->findPlayer($playerName, $lane, $match->team_id);
            if (!$player) {
                Log::warning("Player not found for pick", [
                    'player_name' => $playerName,
                    'lane' => $lane,
                    'team_id' => $match->team_id
                ]);
                continue;
            }
            
            // Determine if this team won
            $isWin = $match->winner === $matchTeam->team;
            
            // Create hero success rate record
            HeroSuccessRate::create([
                'player_id' => $player->id,
                'team_id' => $match->team_id,
                'match_id' => $match->id,
                'hero_name' => $heroName,
                'match_type' => $match->match_type,
                'is_win' => $isWin,
                'match_date' => $match->created_at
            ]);
            
            // Create H2H statistics for each enemy hero
            if ($isOurTeam) {
                foreach ($enemyPicks as $enemyPick) {
                    if (is_array($enemyPick) && isset($enemyPick['hero'])) {
                        H2HStatistics::create([
                            'player_id' => $player->id,
                            'team_id' => $match->team_id,
                            'match_id' => $match->id,
                            'hero_used' => $heroName,
                            'enemy_hero' => $enemyPick['hero'],
                            'match_type' => $match->match_type,
                            'is_win' => $isWin,
                            'match_date' => $match->created_at
                        ]);
                    }
                }
            }
        }
    }
    
    /**
     * Find player by name and lane, with fallback to role matching
     */
    private function findPlayer(?string $playerName, string $lane, int $teamId)
    {
        // First try to find by exact name match
        if ($playerName) {
            $player = Player::where('name', $playerName)
                ->where('team_id', $teamId)
                ->first();
            if ($player) {
                return $player;
            }
        }
        
        // Fallback to role matching
        $role = $this->mapLaneToRole($lane);
        return Player::where('role', $role)
            ->where('team_id', $teamId)
            ->first();
    }
    
    /**
     * Map lane to role
     */
    private function mapLaneToRole(string $lane): string
    {
        $mapping = [
            'top' => 'top',
            'jungle' => 'jungle', 
            'mid' => 'mid',
            'bot' => 'adc',
            'support' => 'support'
        ];
        
        return $mapping[strtolower($lane)] ?? 'unknown';
    }
}

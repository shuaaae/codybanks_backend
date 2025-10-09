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
        // Get our team name from the team_id
        $ourTeam = \App\Models\Team::find($match->team_id);
        if (!$ourTeam) {
            Log::warning("Team not found for match", ['team_id' => $match->team_id, 'match_id' => $match->id]);
            return;
        }
        
        // Only process if this is our team
        if ($matchTeam->team !== $ourTeam->name) {
            Log::info("Skipping enemy team", ['team_name' => $matchTeam->team, 'our_team' => $ourTeam->name]);
            return;
        }
        
        Log::info("Processing our team", ['team_name' => $matchTeam->team, 'our_team' => $ourTeam->name]);
        
        $picks1 = $matchTeam->picks1 ?? [];
        $picks2 = $matchTeam->picks2 ?? [];
        
        // Combine all picks from our team
        $ourPicks = array_merge($picks1, $picks2);
        
        // Find enemy team picks
        $enemyPicks = $this->getEnemyPicks($match, $matchTeam);
        
        // Process our team's picks
        $this->processPicks($match, $matchTeam, $ourPicks, $enemyPicks, true);
    }
    
    /**
     * Get enemy team picks for H2H statistics
     */
    private function getEnemyPicks(GameMatch $match, MatchTeam $ourTeam)
    {
        $enemyPicks = [];
        
        // Get our team name
        $ourTeamModel = \App\Models\Team::find($match->team_id);
        if (!$ourTeamModel) {
            return $enemyPicks;
        }
        
        foreach ($match->teams as $team) {
            // Skip our team
            if ($team->team === $ourTeamModel->name) {
                continue;
            }
            
            // This is an enemy team, get their picks
            $enemyPicks1 = $team->picks1 ?? [];
            $enemyPicks2 = $team->picks2 ?? [];
            $enemyPicks = array_merge($enemyPicks, $enemyPicks1, $enemyPicks2);
            
            Log::info("Found enemy team picks", [
                'enemy_team' => $team->team,
                'picks1_count' => count($enemyPicks1),
                'picks2_count' => count($enemyPicks2)
            ]);
        }
        
        return $enemyPicks;
    }
    
    /**
     * Process picks data and create statistics records
     */
    private function processPicks(GameMatch $match, MatchTeam $matchTeam, array $ourPicks, array $enemyPicks, bool $isOurTeam)
    {
        // Get actual team players for proper mapping
        $teamPlayers = Player::where('team_id', $match->team_id)->get();
        $laneToPlayer = [];
        
        // Create proper lane to player mapping based on actual team players
        foreach ($teamPlayers as $player) {
            $laneToPlayer[strtolower($player->role)] = $player->name;
        }
        
        Log::info("Created lane to player mapping", [
            'team_id' => $match->team_id,
            'mapping' => $laneToPlayer
        ]);
        
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
            
            // If player is null, use lane mapping
            if (!$playerName) {
                $playerName = $laneToPlayer[$lane] ?? null;
                Log::info("Using lane mapping for null player", [
                    'hero' => $heroName,
                    'lane' => $lane,
                    'mapped_player' => $playerName
                ]);
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
            
            // CRITICAL: Validate that player belongs to the correct team
            if ($player->team_id !== $match->team_id) {
                Log::error("SECURITY VIOLATION: Player belongs to different team", [
                    'player_id' => $player->id,
                    'player_name' => $player->name,
                    'player_team_id' => $player->team_id,
                    'match_team_id' => $match->team_id,
                    'match_id' => $match->id
                ]);
                continue; // Skip this record to prevent cross-team contamination
            }
            
            // Determine if this team won
            $isWin = $match->winner === $matchTeam->team;
            
            // Check for existing hero success rate record to prevent duplicates
            $existingRecord = HeroSuccessRate::where('player_id', $player->id)
                ->where('match_id', $match->id)
                ->where('hero_name', $heroName)
                ->first();
            
            if ($existingRecord) {
                Log::warning("Hero success rate record already exists, skipping", [
                    'player_id' => $player->id,
                    'match_id' => $match->id,
                    'hero_name' => $heroName
                ]);
            } else {
                // Create hero success rate record
                HeroSuccessRate::create([
                    'player_id' => $player->id,
                    'team_id' => $match->team_id,
                    'match_id' => $match->id,
                    'hero_name' => $heroName,
                    'lane' => $lane,
                    'match_type' => $match->match_type,
                    'is_win' => $isWin,
                    'match_date' => $match->created_at
                ]);
            }
            
            // Create H2H statistics for the enemy hero in the SAME LANE only
            if ($isOurTeam) {
                // Find the enemy hero in the same lane
                $enemyHeroInSameLane = null;
                foreach ($enemyPicks as $enemyPick) {
                    if (is_array($enemyPick) && isset($enemyPick['hero']) && isset($enemyPick['lane'])) {
                        // Check if this enemy pick is in the same lane as our player
                        if (strtolower($enemyPick['lane']) === strtolower($lane)) {
                            $enemyHeroInSameLane = $enemyPick['hero'];
                            break;
                        }
                    }
                }
                
                // Only create H2H record if we found an enemy hero in the same lane
                if ($enemyHeroInSameLane) {
                    // Check if H2H record already exists to prevent duplicates
                    $existingH2H = H2HStatistics::where('player_id', $player->id)
                        ->where('match_id', $match->id)
                        ->where('hero_used', $heroName)
                        ->where('enemy_hero', $enemyHeroInSameLane)
                        ->first();
                    
                    if (!$existingH2H) {
                        H2HStatistics::create([
                            'player_id' => $player->id,
                            'team_id' => $match->team_id,
                            'match_id' => $match->id,
                            'hero_used' => $heroName,
                            'enemy_hero' => $enemyHeroInSameLane,
                            'lane' => $lane,
                            'match_type' => $match->match_type,
                            'is_win' => $isWin,
                            'match_date' => $match->created_at
                        ]);
                    }
                    
                    Log::info("Created H2H record for same-lane matchup", [
                        'player' => $player->name,
                        'hero_used' => $heroName,
                        'enemy_hero' => $enemyHeroInSameLane,
                        'lane' => $lane,
                        'match_id' => $match->id
                    ]);
                } else {
                    Log::warning("No enemy hero found in same lane for H2H", [
                        'player' => $player->name,
                        'lane' => $lane,
                        'match_id' => $match->id
                    ]);
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
                Log::info("Found player by name", [
                    'player_name' => $playerName,
                    'player_id' => $player->id,
                    'player_role' => $player->role,
                    'lane' => $lane
                ]);
                return $player;
            } else {
                Log::warning("Player not found by name", [
                    'player_name' => $playerName,
                    'team_id' => $teamId,
                    'lane' => $lane
                ]);
            }
        }
        
        // Fallback to role matching
        $role = $this->mapLaneToRole($lane);
        $player = Player::where('role', $role)
            ->where('team_id', $teamId)
            ->first();
            
        if ($player) {
            Log::info("Found player by role fallback", [
                'role' => $role,
                'player_id' => $player->id,
                'player_name' => $player->name,
                'lane' => $lane
            ]);
        } else {
            Log::error("No player found for lane", [
                'lane' => $lane,
                'role' => $role,
                'team_id' => $teamId
            ]);
        }
        
        return $player;
    }
    
    /**
     * Map lane to role
     */
    private function mapLaneToRole(string $lane): string
    {
        $mapping = [
            'exp' => 'exp',
            'jungler' => 'jungler', 
            'mid' => 'mid',
            'gold' => 'gold',
            'roam' => 'roam'
        ];
        
        return $mapping[strtolower($lane)] ?? 'unknown';
    }
    
    /**
     * Validate data integrity for a team's statistics
     */
    public function validateTeamDataIntegrity($teamId, $matchType = 'tournament')
    {
        Log::info("Starting data integrity validation", [
            'team_id' => $teamId,
            'match_type' => $matchType
        ]);
        
        $issues = [];
        
        // Get team matches
        $matches = GameMatch::where('team_id', $teamId)
            ->where('match_type', $matchType)
            ->pluck('id')
            ->toArray();
        
        // Get team players
        $players = Player::where('team_id', $teamId)->get();
        
        foreach ($players as $player) {
            // Check for records in wrong matches
            $wrongMatchRecords = HeroSuccessRate::where('player_id', $player->id)
                ->where('match_type', $matchType)
                ->whereNotIn('match_id', $matches)
                ->get();
            
            if ($wrongMatchRecords->count() > 0) {
                $issues[] = [
                    'type' => 'wrong_match_records',
                    'player' => $player->name,
                    'count' => $wrongMatchRecords->count(),
                    'matches' => $wrongMatchRecords->pluck('match_id')->toArray()
                ];
            }
            
            // Check for duplicate records
            $duplicateGroups = HeroSuccessRate::where('player_id', $player->id)
                ->where('match_type', $matchType)
                ->selectRaw('match_id, hero_name, COUNT(*) as count')
                ->groupBy('match_id', 'hero_name')
                ->having('count', '>', 1)
                ->get();
            
            if ($duplicateGroups->count() > 0) {
                $issues[] = [
                    'type' => 'duplicate_hero_records',
                    'player' => $player->name,
                    'count' => $duplicateGroups->count(),
                    'duplicates' => $duplicateGroups->toArray()
                ];
            }
            
            // Check H2H duplicates
            $h2hDuplicates = H2HStatistics::where('player_id', $player->id)
                ->where('match_type', $matchType)
                ->selectRaw('match_id, hero_used, enemy_hero, COUNT(*) as count')
                ->groupBy('match_id', 'hero_used', 'enemy_hero')
                ->having('count', '>', 1)
                ->get();
            
            if ($h2hDuplicates->count() > 0) {
                $issues[] = [
                    'type' => 'duplicate_h2h_records',
                    'player' => $player->name,
                    'count' => $h2hDuplicates->count(),
                    'duplicates' => $h2hDuplicates->toArray()
                ];
            }
        }
        
        if (empty($issues)) {
            Log::info("Data integrity validation passed", ['team_id' => $teamId]);
            return ['status' => 'clean', 'issues' => []];
        } else {
            Log::warning("Data integrity issues found", [
                'team_id' => $teamId,
                'issues' => $issues
            ]);
            return ['status' => 'issues_found', 'issues' => $issues];
        }
    }
    
    /**
     * Clean up data integrity issues for a team
     */
    public function cleanupTeamDataIntegrity($teamId, $matchType = 'tournament')
    {
        Log::info("Starting data integrity cleanup", [
            'team_id' => $teamId,
            'match_type' => $matchType
        ]);
        
        $cleaned = 0;
        
        // Get team matches
        $matches = GameMatch::where('team_id', $teamId)
            ->where('match_type', $matchType)
            ->pluck('id')
            ->toArray();
        
        // Get team players
        $players = Player::where('team_id', $teamId)->get();
        
        foreach ($players as $player) {
            // Remove records in wrong matches
            $wrongMatchRecords = HeroSuccessRate::where('player_id', $player->id)
                ->where('match_type', $matchType)
                ->whereNotIn('match_id', $matches)
                ->delete();
            
            $cleaned += $wrongMatchRecords;
            
            // Remove duplicate hero records (keep first, delete rest)
            $duplicateGroups = HeroSuccessRate::where('player_id', $player->id)
                ->where('match_type', $matchType)
                ->selectRaw('match_id, hero_name, MIN(id) as keep_id')
                ->groupBy('match_id', 'hero_name')
                ->havingRaw('COUNT(*) > 1')
                ->get();
            
            foreach ($duplicateGroups as $group) {
                $deleted = HeroSuccessRate::where('player_id', $player->id)
                    ->where('match_id', $group->match_id)
                    ->where('hero_name', $group->hero_name)
                    ->where('id', '!=', $group->keep_id)
                    ->delete();
                
                $cleaned += $deleted;
            }
            
            // Remove duplicate H2H records
            $h2hDuplicates = H2HStatistics::where('player_id', $player->id)
                ->where('match_type', $matchType)
                ->selectRaw('match_id, hero_used, enemy_hero, MIN(id) as keep_id')
                ->groupBy('match_id', 'hero_used', 'enemy_hero')
                ->havingRaw('COUNT(*) > 1')
                ->get();
            
            foreach ($h2hDuplicates as $group) {
                $deleted = H2HStatistics::where('player_id', $player->id)
                    ->where('match_id', $group->match_id)
                    ->where('hero_used', $group->hero_used)
                    ->where('enemy_hero', $group->enemy_hero)
                    ->where('id', '!=', $group->keep_id)
                    ->delete();
                
                $cleaned += $deleted;
            }
        }
        
        Log::info("Data integrity cleanup completed", [
            'team_id' => $teamId,
            'records_cleaned' => $cleaned
        ]);
        
        return $cleaned;
    }
}

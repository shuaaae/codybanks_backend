<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\MatchTeam;
use App\Models\Player;
use Illuminate\Support\Facades\Log;

class LaneChangeDetector
{
    /**
     * Detect lane changes between old and new match data
     */
    public function detectLaneChanges(GameMatch $match, array $newTeamsData)
    {
        Log::info("Detecting lane changes for match {$match->id}");
        
        $laneChanges = [];
        
        // Get current match teams
        $currentTeams = $match->teams;
        
        foreach ($newTeamsData as $newTeamData) {
            $teamName = $newTeamData['team'];
            $teamColor = $newTeamData['team_color'];
            
            // Find the current team data
            $currentTeam = $currentTeams->where('team', $teamName)
                ->where('team_color', $teamColor)
                ->first();
            
            if (!$currentTeam) {
                Log::warning("Current team not found for comparison", [
                    'team_name' => $teamName,
                    'team_color' => $teamColor
                ]);
                continue;
            }
            
            // Get current picks from database
            $currentPicks = array_merge($currentTeam->picks1 ?? [], $currentTeam->picks2 ?? []);
            $newPicks = array_merge($newTeamData['picks1'] ?? [], $newTeamData['picks2'] ?? []);
            
            // If current picks are empty, get baseline from statistics
            if (empty($currentPicks) && $teamName === $match->team->name) {
                $currentPicks = $this->getBaselineFromStatistics($match);
                Log::info("Using statistics as baseline for comparison", [
                    'match_id' => $match->id,
                    'baseline_count' => count($currentPicks)
                ]);
            }
            
            $changes = $this->comparePicks($currentPicks, $newPicks, $match->team_id);
            $laneChanges = array_merge($laneChanges, $changes);
        }
        
        Log::info("Detected lane changes", [
            'match_id' => $match->id,
            'changes_count' => count($laneChanges),
            'changes' => $laneChanges
        ]);
        
        return $laneChanges;
    }
    
    /**
     * Get baseline picks from statistics when database picks are empty
     */
    private function getBaselineFromStatistics(GameMatch $match)
    {
        $baselinePicks = [];
        
        // Get hero success rate records for this match
        $heroStats = \App\Models\HeroSuccessRate::where('match_id', $match->id)->get();
        
        foreach ($heroStats as $stat) {
            $player = \App\Models\Player::find($stat->player_id);
            if ($player) {
                $baselinePicks[] = [
                    'hero' => $stat->hero_name,
                    'lane' => $stat->lane,
                    'player' => $player->name
                ];
            }
        }
        
        return $baselinePicks;
    }
    
    /**
     * Compare old and new picks to detect lane changes
     */
    private function comparePicks(array $currentPicks, array $newPicks, int $teamId)
    {
        $changes = [];
        
        // Create maps for easier comparison
        $currentMap = $this->createPickMap($currentPicks);
        $newMap = $this->createPickMap($newPicks);
        
        // Check for lane changes
        foreach ($currentMap as $playerName => $currentPick) {
            if (isset($newMap[$playerName])) {
                $newPick = $newMap[$playerName];
                
                // Check if lane changed
                if ($currentPick['lane'] !== $newPick['lane']) {
                    $changes[] = [
                        'player_name' => $playerName,
                        'old_lane' => $currentPick['lane'],
                        'new_lane' => $newPick['lane'],
                        'hero' => $currentPick['hero']
                    ];
                    
                    Log::info("Detected lane change", [
                        'player' => $playerName,
                        'hero' => $currentPick['hero'],
                        'old_lane' => $currentPick['lane'],
                        'new_lane' => $newPick['lane']
                    ]);
                }
            }
        }
        
        return $changes;
    }
    
    /**
     * Create a map of picks by player name for easier comparison
     */
    private function createPickMap(array $picks)
    {
        $map = [];
        
        foreach ($picks as $pick) {
            if (isset($pick['player']) && isset($pick['hero']) && isset($pick['lane'])) {
                $playerName = is_array($pick['player']) ? implode(' ', $pick['player']) : $pick['player'];
                $map[$playerName] = [
                    'hero' => $pick['hero'],
                    'lane' => $pick['lane']
                ];
            }
        }
        
        return $map;
    }
    
    /**
     * Process lane changes and update statistics
     */
    public function processLaneChanges(GameMatch $match, array $laneChanges)
    {
        if (empty($laneChanges)) {
            Log::info("No lane changes to process for match {$match->id}");
            return true;
        }
        
        Log::info("Processing lane changes for match {$match->id}", [
            'changes_count' => count($laneChanges)
        ]);
        
        // Use the LaneChangeService to handle the changes
        $laneChangeService = new LaneChangeService();
        
        // Convert to the format expected by LaneChangeService
        $formattedChanges = array_map(function($change) {
            return [
                'player_name' => $change['player_name'],
                'old_lane' => $change['old_lane'],
                'new_lane' => $change['new_lane']
            ];
        }, $laneChanges);
        
        // Process the lane changes
        $result = $laneChangeService->handleBulkLaneChanges($match->id, $formattedChanges);
        
        if ($result) {
            Log::info("Successfully processed lane changes for match {$match->id}");
        } else {
            Log::error("Failed to process lane changes for match {$match->id}");
        }
        
        return $result;
    }
}

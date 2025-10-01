<?php

namespace App\Services;

use App\Models\HeroSuccessRate;
use App\Models\H2HStatistics;
use App\Models\GameMatch;
use App\Models\MatchTeam;
use App\Models\Player;
use Illuminate\Support\Facades\Log;

class LaneChangeService
{
    /**
     * Handle lane changes for a specific match
     */
    public function handleLaneChange(int $matchId, string $playerName, string $oldLane, string $newLane)
    {
        Log::info("Handling lane change", [
            'match_id' => $matchId,
            'player_name' => $playerName,
            'old_lane' => $oldLane,
            'new_lane' => $newLane
        ]);

        // Get the match
        $match = GameMatch::find($matchId);
        if (!$match) {
            Log::error("Match not found", ['match_id' => $matchId]);
            return false;
        }

        // Find the player
        $player = Player::where('name', $playerName)
            ->where('team_id', $match->team_id)
            ->first();
        
        if (!$player) {
            Log::error("Player not found", [
                'player_name' => $playerName,
                'team_id' => $match->team_id
            ]);
            return false;
        }

        // Update existing statistics records
        $this->updateHeroSuccessRateLane($player->id, $matchId, $oldLane, $newLane);
        $this->updateH2HStatisticsLane($player->id, $matchId, $oldLane, $newLane);

        // Re-sync the match to ensure all statistics are correct
        $syncService = new StatisticsSyncService();
        $syncService->syncMatchStatistics($match);

        Log::info("Lane change completed successfully");
        return true;
    }

    /**
     * Update hero success rate records with new lane
     */
    private function updateHeroSuccessRateLane(int $playerId, int $matchId, string $oldLane, string $newLane)
    {
        $records = HeroSuccessRate::where('player_id', $playerId)
            ->where('match_id', $matchId)
            ->where('lane', $oldLane)
            ->get();

        foreach ($records as $record) {
            $record->update(['lane' => $newLane]);
            Log::info("Updated hero success rate lane", [
                'record_id' => $record->id,
                'old_lane' => $oldLane,
                'new_lane' => $newLane
            ]);
        }
    }

    /**
     * Update H2H statistics records with new lane
     */
    private function updateH2HStatisticsLane(int $playerId, int $matchId, string $oldLane, string $newLane)
    {
        $records = H2HStatistics::where('player_id', $playerId)
            ->where('match_id', $matchId)
            ->where('lane', $oldLane)
            ->get();

        foreach ($records as $record) {
            $record->update(['lane' => $newLane]);
            Log::info("Updated H2H statistics lane", [
                'record_id' => $record->id,
                'old_lane' => $oldLane,
                'new_lane' => $newLane
            ]);
        }
    }

    /**
     * Handle bulk lane changes for a match
     */
    public function handleBulkLaneChanges(int $matchId, array $laneChanges)
    {
        Log::info("Handling bulk lane changes", [
            'match_id' => $matchId,
            'changes_count' => count($laneChanges)
        ]);

        foreach ($laneChanges as $change) {
            $this->handleLaneChange(
                $matchId,
                $change['player_name'],
                $change['old_lane'],
                $change['new_lane']
            );
        }

        Log::info("Bulk lane changes completed");
        return true;
    }

    /**
     * Re-sync all statistics for a match after lane changes
     */
    public function resyncMatchStatistics(int $matchId)
    {
        Log::info("Re-syncing match statistics", ['match_id' => $matchId]);

        $match = GameMatch::find($matchId);
        if (!$match) {
            Log::error("Match not found for re-sync", ['match_id' => $matchId]);
            return false;
        }

        // Clear existing statistics for this match
        HeroSuccessRate::where('match_id', $matchId)->delete();
        H2HStatistics::where('match_id', $matchId)->delete();

        // Re-sync the match
        $syncService = new StatisticsSyncService();
        $syncService->syncMatchStatistics($match);

        Log::info("Match statistics re-sync completed");
        return true;
    }
}

<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\MatchTeam;
use App\Models\MatchPlayerAssignment;
use App\Models\PlayerStat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MatchHeroSyncService
{
    /**
     * Sync hero changes from MatchPlayerAssignment to MatchTeam data
     */
    public function syncHeroToMatchTeams($matchId, $teamId, $playerId, $role, $newHeroName)
    {
        try {
            DB::beginTransaction();

            // Get the match with its teams
            $match = GameMatch::with('teams')->find($matchId);
            if (!$match) {
                throw new \Exception('Match not found');
            }

            // Get the player assignment
            $assignment = MatchPlayerAssignment::where('match_id', $matchId)
                ->where('player_id', $playerId)
                ->where('role', $role)
                ->whereHas('player', function($query) use ($teamId) {
                    $query->where('team_id', $teamId);
                })
                ->first();

            if (!$assignment) {
                throw new \Exception('Player assignment not found');
            }

            // Update the assignment
            $assignment->update(['hero_name' => $newHeroName]);

            // Update the corresponding team data
            $this->updateTeamPicks($match, $teamId, $role, $newHeroName);

            // Update player statistics
            $this->updatePlayerStats($matchId, $playerId, $newHeroName, $match->winner);

            DB::commit();

            Log::info('Hero synced to match teams', [
                'match_id' => $matchId,
                'player_id' => $playerId,
                'role' => $role,
                'new_hero' => $newHeroName
            ]);

            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error syncing hero to match teams', [
                'match_id' => $matchId,
                'player_id' => $playerId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Update team picks in MatchTeam based on hero changes
     */
    private function updateTeamPicks($match, $teamId, $role, $newHeroName)
    {
        foreach ($match->teams as $team) {
            // Find the team that belongs to the current team (not the match team)
            // We need to determine which team color corresponds to our team
            $teamColor = $this->determineTeamColor($match, $teamId);
            
            if ($team->team_color === $teamColor) {
                $this->updateTeamPicksForColor($team, $role, $newHeroName);
            }
        }
    }

    /**
     * Determine which team color (blue/red) corresponds to the given team ID
     */
    private function determineTeamColor($match, $teamId)
    {
        // This is a simplified approach - you might need to adjust based on your logic
        // For now, we'll assume the first team is blue and second is red
        $teams = $match->teams->sortBy('id');
        $teamIndex = 0;
        
        foreach ($teams as $index => $team) {
            if ($team->team_id === $teamId) {
                $teamIndex = $index;
                break;
            }
        }
        
        return $teamIndex === 0 ? 'blue' : 'red';
    }

    /**
     * Update picks for a specific team color
     */
    private function updateTeamPicksForColor($team, $role, $newHeroName)
    {
        $roleToPickIndex = [
            'exp' => 0,
            'mid' => 1,
            'jungler' => 2,
            'gold' => 3,
            'roam' => 4
        ];

        $pickIndex = $roleToPickIndex[$role] ?? null;
        if ($pickIndex === null) {
            return;
        }

        // Update picks1 (first phase picks) - ensure proper structure
        $picks1 = $team->picks1 ?? [];
        if (isset($picks1[$pickIndex])) {
            // If the pick is just a string, replace it
            if (is_string($picks1[$pickIndex])) {
                $picks1[$pickIndex] = $newHeroName;
            } else {
                // If it's an object, update the hero property
                $picks1[$pickIndex] = array_merge($picks1[$pickIndex], [
                    'hero' => $newHeroName,
                    'lane' => $role
                ]);
            }
            $team->picks1 = $picks1;
        }

        // Update picks2 (second phase picks) if needed
        $picks2 = $team->picks2 ?? [];
        if (isset($picks2[$pickIndex])) {
            // If the pick is just a string, replace it
            if (is_string($picks2[$pickIndex])) {
                $picks2[$pickIndex] = $newHeroName;
            } else {
                // If it's an object, update the hero property
                $picks2[$pickIndex] = array_merge($picks2[$pickIndex], [
                    'hero' => $newHeroName,
                    'lane' => $role
                ]);
            }
            $team->picks2 = $picks2;
        }

        $team->save();

        Log::info('Updated team picks', [
            'team_id' => $team->id,
            'team_color' => $team->team_color,
            'role' => $role,
            'pick_index' => $pickIndex,
            'new_hero' => $newHeroName,
            'picks1' => $picks1,
            'picks2' => $picks2
        ]);
    }

    /**
     * Update player statistics based on hero changes
     */
    private function updatePlayerStats($matchId, $playerId, $newHeroName, $matchWinner)
    {
        $assignment = MatchPlayerAssignment::where('match_id', $matchId)
            ->where('player_id', $playerId)
            ->with('player')
            ->first();
        if (!$assignment) {
            return;
        }

        $player = $assignment->player;
        $teamId = $player->team_id;
        $playerName = $player->name;

        // Get or create player stat for this hero
        $playerStat = PlayerStat::where('team_id', $teamId)
            ->where('player_name', $playerName)
            ->where('hero_name', $newHeroName)
            ->first();

        if (!$playerStat) {
            $playerStat = PlayerStat::create([
                'team_id' => $teamId,
                'player_name' => $playerName,
                'hero_name' => $newHeroName,
                'games_played' => 0,
                'wins' => 0,
                'losses' => 0,
                'win_rate' => 0.00,
                'kills' => 0,
                'deaths' => 0,
                'assists' => 0,
                'kda_ratio' => 0.00
            ]);
        }

        // Update the stats based on match result
        $playerStat->increment('games_played');
        
        if ($matchWinner === 'win') {
            $playerStat->increment('wins');
        } else {
            $playerStat->increment('losses');
        }

        // Recalculate win rate
        $playerStat->win_rate = $playerStat->wins / $playerStat->games_played;
        $playerStat->save();

        Log::info('Updated player stats', [
            'player_name' => $playerName,
            'hero_name' => $newHeroName,
            'games_played' => $playerStat->games_played,
            'wins' => $playerStat->wins,
            'losses' => $playerStat->losses,
            'win_rate' => $playerStat->win_rate
        ]);
    }

    /**
     * Sync all hero assignments to match teams when a match is updated
     */
    public function syncAllHeroesToMatchTeams($matchId)
    {
        try {
            $match = GameMatch::with(['teams', 'playerAssignments.player'])->find($matchId);
            if (!$match) {
                throw new \Exception('Match not found');
            }

            // Get all player assignments for this match
            $assignments = $match->playerAssignments()->with('player')->get();

            foreach ($assignments as $assignment) {
                if ($assignment->hero_name) {
                    $this->updateTeamPicks($match, $assignment->player->team_id, $assignment->role, $assignment->hero_name);
                }
            }

            // Ensure all picks have proper structure with lane information
            $this->ensurePicksStructure($match);

            Log::info('Synced all heroes to match teams', [
                'match_id' => $matchId,
                'assignments_count' => $assignments->count()
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Error syncing all heroes to match teams', [
                'match_id' => $matchId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Ensure picks have proper structure with lane information
     */
    private function ensurePicksStructure($match)
    {
        $roles = ['exp', 'mid', 'jungler', 'gold', 'roam'];
        
        foreach ($match->teams as $team) {
            $picks1 = $team->picks1 ?? [];
            $picks2 = $team->picks2 ?? [];
            
            // Ensure picks1 has proper structure
            for ($i = 0; $i < 5; $i++) {
                if (isset($picks1[$i])) {
                    if (is_string($picks1[$i])) {
                        // Convert string to object with lane information
                        $picks1[$i] = [
                            'hero' => $picks1[$i],
                            'lane' => $roles[$i] ?? 'unknown'
                        ];
                    } elseif (is_array($picks1[$i]) && !isset($picks1[$i]['lane'])) {
                        // Add lane information if missing
                        $picks1[$i]['lane'] = $roles[$i] ?? 'unknown';
                    }
                }
            }
            
            // Ensure picks2 has proper structure
            for ($i = 0; $i < 5; $i++) {
                if (isset($picks2[$i])) {
                    if (is_string($picks2[$i])) {
                        // Convert string to object with lane information
                        $picks2[$i] = [
                            'hero' => $picks2[$i],
                            'lane' => $roles[$i] ?? 'unknown'
                        ];
                    } elseif (is_array($picks2[$i]) && !isset($picks2[$i]['lane'])) {
                        // Add lane information if missing
                        $picks2[$i]['lane'] = $roles[$i] ?? 'unknown';
                    }
                }
            }
            
            $team->picks1 = $picks1;
            $team->picks2 = $picks2;
            $team->save();
        }
    }
}

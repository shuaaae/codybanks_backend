<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GameMatch;
use App\Models\MatchPlayerAssignment;
use App\Models\Player;
use App\Models\Team;
use App\Services\MatchHeroSyncService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MatchPlayerAssignmentController extends Controller
{
    /**
     * Assign players to a match (including substitutes)
     */
    public function assignPlayers(Request $request): JsonResponse
    {
        $request->validate([
            'match_id' => 'required|exists:matches,id',
            'team_id' => 'required|exists:teams,id',
            'assignments' => 'required|array',
            'assignments.*.player_id' => 'required|exists:players,id',
            'assignments.*.role' => 'required|in:exp,mid,jungler,gold,roam',
            'assignments.*.hero_name' => 'nullable|string|max:255',
            'assignments.*.is_starting_lineup' => 'boolean',
            'assignments.*.substitute_order' => 'nullable|integer|min:1',
            'assignments.*.notes' => 'nullable|string'
        ]);

        try {
            DB::beginTransaction();

            $matchId = $request->input('match_id');
            $teamId = $request->input('team_id');
            $assignments = $request->input('assignments');

            // Clear existing assignments for this match and team
            MatchPlayerAssignment::where('match_id', $matchId)
                ->whereHas('player', function($query) use ($teamId) {
                    $query->where('team_id', $teamId);
                })
                ->delete();

            // Create new assignments
            foreach ($assignments as $assignment) {
                MatchPlayerAssignment::create([
                    'match_id' => $matchId,
                    'player_id' => $assignment['player_id'],
                    'role' => $assignment['role'],
                    'hero_name' => $assignment['hero_name'] ?? null,
                    'is_starting_lineup' => $assignment['is_starting_lineup'] ?? true,
                    'substitute_order' => $assignment['substitute_order'] ?? null,
                    'notes' => $assignment['notes'] ?? null
                ]);
            }

            DB::commit();

            Log::info('Players assigned to match', [
                'match_id' => $matchId,
                'team_id' => $teamId,
                'assignments_count' => count($assignments)
            ]);

            return response()->json([
                'message' => 'Players assigned successfully',
                'assignments_count' => count($assignments)
            ])->header('Access-Control-Allow-Origin', '*')
              ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
              ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error assigning players to match', [
                'match_id' => $request->input('match_id'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to assign players to match',
                'message' => $e->getMessage()
            ], 500)->header('Access-Control-Allow-Origin', '*')
              ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
              ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        }
    }

    /**
     * Get player assignments for a match
     */
    public function getMatchAssignments(Request $request, $match_id): JsonResponse
    {
        $request->validate([
            'team_id' => 'required|exists:teams,id'
        ]);

        // Validate match_id from route parameter
        if (!\App\Models\GameMatch::where('id', $match_id)->exists()) {
            return response()->json([
                'error' => 'Match not found',
                'message' => 'The specified match does not exist'
            ], 404)->header('Access-Control-Allow-Origin', '*')
              ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
              ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        }

        try {
            $matchId = $match_id;
            $teamId = $request->input('team_id');

            $assignments = MatchPlayerAssignment::where('match_id', $matchId)
                ->whereHas('player', function($query) use ($teamId) {
                    $query->where('team_id', $teamId);
                })
                ->with(['player:id,name,role,photo,is_substitute,player_code'])
                ->orderBy('role')
                ->orderBy('is_starting_lineup', 'desc')
                ->orderBy('substitute_order')
                ->get()
                ->groupBy('role');

            return response()->json([
                'assignments' => $assignments,
                'match_id' => $matchId,
                'team_id' => $teamId
            ])->header('Access-Control-Allow-Origin', '*')
              ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
              ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');

        } catch (\Exception $e) {
            Log::error('Error getting match assignments', [
                'match_id' => $matchId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to get match assignments',
                'message' => $e->getMessage()
            ], 500)->header('Access-Control-Allow-Origin', '*')
              ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
              ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        }
    }

    /**
     * Get available players for a role (including substitutes)
     */
    public function getAvailablePlayers(Request $request): JsonResponse
    {
        $request->validate([
            'team_id' => 'required|exists:teams,id',
            'role' => 'required|in:exp,mid,jungler,gold,roam'
        ]);

        try {
            $teamId = $request->input('team_id');
            $role = $request->input('role');

            // Get primary player for this role
            $primaryPlayer = Player::where('team_id', $teamId)
                ->where('role', $role)
                ->where('is_substitute', false)
                ->first();

            // Get substitutes for this role
            $substitutes = Player::where('team_id', $teamId)
                ->where('role', $role)
                ->where('is_substitute', true)
                ->orderBy('substitute_order')
                ->get();

            return response()->json([
                'primary_player' => $primaryPlayer,
                'substitutes' => $substitutes,
                'role' => $role,
                'team_id' => $teamId
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting available players', [
                'team_id' => $request->input('team_id'),
                'role' => $request->input('role'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to get available players',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update substitute information (sub in/out times)
     */
    public function updateSubstituteInfo(Request $request): JsonResponse
    {
        $request->validate([
            'assignment_id' => 'required|exists:match_player_assignments,id',
            'substituted_in_at' => 'nullable|date',
            'substituted_out_at' => 'nullable|date',
            'notes' => 'nullable|string'
        ]);

        try {
            $assignment = MatchPlayerAssignment::findOrFail($request->input('assignment_id'));
            
            $assignment->update([
                'substituted_in_at' => $request->input('substituted_in_at'),
                'substituted_out_at' => $request->input('substituted_out_at'),
                'notes' => $request->input('notes')
            ]);

            return response()->json([
                'message' => 'Substitute information updated successfully',
                'assignment' => $assignment->fresh()
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating substitute info', [
                'assignment_id' => $request->input('assignment_id'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to update substitute information',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get match statistics by player (including substitutes)
     */
    public function getPlayerMatchStats(Request $request): JsonResponse
    {
        $request->validate([
            'player_id' => 'required|exists:players,id',
            'match_id' => 'nullable|exists:matches,id'
        ]);

        try {
            $playerId = $request->input('player_id');
            $matchId = $request->input('match_id');

            $query = MatchPlayerAssignment::where('player_id', $playerId)
                ->with(['match:id,match_date,winner,team_id']);

            if ($matchId) {
                $query->where('match_id', $matchId);
            }

            $assignments = $query->orderBy('created_at', 'desc')->get();

            $player = Player::find($playerId);
            $stats = [
                'total_matches' => $assignments->count(),
                'starting_lineup_matches' => $assignments->where('is_starting_lineup', true)->count(),
                'substitute_matches' => $assignments->where('is_starting_lineup', false)->count(),
                'role' => $player->role,
                'is_substitute' => $player->is_substitute,
                'assignments' => $assignments
            ];

            return response()->json($stats);

        } catch (\Exception $e) {
            Log::error('Error getting player match stats', [
                'player_id' => $request->input('player_id'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to get player match statistics',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update hero assignment for a specific player in a match
     */
    public function updateHeroAssignment(Request $request): JsonResponse
    {
        $request->validate([
            'match_id' => 'required|exists:matches,id',
            'team_id' => 'required|exists:teams,id',
            'player_id' => 'required|exists:players,id',
            'role' => 'required|in:exp,mid,jungler,gold,roam',
            'old_hero_name' => 'nullable|string',
            'new_hero_name' => 'required|string|max:255'
        ]);

        try {
            $matchId = $request->input('match_id');
            $teamId = $request->input('team_id');
            $playerId = $request->input('player_id');
            $role = $request->input('role');
            $oldHeroName = $request->input('old_hero_name');
            $newHeroName = $request->input('new_hero_name');

            // Find the assignment to update
            $assignment = MatchPlayerAssignment::where('match_id', $matchId)
                ->where('player_id', $playerId)
                ->where('role', $role)
                ->whereHas('player', function($query) use ($teamId) {
                    $query->where('team_id', $teamId);
                })
                ->first();

            if (!$assignment) {
                return response()->json([
                    'error' => 'Player assignment not found'
                ], 404);
            }

            // Use the sync service to update hero and sync with match teams
            $syncService = new MatchHeroSyncService();
            $syncService->syncHeroToMatchTeams($matchId, $teamId, $playerId, $role, $newHeroName);

            Log::info('Hero assignment updated and synced', [
                'match_id' => $matchId,
                'player_id' => $playerId,
                'role' => $role,
                'old_hero' => $oldHeroName,
                'new_hero' => $newHeroName
            ]);

            return response()->json([
                'message' => 'Hero assignment updated successfully and synced with match teams',
                'assignment' => $assignment->fresh()
            ])->header('Access-Control-Allow-Origin', '*')
              ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
              ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');

        } catch (\Exception $e) {
            Log::error('Error updating hero assignment', [
                'match_id' => $request->input('match_id'),
                'player_id' => $request->input('player_id'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to update hero assignment',
                'message' => $e->getMessage()
            ], 500)->header('Access-Control-Allow-Origin', '*')
              ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
              ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        }
    }

    /**
     * Update player lane assignment in a match
     */
    public function updateLaneAssignment(Request $request): JsonResponse
    {
        $request->validate([
            'match_id' => 'required|exists:matches,id',
            'team_id' => 'required|exists:teams,id',
            'player_id' => 'required|exists:players,id',
            'old_role' => 'required|in:exp,mid,jungler,gold,roam',
            'new_role' => 'required|in:exp,mid,jungler,gold,roam',
            'hero_name' => 'nullable|string|max:255'
        ]);

        try {
            $matchId = $request->input('match_id');
            $teamId = $request->input('team_id');
            $playerId = $request->input('player_id');
            $oldRole = $request->input('old_role');
            $newRole = $request->input('new_role');
            $heroName = $request->input('hero_name');

            DB::beginTransaction();

            // Find the assignment for this player in this match (regardless of current role)
            $assignment = MatchPlayerAssignment::where('match_id', $matchId)
                ->where('player_id', $playerId)
                ->whereHas('player', function($query) use ($teamId) {
                    $query->where('team_id', $teamId);
                })
                ->first();

            if (!$assignment) {
                return response()->json([
                    'error' => 'Player assignment not found for this match'
                ], 404);
            }

            // Store the old role and hero for statistics update
            $oldRole = $assignment->role;
            $oldHeroName = $assignment->hero_name;

            // Check if there's already an assignment for the new role (by a different player)
            $existingNewRoleAssignment = MatchPlayerAssignment::where('match_id', $matchId)
                ->where('role', $newRole)
                ->where('player_id', '!=', $playerId) // Different player
                ->whereHas('player', function($query) use ($teamId) {
                    $query->where('team_id', $teamId);
                })
                ->first();

            if ($existingNewRoleAssignment) {
                return response()->json([
                    'error' => 'Another player is already assigned to the ' . $newRole . ' role in this match'
                ], 400);
            }

            // Update the assignment with new role and hero
            $assignment->update([
                'role' => $newRole,
                'hero_name' => $heroName ?? $assignment->hero_name
            ]);

            // Update player statistics to reflect the role change
            $this->updatePlayerStatsForRoleChange($matchId, $playerId, $oldRole, $newRole, $heroName ?? $assignment->hero_name, $oldHeroName);

            // Sync with match teams data
            $syncService = new MatchHeroSyncService();
            $syncService->syncAllHeroesToMatchTeams($matchId);

            DB::commit();

            Log::info('Player lane assignment updated', [
                'match_id' => $matchId,
                'player_id' => $playerId,
                'old_role' => $oldRole,
                'new_role' => $newRole,
                'hero_name' => $heroName
            ]);

            return response()->json([
                'message' => 'Player lane assignment updated successfully',
                'assignment' => $oldAssignment->fresh()
            ])->header('Access-Control-Allow-Origin', '*')
              ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
              ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating lane assignment', [
                'match_id' => $request->input('match_id'),
                'player_id' => $request->input('player_id'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to update lane assignment',
                'message' => $e->getMessage()
            ], 500)->header('Access-Control-Allow-Origin', '*')
              ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
              ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        }
    }

    /**
     * Update player statistics when role changes
     */
    private function updatePlayerStatsForRoleChange($matchId, $playerId, $oldRole, $newRole, $newHeroName, $oldHeroName)
    {
        try {
            // Get the match to determine winner
            $match = GameMatch::find($matchId);
            if (!$match) {
                Log::error('Match not found for role change', ['match_id' => $matchId]);
                return;
            }

            // Get the player
            $player = Player::find($playerId);
            if (!$player) {
                Log::error('Player not found for role change', ['player_id' => $playerId]);
                return;
            }

            // If we have an old hero name, remove stats for the old hero/role combination
            if ($oldHeroName) {
                $oldPlayerStat = PlayerStat::where('team_id', $player->team_id)
                    ->where('player_name', $player->name)
                    ->where('hero_name', $oldHeroName)
                    ->first();

                if ($oldPlayerStat) {
                    // Decrement the old stats
                    $oldPlayerStat->decrement('games_played');
                    if ($match->winner === 'win') {
                        $oldPlayerStat->decrement('wins');
                    } else {
                        $oldPlayerStat->decrement('losses');
                    }

                    // Recalculate win rate for old stats
                    if ($oldPlayerStat->games_played > 0) {
                        $oldPlayerStat->win_rate = $oldPlayerStat->wins / $oldPlayerStat->games_played;
                    } else {
                        $oldPlayerStat->win_rate = 0.00;
                    }
                    $oldPlayerStat->save();

                    Log::info('Decremented old player stats', [
                        'player_name' => $player->name,
                        'old_hero' => $oldHeroName,
                        'old_role' => $oldRole,
                        'games_played' => $oldPlayerStat->games_played,
                        'wins' => $oldPlayerStat->wins,
                        'losses' => $oldPlayerStat->losses
                    ]);
                }
            }

            // Create or update stats for the new hero/role combination
            $newPlayerStat = PlayerStat::where('team_id', $player->team_id)
                ->where('player_name', $player->name)
                ->where('hero_name', $newHeroName)
                ->first();

            if (!$newPlayerStat) {
                $newPlayerStat = PlayerStat::create([
                    'team_id' => $player->team_id,
                    'player_name' => $player->name,
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
            $newPlayerStat->increment('games_played');
            
            if ($match->winner === 'win') {
                $newPlayerStat->increment('wins');
            } else {
                $newPlayerStat->increment('losses');
            }

            // Recalculate win rate
            $newPlayerStat->win_rate = $newPlayerStat->wins / $newPlayerStat->games_played;
            $newPlayerStat->save();

            Log::info('Updated player stats for role change', [
                'player_name' => $player->name,
                'hero_name' => $newHeroName,
                'old_role' => $oldRole,
                'new_role' => $newRole,
                'games_played' => $newPlayerStat->games_played,
                'wins' => $newPlayerStat->wins,
                'losses' => $newPlayerStat->losses,
                'win_rate' => $newPlayerStat->win_rate
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating player stats for role change', [
                'match_id' => $matchId,
                'player_id' => $playerId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get match data with synchronized teams and hero assignments
     */
    public function getMatchWithSync(Request $request, $match_id): JsonResponse
    {
        $request->validate([
            'team_id' => 'required|exists:teams,id'
        ]);

        try {
            $teamId = $request->input('team_id');
            
            // Get the match with all related data
            $match = GameMatch::where('id', $match_id)
                ->where('team_id', $teamId)
                ->with([
                    'teams:id,match_id,team,team_color,banning_phase1,banning_phase2,picks1,picks2',
                    'playerAssignments.player:id,name,role,photo,is_substitute,player_code'
                ])
                ->first();

            if (!$match) {
                return response()->json([
                    'error' => 'Match not found or access denied'
                ], 404);
            }

            // Sync all heroes to ensure match teams data is up to date
            $syncService = new MatchHeroSyncService();
            $syncService->syncAllHeroesToMatchTeams($match->id);

            // Reload the match with updated data
            $match->load([
                'teams:id,match_id,team,team_color,banning_phase1,banning_phase2,picks1,picks2',
                'playerAssignments.player:id,name,role,photo,is_substitute,player_code'
            ]);

            return response()->json([
                'match' => $match,
                'message' => 'Match data synchronized successfully'
            ])->header('Access-Control-Allow-Origin', '*')
              ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
              ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');

        } catch (\Exception $e) {
            Log::error('Error getting match with sync', [
                'match_id' => $match_id,
                'team_id' => $request->input('team_id'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to get synchronized match data',
                'message' => $e->getMessage()
            ], 500)->header('Access-Control-Allow-Origin', '*')
              ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
              ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        }
    }
}

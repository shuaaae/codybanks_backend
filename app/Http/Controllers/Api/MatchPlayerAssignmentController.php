<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GameMatch;
use App\Models\MatchPlayerAssignment;
use App\Models\Player;
use App\Models\PlayerStat;
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
            // But allow swaps - if the other player is moving to our old role, it's a valid swap
            $existingNewRoleAssignment = MatchPlayerAssignment::where('match_id', $matchId)
                ->where('role', $newRole)
                ->where('player_id', '!=', $playerId) // Different player
                ->whereHas('player', function($query) use ($teamId) {
                    $query->where('team_id', $teamId);
                })
                ->first();

            if ($existingNewRoleAssignment) {
                // Check if this is a valid swap (the other player is moving to our old role)
                $isValidSwap = MatchPlayerAssignment::where('match_id', $matchId)
                    ->where('player_id', $existingNewRoleAssignment->player_id)
                    ->where('role', $oldRole)
                    ->whereHas('player', function($query) use ($teamId) {
                        $query->where('team_id', $teamId);
                    })
                    ->exists();

                if (!$isValidSwap) {
                    return response()->json([
                        'error' => 'Another player is already assigned to the ' . $newRole . ' role in this match'
                    ], 400);
                }
            }

            // Update the assignment with new role and hero
            $assignment->update([
                'role' => $newRole,
                'hero_name' => $heroName ?? $assignment->hero_name
            ]);

            // Update player statistics to reflect the role change
            $this->updatePlayerStatsForRoleChange($matchId, $playerId, $oldRole, $newRole, $heroName ?? $assignment->hero_name, $oldHeroName);

            // DO NOT sync with match teams data - this corrupts the original picks data
            // Lane assignment updates should only update MatchPlayerAssignment records
            // The original match picks data should remain intact for fresh matches

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
     * Swap lane assignments between two players
     */
    public function swapLaneAssignments(Request $request): JsonResponse
    {
        $request->validate([
            'match_id' => 'required|exists:matches,id',
            'team_id' => 'required|exists:teams,id',
            'player1_id' => 'required|exists:players,id',
            'player2_id' => 'required|exists:players,id',
            'player1_new_role' => 'required|in:exp,mid,jungler,gold,roam',
            'player2_new_role' => 'required|in:exp,mid,jungler,gold,roam',
            'player1_hero_name' => 'nullable|string|max:255',
            'player2_hero_name' => 'nullable|string|max:255'
        ]);

        try {
            $matchId = $request->input('match_id');
            $teamId = $request->input('team_id');
            $player1Id = $request->input('player1_id');
            $player2Id = $request->input('player2_id');
            $player1NewRole = $request->input('player1_new_role');
            $player2NewRole = $request->input('player2_new_role');
            $player1HeroName = $request->input('player1_hero_name');
            $player2HeroName = $request->input('player2_hero_name');

            DB::beginTransaction();

            // Get both assignments
            $assignment1 = MatchPlayerAssignment::where('match_id', $matchId)
                ->where('player_id', $player1Id)
                ->whereHas('player', function($query) use ($teamId) {
                    $query->where('team_id', $teamId);
                })
                ->first();

            $assignment2 = MatchPlayerAssignment::where('match_id', $matchId)
                ->where('player_id', $player2Id)
                ->whereHas('player', function($query) use ($teamId) {
                    $query->where('team_id', $teamId);
                })
                ->first();

            // If assignments don't exist, create them
            if (!$assignment1) {
                Log::info('Creating missing player assignment for player1', [
                    'match_id' => $matchId,
                    'player_id' => $player1Id,
                    'team_id' => $teamId
                ]);
                
                $assignment1 = MatchPlayerAssignment::create([
                    'match_id' => $matchId,
                    'player_id' => $player1Id,
                    'role' => $request->input('player1_new_role'), // Use the new role from request
                    'hero_name' => $player1HeroName
                ]);
            }
            
            if (!$assignment2) {
                Log::info('Creating missing player assignment for player2', [
                    'match_id' => $matchId,
                    'player_id' => $player2Id,
                    'team_id' => $teamId
                ]);
                
                $assignment2 = MatchPlayerAssignment::create([
                    'match_id' => $matchId,
                    'player_id' => $player2Id,
                    'role' => $request->input('player2_new_role'), // Use the new role from request
                    'hero_name' => $player2HeroName
                ]);
            }
            
            Log::info('Player assignments ready for lane swap', [
                'match_id' => $matchId,
                'team_id' => $teamId,
                'assignment1' => [
                    'id' => $assignment1->id,
                    'player_id' => $assignment1->player_id,
                    'player_name' => $assignment1->player->name ?? 'unknown',
                    'role' => $assignment1->role,
                    'hero_name' => $assignment1->hero_name,
                    'was_created' => !$assignment1->wasRecentlyCreated ? 'existing' : 'newly_created'
                ],
                'assignment2' => [
                    'id' => $assignment2->id,
                    'player_id' => $assignment2->player_id,
                    'player_name' => $assignment2->player->name ?? 'unknown',
                    'role' => $assignment2->role,
                    'hero_name' => $assignment2->hero_name,
                    'was_created' => !$assignment2->wasRecentlyCreated ? 'existing' : 'newly_created'
                ]
            ]);

            // Store old roles and heroes for statistics update
            $player1OldRole = $assignment1->role ?? null;
            $player1OldHero = $assignment1->hero_name ?? null;
            $player2OldRole = $assignment2->role ?? null;
            $player2OldHero = $assignment2->hero_name ?? null;

            // Update both assignments simultaneously
            $assignment1->update([
                'role' => $player1NewRole,
                'hero_name' => $player1HeroName ?? $assignment1->hero_name
            ]);

            $assignment2->update([
                'role' => $player2NewRole,
                'hero_name' => $player2HeroName ?? $assignment2->hero_name
            ]);

            // Update player statistics for both players (only if we had old roles/heroes)
            if ($player1OldRole && $player1OldHero) {
                $this->updatePlayerStatsForRoleChange($matchId, $player1Id, $player1OldRole, $player1NewRole, $player1HeroName ?? $assignment1->hero_name, $player1OldHero);
            }
            if ($player2OldRole && $player2OldHero) {
                $this->updatePlayerStatsForRoleChange($matchId, $player2Id, $player2OldRole, $player2NewRole, $player2HeroName ?? $assignment2->hero_name, $player2OldHero);
            }

            // DO NOT sync with match teams data - this corrupts the original picks data
            // Lane swapping should only update MatchPlayerAssignment records
            // The original match picks data should remain intact for fresh matches

            DB::commit();

            Log::info('Lane assignments swapped successfully', [
                'match_id' => $matchId,
                'team_id' => $teamId,
                'player1_id' => $player1Id,
                'player2_id' => $player2Id,
                'player1_old_role' => $player1OldRole,
                'player1_new_role' => $player1NewRole,
                'player2_old_role' => $player2OldRole,
                'player2_new_role' => $player2NewRole,
                'player1_hero' => $player1HeroName ?? $assignment1->hero_name,
                'player2_hero' => $player2HeroName ?? $assignment2->hero_name
            ]);

            return response()->json([
                'message' => 'Lane assignments swapped successfully',
                'assignments' => [
                    'player1' => $assignment1->fresh(),
                    'player2' => $assignment2->fresh()
                ]
            ])->header('Access-Control-Allow-Origin', '*')
              ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
              ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Error swapping lane assignments', [
                'match_id' => $request->input('match_id'),
                'player1_id' => $request->input('player1_id'),
                'player2_id' => $request->input('player2_id'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to swap lane assignments',
                'message' => $e->getMessage()
            ], 500)->header('Access-Control-Allow-Origin', '*')
              ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
              ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        }
    }

    /**
     * Get player statistics based on match assignments
     */
    public function getPlayerStatistics(Request $request): JsonResponse
    {
        $request->validate([
            'team_id' => 'required|exists:teams,id',
            'match_type' => 'nullable|string|in:scrim,tournament'
        ]);

        try {
            $teamId = $request->input('team_id');
            $matchType = $request->input('match_type', 'scrim');

            // Get all team players first to ensure all players are included
            $teamPlayers = Player::where('team_id', $teamId)->get();
            $allPlayerNames = $teamPlayers->pluck('name')->toArray();

            // Get all matches for this team and match type
            $matches = GameMatch::where('team_id', $teamId)
                ->where('match_type', $matchType)
                ->with(['playerAssignments.player', 'teams'])
                ->get();

            $playerStats = [];

            foreach ($matches as $match) {
                $isWinningTeam = $match->winner === 'win';
                
                // Get all players for this match - both edited and non-edited
                $matchPlayers = [];
                
                // First, get edited players from MatchPlayerAssignment records
                foreach ($match->playerAssignments as $assignment) {
                    $playerName = $assignment->player->name;
                    $heroName = $assignment->hero_name;
                    $role = $assignment->role;
                    
                    if (!$heroName) continue;
                    
                    $matchPlayers[$playerName] = [
                        'player_name' => $playerName,
                        'hero_name' => $heroName,
                        'role' => $role,
                        'is_edited' => true
                    ];
                }
                
            // Then, get non-edited players from original match data
            foreach ($match->teams as $team) {
                if ($team->team_id == $teamId) {
                    $allPicks = array_merge($team->picks1 ?? [], $team->picks2 ?? []);
                    
                    Log::info('Processing fresh match data', [
                        'match_id' => $match->id,
                        'team_id' => $teamId,
                        'picks1' => $team->picks1,
                        'picks2' => $team->picks2,
                        'all_picks' => $allPicks,
                        'has_assignments' => $match->playerAssignments->count() > 0
                    ]);
                    
                    // Map roles to player names based on team players
                    $roleToPlayerMap = [];
                    foreach ($teamPlayers as $player) {
                        $roleToPlayerMap[strtolower($player->role)] = $player->name;
                    }
                    
                    Log::info('Role to Player Mapping', [
                        'match_id' => $match->id,
                        'roleToPlayerMap' => $roleToPlayerMap,
                        'allPicks' => $allPicks
                    ]);
                    
                    foreach ($allPicks as $index => $pick) {
                            $playerName = null;
                            $heroName = null;
                            $role = 'unknown';
                            
                            if (is_array($pick)) {
                                if (isset($pick['hero']) && isset($pick['lane'])) {
                                    $heroName = $pick['hero'];
                                    $role = $pick['lane'];
                                    // Direct mapping: hero in EXP lane → EXP lane player
                                    // hero in MID lane → MID lane player, etc.
                                    $playerName = $roleToPlayerMap[strtolower($role)] ?? null;
                                    
                                    Log::info('Hero to Player Mapping', [
                                        'match_id' => $match->id,
                                        'hero' => $heroName,
                                        'lane' => $role,
                                        'mapped_player' => $playerName,
                                        'available_roles' => array_keys($roleToPlayerMap)
                                    ]);
                                }
                            } elseif (is_string($pick)) {
                                $heroName = $pick;
                                // Map index to role based on standard lane order
                                $laneOrder = ['exp', 'mid', 'jungler', 'gold', 'roam'];
                                $role = $laneOrder[$index] ?? 'unknown';
                                $playerName = $roleToPlayerMap[strtolower($role)] ?? null;
                                
                                Log::info('String Hero to Player Mapping', [
                                    'match_id' => $match->id,
                                    'hero' => $heroName,
                                    'index' => $index,
                                    'lane' => $role,
                                    'mapped_player' => $playerName
                                ]);
                            }
                            
                            // Only add if this player doesn't have an edited assignment and we found a valid player name
                            if ($playerName && $heroName && !isset($matchPlayers[$playerName])) {
                                $matchPlayers[$playerName] = [
                                    'player_name' => $playerName,
                                    'hero_name' => $heroName,
                                    'role' => $role,
                                    'is_edited' => false
                                ];
                                
                                Log::info('Successfully Mapped Hero to Player', [
                                    'match_id' => $match->id,
                                    'player' => $playerName,
                                    'hero' => $heroName,
                                    'lane' => $role
                                ]);
                            } else {
                                Log::warning('Failed to Map Hero to Player', [
                                    'match_id' => $match->id,
                                    'hero' => $heroName,
                                    'lane' => $role,
                                    'playerName' => $playerName,
                                    'already_exists' => isset($matchPlayers[$playerName])
                                ]);
                            }
                        }
                        break;
                    }
                }
                
                // Process all players for this match
                foreach ($matchPlayers as $playerData) {
                    $playerName = $playerData['player_name'];
                    $heroName = $playerData['hero_name'];
                    $role = $playerData['role'];
                    $isEdited = $playerData['is_edited'];
                    
                    $key = "{$playerName}_{$heroName}_{$role}";
                    
                    if (!isset($playerStats[$key])) {
                        $playerStats[$key] = [
                            'player_name' => $playerName,
                            'hero_name' => $heroName,
                            'role' => $role,
                            'games_played' => 0,
                            'wins' => 0,
                            'losses' => 0,
                            'win_rate' => 0.00,
                            'is_edited' => $isEdited
                        ];
                    }
                    
                    $playerStats[$key]['games_played']++;
                    if ($isWinningTeam) {
                        $playerStats[$key]['wins']++;
                    } else {
                        $playerStats[$key]['losses']++;
                    }
                    
                    $playerStats[$key]['win_rate'] = $playerStats[$key]['wins'] / $playerStats[$key]['games_played'];
                }
                
            }

            // Group by player name and ensure all players are included
            $groupedStats = [];
            foreach ($allPlayerNames as $playerName) {
                $groupedStats[$playerName] = [];
            }
            
            foreach ($playerStats as $key => $stats) {
                $playerName = $stats['player_name'];
                $groupedStats[$playerName][] = [
                    'hero_name' => $stats['hero_name'],
                    'role' => $stats['role'],
                    'wins' => $stats['wins'],
                    'losses' => $stats['losses'],
                    'games_played' => $stats['games_played'],
                    'win_rate' => $stats['win_rate'],
                    'is_edited' => $stats['is_edited']
                ];
            }

            // Calculate H2H statistics
            $h2hStats = $this->calculateH2HStatistics($matches, $allPlayerNames);

            Log::info('Final API response', [
                'team_id' => $teamId,
                'match_type' => $matchType,
                'total_matches' => $matches->count(),
                'player_statistics_keys' => array_keys($groupedStats),
                'player_statistics' => $groupedStats,
                'h2h_statistics_keys' => array_keys($h2hStats),
                'sample_player_stats' => array_slice($groupedStats, 0, 2, true)
            ]);

            return response()->json([
                'player_statistics' => $groupedStats,
                'h2h_statistics' => $h2hStats,
                'total_matches' => $matches->count()
            ])->header('Access-Control-Allow-Origin', '*')
              ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
              ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');

        } catch (\Exception $e) {
            Log::error('Error getting player statistics', [
                'team_id' => $request->input('team_id'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to get player statistics',
                'message' => $e->getMessage()
            ], 500)->header('Access-Control-Allow-Origin', '*')
              ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
              ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        }
    }

    /**
     * Calculate H2H statistics for all players
     */
    private function calculateH2HStatistics($matches, $allPlayerNames)
    {
        $h2hStats = [];
        
        // Initialize H2H stats for all players
        foreach ($allPlayerNames as $playerName) {
            $h2hStats[$playerName] = [];
        }
        
        foreach ($matches as $match) {
            $isWinningTeam = $match->winner === 'win';
            
            // Get all players for this match - both edited and non-edited
            $matchPlayers = [];
            
            // First, get edited players from MatchPlayerAssignment records
            foreach ($match->playerAssignments as $assignment) {
                $playerName = $assignment->player->name;
                $heroName = $assignment->hero_name;
                $role = $assignment->role;
                
                if (!$heroName) continue;
                
                $matchPlayers[$playerName] = [
                    'player_name' => $playerName,
                    'hero_name' => $heroName,
                    'role' => $role,
                    'is_edited' => true
                ];
            }
            
            // Then, get non-edited players from original match data
            foreach ($match->teams as $team) {
                if ($team->team_id == $match->team_id) {
                    $allPicks = array_merge($team->picks1 ?? [], $team->picks2 ?? []);
                    
                    // Map roles to player names based on team players
                    $roleToPlayerMap = [];
                    foreach ($teamPlayers as $player) {
                        $roleToPlayerMap[strtolower($player->role)] = $player->name;
                    }
                    
                    foreach ($allPicks as $index => $pick) {
                        $playerName = null;
                        $heroName = null;
                        $role = 'unknown';
                        
                        if (is_array($pick)) {
                            if (isset($pick['hero']) && isset($pick['lane'])) {
                                $heroName = $pick['hero'];
                                $role = $pick['lane'];
                                // Direct mapping: hero in EXP lane → EXP lane player
                                // hero in MID lane → MID lane player, etc.
                                $playerName = $roleToPlayerMap[strtolower($role)] ?? null;
                            }
                        } elseif (is_string($pick)) {
                            $heroName = $pick;
                            // Map index to role based on standard lane order
                            $laneOrder = ['exp', 'mid', 'jungler', 'gold', 'roam'];
                            $role = $laneOrder[$index] ?? 'unknown';
                            $playerName = $roleToPlayerMap[strtolower($role)] ?? null;
                        }
                        
                        // Only add if this player doesn't have an edited assignment and we found a valid player name
                        if ($playerName && $heroName && !isset($matchPlayers[$playerName])) {
                            $matchPlayers[$playerName] = [
                                'player_name' => $playerName,
                                'hero_name' => $heroName,
                                'role' => $role,
                                'is_edited' => false
                            ];
                            
                            Log::info('H2H - Added fresh match player', [
                                'player_name' => $playerName,
                                'hero_name' => $heroName,
                                'role' => $role,
                                'is_edited' => false
                            ]);
                        }
                    }
                    break;
                }
            }
            
            // Calculate H2H for all players in this match
            foreach ($matchPlayers as $playerData) {
                $playerName = $playerData['player_name'];
                $heroName = $playerData['hero_name'];
                
                // For H2H, we'll create a simple matchup
                $h2hKey = "{$playerName}_vs_enemy_{$heroName}";
                
                if (!isset($h2hStats[$playerName][$h2hKey])) {
                    $h2hStats[$playerName][$h2hKey] = [
                        'our_player' => $playerName,
                        'enemy_player' => 'Enemy',
                        'our_hero' => $heroName,
                        'enemy_hero' => 'Unknown',
                        'our_wins' => 0,
                        'enemy_wins' => 0,
                        'total_matches' => 0,
                        'win_rate' => 0.00
                    ];
                }
                
                $h2hStats[$playerName][$h2hKey]['total_matches']++;
                if ($isWinningTeam) {
                    $h2hStats[$playerName][$h2hKey]['our_wins']++;
                } else {
                    $h2hStats[$playerName][$h2hKey]['enemy_wins']++;
                }
                
                $h2hStats[$playerName][$h2hKey]['win_rate'] = 
                    $h2hStats[$playerName][$h2hKey]['our_wins'] / 
                    $h2hStats[$playerName][$h2hKey]['total_matches'];
            }
        }
        
        return $h2hStats;
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

            // DO NOT sync all heroes - this corrupts the original match picks data
            // Lane swapping should only update MatchPlayerAssignment records
            // The original match picks data should remain intact for fresh matches

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

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GameMatch;
use App\Models\MatchPlayerAssignment;
use App\Models\Player;
use App\Models\Team;
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
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error assigning players to match', [
                'match_id' => $request->input('match_id'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to assign players to match',
                'message' => $e->getMessage()
            ], 500);
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
            DB::beginTransaction();

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

            // Update the hero name
            $assignment->update([
                'hero_name' => $newHeroName
            ]);

            DB::commit();

            Log::info('Hero assignment updated', [
                'match_id' => $matchId,
                'player_id' => $playerId,
                'role' => $role,
                'old_hero' => $oldHeroName,
                'new_hero' => $newHeroName
            ]);

            return response()->json([
                'message' => 'Hero assignment updated successfully',
                'assignment' => $assignment->fresh()
            ])->header('Access-Control-Allow-Origin', '*')
              ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
              ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');

        } catch (\Exception $e) {
            DB::rollBack();
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
}

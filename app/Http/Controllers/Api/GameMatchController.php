<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GameMatch;
use App\Models\MatchTeam;
use Illuminate\Http\Request;

class GameMatchController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // Get team_id from query parameter (frontend sends this)
        $teamId = $request->query('team_id');
        
        // Fallback to session if no query parameter
        if (!$teamId) {
            $teamId = session('active_team_id');
        }
        
        // Debug logging
        \Log::info('GameMatchController::index called', [
            'query_team_id' => $request->query('team_id'),
            'session_team_id' => session('active_team_id'),
            'final_team_id' => $teamId,
            'session_id' => session()->getId(),
            'all_sessions' => session()->all()
        ]);
        
        // Build the query using hard deletes (no soft delete filtering)
        // Use raw SQL to bypass any global scoping that might add deleted_at filters
        $sql = "SELECT * FROM matches";
        $bindings = [];
        
        if ($teamId) {
            $sql .= " WHERE team_id = ?";
            $bindings[] = $teamId;
        }
        
        $sql .= " ORDER BY match_date ASC";
        
        // Debug: Log the raw SQL query
        \Log::info('Raw SQL query to be executed', [
            'sql' => $sql,
            'bindings' => $bindings,
            'team_id' => $teamId
        ]);
        
        // Execute raw query to bypass Eloquent's global scoping
        $matches = \DB::select($sql, $bindings);
        
        // Convert to collection for consistency
        $matches = collect($matches);
        
        // Load teams relationship manually since we're using raw SQL
        if ($matches->isNotEmpty()) {
            $matchIds = $matches->pluck('id')->toArray();
            $teams = \DB::select("SELECT * FROM match_teams WHERE match_id IN (" . implode(',', array_fill(0, count($matchIds), '?')) . ")", $matchIds);
            
            // Group teams by match_id
            $teamsByMatch = collect($teams)->groupBy('match_id');
            
            // Attach teams to each match
            $matches->each(function($match) use ($teamsByMatch) {
                $match->teams = $teamsByMatch->get($match->id, []);
            });
        }
        
        \Log::info('Matches returned', [
            'count' => $matches->count(),
            'team_ids' => $matches->pluck('team_id')->unique()->toArray(),
            'filtered_by_team_id' => $teamId,
            'first_match' => $matches->first() ? $matches->first()->toArray() : null
        ]);
        
        return response()->json($matches);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            // Get team_id from request body first, then fallback to session
            $teamId = $request->input('team_id');
            if (!$teamId) {
                $teamId = session('active_team_id');
            }
            
            // Debug logging
            \Log::info('GameMatchController::store called', [
                'request_team_id' => $request->input('team_id'),
                'session_team_id' => session('active_team_id'),
                'final_team_id' => $teamId
            ]);
            
            // Validate the request
            $validated = $request->validate([
                'match_date' => 'required|date',
                'winner' => 'required|string',
                'turtle_taken' => 'nullable|string',
                'lord_taken' => 'nullable|string',
                'notes' => 'nullable|string',
                'playstyle' => 'nullable|string',
                'team_id' => 'nullable|exists:teams,id', // Allow team_id in request
                'teams' => 'required|array|size:2',
                'teams.*.team' => 'required|string',
                'teams.*.team_color' => 'required|in:blue,red',
                'teams.*.banning_phase1' => 'required|array',
                'teams.*.picks1' => 'required|array',
                'teams.*.banning_phase2' => 'required|array',
                'teams.*.picks2' => 'required|array',
            ]);

            // Create the match
            $match = GameMatch::create([
                'match_date' => $validated['match_date'],
                'winner' => $validated['winner'],
                'turtle_taken' => $validated['turtle_taken'] ?? null,
                'lord_taken' => $validated['lord_taken'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'playstyle' => $validated['playstyle'] ?? null,
                'team_id' => $teamId, // Use the determined team_id
            ]);

            // Defensive: Only create teams if present and is array
            if (isset($validated['teams']) && is_array($validated['teams'])) {
                foreach ($validated['teams'] as $teamData) {
                    $teamData['match_id'] = $match->id;
                    MatchTeam::create($teamData);
                }
            }

            return response()->json(['message' => 'Match and teams saved successfully.'], 201);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('GameMatchController::store error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Failed to save match',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            // Find the match
            $match = GameMatch::findOrFail($id);
            
            // Delete related match_teams first to satisfy foreign key constraints
            $match->teams()->delete();
            
            // Hard delete the match
            $match->delete();
            
            return response()->noContent();
        } catch (\Exception $e) {
            \Log::error('GameMatchController::destroy error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json(['error' => 'Failed to delete match: ' . $e->getMessage()], 500);
        }
    }
}

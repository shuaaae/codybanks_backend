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
        
        // Build the query
        $query = \App\Models\GameMatch::with('teams')->whereNull('deleted_at');
        
        // Filter by team_id if provided and not null/empty
        if ($teamId && $teamId !== 'null' && $teamId !== '') {
            $query->where('team_id', $teamId);
            \Log::info('Filtering matches by team_id', ['team_id' => $teamId]);
        } else {
            \Log::info('No team_id provided or team_id is null/empty, returning all matches');
        }
        
        // Get matches ordered by date (oldest first)
        $matches = $query->orderBy('match_date', 'asc')->get();
        
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
            
            // Soft delete the match (keeps data for statistics)
            $match->deleted_at = now();
            $match->save();
            
            return response()->json(['message' => 'Match archived successfully. Data preserved for statistics.'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to archive match: ' . $e->getMessage()], 500);
        }
    }
}

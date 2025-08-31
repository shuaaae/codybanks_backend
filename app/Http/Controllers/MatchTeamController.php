<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MatchTeam;

class MatchTeamController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // Get the active team ID from session or request header
        $activeTeamId = session('active_team_id');
        
        // If no session, try to get from request header (for frontend compatibility)
        if (!$activeTeamId) {
            $activeTeamId = $request->header('X-Active-Team-ID');
        }

        if (!$activeTeamId) {
            return response()->json(['error' => 'No active team found'], 404);
        }
        
        // CRITICAL FIX: Always filter by the current team's ID to prevent data mixing
        // Return only match teams for matches that belong to the current team
        return MatchTeam::with('match')
            ->whereHas('match', function($query) use ($activeTeamId) {
                $query->where('team_id', $activeTeamId);
            })
            ->get();
    }
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
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
        try {
            $matchTeam = MatchTeam::findOrFail($id);
            
            $validated = $request->validate([
                'team' => 'required|string',
                'team_color' => 'required|in:blue,red',
                'banning_phase1' => 'required|array',
                'banning_phase2' => 'required|array',
                'picks1' => 'required|array',
                'picks2' => 'required|array',
            ]);
            
            $matchTeam->update($validated);
            
            return response()->json(['message' => 'Match team updated successfully.'], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to update match team.'], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}

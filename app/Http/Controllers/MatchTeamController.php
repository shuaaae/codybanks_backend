<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MatchTeam;

class MatchTeamController extends Controller
{
    /**
     * Display a listing of the resource.
     */
 // In your MatchTeamController
public function index()
{
    return MatchTeam::with('match')->get();
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

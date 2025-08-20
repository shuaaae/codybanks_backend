<?php

namespace App\Http\Controllers;

use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TeamController extends Controller
{
    /**
     * Get all teams
     */
    public function index(): JsonResponse
    {
        $teams = Team::orderBy('created_at', 'desc')->get();
        return response()->json($teams);
    }

    /**
     * Store a new team
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'players' => 'required|array',
            'logo_path' => 'nullable|string'
        ]);

        $team = Team::create([
            'name' => $request->name,
            'logo_path' => $request->logo_path,
            'players_data' => $request->players
        ]);

        return response()->json($team, 201);
    }

    /**
     * Set active team
     */
    public function setActive(Request $request): JsonResponse
    {
        $teamId = $request->input('team_id');
        
        if ($teamId === null || $teamId === 'null') {
            // Clear active team
            session()->forget('active_team_id');
            return response()->json([
                'message' => 'Active team cleared'
            ]);
        }
        
        $request->validate([
            'team_id' => 'required|exists:teams,id'
        ]);

        $team = Team::findOrFail($teamId);
        
        // Store active team in session
        session(['active_team_id' => $team->id]);
        
        // Also return the team ID in the response for frontend to use in headers
        return response()->json([
            'message' => 'Team set as active',
            'team' => $team,
            'team_id' => $team->id
        ]);
    }

    /**
     * Get active team
     */
    public function getActive(): JsonResponse
    {
        // First try to get from session
        $activeTeamId = session('active_team_id');
        
        // If no session, try to get from request header (for frontend compatibility)
        if (!$activeTeamId) {
            $activeTeamId = request()->header('X-Active-Team-ID');
        }
        
        // Log for debugging
        \Log::info('getActive called', [
            'session_team_id' => session('active_team_id'),
            'header_team_id' => request()->header('X-Active-Team-ID'),
            'final_team_id' => $activeTeamId
        ]);
        
        if (!$activeTeamId) {
            return response()->json(['message' => 'No active team'], 404);
        }

        $team = Team::find($activeTeamId);
        
        if (!$team) {
            return response()->json(['message' => 'Active team not found'], 404);
        }

        return response()->json($team);
    }

    /**
     * Debug endpoint to check current session and active team
     */
    public function debug(): JsonResponse
    {
        $activeTeamId = session('active_team_id');
        $allSessions = session()->all();
        
        return response()->json([
            'active_team_id' => $activeTeamId,
            'all_sessions' => $allSessions,
            'session_id' => session()->getId(),
            'teams_count' => Team::count(),
            'all_teams' => Team::select('id', 'name')->get()
        ]);
    }

    /**
     * Upload team logo
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        \Log::info('Logo upload request received', [
            'has_file' => $request->hasFile('logo'),
            'files' => $request->allFiles()
        ]);

        $request->validate([
            'logo' => 'required|image|max:2048', // 2MB max
        ]);

        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            $filename = 'team_logo_' . uniqid() . '.' . $file->getClientOriginalExtension();
            
            \Log::info('Processing logo upload', [
                'original_name' => $file->getClientOriginalName(),
                'filename' => $filename,
                'size' => $file->getSize()
            ]);
            
            // Store in public/teams directory
            $path = $file->storeAs('teams', $filename, 'public');
            
            \Log::info('Logo uploaded successfully', [
                'path' => $path,
                'full_url' => 'storage/' . $path
            ]);
            
            return response()->json([
                'logo_path' => 'storage/' . $path,
                'message' => 'Logo uploaded successfully'
            ]);
        }

        \Log::error('No logo file provided in upload request');
        return response()->json(['error' => 'No logo file provided'], 400);
    }

    /**
     * Delete a team
     */
    public function destroy($id): JsonResponse
    {
        $team = Team::findOrFail($id);
        
        // Check if this is the active team and clear it if so
        $activeTeamId = session('active_team_id');
        if ($activeTeamId == $id) {
            session()->forget('active_team_id');
        }
        
        // Delete the team
        $team->delete();
        
        return response()->json([
            'message' => 'Team deleted successfully'
        ]);
    }
}

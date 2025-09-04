<?php

namespace App\Http\Controllers;

use App\Models\Team;

use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class TeamController extends Controller
{
    /**
     * Get all teams
     */
    public function index(): JsonResponse
    {
        $teams = Team::orderBy('created_at', 'desc')->get();
        
        // Add player count to each team
        $teamsWithPlayerCount = $teams->map(function ($team) {
            $playerCount = 0;
            
            // Try to get player count from players_data JSON field
            if ($team->players_data && is_array($team->players_data)) {
                $playerCount = count($team->players_data);
            }
            
            // If no players_data or it's empty, try to get from Player model
            if ($playerCount === 0) {
                $playerCount = \App\Models\Player::where('team_id', $team->id)->count();
            }
            
            return [
                'id' => $team->id,
                'name' => $team->name,
                'logo_path' => $team->logo_path,
                'created_at' => $team->created_at,
                'updated_at' => $team->updated_at,
                'players_data' => $team->players_data,
                'player_count' => $playerCount
            ];
        });
        
        return response()->json($teamsWithPlayerCount);
    }

    /**
     * Get a specific team by ID
     */
    public function show($id): JsonResponse
    {
        try {
            $team = Team::findOrFail($id);
            return response()->json($team);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Team not found'], 404);
        } catch (\Exception $e) {
            \Log::error('Error fetching team: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch team'], 500);
        }
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

        try {
            \Log::info('Creating team with players data:', [
                'team_name' => $request->name,
                'players_count' => count($request->players),
                'players_data' => $request->players,
                'raw_request' => $request->all()
            ]);

            // Validate players data is not empty
            if (empty($request->players) || !is_array($request->players)) {
                \Log::error('Invalid players data received:', [
                    'players' => $request->players,
                    'is_array' => is_array($request->players),
                    'empty' => empty($request->players)
                ]);
                return response()->json(['error' => 'Invalid players data'], 400);
            }

            // Create the team first
            $team = Team::create([
                'name' => $request->name,
                'logo_path' => $request->logo_path,
                'players_data' => json_encode($request->players)
            ]);

            // Create individual Player records for each player
            $createdPlayers = [];
            $skippedPlayers = [];
            
            foreach ($request->players as $index => $playerData) {
                // Ensure player has a name
                if (empty($playerData['name'])) {
                    \Log::warning('Skipping player with no name at index ' . $index, $playerData);
                    $skippedPlayers[] = ['index' => $index, 'reason' => 'No name', 'data' => $playerData];
                    continue;
                }
                
                // If role is missing, assign a default role based on position
                $role = $playerData['role'];
                if (empty($role)) {
                    // Assign default roles based on position for the first 5 players
                    $defaultRoles = ['exp', 'mid', 'jungler', 'gold', 'roam'];
                    if ($index < count($defaultRoles)) {
                        $role = $defaultRoles[$index];
                        \Log::info('Assigned default role for player at index ' . $index . ': ' . $role);
                    } else {
                        $role = 'substitute'; // Default for additional players
                        \Log::info('Assigned substitute role for additional player at index ' . $index);
                    }
                } else {
                    // Normalize role to ensure consistency
                    $role = $this->normalizeRole($role);
                }
                
                // Create the player record
                $player = Player::create([
                    'name' => $playerData['name'],
                    'role' => $role,
                    'team_id' => $team->id
                ]);
                $createdPlayers[] = $player;
                \Log::info('Created player record with normalized role:', [
                    'name' => $playerData['name'],
                    'original_role' => $playerData['role'] ?? 'null',
                    'normalized_role' => $role,
                    'player_id' => $player->id
                ]);
            }
            
            // Update the team's players_data with the corrected roles
            $correctedPlayersData = [];
            foreach ($request->players as $index => $playerData) {
                if ($index < count($createdPlayers)) {
                    $correctedPlayersData[] = [
                        'name' => $playerData['name'],
                        'role' => $createdPlayers[$index]->role
                    ];
                } else {
                    $correctedPlayersData[] = $playerData;
                }
            }
            
            // Update the team with corrected player data
            $team->update(['players_data' => json_encode($correctedPlayersData)]);

            // Refresh the team from database to verify data was saved
            $team->refresh();
            
            \Log::info('Team created successfully with players', [
                'team_id' => $team->id,
                'team_name' => $team->name,
                'players_count' => count($request->players),
                'created_players_count' => count($createdPlayers),
                'saved_players_data' => $team->players_data,
                'corrected_players_data' => $correctedPlayersData
            ]);

            return response()->json($team, 201);
        } catch (\Exception $e) {
            \Log::error('Error creating team: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json(['error' => 'Failed to create team: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Sync existing team players to individual Player records
     */
    public function syncTeamPlayers(Request $request): JsonResponse
    {
        $request->validate([
            'team_id' => 'required|exists:teams,id'
        ]);

        $team = Team::findOrFail($request->team_id);
        $playersData = $team->players_data ?? [];
        
        if (empty($playersData)) {
            return response()->json([
                'message' => 'No players data found for this team',
                'synced_count' => 0
            ]);
        }

        \Log::info('Syncing team players', [
            'team_id' => $team->id,
            'team_name' => $team->name,
            'players_count' => count($playersData),
            'players_data' => $playersData
        ]);

        $syncedCount = 0;
        $updatedCount = 0;
        $errors = [];
        
        foreach ($playersData as $index => $playerData) {
            try {
                // Ensure player has a name
                if (empty($playerData['name'])) {
                    $errors[] = "Player at index {$index} has no name";
                    continue;
                }
                
                // If role is missing, assign a default role based on position
                $role = $playerData['role'];
                if (empty($role)) {
                    // Assign default roles based on position for the first 5 players
                    $defaultRoles = ['exp', 'mid', 'jungler', 'gold', 'roam'];
                    if ($index < count($defaultRoles)) {
                        $role = $defaultRoles[$index];
                        \Log::info("Assigned default role '{$role}' for player '{$playerData['name']}' at index {$index}");
                    } else {
                        $role = 'substitute'; // Default for additional players
                        \Log::info("Assigned substitute role for additional player '{$playerData['name']}' at index {$index}");
                    }
                } else {
                    // Normalize role to ensure consistency
                    $role = $this->normalizeRole($role);
                }
                
                // Check if player already exists
                $existingPlayer = Player::where('name', $playerData['name'])
                    ->where('team_id', $team->id)
                    ->first();
                
                if ($existingPlayer) {
                    // Update existing player if role is different
                    if ($existingPlayer->role !== $role) {
                        $existingPlayer->update(['role' => $role]);
                        $updatedCount++;
                        \Log::info("Updated existing player '{$playerData['name']}' role from '{$existingPlayer->role}' to '{$role}'");
                    }
                } else {
                    // Create new player record
                    Player::create([
                        'name' => $playerData['name'],
                        'role' => $role,
                        'team_id' => $team->id
                    ]);
                    $syncedCount++;
                    \Log::info("Created new player record for '{$playerData['name']}' with normalized role '{$role}' (original: '{$playerData['role']}')");
                }
            } catch (\Exception $e) {
                $errors[] = "Error processing player '{$playerData['name']}': " . $e->getMessage();
                \Log::error("Error syncing player '{$playerData['name']}' : " . $e->getMessage());
            }
        }
        
        // Update the team's players_data with corrected roles if any were assigned
        $correctedPlayersData = [];
        foreach ($playersData as $index => $playerData) {
            $correctedPlayersData[] = [
                'name' => $playerData['name'],
                'role' => $playerData['role'] ?: ($index < 5 ? ['exp', 'mid', 'jungler', 'gold', 'roam'][$index] : 'substitute')
            ];
        }
        
        if ($correctedPlayersData !== $playersData) {
            $team->update(['players_data' => $correctedPlayersData]);
            \Log::info("Updated team players_data with corrected roles");
        }

        return response()->json([
            'message' => 'Team players synced successfully',
            'synced_count' => $syncedCount,
            'updated_count' => $updatedCount,
            'errors' => $errors,
            'total_processed' => count($playersData)
        ]);
    }

    /**
     * Test role normalization
     */
    public function testRoleNormalization(Request $request): JsonResponse
    {
        $testRoles = [
            'jungler', 'Jungler', 'JUNGLER', 'jungle', 'Jungle', 'JUNGLE',
            'mid', 'Mid', 'MID', 'midlaner', 'Mid Laner', 'MIDLANER',
            'exp', 'Exp', 'EXP', 'explane', 'Explane', 'EXPLANE',
            'gold', 'Gold', 'GOLD', 'adc', 'ADC', 'marksman',
            'roam', 'Roam', 'ROAM', 'support', 'Support', 'SUPPORT',
            'sub', 'Sub', 'SUB', 'substitute', 'Substitute', 'SUBSTITUTE'
        ];

        $normalizedRoles = [];
        foreach ($testRoles as $role) {
            $normalizedRoles[$role] = $this->normalizeRole($role);
        }

        return response()->json([
            'message' => 'Role normalization test results',
            'test_roles' => $testRoles,
            'normalized_roles' => $normalizedRoles
        ]);
    }

    /**
     * Normalize role values to ensure consistency
     */
    private function normalizeRole($role)
    {
        if (empty($role)) {
            return $role;
        }

        $role = strtolower(trim($role));
        
        // Map various role formats to standard ones
        $roleMap = [
            // Standard roles
            'exp' => 'exp',
            'mid' => 'mid',
            'jungler' => 'jungler',
            'gold' => 'gold',
            'roam' => 'roam',
            'sub' => 'substitute',
            'substitute' => 'substitute',
            
            // Common variations
            'explane' => 'exp',
            'explaner' => 'exp',
            'top' => 'exp',
            'top_laner' => 'exp',
            'toplaner' => 'exp',
            
            'midlane' => 'mid',
            'mid_laner' => 'mid',
            'midlaner' => 'mid',
            'middle' => 'mid',
            
            'jungle' => 'jungler',
            'jungler' => 'jungler',
            
            'adc' => 'gold',
            'marksman' => 'gold',
            'gold_lane' => 'gold',
            'goldlane' => 'gold',
            'carry' => 'gold',
            
            'support' => 'roam',
            'roamer' => 'roam',
            'roam_lane' => 'roam',
            'roamlane' => 'roam',
            
            'backup' => 'substitute',
            'reserve' => 'substitute',
            'sub' => 'substitute'
        ];
        
        return $roleMap[$role] ?? $role;
    }

    /**
     * Set active team
     */
    public function setActive(Request $request): JsonResponse
    {
        try {
            \Log::info('setActive called', [
                'request_data' => $request->all(),
                'team_id' => $request->input('team_id'),
                'session_id' => session()->getId()
            ]);
            
            $teamId = $request->input('team_id');
            
            if ($teamId === null || $teamId === 'null') {
                // Clear active team from session
                session()->forget('active_team_id');
                
                \Log::info('Active team cleared from session');
                return response()->json([
                    'message' => 'Active team cleared'
                ]);
            }
            
            $request->validate([
                'team_id' => 'required|exists:teams,id'
            ]);

            $team = Team::findOrFail($teamId);
            
            // Simplified: Just store active team in session without database records
            session(['active_team_id' => $team->id]);
            
            \Log::info('Active team set in session', [
                'team_id' => $team->id,
                'team_name' => $team->name,
                'session_active_team_id' => session('active_team_id')
            ]);
            
            // Also return the team ID in the response for frontend to use in headers
            return response()->json([
                'message' => 'Team set as active',
                'team' => $team,
                'team_id' => $team->id
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error in setActive: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'team_id' => $request->input('team_id')
            ]);
            
            return response()->json([
                'error' => 'Failed to set team as active: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if any teams exist
     */
    public function checkTeamsExist(): JsonResponse
    {
        try {
            $teamCount = Team::count();
            
            return response()->json([
                'has_teams' => $teamCount > 0,
                'team_count' => $teamCount,
                'message' => $teamCount > 0 ? 'Teams found' : 'No teams found'
            ], 200);
        } catch (\Exception $e) {
            \Log::error('Error checking teams existence: ' . $e->getMessage());
            return response()->json([
                'has_teams' => false,
                'team_count' => 0,
                'message' => 'Error checking teams',
                'error' => $e->getMessage()
            ], 500);
        }
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
            // No active team found - try to get the latest team as fallback
            \Log::info('No active team found, trying to get latest team as fallback');
            
            // Get the most recently created team for this user
            $latestTeam = Team::orderBy('created_at', 'desc')->first();
            
            if ($latestTeam) {
                \Log::info('Found latest team as fallback', ['team_id' => $latestTeam->id, 'team_name' => $latestTeam->name]);
                
                // Set this team as active for the current session
                session(['active_team_id' => $latestTeam->id]);
                

                
                return response()->json($latestTeam);
            } else {
                \Log::info('No teams found at all, returning empty response');
                return response()->json([
                    'message' => 'No teams found',
                    'teams' => [],
                    'has_teams' => false
                ], 200);
            }
        }

        $team = Team::find($activeTeamId);
        
        if (!$team) {
            \Log::info('Active team not found, clearing session and returning empty response');
            session()->forget('active_team_id');
            return response()->json([
                'message' => 'Active team not found',
                'teams' => [],
                'has_teams' => false
            ], 200);
        }



        return response()->json($team);
    }

    /**
     * Ensure all players in a team have individual database records
     */
    public function ensurePlayerRecords(Request $request): JsonResponse
    {
        $request->validate([
            'team_id' => 'required|exists:teams,id'
        ]);

        $team = Team::findOrFail($request->team_id);
        $playersData = $team->players_data ?? [];
        
        if (empty($playersData)) {
            return response()->json([
                'message' => 'No players data found for this team',
                'ensured_count' => 0
            ]);
        }

        \Log::info('Ensuring player records exist for team', [
            'team_id' => $team->id,
            'team_name' => $team->name,
            'players_count' => count($playersData)
        ]);

        $ensuredCount = 0;
        $errors = [];
        
        foreach ($playersData as $index => $playerData) {
            try {
                // Ensure player has a name
                if (empty($playerData['name'])) {
                    $errors[] = "Player at index {$index} has no name";
                    continue;
                }
                
                // If role is missing, assign a default role based on position
                $role = $playerData['role'];
                if (empty($role)) {
                    // Assign default roles based on position for the first 5 players
                    $defaultRoles = ['exp', 'mid', 'jungler', 'gold', 'roam'];
                    if ($index < count($defaultRoles)) {
                        $role = $defaultRoles[$index];
                        \Log::info("Assigned default role '{$role}' for player '{$playerData['name']}' at index {$index}");
                    } else {
                        $role = 'substitute'; // Default for additional players
                        \Log::info("Assigned substitute role for additional player '{$playerData['name']}' at index {$index}");
                    }
                } else {
                    // Normalize role to ensure consistency
                    $role = $this->normalizeRole($role);
                }
                
                // Check if player already exists
                $existingPlayer = Player::where('name', $playerData['name'])
                    ->where('team_id', $team->id)
                    ->first();
                
                if (!$existingPlayer) {
                    // Create new player record
                    Player::create([
                        'name' => $playerData['name'],
                        'role' => $role,
                        'team_id' => $team->id
                    ]);
                    $ensuredCount++;
                    \Log::info("Created new player record for '{$playerData['name']}' with role '{$role}'");
                } else {
                    // Update existing player if role is different
                    if ($existingPlayer->role !== $role) {
                        $existingPlayer->update(['role' => $role]);
                        \Log::info("Updated existing player '{$playerData['name']}' role to '{$role}'");
                    }
                }
            } catch (\Exception $e) {
                $errors[] = "Error processing player '{$playerData['name']}': " . $e->getMessage();
                \Log::error("Error ensuring player record for '{$playerData['name']}' : " . $e->getMessage());
            }
        }
        
        // Update the team's players_data with corrected roles if any were assigned
        $correctedPlayersData = [];
        foreach ($playersData as $index => $playerData) {
            $correctedPlayersData[] = [
                'name' => $playerData['name'],
                'role' => $playerData['role'] ?: ($index < 5 ? ['exp', 'mid', 'jungler', 'gold', 'roam'][$index] : 'substitute')
            ];
        }
        
        if ($correctedPlayersData !== $playersData) {
            $team->update(['players_data' => $correctedPlayersData]);
            \Log::info("Updated team players_data with corrected roles");
        }

        // Fetch the updated team data with player records
        $updatedTeam = Team::with('players')->find($team->id);
        
        // Check if players relationship loaded correctly
        if ($updatedTeam->players && method_exists($updatedTeam->players, 'map')) {
            $playersWithIds = $updatedTeam->players->map(function($player) {
                return [
                    'id' => $player->id,
                    'name' => $player->name,
                    'role' => $player->role,
                    'team_id' => $player->team_id
                ];
            })->toArray();
        } else {
            // Fallback: use the players_data JSON field if relationship failed
            \Log::warning('Players relationship failed to load in ensurePlayerRecords, using players_data JSON field', [
                'team_id' => $updatedTeam->id,
                'players_type' => gettype($updatedTeam->players),
                'players_data_type' => gettype($updatedTeam->players_data)
            ]);
            $playersWithIds = $updatedTeam->players_data ?? [];
        }

        return response()->json([
            'message' => 'Player records ensured successfully',
            'ensured_count' => $ensuredCount,
            'errors' => $errors,
            'total_processed' => count($playersData),
            'updated_players' => $playersWithIds,
            'team_id' => $team->id
        ]);
    }

    /**
     * Check if a team is available (not active by another session)
     */
    public function checkAvailability(Request $request): JsonResponse
    {
        $teamId = $request->input('team_id');
        
        if (!$teamId) {
            return response()->json(['error' => 'Team ID is required'], 400);
        }
        
        $team = Team::find($teamId);
        if (!$team) {
            return response()->json(['error' => 'Team not found'], 404);
        }
        
        // Since we removed session restrictions, all teams are always available
        return response()->json([
            'available' => true,
            'message' => 'Team is available (no session restrictions)',
            'team_name' => $team->name
        ]);
    }

    /**
     * Check if the current user's session is active for a specific team
     */
    public function checkMySession(Request $request): JsonResponse
    {
        $teamId = $request->input('team_id');
        
        if (!$teamId) {
            return response()->json(['error' => 'Team ID is required'], 400);
        }
        
        $team = Team::find($teamId);
        if (!$team) {
            return response()->json(['error' => 'Team not found'], 404);
        }
        
        // Since we removed session restrictions, always return that the team is available
        return response()->json([
            'my_session_active' => true,
            'message' => 'Team is available (no session restrictions)',
            'team_name' => $team->name
        ]);
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
            'all_teams' => Team::select('id', 'name')->get(),
            'message' => 'Session restrictions removed - all teams are accessible'
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
     * Check if team name exists
     */
    public function checkNameExists(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255'
        ]);

        $teamName = trim($request->input('name'));
        
        if (empty($teamName)) {
            return response()->json([
                'exists' => false,
                'message' => 'Team name cannot be empty'
            ], 400);
        }

        $existingTeam = Team::where('name', $teamName)->first();
        
        return response()->json([
            'exists' => $existingTeam ? true : false,
            'message' => $existingTeam ? 'Team name already exists' : 'Team name is available'
        ]);
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

    /**
     * Get active team for current session
     */
    public function getActiveTeam(Request $request): JsonResponse
    {
        try {
            \Log::info('getActiveTeam called', [
                'session_id' => session()->getId(),
                'request_data' => $request->all()
            ]);
            
            // Get active team ID from session
            $activeTeamId = session('active_team_id');
            
            if (!$activeTeamId) {
                // No active team found - try to get the latest team as fallback
                \Log::info('No active team found, trying to get latest team as fallback');
                
                // Get the most recently created team for this user
                $latestTeam = Team::orderBy('created_at', 'desc')->first();
                
                if ($latestTeam) {
                    \Log::info('Found latest team as fallback', ['team_id' => $latestTeam->id, 'team_name' => $latestTeam->name]);
                    
                    // Simplified: Just set the team as active without complex session management
                    session(['active_team_id' => $latestTeam->id]);
                    
                    // Load the team with player relationships to get actual player IDs
                    $latestTeam->load('players');
                    
                    // Create a response that includes both the team data and the actual player records
                    $responseData = $latestTeam->toArray();
                    
                    // Check if players relationship loaded correctly
                    if ($latestTeam->players && method_exists($latestTeam->players, 'map')) {
                        $responseData['players_data'] = $latestTeam->players->map(function($player) {
                            return [
                                'id' => $player->id,
                                'name' => $player->name,
                                'role' => $player->role,
                                'team_id' => $player->team_id,
                                'photo' => $player->photo,
                                'is_substitute' => $player->is_substitute,
                                'player_code' => $player->player_code,
                                'notes' => $player->notes,
                                'primary_player_id' => $player->primary_player_id,
                                'substitute_order' => $player->substitute_order,
                                'created_at' => $player->created_at,
                                'updated_at' => $player->updated_at
                            ];
                        })->toArray();
                    } else {
                        // Fallback: use the players_data JSON field if relationship failed
                        \Log::warning('Players relationship failed to load, using players_data JSON field', [
                            'team_id' => $latestTeam->id,
                            'players_type' => gettype($latestTeam->players),
                            'players_data_type' => gettype($latestTeam->players_data)
                        ]);
                        $responseData['players_data'] = $latestTeam->players_data ?? [];
                    }
                    
                    \Log::info('Returning latest team as fallback with player records', [
                        'team_id' => $latestTeam->id, 
                        'team_name' => $latestTeam->name,
                        'player_count' => count($responseData['players_data'])
                    ]);
                    
                    return response()->json($responseData);
                } else {
                    \Log::info('No teams found at all, returning empty response');
                    return response()->json([
                        'message' => 'No teams found',
                        'teams' => [],
                        'has_teams' => false
                    ], 200);
                }
            }
            
            // Get the active team
            $team = Team::find($activeTeamId);
            
            if (!$team) {
                \Log::warning('Active team not found in database', ['active_team_id' => $activeTeamId]);
                session()->forget('active_team_id');
                return response()->json([
                    'message' => 'Active team not found',
                    'teams' => [],
                    'has_teams' => false
                ], 200);
            }
            
            // Load the team with player relationships to get actual player IDs
            $team->load('players');
            
            // Create a response that includes both the team data and the actual player records
            $responseData = $team->toArray();
            
            // Check if players relationship loaded correctly
            if ($team->players && method_exists($team->players, 'map')) {
                $responseData['players_data'] = $team->players->map(function($player) {
                    return [
                        'id' => $player->id,
                        'name' => $player->name,
                        'role' => $player->role,
                        'team_id' => $player->team_id,
                        'photo' => $player->photo,
                        'is_substitute' => $player->is_substitute,
                        'player_code' => $player->player_code,
                        'notes' => $player->notes,
                        'primary_player_id' => $player->primary_player_id,
                        'substitute_order' => $player->substitute_order,
                        'created_at' => $player->created_at,
                        'updated_at' => $player->updated_at
                    ];
                })->toArray();
            } else {
                // Fallback: use the players_data JSON field if relationship failed
                \Log::warning('Players relationship failed to load, using players_data JSON field', [
                    'team_id' => $team->id,
                    'players_type' => gettype($team->players),
                    'players_data_type' => gettype($team->players_data)
                ]);
                $responseData['players_data'] = $team->players_data ?? [];
            }
            
            \Log::info('Returning active team with player records', [
                'team_id' => $team->id, 
                'team_name' => $team->name,
                'player_count' => count($responseData['players_data'])
            ]);
            
            return response()->json($responseData);
            
        } catch (\Exception $e) {
            \Log::error('Error in getActiveTeam: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to get active team: ' . $e->getMessage()], 500);
        }
    }
}

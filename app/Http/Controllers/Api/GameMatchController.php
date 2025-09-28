<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GameMatch;
use App\Models\MatchTeam;
use App\Services\MatchHeroSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GameMatchController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            // Get team_id from query parameter first
            $teamId = $request->query('team_id');
            $teamId = is_numeric($teamId) ? (int) $teamId : null;
            
            // Get match_type from query parameter
            $matchType = $request->query('match_type', 'scrim');
            
            // Debug logging
            \Log::info('GET /api/matches called', [
                'query_team_id' => $request->query('team_id'),
                'parsed_team_id' => $teamId,
                'match_type' => $matchType,
                'session_team_id' => session('active_team_id'),
                'header_team_id' => $request->header('X-Active-Team-ID'),
                'all_headers' => $request->headers->all()
            ]);
            
            // If no team_id in query, get from session or header
            if (!$teamId) {
                $teamId = session('active_team_id');
                \Log::info('Using session team_id', ['team_id' => $teamId]);
            }
            
            if (!$teamId) {
                $teamId = $request->header('X-Active-Team-ID');
                \Log::info('Using header team_id', ['team_id' => $teamId]);
            }
            
            // If still no team_id, try to get the latest team as fallback
            if (!$teamId) {
                $latestTeam = \App\Models\Team::orderBy('created_at', 'desc')->first();
                if ($latestTeam) {
                    $teamId = $latestTeam->id;
                    \Log::info('Using latest team as fallback', ['team_id' => $teamId, 'team_name' => $latestTeam->name]);
                }
            }

            // CRITICAL FIX: Always filter by team ID to prevent data mixing
            if (!$teamId) {
                \Log::warning('No active team found, returning 404');
                return response()->json(['error' => 'No active team found'], 404);
            }

            // Log all matches for this team before filtering
            $allMatches = \App\Models\GameMatch::where('team_id', $teamId)->get();
            \Log::info('All matches for team_id', [
                'team_id' => $teamId,
                'total_matches' => $allMatches->count(),
                'matches' => $allMatches->map(function($match) {
                    return [
                        'id' => $match->id,
                        'match_date' => $match->match_date,
                        'winner' => $match->winner,
                        'match_type' => $match->match_type
                    ];
                })
            ]);

            $q = \App\Models\GameMatch::query()
                ->select(['id','team_id','match_date','winner','turtle_taken','lord_taken','notes','playstyle','match_type','annual_map'])
                ->with([
                    'teams:id,match_id,team,team_color,banning_phase1,picks1,banning_phase2,picks2',
                ])
                ->where('team_id', $teamId) // Always filter by team ID
                ->where('match_type', $matchType); // Filter by match type

            $matches = $q->orderBy('match_date', 'desc')->get();

            \Log::info('Filtered matches result', [
                'team_id' => $teamId,
                'match_type' => $matchType,
                'matches_count' => $matches->count(),
                'matches' => $matches->map(function($match) {
                    return [
                        'id' => $match->id,
                        'match_date' => $match->match_date,
                        'winner' => $match->winner,
                        'match_type' => $match->match_type,
                        'teams_count' => $match->teams->count()
                    ];
                })
            ]);

            return response()->json($matches);
        } catch (\Throwable $e) {
            \Log::error('GET /api/matches failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
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
                'annual_map' => 'nullable|string',
                'team_id' => 'nullable|exists:teams,id', // Allow team_id in request
                'match_type' => 'nullable|in:scrim,tournament',
                'teams' => 'required|array|size:2',
                'teams.*.team' => 'required|string',
                'teams.*.team_color' => 'required|in:blue,red',
                'teams.*.banning_phase1' => 'required|array',
                'teams.*.picks1' => 'required|array',
                'teams.*.banning_phase2' => 'required|array',
                'teams.*.picks2' => 'required|array',
                'player_assignments' => 'nullable|array',
                'player_assignments.blue' => 'nullable|array',
                'player_assignments.red' => 'nullable|array',
            ]);

            // Create the match
            $match = GameMatch::create([
                'match_date' => $validated['match_date'],
                'winner' => $validated['winner'],
                'turtle_taken' => $validated['turtle_taken'] ?? null,
                'lord_taken' => $validated['lord_taken'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'playstyle' => $validated['playstyle'] ?? null,
                'annual_map' => $validated['annual_map'] ?? null,
                'match_type' => $validated['match_type'] ?? 'scrim',
                'team_id' => $teamId, // Use the determined team_id
            ]);

            // Defensive: Only create teams if present and is array
            if (isset($validated['teams']) && is_array($validated['teams'])) {
                foreach ($validated['teams'] as $teamData) {
                    // Log the team data to see what's being stored
                    \Log::info('Creating match team', [
                        'match_id' => $match->id,
                        'team_name' => $teamData['team'],
                        'team_color' => $teamData['team_color'],
                        'picks1_count' => count($teamData['picks1'] ?? []),
                        'picks2_count' => count($teamData['picks2'] ?? []),
                        'picks1_sample' => array_slice($teamData['picks1'] ?? [], 0, 2), // Log first 2 picks
                        'picks2_sample' => array_slice($teamData['picks2'] ?? [], 0, 2), // Log first 2 picks
                    ]);
                    
                    $teamData['match_id'] = $match->id;
                    MatchTeam::create($teamData);
                }
            }

            // Process player assignments if provided (for comprehensive draft data)
            if (isset($validated['player_assignments']) && is_array($validated['player_assignments'])) {
                \Log::info('Processing player assignments', [
                    'match_id' => $match->id,
                    'player_assignments' => $validated['player_assignments']
                ]);
                
                // Get the team ID for the current team
                $currentTeamId = $validated['team_id'] ?? $teamId;
                
                // Process blue team assignments
                if (isset($validated['player_assignments']['blue']) && is_array($validated['player_assignments']['blue'])) {
                    $this->processPlayerAssignments($match->id, $currentTeamId, $validated['player_assignments']['blue'], 'blue');
                }
                
                // Process red team assignments
                if (isset($validated['player_assignments']['red']) && is_array($validated['player_assignments']['red'])) {
                    $this->processPlayerAssignments($match->id, $currentTeamId, $validated['player_assignments']['red'], 'red');
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
        try {
            // Get the active team ID from session or request header
            $activeTeamId = session('active_team_id');
            
            if (!$activeTeamId) {
                $activeTeamId = request()->header('X-Active-Team-ID');
            }

            if (!$activeTeamId) {
                return response()->json(['error' => 'No active team found'], 404);
            }

            $match = GameMatch::where('id', $id)
                ->where('team_id', $activeTeamId)
                ->with([
                    'teams:id,match_id,team,team_color,banning_phase1,banning_phase2,picks1,picks2',
                    'playerAssignments.player:id,name,role,photo,is_substitute,player_code'
                ])
                ->first();

            if (!$match) {
                return response()->json(['error' => 'Match not found or access denied'], 404);
            }

            // DO NOT sync heroes - this corrupts the original match picks data
            // Lane swapping should only update MatchPlayerAssignment records
            // The original match picks data should remain intact for fresh matches

            // Reload the match with updated data
            $match->load([
                'teams:id,match_id,team,team_color,banning_phase1,banning_phase2,picks1,picks2',
                'playerAssignments.player:id,name,role,photo,is_substitute,player_code'
            ]);

            return response()->json($match);

        } catch (\Exception $e) {
            \Log::error('GameMatchController::show error', [
                'match_id' => $id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Failed to get match',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        try {
            // Get the active team ID from session or request header
            $activeTeamId = session('active_team_id');
            
            if (!$activeTeamId) {
                $activeTeamId = $request->header('X-Active-Team-ID');
            }

            // CRITICAL FIX: Ensure the match belongs to the current team
            if (!$activeTeamId) {
                return response()->json(['error' => 'No active team found'], 404);
            }

            $match = GameMatch::where('id', $id)
                ->where('team_id', $activeTeamId)
                ->first();

            if (!$match) {
                return response()->json(['error' => 'Match not found or access denied'], 404);
            }
            
            // Log the incoming request for debugging
            \Log::info('Updating match', [
                'match_id' => $id,
                'active_team_id' => $activeTeamId,
                'request_data' => $request->all(),
                'current_match_data' => $match->toArray(),
                'team_id_header' => $request->header('X-Active-Team-ID'),
                'all_headers' => $request->headers->all()
            ]);

        $validated = $request->validate([
            'match_date'   => ['required','date'],
            'winner'       => ['required','string'],
            'turtle_taken' => ['nullable','string'],
            'lord_taken'   => ['nullable','string'],
            'notes'        => ['nullable','string'],
            'playstyle'    => ['nullable','string'],
            'annual_map'   => ['nullable','string'],
            // teams payload is required for editing in this app
            'teams'                    => ['required','array','size:2'],
            'teams.*.team'             => ['required','string'],
            'teams.*.team_color'       => ['required','in:blue,red'],
            'teams.*.banning_phase1'   => ['nullable','array'],
            'teams.*.banning_phase2'   => ['nullable','array'],
            'teams.*.picks1'           => ['nullable','array'],
            'teams.*.picks2'           => ['nullable','array'],
        ]);

        return DB::transaction(function () use ($match, $validated) {
            // Log the validated data to see what's being updated
            \Log::info('Updating match with validated data', [
                'match_id' => $match->id,
                'teams_count' => count($validated['teams']),
                'teams_sample' => array_map(function($team) {
                    return [
                        'team_name' => $team['team'],
                        'team_color' => $team['team_color'],
                        'picks1_count' => count($team['picks1'] ?? []),
                        'picks2_count' => count($team['picks2'] ?? []),
                        'picks1_sample' => array_slice($team['picks1'] ?? [], 0, 2),
                        'picks2_sample' => array_slice($team['picks2'] ?? [], 0, 2),
                    ];
                }, $validated['teams'])
            ]);
            
            // Update parent row
            $match->update([
                'match_date'   => $validated['match_date'],
                'winner'       => $validated['winner'],
                'turtle_taken' => $validated['turtle_taken'] ?? null,
                'lord_taken'   => $validated['lord_taken'] ?? null,
                'notes'        => $validated['notes'] ?? null,
                'playstyle'    => $validated['playstyle'] ?? null,
                'annual_map'   => $validated['annual_map'] ?? null,
            ]);

            // Update existing team records instead of recreating them
            $existingTeams = $match->teams;
            
            foreach ($validated['teams'] as $index => $t) {
                $teamColor = $t['team_color'];
                $existingTeam = $existingTeams->where('team_color', $teamColor)->first();
                
                if ($existingTeam) {
                    // Update existing team record
                    $updateData = [
                        'team' => $t['team'],
                    ];
                    
                    // Only update picks and bans if they have actual data (not empty arrays)
                    if (isset($t['banning_phase1']) && !empty($t['banning_phase1'])) {
                        $updateData['banning_phase1'] = $t['banning_phase1'];
                    }
                    if (isset($t['banning_phase2']) && !empty($t['banning_phase2'])) {
                        $updateData['banning_phase2'] = $t['banning_phase2'];
                    }
                    if (isset($t['picks1']) && !empty($t['picks1'])) {
                        $updateData['picks1'] = $t['picks1'];
                    }
                    if (isset($t['picks2']) && !empty($t['picks2'])) {
                        $updateData['picks2'] = $t['picks2'];
                    }
                    
                    $existingTeam->update($updateData);
                    
                    \Log::info('Updated existing match team', [
                        'match_id' => $match->id,
                        'team_name' => $updateData['team'],
                        'team_color' => $teamColor,
                        'updated_fields' => array_keys($updateData),
                        'banning_phase1_count' => count($existingTeam->banning_phase1 ?? []),
                        'banning_phase2_count' => count($existingTeam->banning_phase2 ?? []),
                        'picks1_count' => count($existingTeam->picks1 ?? []),
                        'picks2_count' => count($existingTeam->picks2 ?? []),
                    ]);
                } else {
                    // Create new team record if it doesn't exist
                    $teamData = [
                        'match_id' => $match->id,
                        'team' => $t['team'],
                        'team_color' => $t['team_color'],
                        'banning_phase1' => $t['banning_phase1'] ?? [],
                        'banning_phase2' => $t['banning_phase2'] ?? [],
                        'picks1' => $t['picks1'] ?? [],
                        'picks2' => $t['picks2'] ?? [],
                    ];
                    
                    MatchTeam::create($teamData);
                    
                    \Log::info('Created new match team', [
                        'match_id' => $match->id,
                        'team_name' => $teamData['team'],
                        'team_color' => $teamData['team_color'],
                        'banning_phase1_count' => count($teamData['banning_phase1']),
                        'banning_phase2_count' => count($teamData['banning_phase2']),
                        'picks1_count' => count($teamData['picks1']),
                        'picks2_count' => count($teamData['picks2']),
                    ]);
                }
            }

            // DO NOT sync all hero assignments - this corrupts the original match picks data
            // Lane swapping should only update MatchPlayerAssignment records
            // The original match picks data should remain intact for fresh matches

            // Return the fresh match with its new teams
            $match->load([
                'teams:id,match_id,team,team_color,banning_phase1,banning_phase2,picks1,picks2'
            ]);

            return response()->json($match, 200);
        });
        
        } catch (\Exception $e) {
            \Log::error('GameMatchController::update error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all(),
                'match_id' => $id
            ]);
            
            return response()->json([
                'error' => 'Failed to update match',
                'message' => $e->getMessage(),
                'details' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            // Get the active team ID from session or request header
            $activeTeamId = session('active_team_id');
            
            if (!$activeTeamId) {
                $activeTeamId = request()->header('X-Active-Team-ID');
            }

            // CRITICAL FIX: Ensure the match belongs to the current team
            if (!$activeTeamId) {
                return response()->json(['error' => 'No active team found'], 404);
            }

            $match = GameMatch::where('id', $id)
                ->where('team_id', $activeTeamId)
                ->first();

            if (!$match) {
                return response()->json(['error' => 'Match not found or access denied'], 404);
            }
            
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

    /**
     * Process player assignments for a match
     */
    private function processPlayerAssignments($matchId, $teamId, $playerAssignments, $teamColor)
    {
        try {
            \Log::info("Processing player assignments for {$teamColor} team", [
                'match_id' => $matchId,
                'team_id' => $teamId,
                'assignments' => $playerAssignments
            ]);

            // Clear existing assignments for this match and team
            \App\Models\MatchPlayerAssignment::where('match_id', $matchId)
                ->whereHas('player', function($query) use ($teamId) {
                    $query->where('team_id', $teamId);
                })
                ->delete();

            // Create new assignments
            foreach ($playerAssignments as $index => $playerData) {
                if (empty($playerData) || !isset($playerData['name']) || !isset($playerData['role'])) {
                    continue;
                }

                // Find the player by name and team
                $player = \App\Models\Player::where('name', $playerData['name'])
                    ->where('team_id', $teamId)
                    ->first();

                if (!$player) {
                    \Log::warning("Player not found for assignment", [
                        'player_name' => $playerData['name'],
                        'team_id' => $teamId,
                        'match_id' => $matchId
                    ]);
                    continue;
                }

                // Normalize role
                $role = $this->normalizeRole($playerData['role']);

                // Create the assignment
                \App\Models\MatchPlayerAssignment::create([
                    'match_id' => $matchId,
                    'player_id' => $player->id,
                    'role' => $role,
                    'hero_name' => $playerData['hero_name'] ?? null,
                    'is_starting_lineup' => true,
                    'substitute_order' => null,
                    'notes' => null
                ]);

                \Log::info("Created player assignment", [
                    'match_id' => $matchId,
                    'player_id' => $player->id,
                    'player_name' => $player->name,
                    'role' => $role,
                    'hero' => $playerData['hero'] ?? null,
                ]);
            }

        } catch (\Exception $e) {
            \Log::error('Error processing player assignments', [
                'match_id' => $matchId,
                'team_id' => $teamId,
                'team_color' => $teamColor,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Normalize role values to ensure consistency
     */
    private function normalizeRole($role)
    {
        if (!$role) return $role;
        
        $normalizedRole = strtolower(trim($role));
        
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
        
        return $roleMap[$normalizedRole] ?? $normalizedRole;
    }
}
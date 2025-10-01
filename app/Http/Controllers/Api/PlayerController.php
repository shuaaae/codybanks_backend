<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Player;
use Illuminate\Support\Facades\DB;

class PlayerController extends Controller
{
    public function uploadPhoto(Request $request, $playerId)
    {
        $request->validate([
            'photo' => 'required|image|max:2048', // 2MB max
        ]);

        // Get the active team ID from session or request header
        $activeTeamId = session('active_team_id');
        
        // If no session, try to get from request header (for frontend compatibility)
        if (!$activeTeamId) {
            $activeTeamId = $request->header('X-Active-Team-ID');
        }

        if (!$activeTeamId) {
            return response()->json(['error' => 'No active team found'], 400);
        }
        
        $player = Player::where('id', $playerId)
                       ->where('team_id', $activeTeamId)
                       ->firstOrFail();

        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            $filename = uniqid() . '.' . $file->getClientOriginalExtension();
            $file->move(public_path('players'), $filename);
            $player->photo = 'players/' . $filename;
            $player->save();
        }

        return response()->json(['photo' => url($player->photo)], 200);
    }

    public function uploadPhotoByName(Request $request)
    {
        $request->validate([
            'photo' => 'required|image|max:2048', // 2MB max
            'playerName' => 'required|string',
        ]);

        // Get the active team ID from session or request header
        $activeTeamId = session('active_team_id');
        
        // If no session, try to get from request header (for frontend compatibility)
        if (!$activeTeamId) {
            $activeTeamId = $request->header('X-Active-Team-ID');
        }
        


        if (!$activeTeamId) {
            return response()->json(['error' => 'No active team found'], 400);
        }

        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            $playerName = preg_replace('/[^A-Za-z0-9_-]/', '', $request->input('playerName'));
            $filename = $playerName . '.' . $file->getClientOriginalExtension();
            $file->move(public_path('players'), $filename);
            $photoPath = 'players/' . $filename;

            // Find or create the player by name for the active team
            $player = \App\Models\Player::firstOrCreate(
                [
                    'name' => $request->input('playerName'),
                    'team_id' => $activeTeamId
                ],
                ['role' => null]
            );
            $player->photo = $photoPath;
            $player->save();

            $photoUrl = url($photoPath);
            return response()->json([
                'photo' => $photoUrl,
                'player' => $player
            ], 200);
        }

        return response()->json(['error' => 'No photo uploaded'], 400);
    }

    public function getPhotoByName(Request $request)
    {
        $request->validate([
            'playerName' => 'required|string',
        ]);

        // Get the active team ID from session or request header
        $activeTeamId = session('active_team_id');
        
        // If no session, try to get from request header (for frontend compatibility)
        if (!$activeTeamId) {
            $activeTeamId = $request->header('X-Active-Team-ID');
        }

        if (!$activeTeamId) {
            return response()->json(['error' => 'No active team found'], 404);
        }

        $player = Player::where('name', $request->input('playerName'))
                       ->where('team_id', $activeTeamId)
                       ->first();

        if ($player && $player->photo) {
            return response()->json([
                'photo_path' => $player->photo
            ], 200);
        }

        // Return default photo instead of 404 error
        return response()->json([
            'photo_path' => 'default-player.png'
        ], 200);
    }

    public function index(Request $request)
    {
        // Check if a specific team_id is requested
        $teamId = $request->query('team_id');
        
        if ($teamId) {
            // Return players for the specified team
            return Player::where('team_id', $teamId)->get();
        }
        
        // Get the active team ID from session or request header
        $activeTeamId = session('active_team_id');
        
        // If no session, try to get from request header (for frontend compatibility)
        if (!$activeTeamId) {
            $activeTeamId = request()->header('X-Active-Team-ID');
        }

        if (!$activeTeamId) {
            // Return empty array instead of error when no active team
            return response()->json([]);
        }
        
        // Return only players for the active team
        return Player::where('team_id', $activeTeamId)->get();
    }

    /**
     * Display the specified player
     */
    public function show($id)
    {
        try {
            $player = Player::with('team')->findOrFail($id);
            
            // Check if player belongs to active team
            $activeTeamId = session('active_team_id');
            if (!$activeTeamId) {
                $activeTeamId = request()->header('X-Active-Team-ID');
            }
            
            if ($activeTeamId && $player->team_id != $activeTeamId) {
                return response()->json(['error' => 'Player not found in active team'], 404);
            }
            
            return response()->json($player);
        } catch (\Exception $e) {
            \Log::error('Error fetching player: ' . $e->getMessage());
            return response()->json(['error' => 'Player not found'], 404);
        }
    }

    public function heroStats($playerName)
    {
        // Get the active team ID from session or request header
        $activeTeamId = session('active_team_id');
        
        // If no session, try to get from request header (for frontend compatibility)
        if (!$activeTeamId) {
            $activeTeamId = request()->header('X-Active-Team-ID');
        }

        if (!$activeTeamId) {
            return response()->json(['error' => 'No active team found'], 404);
        }
        
        // Get match_teams joined with matches, filtered by active team
        $matchTeams = \App\Models\MatchTeam::with(['match' => function($query) use ($activeTeamId) {
            $query->where('team_id', $activeTeamId);
        }])->whereHas('match', function($query) use ($activeTeamId) {
            $query->where('team_id', $activeTeamId);
        })->get();
        
        $heroStats = [];

        foreach ($matchTeams as $team) {
            $match = $team->match;
            if (!$match) continue; // Skip if no match (shouldn't happen with whereHas)
            
            $isWin = $team->team === $match->winner;
            // Combine picks1 and picks2
            $picks = array_merge($team->picks1 ?? [], $team->picks2 ?? []);
            
            // Debug logging for picks
            \Log::info("Processing picks for player {$playerName}", [
                'team' => $team->team,
                'match_id' => $match->id,
                'is_win' => $isWin,
                'picks_count' => count($picks),
                'picks_sample' => array_slice($picks, 0, 3) // Log first 3 picks
            ]);
            foreach ($picks as $pick) {
                // Log each pick for debugging
                \Log::info("Processing pick in heroStats", [
                    'pick' => $pick,
                    'pick_type' => gettype($pick),
                    'playerName' => $playerName,
                    'match_id' => $match->id,
                    'team' => $team->team
                ]);
                
                // Support both {hero, player} object and legacy string format
                if (is_array($pick) && isset($pick['hero']) && isset($pick['player'])) {
                    // Check if player is an object with name property
                    if (is_object($pick['player']) && isset($pick['player']['name'])) {
                        if (strtolower($pick['player']['name']) === strtolower($playerName)) {
                            $hero = $pick['hero'];
                            \Log::info("Hero matched for player object in heroStats", [
                                'hero' => $hero,
                                'pickPlayer' => $pick['player']['name'],
                                'requestedPlayer' => $playerName
                            ]);
                        } else {
                            \Log::info("Player mismatch - skipping in heroStats", [
                                'pickPlayer' => $pick['player']['name'],
                                'requestedPlayer' => $playerName
                            ]);
                            continue; // Skip if this pick doesn't belong to the requested player
                        }
                    } 
                    // Check if player is a string
                    elseif (is_string($pick['player'])) {
                        if (strtolower($pick['player']) === strtolower($playerName)) {
                            $hero = $pick['hero'];
                            \Log::info("Hero matched for player string in heroStats", [
                                'hero' => $hero,
                                'pickPlayer' => $pick['player'],
                                'requestedPlayer' => $playerName
                            ]);
                        } else {
                            \Log::info("Player mismatch - skipping in heroStats", [
                                'pickPlayer' => $pick['player'],
                                'requestedPlayer' => $playerName
                            ]);
                            continue; // Skip if this pick doesn't belong to the requested player
                        }
                    } else {
                        \Log::warning("Unrecognized player format in heroStats", [
                            'pick' => $pick,
                            'player' => $pick['player']
                        ]);
                        continue; // Skip if player format is unrecognized
                    }
                } elseif (is_string($pick)) {
                    // CRITICAL: Legacy string format is dangerous - skip to prevent data mixing
                    // String picks without player assignment can't be accurately attributed
                    \Log::warning("Legacy string pick format detected - skipping to prevent data mixing", [
                        'pick' => $pick,
                        'playerName' => $playerName,
                        'match_id' => $match->id,
                        'team' => $team->team
                    ]);
                    continue; // Skip legacy format to prevent data mixing
                } else {
                    continue; // Skip unrecognized pick format
                }
                
                if (!isset($heroStats[$hero])) {
                    $heroStats[$hero] = ['win' => 0, 'lose' => 0, 'total' => 0];
                }
                $heroStats[$hero]['total']++;
                if ($isWin) {
                    $heroStats[$hero]['win']++;
                } else {
                    $heroStats[$hero]['lose']++;
                }
            }
        }
        // Calculate winrate
        $result = [];
        foreach ($heroStats as $hero => $stat) {
            $rate = $stat['total'] > 0 ? round($stat['win'] / $stat['total'] * 100) : 0;
            $result[] = [
                'hero' => $hero,
                'win' => $stat['win'],
                'lose' => $stat['lose'],
                'total' => $stat['total'],
                'winrate' => $rate
            ];
        }
        // Sort by total desc
        usort($result, function($a, $b) { return $b['total'] <=> $a['total']; });
        
        // Log final results for debugging
        \Log::info("Final hero stats for player {$playerName} in heroStats", [
            'playerName' => $playerName,
            'totalHeroes' => count($result),
            'heroes' => $result,
            'activeTeamId' => $activeTeamId
        ]);
        
        return response()->json($result);
    }

    public function heroStatsByTeam(Request $request, $playerName)
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
        
        $teamName = $request->query('teamName');
        $role = $request->query('role'); // Get role parameter for unique player identification
        $matchType = $request->query('match_type', 'scrim'); // Get match type parameter, default to scrim
        
        // Debug logging
        \Log::info("Player stats request", [
            'playerName' => $playerName,
            'activeTeamId' => $activeTeamId,
            'teamName' => $teamName,
            'role' => $role,
            'matchType' => $matchType
        ]);
        
        // DEBUG: Check if team exists
        $team = \App\Models\Team::find($activeTeamId);
        \Log::info("DEBUG: Team lookup", [
            'activeTeamId' => $activeTeamId,
            'teamFound' => $team ? $team->name : 'NOT FOUND',
            'requestedTeamName' => $teamName
        ]);
        
        // DEBUG: Check what tournament matches exist
        $debugTournamentMatches = \App\Models\GameMatch::where('team_id', $activeTeamId)
            ->where('match_type', 'tournament')
            ->with(['teams'])
            ->get();
        \Log::info("DEBUG: Tournament matches found", [
            'count' => $debugTournamentMatches->count(),
            'matches' => $debugTournamentMatches->map(function($match) {
                return [
                    'id' => $match->id,
                    'winner' => $match->winner,
                    'teams' => $match->teams->pluck('team')->toArray()
                ];
            })->toArray()
        ]);
        
        // Find the player in the team - use both name and role for unique identification
        $player = \App\Models\Player::where('name', $playerName)
            ->where('team_id', $activeTeamId)
            ->where('role', $role) // CRITICAL: Also filter by role to ensure unique player
            ->first();
            
        if (!$player) {
            \Log::warning("Player not found in team", [
                'playerName' => $playerName,
                'activeTeamId' => $activeTeamId,
                'role' => $role
            ]);
            return response()->json([]);
        }
        
        // HYBRID APPROACH: Use MatchPlayerAssignment first, then fallback to match picks data
        // This ensures we get data from all matches, even if player assignments don't have hero names
        
        // First, try to get data from player assignments with hero names
        $playerAssignments = \App\Models\MatchPlayerAssignment::where('player_id', $player->id)
            ->where('role', $player->role) // CRITICAL: Only include assignments for the player's actual role
            ->whereHas('match', function($query) use ($activeTeamId, $matchType) {
                $query->where('team_id', $activeTeamId)
                      ->where('match_type', $matchType);
            })
            ->whereNotNull('hero_name') // Only include assignments with hero data
            ->with(['match' => function($query) use ($teamName) {
                $query->select('id', 'winner', 'match_date');
            }])
            ->get();
            
        \Log::info("Found player assignments with hero names", [
            'playerId' => $player->id,
            'playerName' => $playerName,
            'assignmentsCount' => $playerAssignments->count(),
            'matchType' => $matchType,
            'activeTeamId' => $activeTeamId,
            'teamName' => $teamName
        ]);
        
        // DEBUG: Log all tournament matches for this team
        if ($matchType === 'tournament') {
            $tournamentMatches = \App\Models\GameMatch::where('team_id', $activeTeamId)
                ->where('match_type', 'tournament')
                ->get();
            \Log::info("DEBUG: Tournament matches for team", [
                'teamId' => $activeTeamId,
                'teamName' => $teamName,
                'tournamentMatchesCount' => $tournamentMatches->count(),
                'matchIds' => $tournamentMatches->pluck('id')->toArray()
            ]);
            
            // DEBUG: Log player assignments for tournament matches
            $tournamentAssignments = \App\Models\MatchPlayerAssignment::where('player_id', $player->id)
                ->whereHas('match', function($query) use ($activeTeamId) {
                    $query->where('team_id', $activeTeamId)
                          ->where('match_type', 'tournament');
                })
                ->get();
            \Log::info("DEBUG: Tournament player assignments", [
                'playerId' => $player->id,
                'playerName' => $playerName,
                'assignmentsCount' => $tournamentAssignments->count(),
                'assignments' => $tournamentAssignments->map(function($assignment) {
                    return [
                        'id' => $assignment->id,
                        'match_id' => $assignment->match_id,
                        'hero_name' => $assignment->hero_name,
                        'role' => $assignment->role
                    ];
                })->toArray()
            ]);
            
            // DEBUG: Check if there are ANY player assignments for tournament matches
            $allTournamentAssignments = \App\Models\MatchPlayerAssignment::whereHas('match', function($query) use ($activeTeamId) {
                $query->where('team_id', $activeTeamId)
                      ->where('match_type', 'tournament');
            })->get();
            \Log::info("DEBUG: All tournament assignments for team", [
                'teamId' => $activeTeamId,
                'totalAssignments' => $allTournamentAssignments->count(),
                'assignments' => $allTournamentAssignments->map(function($assignment) {
                    return [
                        'id' => $assignment->id,
                        'player_id' => $assignment->player_id,
                        'match_id' => $assignment->match_id,
                        'hero_name' => $assignment->hero_name,
                        'role' => $assignment->role
                    ];
                })->toArray()
            ]);
        }
        
        $heroStats = [];
        $processedMatchIds = [];

        // Process assignments with hero names
        foreach ($playerAssignments as $assignment) {
            $match = $assignment->match;
            if (!$match) continue;
            
            // Determine if this was a win or loss
            $isWin = $match->winner === $teamName;
            $hero = $assignment->hero_name;
            
            if (!$hero) {
                \Log::debug("Assignment {$assignment->id} has no hero name, skipping");
                continue;
            }
            
            if (!isset($heroStats[$hero])) {
                $heroStats[$hero] = ['win' => 0, 'lose' => 0, 'total' => 0];
            }
            $heroStats[$hero]['total']++;
            if ($isWin) {
                $heroStats[$hero]['win']++;
            } else {
                $heroStats[$hero]['lose']++;
            }
            
            $processedMatchIds[] = $match->id;
            
            // Log successful match for debugging
            \Log::info("Player assignment stats found", [
                'playerName' => $playerName,
                'playerId' => $player->id,
                'role' => $assignment->role,
                'hero' => $hero,
                'match_id' => $match->id,
                'is_win' => $isWin,
                'winner' => $match->winner,
                'teamName' => $teamName,
                'is_starting_lineup' => $assignment->is_starting_lineup
            ]);
        }
        
        // FALLBACK: Get data from matches where player assignments don't have hero names
        // BUT ONLY for matches where this specific player was actually assigned to play
        $fallbackAssignments = \App\Models\MatchPlayerAssignment::where('player_id', $player->id)
            ->where('role', $player->role) // CRITICAL: Only assignments for this player's role
            ->whereHas('match', function($query) use ($activeTeamId, $matchType) {
                $query->where('team_id', $activeTeamId)
                      ->where('match_type', $matchType);
            })
            ->whereNull('hero_name') // Only assignments WITHOUT hero names
            ->whereNotIn('match_id', $processedMatchIds) // Don't process matches we already handled
            ->with(['match.teams' => function($query) {
                $query->select('id', 'match_id', 'team', 'picks1', 'picks2');
            }])
            ->get();
            
        // TOURNAMENT FALLBACK: If we're in tournament mode and no player assignments found, try direct match picks approach
        if ($matchType === 'tournament' && $playerAssignments->count() === 0 && $fallbackAssignments->count() === 0) {
            \Log::info("DEBUG: No player assignments found for tournament, trying direct match picks approach");
            // Track processed match IDs to prevent duplicates
            $tournamentProcessedMatchIds = [];
            
            // Get tournament matches for this team - use the correct GameMatch model
            $tournamentMatches = \App\Models\GameMatch::where('team_id', $activeTeamId)
                ->where('match_type', 'tournament')
                ->with(['teams' => function($query) {
                    $query->select('id', 'match_id', 'team', 'picks1', 'picks2');
                }])
                ->get();
                
            \Log::info("DEBUG: Direct tournament matches approach", [
                'tournamentMatchesCount' => $tournamentMatches->count(),
                'matchIds' => $tournamentMatches->pluck('id')->toArray()
            ]);
            
            // Process each tournament match directly
            foreach ($tournamentMatches as $match) {
                // Skip if already processed
                if (in_array($match->id, $tournamentProcessedMatchIds)) {
                    \Log::debug("Skipping already processed tournament match {$match->id}");
                    continue;
                }
                
                \Log::info("DEBUG: Processing tournament match for hero stats", [
                    'matchId' => $match->id,
                    'teams' => $match->teams->pluck('team')->toArray(),
                    'lookingForTeam' => $teamName,
                    'matchWinner' => $match->winner
                ]);
                
                // For tournament matches, we need to find our team in the match
                // Since the match has team_id = our activeTeamId, we know this match belongs to us
                // We need to find which team in the match is ours
                $ourTeamName = \App\Models\Team::find($activeTeamId)->name ?? $teamName;
                
                // Try to find our team by name first
                $matchTeam = $match->teams->first(function($team) use ($ourTeamName, $teamName, $match) {
                    return $team->team === $ourTeamName || 
                           $team->team === $teamName ||
                           $team->team === $match->winner;
                });
                
                // If still no match, take the first team (this is a fallback)
                if (!$matchTeam) {
                    $matchTeam = $match->teams->first();
                }
                
                if (!$matchTeam) {
                    \Log::debug("No matching team found for match {$match->id}, teams: " . json_encode($match->teams->pluck('team')->toArray()));
                    continue;
                }
                
                // Get picks data and find hero for this player's role
                $picks = array_merge($matchTeam->picks1 ?? [], $matchTeam->picks2 ?? []);
                
                \Log::info("DEBUG: Processing picks for hero stats", [
                    'matchId' => $match->id,
                    'ourTeam' => $matchTeam->team,
                    'picks' => $picks,
                    'playerRole' => $role
                ]);
                
                // Find the pick for this player's role
                $playerPick = null;
                foreach ($picks as $pick) {
                    if (is_array($pick) && isset($pick['lane']) && isset($pick['hero'])) {
                        if (strtolower($pick['lane']) === strtolower($player->role)) {
                            $playerPick = $pick;
                            break;
                        }
                    }
                }
                
                if (!$playerPick || !$playerPick['hero']) {
                    \Log::debug("No hero found for role {$player->role} in tournament match {$match->id} for player {$playerName}");
                    continue;
                }
                
                // Determine if this was a win or loss
                $isWin = $match->winner === $matchTeam->team;
                $hero = $playerPick['hero'];
                
                if (!isset($heroStats[$hero])) {
                    $heroStats[$hero] = ['win' => 0, 'lose' => 0, 'total' => 0];
                }
                $heroStats[$hero]['total']++;
                if ($isWin) {
                    $heroStats[$hero]['win']++;
                } else {
                    $heroStats[$hero]['lose']++;
                }
                
                \Log::info("DEBUG: Direct tournament match processed", [
                    'playerName' => $playerName,
                    'matchId' => $match->id,
                    'hero' => $hero,
                    'isWin' => $isWin,
                    'winner' => $match->winner,
                    'teamName' => $teamName,
                    'currentHeroStats' => $heroStats
                ]);
                
                // Mark this match as processed
                $tournamentProcessedMatchIds[] = $match->id;
            }
        }
            
        \Log::info("Processing fallback assignments for hero stats", [
            'playerId' => $player->id,
            'playerName' => $playerName,
            'fallbackAssignmentsCount' => $fallbackAssignments->count(),
            'processedMatchIds' => $processedMatchIds
        ]);
        
        foreach ($fallbackAssignments as $assignment) {
            $match = $assignment->match;
            if (!$match) continue;
            
            // Find the team's match team data
            $matchTeam = $match->teams->first(function($team) use ($teamName) {
                return $team->team === $teamName;
            });
            
            if (!$matchTeam) continue;
            
            // Get picks data and find hero for this player's role
            $picks = array_merge($matchTeam->picks1 ?? [], $matchTeam->picks2 ?? []);
            
            // Find the pick for this player's role
            $playerPick = null;
            foreach ($picks as $pick) {
                if (is_array($pick) && isset($pick['lane']) && isset($pick['hero'])) {
                    if (strtolower($pick['lane']) === strtolower($player->role)) {
                        $playerPick = $pick;
                        break;
                    }
                }
            }
            
            if (!$playerPick || !$playerPick['hero']) {
                \Log::debug("No hero found for role {$player->role} in fallback match {$match->id} for player {$playerName}");
                continue;
            }
            
            // Determine if this was a win or loss
            $isWin = $match->winner === $teamName;
            $hero = $playerPick['hero'];
            
            if (!isset($heroStats[$hero])) {
                $heroStats[$hero] = ['win' => 0, 'lose' => 0, 'total' => 0];
            }
            $heroStats[$hero]['total']++;
            if ($isWin) {
                $heroStats[$hero]['win']++;
            } else {
                $heroStats[$hero]['lose']++;
            }
            
            // Log successful fallback match for debugging
            \Log::info("Fallback assignment stats found", [
                'playerName' => $playerName,
                'playerId' => $player->id,
                'role' => $player->role,
                'hero' => $hero,
                'match_id' => $match->id,
                'assignment_id' => $assignment->id,
                'is_win' => $isWin,
                'winner' => $match->winner,
                'teamName' => $teamName,
                'is_starting_lineup' => $assignment->is_starting_lineup,
                'dataSource' => 'fallback_assignment'
            ]);
        }
        
        // Calculate winrate
        $result = [];
        foreach ($heroStats as $hero => $stat) {
            $rate = $stat['total'] > 0 ? round($stat['win'] / $stat['total'] * 100) : 0;
            $result[] = [
                'hero' => $hero,
                'win' => $stat['win'],
                'lose' => $stat['lose'],
                'total' => $stat['total'],
                'winrate' => $rate
            ];
        }
        // Sort by total desc
        usort($result, function($a, $b) { return $b['total'] <=> $a['total']; });
        
        // Log final results for debugging
        \Log::info("Final hero stats for player {$playerName} in heroStatsByTeam", [
            'playerName' => $playerName,
            'playerId' => $player->id,
            'role' => $role,
            'totalHeroes' => count($result),
            'heroes' => $result,
            'activeTeamId' => $activeTeamId,
            'dataSource' => 'MatchPlayerAssignment'
        ]);
        
        return response()->json($result);
    }

    public function heroH2HStatsByTeam(Request $request, $playerName)
    {
        // Get the active team ID from session or request header
        $activeTeamId = session('active_team_id');
        
        // If no session, try to get from request header (for frontend compatibility)
        if (!$activeTeamId) {
            $activeTeamId = $request->header('X-Active-Team-ID');
        }
        
        $teamName = $request->query('teamName');
        $role = $request->query('role'); // Get role parameter for unique player identification
        $matchType = $request->query('match_type', 'scrim'); // Get match type parameter, default to scrim
        
        // Debug logging
        \Log::info("Player H2H stats request", [
            'playerName' => $playerName,
            'activeTeamId' => $activeTeamId,
            'teamName' => $teamName,
            'role' => $role,
            'matchType' => $matchType
        ]);
        
        // Find the player in the team - use both name and role for unique identification
        $player = \App\Models\Player::where('name', $playerName)
            ->where('team_id', $activeTeamId)
            ->where('role', $role) // CRITICAL: Also filter by role to ensure unique player
            ->first();
            
        if (!$player) {
            \Log::warning("Player not found in team for H2H stats", [
                'playerName' => $playerName,
                'activeTeamId' => $activeTeamId,
                'role' => $role
            ]);
            return response()->json([]);
        }
        
        // HYBRID APPROACH: Use MatchPlayerAssignment first, then fallback to match picks data
        // This ensures we get H2H data from all matches, even if player assignments don't have hero names
        
        // First, try to get data from player assignments with hero names
        $playerAssignments = \App\Models\MatchPlayerAssignment::where('player_id', $player->id)
            ->where('role', $player->role) // CRITICAL: Only include assignments for the player's actual role
            ->whereHas('match', function($query) use ($activeTeamId, $matchType) {
                $query->where('team_id', $activeTeamId)
                      ->where('match_type', $matchType);
            })
            ->whereNotNull('hero_name') // Only include assignments with hero data
            ->with(['match.teams' => function($query) {
                $query->select('id', 'match_id', 'team', 'picks1', 'picks2');
            }])
            ->get();
            
        \Log::info("Found player assignments with hero names for H2H stats", [
            'playerId' => $player->id,
            'playerName' => $playerName,
            'assignmentsCount' => $playerAssignments->count(),
            'matchType' => $matchType
        ]);
        
        $h2hStats = [];
        $processedMatchIds = [];

        // Process assignments with hero names
        foreach ($playerAssignments as $assignment) {
            $match = $assignment->match;
            if (!$match) continue;
            
            // Find the team's match team data
            $matchTeam = $match->teams->first(function($team) use ($teamName) {
                return $team->team === $teamName;
            });
            
            if (!$matchTeam) continue;
            
            // Find the enemy team in the same match
            $enemyTeam = $match->teams->first(function($team) use ($teamName) {
                return $team->team !== $teamName;
            });
            if (!$enemyTeam) continue;
            
            // Get the player's hero from the assignment
            $playerHero = $assignment->hero_name;
            if (!$playerHero) {
                \Log::debug("Assignment {$assignment->id} has no hero name, skipping H2H");
                continue;
            }
            
            // Find enemy hero in the same lane from the enemy team's picks
            $enemyPicks = array_merge($enemyTeam->picks1 ?? [], $enemyTeam->picks2 ?? []);
            $enemyHero = null;
            
            foreach ($enemyPicks as $ep) {
                if (is_array($ep) && isset($ep['hero']) && isset($ep['lane'])) {
                    if (strtolower($ep['lane']) === strtolower($assignment->role)) {
                        $enemyHero = $ep['hero'];
                        break;
                    }
                }
            }
            
            if (!$enemyHero) {
                \Log::debug("No enemy hero found for role {$assignment->role} in match {$match->id}");
                continue;
            }
            
            // Determine if this was a win or loss
            $isWin = $match->winner === $teamName;
            
            $key = $playerHero . ' vs ' . $enemyHero;
            
            if (!isset($h2hStats[$key])) {
                $h2hStats[$key] = [
                    'player_hero' => $playerHero,
                    'enemy_hero' => $enemyHero,
                    'win' => 0,
                    'lose' => 0,
                    'total' => 0
                ];
            }
            $h2hStats[$key]['total']++;
            if ($isWin) {
                $h2hStats[$key]['win']++;
            } else {
                $h2hStats[$key]['lose']++;
            }
            
            $processedMatchIds[] = $match->id;
            
            // Log successful H2H match for debugging
            \Log::info("H2H Player assignment stats found", [
                'playerName' => $playerName,
                'playerId' => $player->id,
                'role' => $assignment->role,
                'playerHero' => $playerHero,
                'enemyHero' => $enemyHero,
                'match_id' => $match->id,
                'is_win' => $isWin,
                'winner' => $match->winner,
                'teamName' => $teamName,
                'is_starting_lineup' => $assignment->is_starting_lineup
            ]);
        }
        
        // FALLBACK: Get H2H data from matches where player assignments don't have hero names
        // BUT ONLY for matches where this specific player was actually assigned to play
        $fallbackAssignments = \App\Models\MatchPlayerAssignment::where('player_id', $player->id)
            ->where('role', $player->role) // CRITICAL: Only assignments for this player's role
            ->whereHas('match', function($query) use ($activeTeamId, $matchType) {
                $query->where('team_id', $activeTeamId)
                      ->where('match_type', $matchType);
            })
            ->whereNull('hero_name') // Only assignments WITHOUT hero names
            ->whereNotIn('match_id', $processedMatchIds) // Don't process matches we already handled
            ->with(['match.teams' => function($query) {
                $query->select('id', 'match_id', 'team', 'picks1', 'picks2');
            }])
            ->get();
            
        \Log::info("Processing fallback assignments for H2H stats", [
            'playerId' => $player->id,
            'playerName' => $playerName,
            'fallbackAssignmentsCount' => $fallbackAssignments->count(),
            'processedMatchIds' => $processedMatchIds
        ]);
        
        foreach ($fallbackAssignments as $assignment) {
            $match = $assignment->match;
            if (!$match) continue;
            
            // Find the team's match team data
            $matchTeam = $match->teams->first(function($team) use ($teamName) {
                return $team->team === $teamName;
            });
            
            if (!$matchTeam) continue;
            
            // Find the enemy team in the same match
            $enemyTeam = $match->teams->first(function($team) use ($teamName) {
                return $team->team !== $teamName;
            });
            if (!$enemyTeam) continue;
            
            // Get picks data and find hero for this player's role
            $picks = array_merge($matchTeam->picks1 ?? [], $matchTeam->picks2 ?? []);
            $enemyPicks = array_merge($enemyTeam->picks1 ?? [], $enemyTeam->picks2 ?? []);
            
            // Find the pick for this player's role
            $playerPick = null;
            foreach ($picks as $pick) {
                if (is_array($pick) && isset($pick['lane']) && isset($pick['hero'])) {
                    if (strtolower($pick['lane']) === strtolower($player->role)) {
                        $playerPick = $pick;
                        break;
                    }
                }
            }
            
            if (!$playerPick || !$playerPick['hero']) {
                \Log::debug("No player hero found for role {$player->role} in fallback H2H match {$match->id} for player {$playerName}");
                continue;
            }
            
            // Find enemy hero in the same lane
            $enemyHero = null;
            foreach ($enemyPicks as $ep) {
                if (is_array($ep) && isset($ep['hero']) && isset($ep['lane'])) {
                    if (strtolower($ep['lane']) === strtolower($player->role)) {
                        $enemyHero = $ep['hero'];
                        break;
                    }
                }
            }
            
            if (!$enemyHero) {
                \Log::debug("No enemy hero found for role {$player->role} in fallback H2H match {$match->id} for player {$playerName}");
                continue;
            }
            
            // Determine if this was a win or loss
            $isWin = $match->winner === $teamName;
            $playerHero = $playerPick['hero'];
            
            $key = $playerHero . ' vs ' . $enemyHero;
            
            if (!isset($h2hStats[$key])) {
                $h2hStats[$key] = [
                    'player_hero' => $playerHero,
                    'enemy_hero' => $enemyHero,
                    'win' => 0,
                    'lose' => 0,
                    'total' => 0
                ];
            }
            $h2hStats[$key]['total']++;
            if ($isWin) {
                $h2hStats[$key]['win']++;
            } else {
                $h2hStats[$key]['lose']++;
            }
            
            // Log successful fallback H2H match for debugging
            \Log::info("Fallback H2H assignment stats found", [
                'playerName' => $playerName,
                'playerId' => $player->id,
                'role' => $player->role,
                'playerHero' => $playerHero,
                'enemyHero' => $enemyHero,
                'match_id' => $match->id,
                'assignment_id' => $assignment->id,
                'is_win' => $isWin,
                'winner' => $match->winner,
                'teamName' => $teamName,
                'is_starting_lineup' => $assignment->is_starting_lineup,
                'dataSource' => 'fallback_assignment'
            ]);
        }
        
        // Calculate winrate
        $result = [];
        foreach ($h2hStats as $stat) {
            $rate = $stat['total'] > 0 ? round($stat['win'] / $stat['total'] * 100) : 0;
            $result[] = array_merge($stat, ['winrate' => $rate]);
        }
        
        // TOURNAMENT FALLBACK: If we're in tournament mode and no player assignments found, try direct match picks approach
        if ($matchType === 'tournament' && $playerAssignments->count() === 0 && $fallbackAssignments->count() === 0) {
            \Log::info("DEBUG: No player assignments found for tournament H2H, trying direct match picks approach");
            
            // Get tournament matches for this team - use the correct GameMatch model
            $tournamentMatches = \App\Models\GameMatch::where('team_id', $activeTeamId)
                ->where('match_type', 'tournament')
                ->with(['teams' => function($query) {
                    $query->select('id', 'match_id', 'team', 'picks1', 'picks2');
                }])
                ->get();
                
            \Log::info("DEBUG: Direct tournament matches approach for H2H", [
                'tournamentMatchesCount' => $tournamentMatches->count(),
                'matchIds' => $tournamentMatches->pluck('id')->toArray(),
                'teamName' => $teamName,
                'activeTeamId' => $activeTeamId
            ]);
            
            // Process each tournament match directly for H2H
            foreach ($tournamentMatches as $match) {
                \Log::info("DEBUG: Processing tournament match for H2H", [
                    'matchId' => $match->id,
                    'teams' => $match->teams->pluck('team')->toArray(),
                    'lookingForTeam' => $teamName,
                    'matchWinner' => $match->winner
                ]);
                
                // For tournament matches, we need to find our team in the match
                // Since the match has team_id = our activeTeamId, we know this match belongs to us
                // We need to find which team in the match is ours
                $ourTeamName = \App\Models\Team::find($activeTeamId)->name ?? $teamName;
                
                // Try to find our team by name first
                $matchTeam = $match->teams->first(function($team) use ($ourTeamName, $teamName, $match) {
                    return $team->team === $ourTeamName || 
                           $team->team === $teamName ||
                           $team->team === $match->winner;
                });
                
                // If still no match, take the first team (this is a fallback)
                if (!$matchTeam) {
                    $matchTeam = $match->teams->first();
                }
                
                if (!$matchTeam) {
                    \Log::debug("No matching team found for match {$match->id}, teams: " . json_encode($match->teams->pluck('team')->toArray()));
                    continue;
                }
                
                // Find the enemy team in the same match
                $enemyTeam = $match->teams->first(function($team) use ($matchTeam) {
                    return $team->team !== $matchTeam->team;
                });
                if (!$enemyTeam) {
                    \Log::debug("No enemy team found for match {$match->id}, our team: {$matchTeam->team}");
                    continue;
                }
                
                \Log::info("DEBUG: Found teams for H2H", [
                    'matchId' => $match->id,
                    'ourTeam' => $matchTeam->team,
                    'enemyTeam' => $enemyTeam->team,
                    'ourPicks' => array_merge($matchTeam->picks1 ?? [], $matchTeam->picks2 ?? []),
                    'enemyPicks' => array_merge($enemyTeam->picks1 ?? [], $enemyTeam->picks2 ?? [])
                ]);
                
                // Get picks data and find hero for this player's role
                $picks = array_merge($matchTeam->picks1 ?? [], $matchTeam->picks2 ?? []);
                $enemyPicks = array_merge($enemyTeam->picks1 ?? [], $enemyTeam->picks2 ?? []);
                
                // Find the pick for this player's role
                $playerPick = null;
                foreach ($picks as $pick) {
                    if (is_array($pick) && isset($pick['lane']) && isset($pick['hero'])) {
                        if (strtolower($pick['lane']) === strtolower($player->role)) {
                            $playerPick = $pick;
                            break;
                        }
                    }
                }
                
                if (!$playerPick || !$playerPick['hero']) {
                    \Log::debug("No hero found for role {$player->role} in tournament match {$match->id} for player {$playerName} H2H");
                    continue;
                }
                
                // Find enemy hero in the same lane
                $enemyHero = null;
                foreach ($enemyPicks as $ep) {
                    if (is_array($ep) && isset($ep['hero']) && isset($ep['lane'])) {
                        if (strtolower($ep['lane']) === strtolower($player->role)) {
                            $enemyHero = $ep['hero'];
                            break;
                        }
                    }
                }
                
                if (!$enemyHero) {
                    \Log::debug("No enemy hero found for role {$player->role} in tournament match {$match->id} for player {$playerName} H2H");
                    continue;
                }
                
                // Determine if this was a win or loss
                $isWin = $match->winner === $matchTeam->team;
                $playerHero = $playerPick['hero'];
                
                $key = $playerHero . ' vs ' . $enemyHero;
                
                if (!isset($h2hStats[$key])) {
                    $h2hStats[$key] = [
                        'player_hero' => $playerHero,
                        'enemy_hero' => $enemyHero,
                        'win' => 0,
                        'lose' => 0,
                        'total' => 0
                    ];
                }
                $h2hStats[$key]['total']++;
                if ($isWin) {
                    $h2hStats[$key]['win']++;
                } else {
                    $h2hStats[$key]['lose']++;
                }
                
                \Log::info("DEBUG: Direct tournament match processed for H2H", [
                    'playerName' => $playerName,
                    'matchId' => $match->id,
                    'playerHero' => $playerHero,
                    'enemyHero' => $enemyHero,
                    'isWin' => $isWin,
                    'winner' => $match->winner,
                    'teamName' => $teamName,
                    'currentH2HStats' => $h2hStats
                ]);
            }
        }
        
        // Sort by total desc
        usort($result, function($a, $b) { return $b['total'] <=> $a['total']; });
        
        // Log final results for debugging
        \Log::info("Final H2H stats for player {$playerName} in heroH2HStatsByTeam", [
            'playerName' => $playerName,
            'playerId' => $player->id,
            'role' => $role,
            'totalMatchups' => count($result),
            'matchups' => $result,
            'activeTeamId' => $activeTeamId,
            'dataSource' => 'MatchPlayerAssignment'
        ]);
        
        return response()->json($result);
    }

    public function debug(Request $request)
    {
        return response()->json([
            'session_id' => session()->getId(),
            'session_started' => session()->isStarted(),
            'active_team_id' => session('active_team_id'),
            'header_team_id' => $request->header('X-Active-Team-ID'),
            'body_team_id' => $request->input('team_id'),
            'all_session_data' => session()->all(),
            'request_headers' => $request->headers->all(),
            'request_data' => $request->all()
        ]);
    }
    
    /**
     * Debug endpoint to inspect match data for a specific player
     */
    public function debugPlayerMatches(Request $request, $playerName)
    {
        $activeTeamId = session('active_team_id');
        if (!$activeTeamId) {
            $activeTeamId = $request->header('X-Active-Team-ID');
        }
        
        if (!$activeTeamId) {
            return response()->json(['error' => 'No active team found'], 404);
        }
        
        // Get all matches for the current team
        $matches = \App\Models\GameMatch::where('team_id', $activeTeamId)->get();
        
        $debugData = [];
        foreach ($matches as $match) {
            $matchData = [
                'match_id' => $match->id,
                'match_date' => $match->match_date,
                'winner' => $match->winner,
                'teams' => []
            ];
            
            foreach ($match->teams as $team) {
                $teamData = [
                    'team' => $team->team,
                    'team_color' => $team->team_color,
                    'picks1' => $team->picks1,
                    'picks2' => $team->picks2
                ];
                
                // Check if any picks contain the player
                $playerPicks = [];
                $allPicks = array_merge($team->picks1 ?? [], $team->picks2 ?? []);
                
                foreach ($allPicks as $pick) {
                    if (is_array($pick) && isset($pick['player'])) {
                        if (is_object($pick['player']) && isset($pick['player']['name'])) {
                            if (strtolower($pick['player']['name']) === strtolower($playerName)) {
                                $playerPicks[] = $pick;
                            }
                        } elseif (is_string($pick['player']) && strtolower($pick['player']) === strtolower($playerName)) {
                            $playerPicks[] = $pick;
                        }
                    }
                }
                
                $teamData['player_picks'] = $playerPicks;
                $matchData['teams'][] = $teamData;
            }
            
            $debugData[] = $matchData;
        }
        
        return response()->json([
            'playerName' => $playerName,
            'activeTeamId' => $activeTeamId,
            'totalMatches' => count($matches),
            'matches' => $debugData
        ]);
    }
    
    /**
     * Test endpoint to simulate the exact logic used in heroStatsByTeam
     */
    public function testPlayerStatsLogic(Request $request, $playerName)
    {
        $activeTeamId = session('active_team_id');
        if (!$activeTeamId) {
            $activeTeamId = $request->header('X-Active-Team-ID');
        }
        
        if (!$activeTeamId) {
            return response()->json(['error' => 'No active team found'], 404);
        }
        
        $teamName = $request->query('teamName');
        
        // Use the exact same logic as heroStatsByTeam
        $query = \App\Models\MatchTeam::with('match');
        
        if ($activeTeamId) {
            $query->whereHas('match', function($q) use ($activeTeamId) {
                $q->where('team_id', $activeTeamId);
            });
        }
        
        if ($teamName) {
            $query->where('team', $teamName);
        }
        
        $matchTeams = $query->get();
        
        $testResults = [];
        $heroStats = [];
        
        foreach ($matchTeams as $team) {
            $match = $team->match;
            if (!$match) continue;
            
            if ($match->team_id != $activeTeamId) {
                continue;
            }
            
            $isWin = $team->team === $match->winner;
            $picks = array_merge($team->picks1 ?? [], $team->picks2 ?? []);
            
            $teamResults = [
                'team' => $team->team,
                'match_id' => $match->id,
                'is_win' => $isWin,
                'picks_processed' => 0,
                'picks_skipped' => 0,
                'picks_details' => []
            ];
            
            foreach ($picks as $pick) {
                $pickDetail = [
                    'raw_pick' => $pick,
                    'pick_type' => gettype($pick),
                    'has_hero' => isset($pick['hero']),
                    'has_lane' => isset($pick['lane']),
                    'has_player' => isset($pick['player']),
                    'player_type' => isset($pick['player']) ? gettype($pick['player']) : 'none',
                    'player_name' => null,
                    'processed' => false,
                    'reason' => ''
                ];
                
                if (is_array($pick) && isset($pick['hero']) && isset($pick['lane'])) {
                    if (isset($pick['player'])) {
                        if (is_object($pick['player']) && isset($pick['player']['name'])) {
                            if (strtolower($pick['player']['name']) === strtolower($playerName)) {
                                $hero = $pick['hero'];
                                $pickDetail['player_name'] = $pick['player']['name'];
                                $pickDetail['processed'] = true;
                                $pickDetail['reason'] = 'Player object match';
                                
                                if (!isset($heroStats[$hero])) {
                                    $heroStats[$hero] = ['win' => 0, 'lose' => 0, 'total' => 0];
                                }
                                $heroStats[$hero]['total']++;
                                if ($isWin) {
                                    $heroStats[$hero]['win']++;
                                } else {
                                    $heroStats[$hero]['lose']++;
                                }
                                
                                $teamResults['picks_processed']++;
                            } else {
                                $pickDetail['player_name'] = $pick['player']['name'];
                                $pickDetail['reason'] = 'Player name mismatch';
                                $teamResults['picks_skipped']++;
                            }
                        } elseif (is_string($pick['player'])) {
                            if (strtolower($pick['player']) === strtolower($playerName)) {
                                $hero = $pick['hero'];
                                $pickDetail['player_name'] = $pick['player'];
                                $pickDetail['processed'] = true;
                                $pickDetail['reason'] = 'Player string match';
                                
                                if (!isset($heroStats[$hero])) {
                                    $heroStats[$hero] = ['win' => 0, 'lose' => 0, 'total' => 0];
                                }
                                $heroStats[$hero]['total']++;
                                if ($isWin) {
                                    $heroStats[$hero]['win']++;
                                } else {
                                    $heroStats[$hero]['lose']++;
                                }
                                
                                $teamResults['picks_processed']++;
                            } else {
                                $pickDetail['player_name'] = $pick['player'];
                                $pickDetail['reason'] = 'Player string mismatch';
                                $teamResults['picks_skipped']++;
                            }
                        } else {
                            $pickDetail['reason'] = 'Unrecognized player format';
                            $teamResults['picks_skipped']++;
                        }
                    } else {
                        $pickDetail['reason'] = 'Missing player assignment';
                        $teamResults['picks_skipped']++;
                    }
                } else {
                    $pickDetail['reason'] = 'Invalid pick format';
                    $teamResults['picks_skipped']++;
                }
                
                $teamResults['picks_details'][] = $pickDetail;
            }
            
            $testResults[] = $teamResults;
        }
        
        return response()->json([
            'playerName' => $playerName,
            'activeTeamId' => $activeTeamId,
            'teamName' => $teamName,
            'testResults' => $testResults,
            'finalHeroStats' => $heroStats,
            'totalPicksProcessed' => array_sum(array_column($testResults, 'picks_processed')),
            'totalPicksSkipped' => array_sum(array_column($testResults, 'picks_skipped'))
        ]);
    }

    /**
     * Store a newly created player
     */
    public function store(Request $request)
    {
        \Log::info('Creating player with data:', $request->all());
        
        $request->validate([
            'name' => 'required|string|max:255',
            'role' => 'required|string|max:255',
            'team_id' => 'required|integer|exists:teams,id'
        ]);

        try {
            // Check if player already exists in the team
            $existingPlayer = Player::where('name', $request->name)
                                  ->where('team_id', $request->team_id)
                                  ->first();
            
            if ($existingPlayer) {
                return response()->json([
                    'error' => 'Player already exists in this team',
                    'existing_player' => $existingPlayer
                ], 409);
            }

            // Validate role format
            $validRoles = ['exp', 'mid', 'jungler', 'gold', 'roam', 'substitute'];
            if (!in_array(strtolower($request->role), $validRoles)) {
                return response()->json([
                    'error' => 'Invalid role. Must be one of: ' . implode(', ', $validRoles)
                ], 422);
            }

            $player = Player::create([
                'name' => trim($request->name),
                'role' => strtolower(trim($request->role)),
                'team_id' => $request->team_id
            ]);

            // Load the team relationship
            $player->load('team');

            \Log::info('Player created successfully:', $player->toArray());
            return response()->json([
                'message' => 'Player created successfully',
                'player' => $player
            ], 201);
        } catch (\Exception $e) {
            \Log::error('Error creating player: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json(['error' => 'Failed to create player: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Update the specified player
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'role' => 'sometimes|string|max:255',
            'team_id' => 'sometimes|integer|exists:teams,id'
        ]);

        try {
            $player = Player::findOrFail($id);
            
            // Check if player belongs to active team
            $activeTeamId = session('active_team_id');
            if (!$activeTeamId) {
                $activeTeamId = request()->header('X-Active-Team-ID');
            }
            
            if ($activeTeamId && $player->team_id != $activeTeamId) {
                return response()->json(['error' => 'Player not found in active team'], 404);
            }

            // Check for duplicate names if name is being updated
            if ($request->has('name') && $request->name !== $player->name) {
                $existingPlayer = Player::where('name', $request->name)
                                      ->where('team_id', $player->team_id)
                                      ->where('id', '!=', $id)
                                      ->first();
                
                if ($existingPlayer) {
                    return response()->json([
                        'error' => 'Another player with this name already exists in the team'
                    ], 409);
                }
            }

            // Validate role format if role is being updated
            if ($request->has('role')) {
                $validRoles = ['exp', 'mid', 'jungler', 'gold', 'roam', 'substitute'];
                if (!in_array(strtolower($request->role), $validRoles)) {
                    return response()->json([
                        'error' => 'Invalid role. Must be one of: ' . implode(', ', $validRoles)
                    ], 422);
                }
            }

            // Update only the provided fields
            $updateData = [];
            if ($request->has('name')) {
                $updateData['name'] = trim($request->name);
            }
            if ($request->has('role')) {
                $updateData['role'] = strtolower(trim($request->role));
            }
            if ($request->has('team_id')) {
                $updateData['team_id'] = $request->team_id;
            }

            $player->update($updateData);
            
            // Load the team relationship
            $player->load('team');
            
            return response()->json([
                'message' => 'Player updated successfully',
                'player' => $player
            ]);
        } catch (\Exception $e) {
            \Log::error('Error updating player: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to update player: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified player (hard delete - permanently removes from database)
     */
    public function destroy($id)
    {
        try {
            \Log::info("Attempting to hard delete player with ID: {$id}");
            
            $player = Player::findOrFail($id);
            \Log::info("Found player: {$player->name} (ID: {$player->id}, Team ID: {$player->team_id})");
            
            // Check if player belongs to active team
            $activeTeamId = session('active_team_id');
            if (!$activeTeamId) {
                $activeTeamId = request()->header('X-Active-Team-ID');
            }
            
            \Log::info("Active team ID: {$activeTeamId}");
            
            if ($activeTeamId && $player->team_id != $activeTeamId) {
                \Log::warning("Player team ID ({$player->team_id}) doesn't match active team ID ({$activeTeamId})");
                return response()->json(['error' => 'Player not found in active team'], 404);
            }

            // Check if player has any match assignments
            $hasMatchAssignments = $player->matchAssignments()->exists();
            if ($hasMatchAssignments) {
                $matchCount = $player->matchAssignments()->count();
                \Log::warning("Cannot delete player {$player->name} - has {$matchCount} match assignments");
                return response()->json([
                    'error' => 'Cannot delete player with match history. Consider marking as inactive instead.',
                    'match_count' => $matchCount
                ], 422);
            }

            $playerName = $player->name;
            $playerTeamId = $player->team_id;
            
            // Use database transaction for hard delete
            \DB::beginTransaction();
            
            try {
                // Option 1: Explicitly delete related records first
                // Uncomment these lines if you want explicit control over related deletions
                
                // Delete player stats (PlayerStat table uses player_name string, not foreign key)
                // We'll handle this manually if needed
                \Log::info("Player stats are stored by player_name string, not foreign key - skipping explicit deletion");
                
                // Delete lane assignments (same as matchAssignments)
                $assignmentsCount = $player->matchAssignments()->count();
                if ($assignmentsCount > 0) {
                    \Log::info("Deleting {$assignmentsCount} lane assignments for player {$playerName}");
                    $player->matchAssignments()->delete();
                }
                
                // Delete match players (same as matchAssignments)
                $matchPlayersCount = $player->matchAssignments()->count();
                if ($matchPlayersCount > 0) {
                    \Log::info("Deleting {$matchPlayersCount} match player records for player {$playerName}");
                    $player->matchAssignments()->delete();
                }
                
                // Notes don't have a player relationship, so no need to delete them
                \Log::info("Notes table doesn't have player relationship - skipping");
                
                // Perform the hard delete (regular delete since no SoftDeletes trait)
                $deleted = $player->delete();
                
                if ($deleted) {
                    \Log::info("Player '{$playerName}' (ID: {$id}) hard deleted successfully from team {$playerTeamId}");
                    
                    // Verify deletion by trying to find the player again
                    $stillExists = Player::find($id);
                    if ($stillExists) {
                        \Log::error("Player {$playerName} still exists after hard deletion attempt!");
                        \DB::rollBack();
                        return response()->json(['error' => 'Player hard deletion failed - player still exists in database'], 500);
                    }
                    
                    // Commit the transaction
                    \DB::commit();
                    
                    return response()->json([
                        'message' => 'Player Removed Successfully',
                        'deleted_player' => [
                            'id' => $id,
                            'name' => $playerName,
                            'team_id' => $playerTeamId
                        ]
                    ], 200);
                } else {
                    \Log::error("Player hard deletion returned false for player {$playerName}");
                    \DB::rollBack();
                    return response()->json(['error' => 'Player hard deletion failed'], 500);
                }
                
            } catch (\Exception $e) {
                // Rollback transaction on any error
                \DB::rollBack();
                \Log::error("Error during hard deletion transaction for player {$playerName}: " . $e->getMessage());
                throw $e; // Re-throw to be caught by outer catch block
            }
            
        } catch (\Exception $e) {
            \Log::error('Error hard deleting player: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            
            // Check if it's a foreign key constraint error
            if (str_contains($e->getMessage(), 'foreign key constraint')) {
                return response()->json([
                    'error' => 'Cannot delete player due to existing related records. Please remove related data first.',
                    'details' => 'Foreign key constraint violation'
                ], 422);
            }
            
            return response()->json(['error' => 'Failed to hard delete player: ' . $e->getMessage()], 500);
        }
    }
}
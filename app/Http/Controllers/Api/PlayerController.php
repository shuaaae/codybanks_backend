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

        return response()->json(['error' => 'Player not found or no photo'], 404);
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
        
        // CRITICAL FIX: Always filter by the current team's ID to prevent data mixing
        // Get match_teams joined with matches, filtered by the current team ID
        $query = \App\Models\MatchTeam::with('match');
        
        // Always filter by the current team ID to ensure data isolation
        if ($activeTeamId) {
            $query->whereHas('match', function($q) use ($activeTeamId, $matchType) {
                $q->where('team_id', $activeTeamId)
                  ->where('match_type', $matchType);
            });
        } else {
            // If no active team ID, return empty results to prevent data leakage
            \Log::warning("No active team ID found, returning empty stats to prevent data mixing");
            return response()->json([]);
        }
        
        // Additional filter by team name if provided (for extra safety)
        if ($teamName) {
            $query->where('team', $teamName);
        }
        
        $matchTeams = $query->get();
        
        // Debug: Log all match teams
        \Log::info("Found match teams", [
            'count' => $matchTeams->count(),
            'teams' => $matchTeams->map(function($team) {
                return [
                    'team' => $team->team,
                    'picks1' => $team->picks1,
                    'picks2' => $team->picks2
                ];
            })
        ]);
        
        // CRITICAL: Log the exact data structure being processed
        \Log::info("Raw match data for player {$playerName}:", [
            'playerName' => $playerName,
            'activeTeamId' => $activeTeamId,
            'teamName' => $teamName,
            'matchTeamsData' => $matchTeams->toArray()
        ]);
        
        $heroStats = [];

        foreach ($matchTeams as $team) {
            $match = $team->match;
            if (!$match) continue; // Skip if no match
            
            // Double-check that this match belongs to the current team
            if ($match->team_id != $activeTeamId) {
                \Log::warning("Match {$match->id} does not belong to current team {$activeTeamId}, skipping");
                continue;
            }
            
            $isWin = $team->team === $match->winner;
            // Combine picks1 and picks2
            $picks = array_merge($team->picks1 ?? [], $team->picks2 ?? []);
            
            // Debug logging for picks
            \Log::info("Processing picks for player {$playerName} in team {$team->team}", [
                'match_id' => $match->id,
                'is_win' => $isWin,
                'picks_count' => count($picks),
                'picks_sample' => array_slice($picks, 0, 3) // Log first 3 picks
            ]);
            
            // CRITICAL: Log all picks to see the exact data structure
            \Log::info("All picks for team {$team->team}:", [
                'picks1' => $team->picks1,
                'picks2' => $team->picks2,
                'merged_picks' => $picks
            ]);
            
            foreach ($picks as $pick) {
                // Check if this pick matches the specific player, not just the role
                if (is_array($pick) && isset($pick['hero']) && isset($pick['lane'])) {
                    $hero = $pick['hero'];
                    $pickPlayerName = null;
                    
                    // Extract player name from various possible data structures
                    if (isset($pick['player'])) {
                        if (is_object($pick['player']) && isset($pick['player']['name'])) {
                            $pickPlayerName = $pick['player']['name'];
                        } elseif (is_string($pick['player'])) {
                            $pickPlayerName = $pick['player'];
                        } elseif (is_array($pick['player']) && isset($pick['player']['name'])) {
                            $pickPlayerName = $pick['player']['name'];
                        }
                    }
                    
                    // Also check for direct player name in pick (fallback for older data)
                    if (!$pickPlayerName && isset($pick['player_name'])) {
                        $pickPlayerName = $pick['player_name'];
                    }
                    
                    // Check if this pick belongs to the requested player
                    if ($pickPlayerName && strtolower($pickPlayerName) === strtolower($playerName)) {
                        if (!isset($heroStats[$hero])) {
                            $heroStats[$hero] = ['win' => 0, 'lose' => 0, 'total' => 0];
                        }
                        $heroStats[$hero]['total']++;
                        if ($isWin) {
                            $heroStats[$hero]['win']++;
                        } else {
                            $heroStats[$hero]['lose']++;
                        }
                        
                        // Log successful match for debugging
                        \Log::info("Player stats match found", [
                            'playerName' => $playerName,
                            'pickPlayerName' => $pickPlayerName,
                            'hero' => $hero,
                            'match_id' => $match->id,
                            'is_win' => $isWin
                        ]);
                    } else {
                        // Log skipped pick for debugging
                        \Log::debug("Pick skipped - player mismatch", [
                            'requestedPlayer' => $playerName,
                            'pickPlayerName' => $pickPlayerName,
                            'pick' => $pick,
                            'match_id' => $match->id
                        ]);
                    }
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
        \Log::info("Final hero stats for player {$playerName} in heroStatsByTeam", [
            'playerName' => $playerName,
            'totalHeroes' => count($result),
            'heroes' => $result,
            'activeTeamId' => $activeTeamId
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
        
        // CRITICAL FIX: Always filter by the current team's ID to prevent data mixing
        // Filter by team name if provided, but always ensure matches belong to current team
        $query = \App\Models\MatchTeam::with('match');
        
        // Always filter by the current team ID to ensure data isolation
        if ($activeTeamId) {
            $query->whereHas('match', function($q) use ($activeTeamId, $matchType) {
                $q->where('team_id', $activeTeamId)
                  ->where('match_type', $matchType);
            });
        } else {
            // If no active team ID, return empty results to prevent data leakage
            \Log::warning("No active team ID found for H2H stats, returning empty results to prevent data mixing");
            return response()->json([]);
        }
        
        // Additional filter by team name if provided (for extra safety)
        if ($teamName) {
            $query->where('team', $teamName);
        }
        
        $matchTeams = $query->get();
        
        \Log::info("Found match teams for H2H", [
            'count' => $matchTeams->count(),
            'teams' => $matchTeams->pluck('team')->toArray()
        ]);
        
        $h2hStats = [];

        foreach ($matchTeams as $team) {
            $match = $team->match;
            if (!$match) continue;
            
            // Double-check that this match belongs to the current team
            if ($match->team_id != $activeTeamId) {
                \Log::warning("Match {$match->id} does not belong to current team {$activeTeamId} for H2H stats, skipping");
                continue;
            }
            
            $isWin = $team->team === $match->winner;
            
            // Find the enemy team in the same match
            $enemyTeam = $match->teams->first(function($t) use ($team) {
                return $t->id !== $team->id;
            });
            if (!$enemyTeam) continue;
            
            // Combine picks1 and picks2 for both teams
            $picks = array_merge($team->picks1 ?? [], $team->picks2 ?? []);
            $enemyPicks = array_merge($enemyTeam->picks1 ?? [], $enemyTeam->picks2 ?? []);
            
            foreach ($picks as $pick) {
                // Check if this pick matches the specific player, not just the role
                if (is_array($pick) && isset($pick['hero']) && isset($pick['lane'])) {
                    $pickPlayerName = null;
                    
                    // Extract player name from various possible data structures
                    if (isset($pick['player'])) {
                        if (is_object($pick['player']) && isset($pick['player']['name'])) {
                            $pickPlayerName = $pick['player']['name'];
                        } elseif (is_string($pick['player'])) {
                            $pickPlayerName = $pick['player'];
                        } elseif (is_array($pick['player']) && isset($pick['player']['name'])) {
                            $pickPlayerName = $pick['player']['name'];
                        }
                    }
                    
                    // Also check for direct player name in pick (fallback for older data)
                    if (!$pickPlayerName && isset($pick['player_name'])) {
                        $pickPlayerName = $pick['player_name'];
                    }
                    
                    // Check if this pick belongs to the requested player
                    if (!$pickPlayerName || strtolower($pickPlayerName) !== strtolower($playerName)) {
                        // Log skipped pick for debugging
                        \Log::debug("H2H Pick skipped - player mismatch", [
                            'requestedPlayer' => $playerName,
                            'pickPlayerName' => $pickPlayerName,
                            'pick' => $pick,
                            'match_id' => $match->id
                        ]);
                        continue;
                    }
                    
                    $playerHero = $pick['hero'];
                    $lane = $pick['lane'];
                    
                    // Find enemy hero in the same lane
                    $enemyPick = null;
                    foreach ($enemyPicks as $ep) {
                        if (is_array($ep) && isset($ep['hero']) && isset($ep['lane']) && strtolower($ep['lane']) === strtolower($lane)) {
                            $enemyPick = $ep;
                            break;
                        }
                    }
                    
                    if (!$enemyPick) continue;
                    
                    $enemyHero = $enemyPick['hero'];
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
                    
                    // Log successful H2H match for debugging
                    \Log::info("H2H Player stats match found", [
                        'playerName' => $playerName,
                        'pickPlayerName' => $pickPlayerName,
                        'playerHero' => $playerHero,
                        'enemyHero' => $enemyHero,
                        'match_id' => $match->id,
                        'is_win' => $isWin
                    ]);
                }
            }
        }
        
        // Calculate winrate
        $result = [];
        foreach ($h2hStats as $stat) {
            $rate = $stat['total'] > 0 ? round($stat['win'] / $stat['total'] * 100) : 0;
            $result[] = array_merge($stat, ['winrate' => $rate]);
        }
        // Sort by total desc
        usort($result, function($a, $b) { return $b['total'] <=> $a['total']; });
        
        // Log final results for debugging
        \Log::info("Final H2H stats for player {$playerName} in heroH2HStatsByTeam", [
            'playerName' => $playerName,
            'totalMatchups' => count($result),
            'matchups' => $result,
            'activeTeamId' => $activeTeamId
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
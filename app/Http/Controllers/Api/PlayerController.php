<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Player;

class PlayerController extends Controller
{
    public function uploadPhoto(Request $request, $playerId)
    {
        $request->validate([
            'photo' => 'required|image|max:2048', // 2MB max
        ]);

        // Get the active team ID from session
        $activeTeamId = session('active_team_id');
        
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

        // Get the active team ID from session
        $activeTeamId = session('active_team_id');

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

        // Get the active team ID from session
        $activeTeamId = session('active_team_id');

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

    public function index()
    {
        // Get the active team ID from session
        $activeTeamId = session('active_team_id');
        
        // Return only players for the active team
        return Player::where('team_id', $activeTeamId)->get();
    }

    public function heroStats($playerName)
    {
        // Get the active team ID from session
        $activeTeamId = session('active_team_id');
        
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
            foreach ($picks as $pick) {
                // Support both {hero, player} and just hero string
                if (is_array($pick) && isset($pick['hero']) && isset($pick['player']) && strtolower($pick['player']) === strtolower($playerName)) {
                    $hero = $pick['hero'];
                } elseif (is_string($pick) && strtolower($pick) === strtolower($playerName)) {
                    $hero = $pick;
                } else {
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
        return response()->json($result);
    }

    public function heroStatsByTeam(Request $request, $playerName)
    {
        // Get the active team ID from session
        $activeTeamId = session('active_team_id');
        $teamName = $request->query('teamName');
        $role = $request->query('role'); // Get role parameter for unique player identification
        
        // Debug logging
        \Log::info("Player stats request", [
            'playerName' => $playerName,
            'activeTeamId' => $activeTeamId,
            'teamName' => $teamName,
            'role' => $role
        ]);
        
        // Get match_teams joined with matches, filtered by team name or active team
        $query = \App\Models\MatchTeam::with('match');
        
        if ($teamName) {
            // Filter by team name if provided
            $query->where('team', $teamName);
        } else if ($activeTeamId) {
            // Fallback to session team ID
            $query->whereHas('match', function($q) use ($activeTeamId) {
                $q->where('team_id', $activeTeamId);
            });
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
        
        $heroStats = [];

        foreach ($matchTeams as $team) {
            $match = $team->match;
            if (!$match) continue; // Skip if no match
            
            $isWin = $team->team === $match->winner;
            // Combine picks1 and picks2
            $picks = array_merge($team->picks1 ?? [], $team->picks2 ?? []);
            
            foreach ($picks as $pick) {
                // Check if this pick matches the player's role/lane
                if (
                    is_array($pick) &&
                    isset($pick['hero']) &&
                    isset($pick['lane']) &&
                    (!$role || strtolower($pick['lane']) === strtolower($role))
                ) {
                    $hero = $pick['hero'];
                    
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
        return response()->json($result);
    }

    public function heroH2HStatsByTeam(Request $request, $playerName)
    {
        $teamName = $request->query('teamName');
        $role = $request->query('role'); // Get role parameter for unique player identification
        
        // Debug logging
        \Log::info("Player H2H stats request", [
            'playerName' => $playerName,
            'teamName' => $teamName,
            'role' => $role
        ]);
        
        // Filter by team name if provided
        $query = \App\Models\MatchTeam::with('match');
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
                if (
                    is_array($pick) &&
                    isset($pick['hero']) &&
                    isset($pick['lane']) &&
                    (!$role || strtolower($pick['lane']) === strtolower($role))
                ) {
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
        return response()->json($result);
    }
}
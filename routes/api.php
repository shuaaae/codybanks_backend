<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\GameMatchController;
use App\Http\Controllers\MatchTeamController;
use App\Http\Controllers\Api\HeroController;
use App\Http\Controllers\Api\PlayerController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\NotesController;
use App\Http\Controllers\Api\DraftController;
use App\Http\Controllers\Api\MobadraftController;

// Test routes
Route::get('/test', function () {
    return response()->json(['message' => 'API is working']);
});

// Hero image route to serve images from local storage
Route::get('/hero-image/{role}/{image}', function ($role, $image) {
    try {
        // URL decode the image filename to handle spaces and special characters
        $decodedImage = urldecode($image);
        
        // Build the local file path
        $imagePath = public_path("heroes/{$role}/{$decodedImage}");
        
        // Check if the file exists
        if (!file_exists($imagePath)) {
            \Log::warning("Hero image not found: {$imagePath}");
            return response()->json(['error' => 'Image not found'], 404);
        }
        
        // Get the image info
        $imageInfo = @getimagesize($imagePath);
        $mimeType = $imageInfo['mime'] ?? 'image/webp';
        
        // Return the image with optimized headers
        return response()->file($imagePath, [
            'Content-Type' => $mimeType,
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET',
            'Access-Control-Allow-Headers' => 'Content-Type',
            'Cache-Control' => 'public, max-age=86400, immutable', // Cache for 24 hours
            'ETag' => md5_file($imagePath), // Enable ETag for better caching
            'Last-Modified' => gmdate('D, d M Y H:i:s', filemtime($imagePath)) . ' GMT'
        ]);
    } catch (\Exception $e) {
        // Log the error for debugging but don't expose it to client
        \Log::error("Hero image error: " . $e->getMessage(), [
            'role' => $role,
            'image' => $image,
            'path' => $imagePath ?? 'unknown'
        ]);
        return response()->json(['error' => 'Failed to load image'], 500);
    }
});

// User photo route to serve images from local storage
Route::get('/user-photo/{filename}', function ($filename) {
    try {
        // Build the local file path
        $imagePath = public_path("users/{$filename}");
        
        // Check if the file exists
        if (!file_exists($imagePath)) {
            \Log::warning("User photo not found: {$imagePath}");
            return response()->json(['error' => 'Image not found'], 404);
        }
        
        // Get the image info
        $imageInfo = @getimagesize($imagePath);
        $mimeType = $imageInfo['mime'] ?? 'image/jpeg';
        
        // Return the image with optimized headers
        return response()->file($imagePath, [
            'Content-Type' => $mimeType,
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET',
            'Access-Control-Allow-Headers' => 'Content-Type',
            'Cache-Control' => 'public, max-age=86400, immutable', // Cache for 24 hours
            'ETag' => md5_file($imagePath), // Enable ETag for better caching
            'Last-Modified' => gmdate('D, d M Y H:i:s', filemtime($imagePath)) . ' GMT'
        ]);
    } catch (\Exception $e) {
        // Log the error for debugging but don't expose it to client
        \Log::error("User photo error: " . $e->getMessage(), [
            'filename' => $filename,
            'path' => $imagePath ?? 'unknown'
        ]);
        return response()->json(['error' => 'Failed to load image'], 500);
    }
});

// Player photo route to serve images from local storage
Route::get('/player-photo/{filename}', function ($filename) {
    try {
        // URL decode the filename to handle spaces and special characters
        $decodedFilename = urldecode($filename);
        
        // Build the local file path
        $imagePath = public_path("players/{$decodedFilename}");
        
        // Check if the file exists
        if (!file_exists($imagePath)) {
            \Log::warning("Player photo not found: {$imagePath}");
            return response()->json(['error' => 'Image not found'], 404);
        }
        
        // Get the image info
        $imageInfo = @getimagesize($imagePath);
        $mimeType = $imageInfo['mime'] ?? 'image/jpeg';
        
        // Return the image with optimized headers
        return response()->file($imagePath, [
            'Content-Type' => $mimeType,
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET',
            'Access-Control-Allow-Headers' => 'Content-Type',
            'Cache-Control' => 'public, max-age=86400, immutable', // Cache for 24 hours
            'ETag' => md5_file($imagePath), // Enable ETag for better caching
            'Last-Modified' => gmdate('D, d M Y H:i:s', filemtime($imagePath)) . ' GMT'
        ]);
    } catch (\Exception $e) {
        // Log the error for debugging but don't expose it to client
        \Log::error("Player photo error: " . $e->getMessage(), [
            'filename' => $filename,
            'path' => $imagePath ?? 'unknown'
        ]);
        return response()->json(['error' => 'Failed to load image'], 500);
    }
});

// Team logo route to serve images from storage
Route::get('/team-logo/{filename}', function ($filename) {
    try {
        // Build the storage file path
        $imagePath = storage_path("app/public/teams/{$filename}");
        
        // Check if the file exists
        if (!file_exists($imagePath)) {
            \Log::warning("Team logo not found: {$imagePath}");
            return response()->json(['error' => 'Image not found'], 404);
        }
        
        // Get the image info
        $imageInfo = @getimagesize($imagePath);
        $mimeType = $imageInfo['mime'] ?? 'image/png';
        
        // Return the image with optimized headers
        return response()->file($imagePath, [
            'Content-Type' => $mimeType,
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET',
            'Access-Control-Allow-Headers' => 'Content-Type',
            'Cache-Control' => 'public, max-age=86400, immutable', // Cache for 24 hours
            'ETag' => md5_file($imagePath), // Enable ETag for better caching
            'Last-Modified' => gmdate('D, d M Y H:i:s', filemtime($imagePath)) . ' GMT'
        ]);
    } catch (\Exception $e) {
        // Log the error for debugging but don't expose it to client
        \Log::error("Team logo error: " . $e->getMessage(), [
            'filename' => $filename,
            'path' => $imagePath ?? 'unknown'
        ]);
        return response()->json(['error' => 'Failed to load image'], 500);
    }
});

Route::get('/test-delete/{id}', function ($id) {
    return response()->json([
        'message' => 'Delete test endpoint working',
        'player_id' => $id,
        'timestamp' => now()->toISOString()
    ]);
});

// Debug route for player deletion testing
Route::delete('/test-delete-player/{id}', function ($id) {
    try {
        $player = \App\Models\Player::find($id);
        if (!$player) {
            return response()->json(['error' => 'Player not found'], 404);
        }
        
        // Check for related records
        $relatedData = [
            'match_assignments' => $player->matchAssignments()->count(),
            'player_stats' => method_exists($player, 'playerStats') ? $player->playerStats()->count() : 0,
            'notes' => method_exists($player, 'notes') ? $player->notes()->count() : 0,
        ];
        
        // Try to delete
        $deleted = $player->delete();
        
        return response()->json([
            'message' => 'Test deletion completed',
            'player_id' => $id,
            'player_name' => $player->name,
            'related_data_before_deletion' => $relatedData,
            'deletion_successful' => $deleted,
            'player_still_exists' => \App\Models\Player::find($id) ? true : false
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'error' => 'Test deletion failed: ' . $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 500);
    }
});

// Authentication routes (no middleware - accessible to everyone)
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/logout', [AuthController::class, 'logout']);
Route::get('/auth/me', [AuthController::class, 'me']);
Route::get('/auth/profile/{id}', [AuthController::class, 'profile']);
Route::post('/auth/upload-photo', [AuthController::class, 'uploadPhoto']);
Route::delete('/auth/delete-photo', [AuthController::class, 'deletePhoto']);

// Match Player Assignment routes (moved outside middleware for CORS)
Route::options('/match-player-assignments/match/{match_id}', function () {
    return response('', 200)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
});
// Move assign route inside middleware group to prevent redirects
Route::get('/match-player-assignments/match/{match_id}', [App\Http\Controllers\Api\MatchPlayerAssignmentController::class, 'getMatchAssignments']);
Route::options('/match-player-assignments/available-players', function () {
    return response('', 200)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
});
Route::get('/match-player-assignments/available-players', [App\Http\Controllers\Api\MatchPlayerAssignmentController::class, 'getAvailablePlayers']);
Route::options('/match-player-assignments/update-substitute', function () {
    return response('', 200)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
});
Route::put('/match-player-assignments/update-substitute', [App\Http\Controllers\Api\MatchPlayerAssignmentController::class, 'updateSubstituteInfo']);
Route::options('/match-player-assignments/player-stats', function () {
    return response('', 200)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
});
Route::get('/match-player-assignments/player-stats', [App\Http\Controllers\Api\MatchPlayerAssignmentController::class, 'getPlayerMatchStats']);
Route::options('/match-player-assignments/update-hero', function () {
    return response('', 200)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
});
// Test routes to verify API is working
Route::get('/test-update-hero', function () {
    return response()->json(['message' => 'Update hero endpoint is accessible'])->header('Access-Control-Allow-Origin', '*');
});

Route::get('/test-assign', function () {
    return response()->json(['message' => 'Assign endpoint is accessible'])->header('Access-Control-Allow-Origin', '*');
});

Route::put('/test-update-hero-put', function () {
    return response()->json(['message' => 'Update hero PUT endpoint is accessible'])->header('Access-Control-Allow-Origin', '*');
});

// Preferred route using assignment_id (more reliable)
Route::put('/match-player-assignments/{assignment}/hero', function (Request $request, \App\Models\MatchPlayerAssignment $assignment) {
    \Log::info('HIT assignment-specific update-hero', [
        'assignment_id' => $assignment->id,
        'payload' => $request->all()
    ]);

    try {
        $request->validate([
            'new_hero_name' => 'required|string|max:255'
        ]);

        $assignment->update(['hero_name' => $request->new_hero_name]);

        \Log::info('Assignment updated via ID route', [
            'assignment_id' => $assignment->id,
            'new_hero_name' => $request->new_hero_name
        ]);

        return response()->json([
            'message' => 'Hero updated',
            'assignment' => $assignment->fresh()
        ])->header('Access-Control-Allow-Origin', '*')
          ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
          ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');

    } catch (\Exception $e) {
        \Log::error('Assignment-specific update error', [
            'assignment_id' => $assignment->id,
            'error' => $e->getMessage()
        ]);

        return response()->json([
            'error' => 'Failed to update hero',
            'message' => $e->getMessage()
        ], 500)->header('Access-Control-Allow-Origin', '*')
          ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
          ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
    }
});

// Update-hero route outside middleware group for direct access
Route::options('/match-player-assignments/update-hero', function () {
    return response('', 200)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
});

// Direct route for update-hero that bypasses potential controller issues
Route::put('/match-player-assignments/update-hero', function (Request $request) {
    \Log::info('HIT update-hero', [
        'payload' => $request->all(),
        'headers' => $request->headers->all(),
        'method' => $request->method(),
        'url' => $request->fullUrl()
    ]);

    try {
        // Validate required fields
        $request->validate([
            'match_id' => 'required|exists:matches,id',
            'team_id' => 'required|exists:teams,id',
            'player_id' => 'required|exists:players,id',
            'role' => 'required|string',
            'old_hero_name' => 'nullable|string',
            'new_hero_name' => 'required|string|max:255'
        ]);

        $matchId = $request->input('match_id');
        $teamId = $request->input('team_id');
        $playerId = $request->input('player_id');
        $role = $request->input('role');
        $oldHeroName = $request->input('old_hero_name');
        $newHeroName = $request->input('new_hero_name');

        // Normalize role names
        $roleMap = [
            'xp' => 'exp',
            'exp' => 'exp',
            'mid' => 'mid',
            'jungle' => 'jungler',
            'jungler' => 'jungler',
            'gold' => 'gold',
            'gold lane' => 'gold',
            'roam' => 'roam',
            'roamer' => 'roam'
        ];
        
        $normalizedRole = strtolower(trim($role));
        $canonicalRole = $roleMap[$normalizedRole] ?? $normalizedRole;
        
        \Log::info('Role normalization', [
            'original_role' => $role,
            'normalized_role' => $normalizedRole,
            'canonical_role' => $canonicalRole
        ]);

        // Try to find existing assignment with strict criteria first
        $assignment = \App\Models\MatchPlayerAssignment::where('match_id', $matchId)
            ->where('player_id', $playerId)
            ->where('role', $canonicalRole)
            ->whereHas('player', function($query) use ($teamId) {
                $query->where('team_id', $teamId);
            })
            ->first();

        \Log::info('Assignment lookup result', [
            'found' => $assignment ? true : false,
            'assignment_id' => $assignment ? $assignment->id : null,
            'criteria' => [
                'match_id' => $matchId,
                'player_id' => $playerId,
                'role' => $canonicalRole,
                'team_id' => $teamId
            ]
        ]);

        // If not found, try upsert approach
        if (!$assignment) {
            \Log::info('Assignment not found, trying upsert approach');
            
            $assignment = \App\Models\MatchPlayerAssignment::firstOrNew([
                'match_id' => $matchId,
                'player_id' => $playerId,
                'role' => $canonicalRole,
            ]);
            
            // Set additional fields if creating new
            if (!$assignment->exists) {
                $assignment->is_starting_lineup = true;
                $assignment->substitute_order = null;
                $assignment->notes = null;
            }
        }

        // Update the hero name
        $assignment->hero_name = $newHeroName;
        $assignment->save();

        \Log::info('Assignment updated successfully', [
            'assignment_id' => $assignment->id,
            'new_hero_name' => $newHeroName
        ]);

        return response()->json([
            'message' => 'Hero assignment updated successfully',
            'assignment' => $assignment->fresh()
        ])->header('Access-Control-Allow-Origin', '*')
          ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
          ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');

    } catch (\Exception $e) {
        \Log::error('Update-hero error', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'payload' => $request->all()
        ]);
        
        return response()->json([
            'error' => 'Failed to update hero assignment',
            'message' => $e->getMessage()
        ], 500)->header('Access-Control-Allow-Origin', '*')
          ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
          ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
    }
});

// Move assign route outside middleware group to ensure CORS headers work
Route::options('/match-player-assignments/assign', function () {
    return response('', 200)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
});

Route::post('/match-player-assignments/assign', function (Request $request) {
    \Log::info('HIT assign route', [
        'payload' => $request->all(),
        'method' => $request->method(),
        'url' => $request->fullUrl()
    ]);
    
    try {
        // Validate that assignments array is not empty before calling controller
        $assignments = $request->input('assignments', []);
        if (empty($assignments)) {
            \Log::warning('Empty assignments array received', [
                'payload' => $request->all()
            ]);
            
            return response()->json([
                'error' => 'No assignments provided',
                'message' => 'The assignments array cannot be empty. Please ensure players are assigned to lanes before exporting.',
                'suggestion' => 'Make sure to assign players to each lane in the draft before exporting the match.'
            ], 400)->header('Access-Control-Allow-Origin', '*')
              ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
              ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        }
        
        return app(App\Http\Controllers\Api\MatchPlayerAssignmentController::class)->assignPlayers($request);
    } catch (\Exception $e) {
        \Log::error('Assign route error', [
            'error' => $e->getMessage(),
            'payload' => $request->all()
        ]);
        
        return response()->json([
            'error' => 'Failed to assign players',
            'message' => $e->getMessage()
        ], 500)->header('Access-Control-Allow-Origin', '*')
          ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
          ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
    }
});

Route::middleware('api')->group(function () {
    
    // Remove update-hero route from middleware group - will add outside
    Route::apiResource('match-teams', MatchTeamController::class);
Route::get('/heroes', [HeroController::class, 'index']);
Route::post('/teams/history', [App\Http\Controllers\Api\HeroController::class, 'storeTeamHistory']);
Route::get('/teams/history', [App\Http\Controllers\Api\HeroController::class, 'getTeamHistory']);
Route::get('/players', [PlayerController::class, 'index']);
Route::post('/players', [PlayerController::class, 'store']);
Route::put('/players/{id}', [PlayerController::class, 'update']);
Route::delete('/players/{id}', [PlayerController::class, 'destroy']);
Route::get('/players/{playerName}/hero-stats', [PlayerController::class, 'heroStats']);
});

// Player routes (moved outside api middleware for proper CRUD operations)
Route::get('/players', [PlayerController::class, 'index']);
Route::post('/players', [PlayerController::class, 'store']);
Route::get('/players/{id}', [PlayerController::class, 'show']);
Route::put('/players/{id}', [PlayerController::class, 'update']);
Route::delete('/players/{id}', [PlayerController::class, 'destroy']);
Route::get('/players/{playerName}/hero-stats', [PlayerController::class, 'heroStats']);
Route::get('/players/{playerName}/hero-stats-by-team', [PlayerController::class, 'heroStatsByTeam']);
Route::get('/players/{playerName}/hero-h2h-stats-by-team', [PlayerController::class, 'heroH2HStatsByTeam']);

// Team routes (with session support)
Route::middleware('enable-sessions')->group(function () {
    // Matches endpoints (moved here to access session data)
    Route::apiResource('matches', GameMatchController::class);
    Route::get('/matches/{id}/sync', [GameMatchController::class, 'show']);
    
    Route::get('/teams', [TeamController::class, 'index']);
    Route::get('/teams/check-exists', [TeamController::class, 'checkTeamsExist']);
    Route::get('/teams/active', [TeamController::class, 'getActiveTeam']); // MUST come before /teams/{id}
    Route::get('/teams/active-multi-device', [TeamController::class, 'getActiveTeamMultiDevice']); // Multi-device support
    Route::get('/teams/{id}', [TeamController::class, 'show']);
    Route::post('/teams', [TeamController::class, 'store']);
    Route::post('/teams/set-active', [TeamController::class, 'setActive']);
    Route::post('/teams/check-availability', [TeamController::class, 'checkAvailability']);
    Route::post('/teams/check-my-session', [TeamController::class, 'checkMySession']);
    Route::get('/teams/debug', [TeamController::class, 'debug']);
    Route::post('/teams/upload-logo', [TeamController::class, 'uploadLogo']);
    Route::post('/teams/check-name', [TeamController::class, 'checkNameExists']);
    Route::post('/teams/sync-players', [TeamController::class, 'syncTeamPlayers']);
    Route::delete('/teams/{id}', [TeamController::class, 'destroy']);
    Route::post('/teams/force-cleanup-sessions', [TeamController::class, 'forceCleanupSessions']);
    
    // Multi-device session management routes
    Route::get('/teams/user-sessions', [TeamController::class, 'getUserActiveSessions']);
    Route::post('/teams/cleanup-sessions', [TeamController::class, 'cleanupOldSessions']);
    // Removed auto-cleanup route - no longer needed
    // Removed complex session management routes - no longer needed
    
    // Photo upload endpoints (moved here to access session data)
    Route::post('/players/{player}/photo', [PlayerController::class, 'uploadPhoto']);
    Route::post('/players/photo-by-name', [PlayerController::class, 'uploadPhotoByName']);
    Route::get('/players/photo-by-name', [PlayerController::class, 'getPhotoByName']);
    
    // Debug endpoint for troubleshooting
    Route::get('/players/debug', [PlayerController::class, 'debug']);
    Route::get('/players/{playerName}/debug-matches', [PlayerController::class, 'debugPlayerMatches']);
    Route::get('/players/{playerName}/test-stats-logic', [PlayerController::class, 'testPlayerStatsLogic']);
    
    // Test endpoint for syncing specific team
    Route::post('/teams/{id}/test-sync', [TeamController::class, 'syncTeamPlayers']);
    Route::post('/test/role-normalization', [TeamController::class, 'testRoleNormalization']);
    Route::post('/teams/{id}/ensure-players', [TeamController::class, 'ensurePlayerRecords']);
});

// Admin routes (protected by admin middleware)
Route::middleware(['api', 'admin'])->group(function () {
    Route::get('/admin/users', [AdminController::class, 'index']);
    Route::post('/admin/users', [AdminController::class, 'store']);
    Route::delete('/admin/users/{user}', [AdminController::class, 'destroy']);
});

// Notes routes (temporarily without authentication for testing)
Route::apiResource('notes', NotesController::class);

// Draft routes
Route::post('/drafts', [DraftController::class, 'store']);
Route::get('/drafts', [DraftController::class, 'index']);
Route::get('/drafts/{id}', [DraftController::class, 'show']);
Route::delete('/drafts/{id}', [DraftController::class, 'destroy']);
Route::get('/drafts/image/{filename}', [DraftController::class, 'serveImage']);

// Mobadraft API proxy routes
Route::get('/mobadraft/last-updated', [MobadraftController::class, 'getLastUpdated']);
Route::get('/mobadraft/heroes', [MobadraftController::class, 'getHeroes']);
Route::get('/mobadraft/tournaments', [MobadraftController::class, 'getTournaments']);
Route::get('/mobadraft/tier-list', [MobadraftController::class, 'getTierList']);

// Test endpoint for mobadraft
Route::get('/mobadraft/test', function () {
    return response()->json([
        'success' => true,
        'message' => 'Mobadraft API proxy is working',
        'timestamp' => now()->toISOString()
    ]);
});

Route::get('/mobadraft/debug/tournament', function () {
    try {
        $response = Http::timeout(15)->get('https://mobadraft.com/api/tournament_statistics');
        
        if ($response->successful()) {
            $data = $response->json();
            return response()->json([
                'success' => true,
                'raw_data' => $data,
                'data_keys' => array_keys($data),
                'has_heroes' => isset($data['heroes']),
                'heroes_count' => isset($data['heroes']) ? count($data['heroes']) : 0,
                'sample_hero' => isset($data['heroes']) && count($data['heroes']) > 0 ? $data['heroes'][0] : null
            ]);
        } else {
            return response()->json([
                'success' => false,
                'error' => 'API request failed',
                'status' => $response->status()
            ]);
        }
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
});

// Get match with synchronized teams and hero assignments
Route::options('/match-player-assignments/{match_id}/sync', function () {
    return response()->json(['message' => 'OK'], 200)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
});

Route::get('/match-player-assignments/{match_id}/sync', function (Request $request, $match_id) {
    $controller = new \App\Http\Controllers\Api\MatchPlayerAssignmentController();
    return $controller->getMatchWithSync($request, $match_id);
});

// Update lane assignment route
Route::options('/match-player-assignments/update-lane', function () {
    return response('', 200)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'PUT, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
});

Route::put('/match-player-assignments/update-lane', [App\Http\Controllers\Api\MatchPlayerAssignmentController::class, 'updateLaneAssignment']);

// Lane swap route for handling simultaneous swaps
Route::options('/match-player-assignments/swap-lanes', function () {
    return response('', 200)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'PUT, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
});
Route::put('/match-player-assignments/swap-lanes', [App\Http\Controllers\Api\MatchPlayerAssignmentController::class, 'swapLaneAssignments']);

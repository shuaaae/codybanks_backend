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

Route::middleware('api')->group(function () {
    Route::apiResource('match-teams', MatchTeamController::class);

// Match Player Assignment routes
Route::post('/match-player-assignments/assign', [App\Http\Controllers\Api\MatchPlayerAssignmentController::class, 'assignPlayers']);
Route::get('/match-player-assignments/match/{match_id}', [App\Http\Controllers\Api\MatchPlayerAssignmentController::class, 'getMatchAssignments']);
Route::get('/match-player-assignments/available-players', [App\Http\Controllers\Api\MatchPlayerAssignmentController::class, 'getAvailablePlayers']);
Route::put('/match-player-assignments/update-substitute', [App\Http\Controllers\Api\MatchPlayerAssignmentController::class, 'updateSubstituteInfo']);
Route::get('/match-player-assignments/player-stats', [App\Http\Controllers\Api\MatchPlayerAssignmentController::class, 'getPlayerMatchStats']);
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
    
    Route::get('/teams', [TeamController::class, 'index']);
    Route::get('/teams/active', [TeamController::class, 'getActiveTeam']); // MUST come before /teams/{id}
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

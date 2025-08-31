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

// Test route
Route::get('/test', function () {
    return response()->json(['message' => 'API is working']);
});

// Authentication routes (no middleware - accessible to everyone)
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/logout', [AuthController::class, 'logout']);
Route::get('/auth/me', [AuthController::class, 'me']);

Route::middleware('api')->group(function () {
    Route::apiResource('matches', GameMatchController::class);
    
Route::apiResource('match-teams', MatchTeamController::class);

// Match Player Assignment routes
Route::post('/match-player-assignments/assign', [App\Http\Controllers\Api\MatchPlayerAssignmentController::class, 'assignPlayers']);
Route::get('/match-player-assignments/match/{match_id}', [App\Http\Controllers\Api\MatchPlayerAssignmentController::class, 'getMatchAssignments']);
Route::get('/match-player-assignments/available-players', [App\Http\Controllers\Api\MatchPlayerAssignmentController::class, 'getAvailablePlayers']);
Route::put('/match-player-assignments/update-substitute', [App\Http\Controllers\Api\MatchPlayerAssignmentController::class, 'updateSubstituteInfo']);
Route::get('/match-player-assignments/player-stats', [App\Http\Controllers\Api\MatchPlayerAssignmentController::class, 'getPlayerMatchStats']);
Route::get('/heroes', [HeroController::class, 'index']);
Route::post('/matches', [GameMatchController::class, 'store']);
Route::post('/teams/history', [App\Http\Controllers\Api\HeroController::class, 'storeTeamHistory']);
Route::get('/teams/history', [App\Http\Controllers\Api\HeroController::class, 'getTeamHistory']);
Route::get('/players', [PlayerController::class, 'index']);
Route::post('/players', [PlayerController::class, 'store']);
Route::put('/players/{id}', [PlayerController::class, 'update']);
Route::delete('/players/{id}', [PlayerController::class, 'destroy']);
Route::get('/players/{playerName}/hero-stats', [PlayerController::class, 'heroStats']);
Route::get('/players/{playerName}/hero-stats-by-team', [PlayerController::class, 'heroStatsByTeam']);
Route::get('/players/{playerName}/hero-h2h-stats-by-team', [PlayerController::class, 'heroH2HStatsByTeam']);
});

// Team routes (with session support)
Route::middleware('enable-sessions')->group(function () {
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

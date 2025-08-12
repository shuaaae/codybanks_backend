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
Route::get('/heroes', [HeroController::class, 'index']);
Route::post('/matches', [GameMatchController::class, 'store']);
Route::post('/teams/history', [App\Http\Controllers\Api\HeroController::class, 'storeTeamHistory']);
Route::get('/teams/history', [App\Http\Controllers\Api\HeroController::class, 'getTeamHistory']);
Route::post('/players/{player}/photo', [PlayerController::class, 'uploadPhoto']);
Route::post('/players/photo-by-name', [PlayerController::class, 'uploadPhotoByName']);
Route::get('/players/photo-by-name', [PlayerController::class, 'getPhotoByName']);
Route::get('/players/{playerName}/hero-stats', [PlayerController::class, 'heroStats']);
Route::get('/players/{playerName}/hero-stats-by-team', [PlayerController::class, 'heroStatsByTeam']);
Route::get('/players/{playerName}/hero-h2h-stats-by-team', [PlayerController::class, 'heroH2HStatsByTeam']);
Route::get('/players', [PlayerController::class, 'index']);

// Team routes
Route::get('/teams', [TeamController::class, 'index']);
Route::post('/teams', [TeamController::class, 'store']);
Route::post('/teams/set-active', [TeamController::class, 'setActive']);
Route::get('/teams/active', [TeamController::class, 'getActive']);
Route::get('/teams/debug', [TeamController::class, 'debug']);
Route::post('/teams/upload-logo', [TeamController::class, 'uploadLogo']);
Route::delete('/teams/{id}', [TeamController::class, 'destroy']);

// Admin routes (protected by admin middleware)
Route::middleware(['api', 'admin'])->group(function () {
    Route::get('/admin/users', [AdminController::class, 'index']);
    Route::post('/admin/users', [AdminController::class, 'store']);
    Route::delete('/admin/users/{user}', [AdminController::class, 'destroy']);
});

// Notes routes (temporarily without authentication for testing)
Route::apiResource('notes', NotesController::class);
});

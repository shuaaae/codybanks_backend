<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Player extends Model
{
    protected $fillable = [
        'name', 
        'role', 
        'photo', 
        'team_id',
        'is_substitute',
        'player_code',
        'notes',
        'primary_player_id',
        'substitute_order'
    ];

    protected $casts = [
        'is_substitute' => 'boolean'
    ];

    // Relationship to team
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    // Primary player (if this is a substitute)
    public function primaryPlayer(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'primary_player_id');
    }

    // Substitutes for this player
    public function substitutes(): HasMany
    {
        return $this->hasMany(Player::class, 'primary_player_id');
    }

    // Match assignments for this player
    public function matchAssignments(): HasMany
    {
        return $this->hasMany(MatchPlayerAssignment::class);
    }

    // Player stats for this player - Note: PlayerStat uses player_name string, not foreign key
    // public function playerStats(): HasMany
    // {
    //     return $this->hasMany(PlayerStat::class, 'player_name', 'name');
    // }

    // Lane assignments for this player
    public function laneAssignments(): HasMany
    {
        return $this->hasMany(MatchPlayerAssignment::class);
    }

    // Match players for this player
    public function matchPlayers(): HasMany
    {
        return $this->hasMany(MatchPlayerAssignment::class);
    }

    // Notes for this player (if any) - Note: Notes model doesn't have player relationship
    // public function notes(): HasMany
    // {
    //     return $this->hasMany(Note::class, 'user_id', 'id');
    // }

    // Scope to filter by team
    public function scopeForTeam($query, $teamId)
    {
        return $query->where('team_id', $team_id);
    }

    // Scope to get only primary players (non-substitutes)
    public function scopePrimary($query)
    {
        return $query->where('is_substitute', false);
    }

    // Scope to get substitutes
    public function scopeSubstitutes($query)
    {
        return $query->where('is_substitute', true);
    }

    // Scope to get substitutes for a specific role
    public function scopeSubstitutesForRole($query, $role)
    {
        return $query->where('is_substitute', true)->where('role', $role);
    }

    // Generate unique player code
    public static function generatePlayerCode($teamId, $role, $name)
    {
        $baseCode = strtoupper(substr($role, 0, 3)) . '_' . strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 3));
        $counter = 1;
        $code = $baseCode;
        
        while (Player::where('player_code', $code)->exists()) {
            $code = $baseCode . '_' . $counter;
            $counter++;
        }
        
        return $code;
    }
}

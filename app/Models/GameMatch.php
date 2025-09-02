<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GameMatch extends Model
{
    use HasFactory;

    protected $table = 'matches';

    protected $fillable = [
        'match_date',
        'winner',
        'turtle_taken',
        'lord_taken',
        'notes',
        'playstyle',
        'team_id',
        'match_type'
    ];

    // Ensure timestamps are automatically updated
    public $timestamps = true;

    // Scope for filtering by match type
    public function scopeByMatchType($query, $matchType)
    {
        return $query->where('match_type', $matchType);
    }

    // Relationship to teams
    public function teams()
    {
        return $this->hasMany(MatchTeam::class, 'match_id');
    }

    // Relationship to the team that created this match
    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    // Player assignments for this match
    public function playerAssignments()
    {
        return $this->hasMany(MatchPlayerAssignment::class, 'match_id');
    }

    // Get players by role for this match
    public function getPlayersByRole($role)
    {
        return $this->playerAssignments()
            ->where('role', $role)
            ->with('player')
            ->get()
            ->pluck('player');
    }

    // Get starting lineup for this match
    public function getStartingLineup()
    {
        return $this->playerAssignments()
            ->where('is_starting_lineup', true)
            ->with('player')
            ->get()
            ->pluck('player');
    }

    // Get substitutes for this match
    public function getSubstitutes()
    {
        return $this->playerAssignments()
            ->where('is_starting_lineup', false)
            ->with('player')
            ->orderBy('substitute_order')
            ->get()
            ->pluck('player');
    }
}
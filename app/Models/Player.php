<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Player extends Model
{
    protected $fillable = ['name', 'role', 'photo', 'team_id'];

    // Relationship to team
    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    // Scope to filter by team
    public function scopeForTeam($query, $teamId)
    {
        return $query->where('team_id', $teamId);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlayerStat extends Model
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'player_name',
        'hero_name',
        'games_played',
        'wins',
        'losses',
        'win_rate',
        'kills',
        'deaths',
        'assists',
        'kda_ratio'
    ];

    protected $casts = [
        'win_rate' => 'decimal:2',
        'kda_ratio' => 'decimal:2',
    ];

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

    // Scope to filter by player
    public function scopeForPlayer($query, $playerName)
    {
        return $query->where('player_name', $playerName);
    }

    // Scope to filter by hero
    public function scopeForHero($query, $heroName)
    {
        return $query->where('hero_name', $heroName);
    }
}

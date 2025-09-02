<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'logo_path',
        'players_data', // JSON field to store players array
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'players_data' => 'array',
    ];

    // Relationships for team isolation
    public function matches()
    {
        return $this->hasMany(GameMatch::class);
    }

    public function players()
    {
        return $this->hasMany(Player::class);
    }

    public function playerStats()
    {
        return $this->hasMany(PlayerStat::class);
    }

    public function activeSession()
    {
        return $this->hasOne(ActiveTeamSession::class);
    }

    // Custom accessor for players_data JSON field (renamed to avoid conflict with relationship)
    public function getPlayersDataAttribute()
    {
        return $this->players_data ?? [];
    }

    public function setPlayersDataAttribute($value)
    {
        $this->players_data = $value;
    }
} 
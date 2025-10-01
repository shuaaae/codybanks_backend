<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HeroSuccessRate extends Model
{
    protected $table = 'hero_success_rate';
    
    protected $fillable = [
        'player_id',
        'team_id', 
        'match_id',
        'hero_name',
        'lane',
        'match_type',
        'is_win',
        'match_date'
    ];

    protected $casts = [
        'is_win' => 'boolean',
        'match_date' => 'datetime'
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }
}

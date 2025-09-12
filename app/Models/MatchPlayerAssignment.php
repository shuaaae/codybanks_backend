<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchPlayerAssignment extends Model
{
    protected $fillable = [
        'match_id',
        'player_id',
        'role',
        'hero_name',
        'is_starting_lineup',
        'substitute_order',
        'substituted_in_at',
        'substituted_out_at',
        'notes'
    ];

    protected $casts = [
        'is_starting_lineup' => 'boolean',
        'substituted_in_at' => 'datetime',
        'substituted_out_at' => 'datetime'
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}

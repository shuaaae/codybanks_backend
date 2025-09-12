<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MatchTeam extends Model
{
    use HasFactory;

    protected $fillable = [
        'match_id',
        'team',
        'team_color',
        'banning_phase1',
        'picks1',
        'banning_phase2',
        'picks2'
    ];

    protected $casts = [
        'banning_phase1' => 'array',
        'picks1' => 'array',
        'banning_phase2' => 'array',
        'picks2' => 'array',
    ];

    // Relationship to match
    public function match()
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }
}
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
        'team_id'
    ];

    /**
     * Boot the model to ensure no soft delete logic is applied
     */
    protected static function boot()
    {
        parent::boot();
    }

    /**
     * Override the newQuery method to ensure no soft delete scoping is applied
     */
    public function newQuery()
    {
        // Create a completely fresh query without any global scoping
        $query = new \Illuminate\Database\Eloquent\Builder(
            new \Illuminate\Database\Query\Builder(
                $this->getConnection(),
                $this->getConnection()->getQueryGrammar(),
                $this->getConnection()->getPostProcessor()
            )
        );
        
        // Set the model instance
        $query->setModel($this);
        
        // Set the table
        $query->from($this->getTable());
        
        return $query;
    }

    /**
     * Scope to ensure no soft delete filtering is applied
     */
    public function scopeWithoutSoftDeletes($query)
    {
        return $query;
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
}
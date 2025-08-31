<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Team;

class TeamSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create a default team if none exists
        if (Team::count() === 0) {
            Team::create([
                'name' => 'Default Team',
                'logo_path' => null,
                'players_data' => []
            ]);
        }
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateHeroSeed extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:generate-hero-seed';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
{
    $basePath = public_path('heroes');
    $roles = scandir($basePath);
    $heroes = [];

    foreach ($roles as $role) {
        if ($role === '.' || $role === '..') continue;
        $rolePath = $basePath . DIRECTORY_SEPARATOR . $role;
        if (!is_dir($rolePath)) continue;

        $files = scandir($rolePath);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $name = pathinfo($file, PATHINFO_FILENAME);
            $image = $file;
            $heroes[] = [
                'name' => $name,
                'role' => $role,
                'image' => $image,
            ];
        }
    }

    // Output as PHP array for seeder
    $output = var_export($heroes, true);
    $this->line("Copy the following array into your HeroSeeder:\n\n" . $output);
}
}

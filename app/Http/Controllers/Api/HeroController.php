<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Hero;
use Illuminate\Support\Facades\Storage;

class HeroController extends Controller
{
    public function index()
    {
        return Hero::all();
    }

    public function storeTeamHistory(Request $request)
    {
        $team = $request->all();
        $file = 'teams_history.json';
        $history = [];
        if (Storage::exists($file)) {
            $history = json_decode(Storage::get($file), true) ?? [];
        }
        $history[] = $team;
        Storage::put($file, json_encode($history, JSON_PRETTY_PRINT));
        return response()->json(['success' => true]);
    }

    public function getTeamHistory()
    {
        $file = 'teams_history.json';
        $history = [];
        if (Storage::exists($file)) {
            $history = json_decode(Storage::get($file), true) ?? [];
        }
        return response()->json($history);
    }
}
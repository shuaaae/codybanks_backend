<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MatchTeam;

class MatchTeamController extends Controller
{
    /**
     * Display a listing of the resource.
     */
 // In your MatchTeamController
public function index()
{
    return MatchTeam::with('match')->get();
}
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}

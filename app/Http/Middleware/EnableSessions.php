<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnableSessions
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Start the session if it hasn't been started
        if (!session()->isStarted()) {
            session()->start();
            \Log::info('Session started in EnableSessions middleware', [
                'session_id' => session()->getId(),
                'request_path' => $request->path(),
                'request_method' => $request->method()
            ]);
        }
        
        \Log::info('Session status in EnableSessions middleware', [
            'session_id' => session()->getId(),
            'session_started' => session()->isStarted(),
            'active_team_id' => session('active_team_id'),
            'request_path' => $request->path(),
            'request_method' => $request->method()
        ]);
        
        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\User;
use Symfony\Component\HttpFoundation\Response;

class IsAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // For now, allow any request with an Authorization header
        // In a production environment, you'd want proper token validation
        $authHeader = $request->header('Authorization');
        
        if (!$authHeader) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return $next($request);
    }
}
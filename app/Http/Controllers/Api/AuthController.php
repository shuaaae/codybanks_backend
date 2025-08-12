<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'password' => 'required'
            ]);

            $user = User::where('email', $request->email)->first();

            if (! $user || ! Hash::check($request->password, $user->password)) {
                return response()->json(['message' => 'Invalid credentials'], 401);
            }

            // Check if this is an admin login attempt
            $isAdminLogin = $request->header('X-Login-Type') === 'admin';
            
            // If user is admin but this is not an admin login, reject
            if ($user->is_admin && !$isAdminLogin) {
                return response()->json(['message' => 'Invalid credentials'], 401);
            }
            
            // If user is not admin but this is an admin login, reject
            if (!$user->is_admin && $isAdminLogin) {
                return response()->json(['message' => 'Invalid credentials'], 401);
            }

            // For API authentication, we'll use stateless authentication
            // Create a simple token or just return the user data
            return response()->json([
                'message' => 'Login successful',
                'user' => $user,
                'token' => 'dummy-token-' . time() // In a real app, you'd use JWT or Sanctum
            ]);
        } catch (\Exception $e) {
            \Log::error('Login error: ' . $e->getMessage());
            return response()->json(['message' => 'Server error occurred'], 500);
        }
    }

    public function logout(Request $request)
    {
        // For API, just return success message
        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request)
    {
        // For API, you'd typically get user from token
        // For now, return a placeholder
        return response()->json(['message' => 'User info endpoint']);
    }
} 
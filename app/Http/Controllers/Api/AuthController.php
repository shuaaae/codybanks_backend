<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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

    public function profile($id)
    {
        try {
            $user = User::findOrFail($id);
            
            // Return user data with full photo URL
            $userData = $user->toArray();
            if ($user->photo) {
                $userData['photo'] = url($user->photo);
            }
            
            return response()->json([
                'message' => 'User profile retrieved successfully',
                'user' => $userData
            ], 200);
        } catch (\Exception $e) {
            \Log::error('Profile fetch error: ' . $e->getMessage());
            return response()->json(['error' => 'User not found'], 404);
        }
    }

    public function uploadPhoto(Request $request)
    {
        try {
            $request->validate([
                'photo' => 'required|image|max:2048', // 2MB max
                'user_id' => 'required|integer|exists:users,id'
            ]);

            $user = User::findOrFail($request->user_id);

            if ($request->hasFile('photo')) {
                $file = $request->file('photo');
                $filename = time() . '_' . $user->id . '.' . $file->getClientOriginalExtension();
                
                // Store the file in public/users directory
                $file->move(public_path('users'), $filename);
                
                // Update user photo path
                $user->photo = 'users/' . $filename;
                $user->save();
            }

            return response()->json([
                'message' => 'Photo uploaded successfully',
                'photo' => url($user->photo),
                'user' => $user
            ], 200);
        } catch (\Exception $e) {
            \Log::error('Photo upload error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to upload photo'], 500);
        }
    }
} 
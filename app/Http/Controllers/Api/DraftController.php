<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DraftController extends Controller
{
    /**
     * Save draft data to database
     */
    public function store(Request $request)
    {
        try {
            $request->validate([
                'blue_team_name' => 'required|string|max:255',
                'red_team_name' => 'required|string|max:255',
                'blue_picks' => 'required|array',
                'red_picks' => 'required|array',
                'blue_bans' => 'required|array',
                'red_bans' => 'required|array',
                'custom_lane_assignments' => 'nullable|array',
                'image_data' => 'nullable|string', // Base64 image data
                'user_id' => 'required|integer'
            ]);

            // Generate unique filename for the image
            $imageFilename = null;
            if ($request->image_data) {
                $imageFilename = 'draft_' . time() . '_' . Str::random(10) . '.png';
                
                // Decode base64 image and save to storage
                $imageData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $request->image_data));
                Storage::disk('public')->put('drafts/' . $imageFilename, $imageData);
            }

            // Save draft data to database
            $draftId = DB::table('drafts')->insertGetId([
                'user_id' => $request->user_id,
                'blue_team_name' => $request->blue_team_name,
                'red_team_name' => $request->red_team_name,
                'blue_picks' => json_encode($request->blue_picks),
                'red_picks' => json_encode($request->red_picks),
                'blue_bans' => json_encode($request->blue_bans),
                'red_bans' => json_encode($request->red_bans),
                'custom_lane_assignments' => json_encode($request->custom_lane_assignments ?? []),
                'image_filename' => $imageFilename,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Draft saved successfully',
                'draft_id' => $draftId,
                'image_url' => $imageFilename ? url('storage/drafts/' . $imageFilename) : null
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save draft: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all drafts for a user
     */
    public function index(Request $request)
    {
        try {
            $userId = $request->user_id;
            
            $drafts = DB::table('drafts')
                ->where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($draft) {
                    return [
                        'id' => $draft->id,
                        'blue_team_name' => $draft->blue_team_name,
                        'red_team_name' => $draft->red_team_name,
                        'blue_picks' => json_decode($draft->blue_picks, true),
                        'red_picks' => json_decode($draft->red_picks, true),
                        'blue_bans' => json_decode($draft->blue_bans, true),
                        'red_bans' => json_decode($draft->red_bans, true),
                        'custom_lane_assignments' => json_decode($draft->custom_lane_assignments, true),
                        'image_url' => $draft->image_filename ? url('storage/drafts/' . $draft->image_filename) : null,
                        'created_at' => $draft->created_at,
                        'updated_at' => $draft->updated_at
                    ];
                });

            return response()->json([
                'success' => true,
                'drafts' => $drafts
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch drafts: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific draft
     */
    public function show($id, Request $request)
    {
        try {
            $userId = $request->user_id;
            
            $draft = DB::table('drafts')
                ->where('id', $id)
                ->where('user_id', $userId)
                ->first();

            if (!$draft) {
                return response()->json([
                    'success' => false,
                    'message' => 'Draft not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'draft' => [
                    'id' => $draft->id,
                    'blue_team_name' => $draft->blue_team_name,
                    'red_team_name' => $draft->red_team_name,
                    'blue_picks' => json_decode($draft->blue_picks, true),
                    'red_picks' => json_decode($draft->red_picks, true),
                    'blue_bans' => json_decode($draft->blue_bans, true),
                    'red_bans' => json_decode($draft->red_bans, true),
                    'custom_lane_assignments' => json_decode($draft->custom_lane_assignments, true),
                    'image_url' => $draft->image_filename ? url('storage/drafts/' . $draft->image_filename) : null,
                    'created_at' => $draft->created_at,
                    'updated_at' => $draft->updated_at
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch draft: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a draft
     */
    public function destroy($id, Request $request)
    {
        try {
            $userId = $request->user_id;
            
            $draft = DB::table('drafts')
                ->where('id', $id)
                ->where('user_id', $userId)
                ->first();

            if (!$draft) {
                return response()->json([
                    'success' => false,
                    'message' => 'Draft not found'
                ], 404);
            }

            // Delete the image file if it exists
            if ($draft->image_filename) {
                Storage::disk('public')->delete('drafts/' . $draft->image_filename);
            }

            // Delete the draft record
            DB::table('drafts')->where('id', $id)->delete();

            return response()->json([
                'success' => true,
                'message' => 'Draft deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete draft: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Serve draft images
     */
    public function serveImage($filename)
    {
        try {
            $imagePath = storage_path('app/public/drafts/' . $filename);
            
            if (!file_exists($imagePath)) {
                return response()->json(['error' => 'Image not found'], 404);
            }
            
            return response()->file($imagePath, [
                'Content-Type' => 'image/png',
                'Access-Control-Allow-Origin' => '*',
                'Cache-Control' => 'public, max-age=86400'
            ]);
            
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to serve image'], 500);
        }
    }
}

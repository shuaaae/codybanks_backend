<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Note;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class NotesController extends Controller
{
    /**
     * Get all notes for the authenticated user.
     */
    public function index(): JsonResponse
    {
        // For now, get all notes since we're not using authentication
        $notes = Note::orderBy('created_at', 'desc')->get();
        
        // Transform the notes to include the formatted date
        $notesWithFormattedDate = $notes->map(function ($note) {
            return [
                'id' => $note->id,
                'user_id' => $note->user_id,
                'title' => $note->title,
                'content' => $note->content,
                'created_at' => $note->created_at,
                'updated_at' => $note->updated_at,
                'date_formatted' => $note->date_formatted
            ];
        });
        
        return response()->json([
            'success' => true,
            'data' => $notesWithFormattedDate
        ]);
    }

    /**
     * Store a new note.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'content' => 'required|string',
            ]);

            // For now, create note without user_id since we're not using authentication
            $note = Note::create([
                'title' => $validated['title'],
                'content' => $validated['content'],
                'user_id' => 1, // Default user ID
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Note saved successfully',
                'data' => $note
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save note'
            ], 500);
        }
    }

    /**
     * Update a note.
     */
    public function update(Request $request, Note $note): JsonResponse
    {
        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'content' => 'required|string',
            ]);

            $note->update([
                'title' => $validated['title'],
                'content' => $validated['content'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Note updated successfully',
                'data' => $note
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update note'
            ], 500);
        }
    }

    /**
     * Delete a note.
     */
    public function destroy(Note $note): JsonResponse
    {
        try {
            $note->delete();

            return response()->json([
                'success' => true,
                'message' => 'Note deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete note'
            ], 500);
        }
    }
} 
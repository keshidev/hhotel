<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Amenity;
use Illuminate\Http\Request;

class AmenityController extends Controller
{
    /**
     * Return all amenities (default + custom), ordered by name.
     */
    public function index()
    {
        $amenities = Amenity::orderBy('is_default', 'desc')
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $amenities,
        ]);
    }

    /**
     * Create a new custom amenity.
     * Rejects duplicates (case-insensitive).
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $name = trim($request->name);

        // Case-insensitive duplicate check
        $exists = Amenity::whereRaw('LOWER(name) = ?', [strtolower($name)])->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => "Amenity \"{$name}\" already exists.",
            ], 422);
        }

        $amenity = Amenity::create([
            'name'       => $name,
            'is_default' => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Amenity \"{$amenity->name}\" added successfully!",
            'data'    => $amenity,
        ], 201);
    }

    /**
     * Delete a custom amenity.
     * Default amenities cannot be deleted.
     */
    public function destroy(Amenity $amenity)
    {
        if ($amenity->is_default) {
            return response()->json([
                'success' => false,
                'message' => 'Default amenities cannot be deleted.',
            ], 403);
        }

        $name = $amenity->name;
        $amenity->delete();

        return response()->json([
            'success' => true,
            'message' => "Amenity \"{$name}\" deleted successfully!",
        ]);
    }
}
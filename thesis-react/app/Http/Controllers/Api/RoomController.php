<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoomController extends Controller
{
    /**
     * Display a listing of rooms with stats.
     */
    public function index(Request $request)
    {
        $query = Room::query();

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('room_number', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Filter by type
        if ($request->has('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }

        // Filter by status
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Filter by price range
        if ($request->has('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }
        if ($request->has('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        // Sorting
        $allowedSortBy = ['created_at', 'room_number', 'name', 'type', 'price', 'capacity', 'status'];
        $sortByInput = $request->get('sort_by', 'created_at');
        $sortBy = in_array($sortByInput, $allowedSortBy, true) ? $sortByInput : 'created_at';
        $sortOrder = $request->get('sort_order', 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = $request->get('per_page', 10);
        $rooms = $query->paginate($perPage);

        // Stats
        $stats = [
            'total' => Room::count(),
            'available' => Room::where('status', 'available')->count(),
            'occupied' => Room::where('status', 'occupied')->count(),
            'cleaning' => Room::where('status', 'cleaning')->count(),
            'maintenance' => Room::where('status', 'maintenance')->count(),
            'executive_suite' => Room::where('type', 'Executive Suite')->count(),
            'deluxe_room' => Room::where('type', 'Deluxe Room')->count(),
            'premier_room' => Room::where('type', 'Premier Room')->count(),
            'studio_room' => Room::where('type', 'Studio Room')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $rooms,
            'stats' => $stats,
        ]);
    }

    /**
     * Store a newly created room.
     */
    public function store(Request $request)
    {
        $request->validate([
            'room_number' => 'required|string|unique:rooms,room_number|max:50',
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'type' => 'required|in:Executive Suite,Deluxe Room,Premier Room,Studio Room',
            'price' => 'required|numeric|min:0',
            'capacity' => 'required|integer|min:1',
            'beds' => 'required|integer|min:1',
            'size' => 'required|numeric|min:0',
            'status' => 'required|in:available,occupied,maintenance,cleaning',
            'amenities' => 'nullable|array',
            'images' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $room = Room::create($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Room created successfully',
            'data' => $room,
        ], 201);
    }

    /**
     * Display the specified room.
     */
    public function show(Room $room)
    {
        $room->load(['bookings' => function ($query) {
            $query->latest()->limit(10);
        }]);

        return response()->json([
            'success' => true,
            'data' => $room,
        ]);
    }

    /**
     * Update the specified room.
     */
    public function update(Request $request, Room $room)
    {
        $request->validate([
            'room_number' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('rooms')->ignore($room->id)],
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string',
            'type' => 'sometimes|required|in:Executive Suite,Deluxe Room,Premier Room,Studio Room',
            'price' => 'sometimes|required|numeric|min:0',
            'capacity' => 'sometimes|required|integer|min:1',
            'beds' => 'sometimes|required|integer|min:1',
            'size' => 'sometimes|required|numeric|min:0',
            'status' => 'sometimes|required|in:available,occupied,maintenance,cleaning',
            'amenities' => 'nullable|array',
            'images' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $room->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Room updated successfully',
            'data' => $room->fresh(),
        ]);
    }

    /**
     * Remove the specified room.
     */
    public function destroy(Room $room)
    {
        // Check if room has active bookings
        if ($room->bookings()->whereIn('booking_status', ['confirmed', 'checked_in'])->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete room with active bookings',
            ], 422);
        }

        $room->delete();

        return response()->json([
            'success' => true,
            'message' => 'Room deleted successfully',
        ]);
    }

    /**
     * Toggle room active status.
     */
    public function toggleStatus(Room $room)
    {
        $room->update([
            'is_active' => !$room->is_active,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Room status updated successfully',
            'data' => $room,
        ]);
    }

    /**
     * Update room status.
     */
    public function updateStatus(Request $request, Room $room)
    {
        $request->validate([
            'status' => 'required|in:available,occupied,maintenance,cleaning',
        ]);

        $room->update([
            'status' => $request->status,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Room status updated successfully',
            'data' => $room,
        ]);
    }

    /**
     * Bulk delete rooms.
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:rooms,id',
        ]);

        Room::whereIn('id', $request->ids)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Rooms deleted successfully',
        ]);
    }

    /**
     * Check room availability.
     */
    public function checkAvailability(Request $request, Room $room)
    {
        $request->validate([
            'check_in' => 'required|date|after_or_equal:today',
            'check_out' => 'required|date|after:check_in',
        ]);

        $isAvailable = $room->isAvailable($request->check_in, $request->check_out);

        return response()->json([
            'success' => true,
            'available' => $isAvailable,
        ]);
    }
}

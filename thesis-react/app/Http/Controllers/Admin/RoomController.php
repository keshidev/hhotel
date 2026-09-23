<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\RoomInventoryConflictException;
use App\Helpers\AuditHelper;
use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Services\RoomInventoryManagementService;
use App\Services\RoomPricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class RoomController extends Controller
{
    private const ROOM_TYPES = [
        'executive_suite',
        'family',
        'deluxe',
        'superior_twin',
        'superior_queen',
        'premier',
    ];

    public function __construct(
        private RoomPricingService $roomPricingService,
        private RoomInventoryManagementService $roomInventoryManagementService,
    ) {
    }

    public function index(Request $request)
    {
        $query = Room::query();

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('room_number', 'like', "%{$search}%")
                  ->orWhere('room_type', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->has('room_type') && $request->room_type) {
            $query->where('room_type', $request->room_type);
        }

        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        if ($request->has('floor') && $request->floor) {
            $query->where('floor', $request->floor);
        }

        $allowedSortBy = ['room_number', 'room_type', 'capacity', 'price_per_night', 'floor', 'status', 'created_at'];
        $sortByInput = $request->get('sort_by', 'room_number');
        $sortBy = in_array($sortByInput, $allowedSortBy, true) ? $sortByInput : 'room_number';
        $sortOrder = $request->get('sort_order', 'asc') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortBy, $sortOrder);

        $rooms = $query->get();

        $rooms->transform(function ($room) {
            $room->price_per_night = $this->roomPricingService->resolveNightlyRateForRoom($room);

            if ($room->images) {
                $images = is_string($room->images) ? json_decode($room->images, true) : $room->images;
                if ($images && is_array($images) && count($images) > 0) {
                    $room->image_urls = array_map(fn($path) => asset('storage/' . $path), $images);
                } else {
                    $room->image_urls = [];
                }
            } else {
                $room->image_urls = [];
            }
            return $room;
        });

        return response()->json([
            'success' => true,
            'data'    => $rooms,
        ]);
    }

    public function store(Request $request)
    {
        $existingRoom = Room::withTrashed()->where('room_number', $request->room_number)->first();

        if ($existingRoom) {
            $msg = $existingRoom->trashed()
                ? "Room number '{$request->room_number}' already exists but was previously deleted."
                : "Room number '{$request->room_number}' already exists.";

            return response()->json([
                'success' => false,
                'message' => $msg,
                'errors'  => ['room_number' => ["The room number has already been taken."]],
            ], 422);
        }

        $validated = $request->validate([
            'room_number'    => 'required|string|unique:rooms,room_number',
            'room_type'      => ['required', Rule::in(self::ROOM_TYPES)],
            'capacity'       => 'required|integer|min:1',
            'price_per_night'=> 'required|numeric|min:0',
            'price_day_tour' => 'nullable|numeric|min:0',
            'floor'          => 'nullable|integer',
            'status'         => 'required|in:available,maintenance,cleaning',
            'description'    => 'nullable|string',
            'amenities'      => 'nullable|array',
            'room_image'     => 'nullable|image|mimes:jpeg,jpg,png,webp|max:5120',
            'show_on_website'=> 'nullable|boolean',
        ]);

        $imagePath = null;
        if ($request->hasFile('room_image')) {
            $imagePath = $request->file('room_image')->store('rooms', 'public');
        }

        $nightlyRate = $this->roomPricingService->resolveNightlyRateByType(
            (string) $validated['room_type'],
            (float) $validated['price_per_night']
        );
        $manualDayTourRate = null;
        if ($request->filled('price_day_tour')) {
            $candidate = round((float) $validated['price_day_tour'], 2);
            $manualDayTourRate = $candidate > 0 ? $candidate : null;
        }

        try {
            $room = Room::create([
                'room_number'     => $validated['room_number'],
                'room_type'       => $validated['room_type'],
                'capacity'        => $validated['capacity'],
                'price_per_night' => $nightlyRate,
                'price_day_tour'  => $manualDayTourRate,
                'floor'           => $validated['floor'] ?? null,
                'status'          => $validated['status'],
                'description'     => $validated['description'] ?? null,
                'amenities'       => $validated['amenities'] ?? [],
                'images'          => $imagePath ? [$imagePath] : [],
                'show_on_website' => array_key_exists('show_on_website', $validated)
                    ? (bool) $validated['show_on_website']
                    : true,
            ]);
        } catch (Throwable $exception) {
            if ($imagePath) {
                Storage::disk('public')->delete($imagePath);
            }

            throw $exception;
        }

        AuditHelper::log(
            actionActivity: 'Room Created',
            modulePage:     'Room Management',
            modelType:      'Room',
            modelId:        $room->id,
            recordAffected: 'Room ' . $room->room_number . ' (' . $room->room_type . ')',
            newValues:      [
                'room_number'     => $room->room_number,
                'room_type'       => $room->room_type,
                'status'          => $room->status,
                'price_per_night' => $room->price_per_night,
                'price_day_tour'  => $room->price_day_tour,
            ],
            action: 'created'
        );

        return response()->json([
            'success' => true,
            'message' => "Room {$room->room_number} created successfully!",
            'data'    => $this->appendImageUrls($room),
        ], 201);
    }

    public function update(Request $request, Room $room)
    {
        if ($request->room_number !== $room->room_number) {
            $existingRoom = Room::withTrashed()
                ->where('room_number', $request->room_number)
                ->where('id', '!=', $room->id)
                ->first();

            if ($existingRoom) {
                return response()->json([
                    'success' => false,
                    'message' => "Room number '{$request->room_number}' is already taken.",
                    'errors'  => ['room_number' => ["The room number has already been taken."]],
                ], 422);
            }
        }

        $validated = $request->validate([
            'room_number'     => 'required|string|unique:rooms,room_number,' . $room->id,
            'room_type'       => ['required', Rule::in(self::ROOM_TYPES)],
            'capacity'        => 'required|integer|min:1',
            'price_per_night' => 'required|numeric|min:0',
            'price_day_tour'  => 'nullable|numeric|min:0',
            'floor'           => 'nullable|integer',
            'status'          => 'required|in:available,occupied,maintenance,cleaning',
            'description'     => 'nullable|string',
            'amenities'       => 'nullable|array',
            'room_image'      => 'nullable|image|mimes:jpeg,jpg,png,webp|max:5120',
            'keep_image'      => 'nullable|string',
            'show_on_website' => 'nullable|boolean',
        ]);

        $oldValues = [
            'room_number'     => (string) $room->room_number,
            'room_type'       => (string) $room->room_type,
            'status'          => (string) $room->status,
            'price_per_night' => (float)  $room->price_per_night,
            'price_day_tour'  => (float)  ($room->price_day_tour ?? 0),
            'floor'           => (int)    ($room->floor ?? 0),
            'capacity'        => (int)    $room->capacity,
        ];

        // Handle image
        $existingImages = is_string($room->images) ? json_decode($room->images, true) : ($room->images ?: []);
        $imagePath      = null;

        if ($request->has('keep_image') && $request->keep_image) {
            if (!in_array($request->keep_image, $existingImages, true)) {
                throw ValidationException::withMessages([
                    'keep_image' => ['The selected existing room image is invalid.'],
                ]);
            }
            $imagePath = $request->keep_image;
        } elseif ($request->hasFile('room_image')) {
            $imagePath = $request->file('room_image')->store('rooms', 'public');
        }

        $nightlyRate = $this->roomPricingService->resolveNightlyRateByType(
            (string) $validated['room_type'],
            (float) $validated['price_per_night']
        );
        $manualDayTourRate = null;
        if ($request->filled('price_day_tour')) {
            $candidate = round((float) $validated['price_day_tour'], 2);
            $manualDayTourRate = $candidate > 0 ? $candidate : null;
        }

        try {
            $room = $this->roomInventoryManagementService->update($room, [
                'room_number'     => $validated['room_number'],
                'room_type'       => $validated['room_type'],
                'capacity'        => $validated['capacity'],
                'price_per_night' => $nightlyRate,
                'price_day_tour'  => $manualDayTourRate,
                'floor'           => $validated['floor'] ?? null,
                'status'          => $validated['status'],
                'description'     => $validated['description'] ?? null,
                'amenities'       => $validated['amenities'] ?? [],
                'images'          => $imagePath ? [$imagePath] : [],
                'show_on_website' => array_key_exists('show_on_website', $validated)
                    ? (bool) $validated['show_on_website']
                    : (bool) $room->show_on_website,
            ]);
        } catch (RoomInventoryConflictException $exception) {
            if ($request->hasFile('room_image') && $imagePath) {
                Storage::disk('public')->delete($imagePath);
            }

            return $this->inventoryConflictResponse($exception);
        } catch (Throwable $exception) {
            if ($request->hasFile('room_image') && $imagePath) {
                Storage::disk('public')->delete($imagePath);
            }

            throw $exception;
        }

        $retainedImages = $imagePath ? [$imagePath] : [];
        $this->deleteImagePaths(array_values(array_diff($existingImages, $retainedImages)), $room->id);

        // Capture new values AFTER update — same cast types as above
        $fresh = $room;
        $newValues = [
            'room_number'     => (string) $fresh->room_number,
            'room_type'       => (string) $fresh->room_type,
            'status'          => (string) $fresh->status,
            'price_per_night' => (float)  $fresh->price_per_night,
            'price_day_tour'  => (float)  ($fresh->price_day_tour ?? 0),
            'floor'           => (int)    ($fresh->floor ?? 0),
            'capacity'        => (int)    $fresh->capacity,
        ];

        $diff = AuditHelper::diff($oldValues, $newValues);

        // Only write an audit row when something actually changed
        if (!empty($diff['old']) || !empty($diff['new'])) {
            // If the only changed field is status, use the more specific label
            $changedKeys    = array_keys($diff['new']);
            $actionActivity = ($changedKeys === ['status'])
                ? 'Room Status Updated'
                : 'Room Updated';

            AuditHelper::log(
                actionActivity: $actionActivity,
                modulePage:     'Room Management',
                modelType:      'Room',
                modelId:        $room->id,
                recordAffected: 'Room ' . $room->room_number,
                oldValues:      $diff['old'],
                newValues:      $diff['new'],
                action:         'updated'
            );
        }

        return response()->json([
            'success' => true,
            'message' => "Room {$room->room_number} updated successfully!",
            'data'    => $this->appendImageUrls($room->fresh()),
        ]);
    }

    public function destroy(Room $room)
    {
        try {
            $deletedRoom = $this->roomInventoryManagementService->delete($room);
        } catch (RoomInventoryConflictException $exception) {
            return $this->inventoryConflictResponse($exception);
        }

        $this->deleteImagePaths($deletedRoom->images ?: [], $deletedRoom->id);

        AuditHelper::log(
            actionActivity: 'Room Deleted',
            modulePage:     'Room Management',
            modelType:      'Room',
            modelId:        $deletedRoom->id,
            recordAffected: 'Room ' . $deletedRoom->room_number . ' (' . $deletedRoom->room_type . ')',
            oldValues:      [
                'room_number' => $deletedRoom->room_number,
                'room_type'   => $deletedRoom->room_type,
                'status'      => $deletedRoom->status,
            ],
            action: 'deleted'
        );

        $roomNumber = $deletedRoom->room_number;

        return response()->json([
            'success' => true,
            'message' => "Room {$roomNumber} deleted successfully!",
        ]);
    }

    public function updateStatus(Request $request, Room $room)
    {
        $validated = $request->validate([
            'status' => 'required|in:available,maintenance,cleaning',
        ]);

        $oldStatus = $room->status;

        // No-op guard: if status hasn't changed, skip update + audit
        if ($oldStatus === $validated['status']) {
            return response()->json([
                'success' => true,
                'message' => 'Room status unchanged.',
                'data'    => $room,
            ]);
        }

        try {
            $room = $this->roomInventoryManagementService->updateStatus($room, $validated['status']);
        } catch (RoomInventoryConflictException $exception) {
            return $this->inventoryConflictResponse($exception);
        }

        AuditHelper::log(
            actionActivity: 'Room Status Updated',
            modulePage:     'Room Management',
            modelType:      'Room',
            modelId:        $room->id,
            recordAffected: 'Room ' . $room->room_number,
            oldValues:      ['status' => $oldStatus],
            newValues:      ['status' => $validated['status']],
            action:         'updated'
        );

        return response()->json([
            'success' => true,
            'message' => 'Room status updated successfully',
            'data'    => $room,
        ]);
    }

    public function getRoomTypes()
    {
        $types = Room::select('room_type')->distinct()->pluck('room_type');

        return response()->json(['success' => true, 'data' => $types]);
    }

    public function getFloors()
    {
        $floors = Room::select('floor')
            ->distinct()
            ->whereNotNull('floor')
            ->orderBy('floor')
            ->pluck('floor');

        return response()->json(['success' => true, 'data' => $floors]);
    }

    public function bulkDelete(Request $request)
    {
        $validated = $request->validate([
            'ids'   => 'required|array',
            'ids.*' => 'exists:rooms,id',
        ]);

        try {
            $rooms = $this->roomInventoryManagementService->deleteMany($validated['ids']);
        } catch (RoomInventoryConflictException $exception) {
            return $this->inventoryConflictResponse($exception);
        }

        foreach ($rooms as $room) {
            $this->deleteImagePaths($room->images ?: [], $room->id);
            AuditHelper::log(
                actionActivity: 'Room Deleted',
                modulePage: 'Room Management',
                modelType: 'Room',
                modelId: $room->id,
                recordAffected: 'Room ' . $room->room_number . ' (' . $room->room_type . ')',
                oldValues: [
                    'room_number' => $room->room_number,
                    'room_type' => $room->room_type,
                    'status' => $room->status,
                ],
                action: 'deleted'
            );
        }

        return response()->json([
            'success' => true,
            'message' => $rooms->count() . ' rooms deleted successfully',
        ]);
    }

    private function inventoryConflictResponse(RoomInventoryConflictException $exception)
    {
        return response()->json([
            'success' => false,
            'message' => $exception->getMessage(),
        ], 409);
    }

    private function deleteImagePaths(array $paths, int $roomId): void
    {
        foreach ($paths as $path) {
            try {
                if (!Storage::disk('public')->delete($path)) {
                    Log::warning('Room image cleanup did not delete a file.', [
                        'room_id' => $roomId,
                        'path' => $path,
                    ]);
                }
            } catch (Throwable $exception) {
                Log::warning('Room image cleanup failed.', [
                    'room_id' => $roomId,
                    'path' => $path,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Append image_urls to a room model instance so the frontend
     * receives ready-to-use URLs instead of raw storage paths.
     */
    private function appendImageUrls(Room $room): Room
    {
        $images = is_string($room->images)
            ? json_decode($room->images, true)
            : ($room->images ?: []);

        $room->image_urls = ($images && is_array($images) && count($images) > 0)
            ? array_map(fn($path) => asset('storage/' . $path), $images)
            : [];

        return $room;
    }

}

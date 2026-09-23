<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Helpers\AuditHelper;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\StaffAccountRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    private function defaultNotificationPreferences(): array
    {
        return [
            'newBooking' => true,
            'paymentUploaded' => true,
            'cancellation' => true,
            'rebooking' => false,
            'dailySummary' => true,
        ];
    }

    public function show()
    {
        /** @var User $user */
        $user = Auth::user();
        $user->load(['bookings', 'activityLogs' => function($query) {
            $query->latest()->limit(10);
        }]);
        $user->notification_preferences = array_merge(
            $this->defaultNotificationPreferences(),
            $user->notification_preferences ?? []
        );

        return response()->json($user);
    }

    public function update(Request $request)
    {
        /** @var User $user */
        $user = Auth::user();
        $oldValues = $user->toArray();

        $validated = $request->validate([
            'name' => ['sometimes', ...StaffAccountRules::name()],
            'email' => ['sometimes', 'required', 'email:rfc', 'max:255', Rule::unique('users')->ignore($user->id)],
            'phone' => ['sometimes', ...StaffAccountRules::phone()],
        ]);

        $user->update($validated);

        // Log activity through centralized audit helper.
        AuditHelper::log(
            actionActivity: 'Profile Updated',
            modulePage: 'Profile',
            modelType: 'User',
            modelId: $user->id,
            recordAffected: 'User: ' . $user->name,
            oldValues: $oldValues,
            newValues: $user->fresh()->toArray(),
            action: 'updated',
            actorUser: $user
        );

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $user->fresh(),
        ]);
    }

    public function changePassword(Request $request)
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'new_password' => ['required', 'string', 'confirmed', StaffAccountRules::password()],
        ]);

        DB::transaction(function () use ($user, $validated) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            if (!Hash::check($validated['current_password'], $lockedUser->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['Current password is incorrect.'],
                ]);
            }

            if (Hash::check($validated['new_password'], $lockedUser->password)) {
                throw ValidationException::withMessages([
                    'new_password' => ['New password must be different from your current password.'],
                ]);
            }

            $lockedUser->forceFill([
                'password' => Hash::make($validated['new_password']),
                'remember_token' => Str::random(60),
            ])->save();
            $lockedUser->tokens()->delete();
        }, 3);

        // Log activity through centralized audit helper.
        AuditHelper::log(
            actionActivity: 'Profile Password Changed',
            modulePage: 'Profile',
            modelType: 'User',
            modelId: $user->id,
            recordAffected: 'User: ' . $user->name,
            oldValues: null,
            newValues: [
                'password_changed' => true,
                'existing_sessions_revoked' => true,
            ],
            action: 'updated',
            actorUser: $user
        );

        return response()->json([
            'message' => 'Password changed successfully. All existing sessions were signed out.',
            'session_revoked' => true,
        ]);
    }

    public function getActivityLog(Request $request)
    {
        /** @var User $user */
        $user = Auth::user();

        $logs = ActivityLog::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

        return response()->json($logs);
    }

    public function updateNotificationPreferences(Request $request)
    {
        /** @var User $user */
        $user = Auth::user();
        $old = $user->notification_preferences ?? $this->defaultNotificationPreferences();

        $validated = $request->validate([
            'newBooking' => 'sometimes|boolean',
            'paymentUploaded' => 'sometimes|boolean',
            'cancellation' => 'sometimes|boolean',
            'rebooking' => 'sometimes|boolean',
            'dailySummary' => 'sometimes|boolean',
        ]);

        $merged = array_merge($this->defaultNotificationPreferences(), $old, $validated);
        $user->update(['notification_preferences' => $merged]);

        AuditHelper::log(
            actionActivity: 'Profile Notification Preferences Updated',
            modulePage: 'Profile',
            modelType: 'User',
            modelId: $user->id,
            recordAffected: 'User: ' . $user->name,
            oldValues: ['notification_preferences' => $old],
            newValues: ['notification_preferences' => $merged],
            action: 'updated',
            actorUser: $user
        );

        return response()->json([
            'message' => 'Notification preferences updated successfully',
            'notification_preferences' => $merged,
        ]);
    }
}

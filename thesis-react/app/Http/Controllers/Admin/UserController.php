<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\AuditHelper;
use App\Http\Controllers\Controller;
use App\Mail\StaffInvitation;
use App\Models\User;
use App\Support\StaffAccountRules;
use App\Support\StaffPasswordLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(['admin', 'receptionist'])],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'sort_by' => ['nullable', Rule::in(['created_at', 'name', 'email', 'role', 'status'])],
            'sort_order' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = User::query();

        if (!empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (!empty($validated['role'])) {
            $query->where('role', $validated['role']);
        }

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $sortBy = $validated['sort_by'] ?? 'created_at';
        $sortOrder = $validated['sort_order'] ?? 'desc';
        $perPage = $validated['per_page'] ?? 10;
        $users = $query->orderBy($sortBy, $sortOrder)->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $users->items(),
            'stats' => [
                'total_admins' => User::where('role', 'admin')->count(),
                'total_receptionists' => User::where('role', 'receptionist')->count(),
                'active_users' => User::where('status', 'active')->count(),
            ],
            'pagination' => [
                'total' => $users->total(),
                'per_page' => $users->perPage(),
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => $this->nameRules(),
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(['admin', 'receptionist'])],
            'phone' => $this->phoneRules(),
            'status' => ['required', Rule::in(['active'])],
            'current_password' => ['required', 'string', 'max:255'],
        ]);

        $this->assertCurrentPassword($request->user(), $validated['current_password']);

        $attributes = collect($validated)->except(['current_password'])->all();
        // No administrator-chosen password is ever issued to a new staff member.
        $attributes['password'] = Hash::make(Str::random(64));
        $user = User::create($attributes);

        $invitationQueued = false;
        try {
            $repository = Password::broker()->getRepository();
            $token = $repository->create($user);
            Mail::to($user->email)->queue(new StaffInvitation(
                userName: $user->name,
                role: $user->role,
                setupLink: StaffPasswordLink::make($user, $token, true),
                expiresIn: config('auth.passwords.users.expire', 30) . ' minutes'
            ));
            $invitationQueued = true;
        } catch (Throwable $exception) {
            Password::broker()->getRepository()->delete($user);
            Log::error('Staff invitation could not be queued.', [
                'user_id' => $user->id,
                'exception' => $exception::class,
            ]);
        }

        AuditHelper::log(
            actionActivity: 'User Created',
            modulePage: 'User Management',
            modelType: 'User',
            modelId: $user->id,
            recordAffected: 'User: ' . $user->name . ' (' . $user->role . ')',
            newValues: [
                ...$user->only(['name', 'email', 'role', 'status', 'phone']),
                'invitation_queued' => $invitationQueued,
            ],
            action: 'created'
        );

        return response()->json([
            'success' => true,
            'message' => $invitationQueued
                ? 'Staff account created. A password setup link was queued for email.'
                : 'Staff account created, but the setup email could not be queued. Ask the staff member to use Forgot Password after mail delivery is restored.',
            'data' => $user,
            'invitation_queued' => $invitationQueued,
        ], 201);
    }

    public function show(int $id)
    {
        return response()->json([
            'success' => true,
            'data' => User::findOrFail($id),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $existingUser = User::findOrFail($id);
        $validated = $request->validate([
            'name' => $this->nameRules(),
            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:255',
                Rule::unique('users', 'email')->ignore($existingUser->id),
            ],
            'role' => ['required', Rule::in(['admin', 'receptionist'])],
            'phone' => $this->phoneRules(),
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'password' => ['nullable', 'confirmed', $this->passwordRule()],
            'current_password' => ['nullable', 'string', 'max:255'],
        ]);

        $actor = $request->user();

        $result = DB::transaction(function () use ($actor, $existingUser, $validated) {
            $user = User::query()->lockForUpdate()->findOrFail($existingUser->id);
            $this->assertCanUpdate($actor, $user, $validated);

            $passwordChanged = !empty($validated['password']);
            $roleChanged = $validated['role'] !== $user->role;
            $statusChanged = $validated['status'] !== $user->status;

            if ($passwordChanged && !$user->is($actor)) {
                abort(403, 'Staff members must set their own passwords through the secure reset link.');
            }

            if ($passwordChanged || $roleChanged || $statusChanged) {
                $this->assertCurrentPassword($actor, $validated['current_password'] ?? null);
            }

            $this->assertActiveAdminContinuity($user, $validated['role'], $validated['status']);

            $oldValues = $user->only(['name', 'email', 'role', 'status', 'phone']);
            $attributes = collect($validated)->except(['current_password'])->all();

            if ($passwordChanged) {
                $attributes['password'] = Hash::make($attributes['password']);
            } else {
                unset($attributes['password']);
            }

            $user->update($attributes);
            $newValues = $user->fresh()->only(['name', 'email', 'role', 'status', 'phone']);
            $diff = AuditHelper::diff($oldValues, $newValues);

            if ($passwordChanged) {
                $diff['old']['password_changed'] = false;
                $diff['new']['password_changed'] = true;
            }

            $sessionRevoked = $passwordChanged || $roleChanged || $validated['status'] === 'inactive';

            if ($sessionRevoked) {
                $user->tokens()->delete();
            }

            return [
                'user' => $user->fresh(),
                'old' => $diff['old'],
                'new' => $diff['new'],
                'session_revoked' => $sessionRevoked,
            ];
        });

        if (!empty($result['old']) || !empty($result['new'])) {
            AuditHelper::log(
                actionActivity: 'User Updated',
                modulePage: 'User Management',
                modelType: 'User',
                modelId: $result['user']->id,
                recordAffected: 'User: ' . $result['user']->name,
                oldValues: $result['old'],
                newValues: $result['new'],
                action: 'updated'
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'data' => $result['user'],
            'session_revoked' => $result['session_revoked'],
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
        ]);
        $actor = $request->user();
        $this->assertCurrentPassword($actor, $validated['current_password']);

        $deactivatedUser = DB::transaction(function () use ($actor, $id) {
            $user = User::query()->lockForUpdate()->findOrFail($id);

            if ($user->is($actor)) {
                abort(403, 'You cannot deactivate your own account.');
            }

            if ($user->role === 'admin') {
                abort(403, 'Admin accounts cannot be deactivated by another admin.');
            }

            if ($user->status === 'inactive') {
                abort(409, 'This account is already inactive and is retained for audit history.');
            }

            $snapshot = $user->only(['name', 'email', 'role', 'status', 'phone']);
            $user->tokens()->delete();
            $user->update(['status' => 'inactive']);

            return ['user' => $user, 'snapshot' => $snapshot];
        });

        AuditHelper::log(
            actionActivity: 'User Access Revoked and Deactivated',
            modulePage: 'User Management',
            modelType: 'User',
            modelId: $deactivatedUser['user']->id,
            recordAffected: 'User: ' . $deactivatedUser['user']->name . ' (' . $deactivatedUser['user']->role . ')',
            oldValues: $deactivatedUser['snapshot'],
            newValues: ['status' => 'inactive', 'access_revoked' => true],
            action: 'deactivated'
        );

        return response()->json([
            'success' => true,
            'message' => 'User access was revoked and the account was deactivated.',
        ]);
    }

    public function toggleStatus(Request $request, int $id)
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
        ]);
        $actor = $request->user();
        $this->assertCurrentPassword($actor, $validated['current_password']);

        $result = DB::transaction(function () use ($actor, $id) {
            $user = User::query()->lockForUpdate()->findOrFail($id);

            if ($user->is($actor)) {
                abort(403, 'You cannot change your own account status.');
            }

            if ($user->role === 'admin') {
                abort(403, 'Admin account status cannot be changed by another admin.');
            }

            $oldStatus = $user->status;
            $newStatus = $oldStatus === 'active' ? 'inactive' : 'active';
            $user->update(['status' => $newStatus]);

            if ($newStatus === 'inactive') {
                $user->tokens()->delete();
            }

            return compact('user', 'oldStatus', 'newStatus');
        });

        AuditHelper::log(
            actionActivity: 'User Status Changed',
            modulePage: 'User Management',
            modelType: 'User',
            modelId: $result['user']->id,
            recordAffected: 'User: ' . $result['user']->name,
            oldValues: ['status' => $result['oldStatus']],
            newValues: ['status' => $result['newStatus']],
            action: 'updated'
        );

        return response()->json([
            'success' => true,
            'message' => "User status updated to {$result['newStatus']}",
            'data' => $result['user']->fresh(),
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct', 'exists:users,id'],
            'current_password' => ['required', 'string', 'max:255'],
        ]);
        $actor = $request->user();
        $this->assertCurrentPassword($actor, $validated['current_password']);

        $deactivatedUsers = DB::transaction(function () use ($actor, $validated) {
            $users = User::whereIn('id', $validated['ids'])->lockForUpdate()->get();

            if ($users->contains(fn (User $user) => $user->is($actor))) {
                abort(403, 'You cannot deactivate your own account.');
            }

            if ($users->contains(fn (User $user) => $user->role === 'admin')) {
                abort(403, 'Admin accounts cannot be deactivated by another admin.');
            }

            if ($users->contains(fn (User $user) => $user->status === 'inactive')) {
                abort(409, 'One or more selected accounts are already inactive.');
            }

            foreach ($users as $user) {
                $snapshot = $user->only(['name', 'email', 'role', 'status', 'phone']);
                $user->tokens()->delete();
                $user->update(['status' => 'inactive']);

                AuditHelper::log(
                    actionActivity: 'User Access Revoked and Deactivated',
                    modulePage: 'User Management',
                    modelType: 'User',
                    modelId: $user->id,
                    recordAffected: 'User: ' . $user->name . ' (' . $user->role . ')',
                    oldValues: $snapshot,
                    newValues: ['status' => 'inactive', 'access_revoked' => true],
                    action: 'deactivated'
                );
            }

            return $users;
        });

        return response()->json([
            'success' => true,
            'message' => $deactivatedUsers->count() . ' users deactivated successfully',
        ]);
    }

    private function assertCanUpdate(User $actor, User $target, array $attributes): void
    {
        if ($target->role === 'admin' && !$target->is($actor)) {
            abort(403, 'Admin accounts cannot be modified by another admin.');
        }

        if ($target->is($actor) && ($attributes['role'] !== $target->role || $attributes['status'] !== $target->status)) {
            abort(403, 'You cannot change your own role or account status.');
        }
    }

    private function assertCurrentPassword(User $actor, ?string $password): void
    {
        if (!$password || !Hash::check($password, $actor->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Your administrator password is incorrect.'],
            ]);
        }
    }

    private function assertActiveAdminContinuity(User $target, string $newRole, string $newStatus): void
    {
        if ($target->role !== 'admin' || $target->status !== 'active') {
            return;
        }

        if ($newRole === 'admin' && $newStatus === 'active') {
            return;
        }

        $otherActiveAdminExists = User::whereKeyNot($target->id)
            ->where('role', 'admin')
            ->where('status', 'active')
            ->lockForUpdate()
            ->exists();

        if (!$otherActiveAdminExists) {
            throw ValidationException::withMessages([
                'status' => ['The system must always have at least one active administrator.'],
            ]);
        }
    }

    private function passwordRule(): \Illuminate\Validation\Rules\Password
    {
        return StaffAccountRules::password();
    }

    private function nameRules(): array
    {
        return StaffAccountRules::name();
    }

    private function phoneRules(): array
    {
        return StaffAccountRules::phone();
    }
}

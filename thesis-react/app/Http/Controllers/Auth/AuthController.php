<?php

namespace App\Http\Controllers\Auth;

use App\Helpers\AuditHelper;
use App\Http\Controllers\Controller;
use App\Mail\PasswordChanged;
use App\Mail\PasswordReset as PasswordResetMail;
use App\Models\User;
use App\Support\StaffAccountRules;
use App\Support\StaffPasswordLink;
use Illuminate\Auth\Events\PasswordReset as PasswordResetEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AuthController extends Controller
{
    private const STAFF_ROLES = ['admin', 'receptionist'];

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|string|email:rfc|max:254',
            'password' => 'required|string|max:255',
        ]);

        $email = strtolower(trim($validated['email']));
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => ['Your account has been deactivated.'],
            ]);
        }

        if (!in_array($user->role, self::STAFF_ROLES, true)) {
            throw ValidationException::withMessages([
                'email' => ['Only staff accounts can sign in here.'],
            ]);
        }

        $tokenName = $user->role === 'admin' ? 'admin-session' : 'receptionist-session';
        $token = $user->createToken($tokenName, ['role:' . $user->role])->plainTextToken;

        AuditHelper::log(
            actionActivity: 'User Logged In',
            modulePage: 'Authentication',
            modelType: 'User',
            modelId: $user->id,
            recordAffected: 'User: ' . $user->name,
            action: 'login',
            actorUser: $user
        );

        return response()->json([
            'message' => 'Login successful',
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        AuditHelper::log(
            actionActivity: 'User Logged Out',
            modulePage: 'Authentication',
            modelType: 'User',
            modelId: $user?->id,
            recordAffected: $user ? 'User: ' . $user->name : 'User logged out',
            action: 'logout'
        );

        $currentToken = $user?->currentAccessToken();

        if ($currentToken) {
            $currentToken->delete();
        } elseif (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    public function forgotPassword(Request $request)
    {
        $startedAt = microtime(true);
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
        ]);
        $email = strtolower(trim($validated['email']));
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('status', 'active')
            ->whereIn('role', self::STAFF_ROLES)
            ->first();

        if ($user) {
            $repository = Password::broker()->getRepository();

            if (!$repository->recentlyCreatedToken($user)) {
                $token = $repository->create($user);

                try {
                    Mail::to($user->email)->queue(new PasswordResetMail(
                        userName: $user->name,
                        resetLink: StaffPasswordLink::make($user, $token),
                        expiresIn: config('auth.passwords.users.expire', 30) . ' minutes'
                    ));

                    AuditHelper::log(
                        actionActivity: 'Password Reset Requested',
                        modulePage: 'Authentication',
                        modelType: 'User',
                        modelId: $user->id,
                        recordAffected: 'User: ' . $user->name,
                        action: 'requested',
                        actorUser: $user,
                        newValues: ['reset_email_queued' => true]
                    );
                } catch (Throwable $exception) {
                    $repository->delete($user);
                    Log::error('Password reset email could not be queued.', [
                        'user_id' => $user->id,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            }
        }

        $this->enforceMinimumResponseTime($startedAt, 300);

        return response()->json([
            'message' => 'If an active staff account uses that email, a reset link has been sent.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $startedAt = microtime(true);
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'token' => ['required', 'string', 'min:40', 'max:512'],
            'password' => [
                'required',
                'string',
                'confirmed',
                StaffAccountRules::password(),
            ],
            'password_confirmation' => ['required', 'string'],
        ]);
        $validated['email'] = strtolower(trim($validated['email']));

        $account = User::query()
            ->whereRaw('LOWER(email) = ?', [$validated['email']])
            ->first();

        if (!$account || $account->status !== 'active' || !in_array($account->role, self::STAFF_ROLES, true)) {
            if ($account) {
                Password::broker()->getRepository()->delete($account);
            }

            return $this->invalidResetResponse($startedAt);
        }

        $resetUser = null;

        try {
            $status = DB::transaction(function () use ($validated, &$resetUser) {
                return Password::broker()->reset(
                    [
                        'email' => $validated['email'],
                        'password' => $validated['password'],
                        'password_confirmation' => $validated['password_confirmation'],
                        'token' => $validated['token'],
                    ],
                    function (User $brokerUser, string $password) use (&$resetUser) {
                        $user = User::query()->lockForUpdate()->find($brokerUser->id);

                        if (!$user || $user->status !== 'active' || !in_array($user->role, self::STAFF_ROLES, true)) {
                            throw new RuntimeException('Account is not eligible for password recovery.');
                        }

                        $user->forceFill([
                            'password' => Hash::make($password),
                            'remember_token' => Str::random(60),
                        ])->save();
                        $user->tokens()->delete();
                        event(new PasswordResetEvent($user));
                        $resetUser = $user;
                    }
                );
            }, 3);
        } catch (RuntimeException) {
            return $this->invalidResetResponse($startedAt);
        }

        if ($status !== Password::PASSWORD_RESET || !$resetUser) {
            return $this->invalidResetResponse($startedAt);
        }

        AuditHelper::log(
            actionActivity: 'Password Reset Completed',
            modulePage: 'Authentication',
            modelType: 'User',
            modelId: $resetUser->id,
            recordAffected: 'User: ' . $resetUser->name,
            action: 'reset',
            actorUser: $resetUser,
            newValues: [
                'password_changed' => true,
                'existing_sessions_revoked' => true,
            ]
        );

        try {
            Mail::to($resetUser->email)->queue(new PasswordChanged($resetUser->name));
        } catch (Throwable $exception) {
            Log::error('Password change confirmation email could not be queued.', [
                'user_id' => $resetUser->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        $this->enforceMinimumResponseTime($startedAt, 200);

        return response()->json([
            'success' => true,
            'message' => 'Password reset successful. All existing sessions were signed out. Please log in.',
        ]);
    }

    private function invalidResetResponse(float $startedAt)
    {
        $this->enforceMinimumResponseTime($startedAt, 200);

        return response()->json([
            'success' => false,
            'message' => 'This password reset link is invalid or expired. Please request a new one.',
        ], 422);
    }

    private function enforceMinimumResponseTime(float $startedAt, int $minimumMilliseconds): void
    {
        $elapsedMicroseconds = (int) ((microtime(true) - $startedAt) * 1_000_000);
        $minimumMicroseconds = $minimumMilliseconds * 1_000;

        if ($elapsedMicroseconds < $minimumMicroseconds) {
            usleep($minimumMicroseconds - $elapsedMicroseconds);
        }
    }
}

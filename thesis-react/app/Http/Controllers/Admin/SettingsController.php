<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\AuditHelper;
use App\Http\Controllers\Controller;
use App\Mail\MailConfigurationTest;
use App\Models\Notification;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\MailConfigurationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SettingsController extends Controller
{
    public function __construct(
        private MailConfigurationService $mailConfigurationService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'hotel_name' => config('app.name', 'H+ Hotel'),
            'contact_email' => config('mail.from.address', ''),
            'contact_phone' => '',
            'address' => '',
            'check_in_time' => '15:00',
            'check_out_time' => '12:00',
            'cancellation_policy' => '',
            'downpayment_percentage' => 50,
            'tax_percentage' => 12,
            'site_url' => config('app.frontend_url', ''),
            'timezone' => config('app.timezone', 'Asia/Manila'),
            'currency' => 'PHP',
            'email_notifications' => true,
            'booking_notifications' => true,
            'maintenance_mode' => false,
        ];
    }

    public function index()
    {
        $settings = SystemSetting::readMany($this->defaults());

        return response()->json($settings);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'hotel_name' => 'sometimes|string|max:255',
            'contact_email' => 'sometimes|nullable|email',
            'contact_phone' => 'sometimes|nullable|string|max:20',
            'address' => 'sometimes|nullable|string',
            'check_in_time' => 'sometimes|date_format:H:i',
            'check_out_time' => 'sometimes|date_format:H:i',
            'cancellation_policy' => 'sometimes|nullable|string',
            'downpayment_percentage' => 'sometimes|numeric|min:1|max:100',
            'tax_percentage' => 'sometimes|numeric|min:0|max:100',
            'site_url' => 'sometimes|nullable|url|max:255',
            'timezone' => 'sometimes|string|max:100',
            'currency' => 'sometimes|string|max:10',
            'email_notifications' => 'sometimes|boolean',
            'booking_notifications' => 'sometimes|boolean',
            'maintenance_mode' => 'sometimes|boolean',
        ]);

        $oldSettings = [];
        $current = SystemSetting::readMany($this->defaults());
        foreach ($validated as $key => $value) {
            $oldSettings[$key] = $current[$key] ?? null;
        }
        $newSettings = $validated;

        SystemSetting::writeMany($validated);

        // Keep cache hot for older code paths still reading `settings.*`.
        foreach ($validated as $key => $value) {
            Cache::put("settings.{$key}", $value);
        }

        AuditHelper::log(
            actionActivity: 'System Settings Updated',
            modulePage:     'Settings',
            modelType:      'Settings',
            modelId:        0,
            recordAffected: 'System Settings',
            oldValues:      $oldSettings,
            newValues:      $newSettings,
            action:         'updated',
            actorUser:      Auth::user()
        );

        // Notify all admins
        $admins = User::where('role', 'admin')->get();
        foreach ($admins as $admin) {
            Notification::create([
                'user_id' => $admin->id,
                'type' => 'settings_updated',
                'title' => 'System Settings Updated',
                'message' => 'System settings have been updated by ' . Auth::user()->name,
            ]);
        }

        return response()->json([
            'message' => 'Settings updated successfully',
            'settings' => $newSettings,
        ]);
    }

    public function mailStatus(Request $request)
    {
        return response()->json([
            'data' => [
                ...$this->mailConfigurationService->status(),
                'test_recipient' => $this->mailConfigurationService->maskIdentifier($request->user()?->email),
            ],
        ]);
    }

    public function sendTestEmail(Request $request)
    {
        $administrator = $request->user();
        $status = $this->mailConfigurationService->status();

        if (!$status['configured']) {
            return response()->json([
                'message' => 'Email delivery is not fully configured. Resolve the listed configuration issues first.',
                'issues' => $status['issues'],
            ], 422);
        }

        if (!$administrator || !filter_var($administrator->email, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'message' => 'Your administrator account does not have a valid email address.',
            ], 422);
        }

        $recipient = $this->mailConfigurationService->maskIdentifier($administrator->email);

        try {
            Mail::to($administrator->email)->send(new MailConfigurationTest(
                administratorName: $administrator->name,
                environment: (string) config('app.env', 'production'),
                sentAt: now()->format('F j, Y g:i A T'),
            ));

            AuditHelper::log(
                actionActivity: 'Email Delivery Test Sent',
                modulePage: 'Settings',
                modelType: 'Mail Configuration',
                modelId: 0,
                recordAffected: $recipient,
                newValues: ['recipient' => $recipient, 'result' => 'sent'],
                action: 'tested',
                actorUser: $administrator,
            );

            return response()->json([
                'message' => "Test email sent to {$recipient}.",
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Administrator email delivery test failed', [
                'user_id' => $administrator->id,
                'exception' => $exception::class,
            ]);

            AuditHelper::log(
                actionActivity: 'Email Delivery Test Failed',
                modulePage: 'Settings',
                modelType: 'Mail Configuration',
                modelId: 0,
                recordAffected: $recipient,
                newValues: ['recipient' => $recipient, 'result' => 'failed'],
                action: 'tested',
                actorUser: $administrator,
            );

            return response()->json([
                'message' => 'The test email could not be delivered. Check the server mail configuration and application logs.',
            ], 502);
        }
    }

    public function getNotifications(Request $request)
    {
        $userId = Auth::id();

        $notifications = Notification::where('user_id', $userId)
            ->orWhereNull('user_id') // system-wide notifications
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

        return response()->json($notifications);
    }

    public function markNotificationAsRead($id)
    {
        $notification = Notification::findOrFail($id);
        
        if ($notification->user_id !== Auth::id() && !is_null($notification->user_id)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $notification->markAsRead();

        return response()->json([
            'message' => 'Notification marked as read',
        ]);
    }

    public function markAllNotificationsAsRead()
    {
        Notification::where('user_id', Auth::id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => 'All notifications marked as read',
        ]);
    }

    public function deleteNotification($id)
    {
        $notification = Notification::findOrFail($id);
        
        if ($notification->user_id !== Auth::id() && !is_null($notification->user_id)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $notification->delete();

        return response()->json([
            'message' => 'Notification deleted',
        ]);
    }
}

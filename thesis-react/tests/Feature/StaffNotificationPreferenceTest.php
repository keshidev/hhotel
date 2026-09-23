<?php

namespace Tests\Feature;

use App\Mail\NewBookingAdminNotification;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\BookingRoom;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Room;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\StaffNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StaffNotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_preferences_notify_only_active_staff_by_email_and_in_app(): void
    {
        Mail::fake();
        SystemSetting::writeMany([
            'email_notifications' => true,
            'booking_notifications' => true,
        ]);
        $this->createStaff('admin@example.com', 'admin', 'active');
        $this->createStaff('reception@example.com', 'receptionist', 'active');
        $this->createStaff('inactive@example.com', 'admin', 'inactive');

        $result = app(StaffNotificationService::class)->notifyNewBooking($this->createBooking());

        $this->assertSame(['emails_queued' => 2, 'in_app_created' => 2], $result);
        Mail::assertQueued(NewBookingAdminNotification::class, 2);
        Mail::assertNotQueued(NewBookingAdminNotification::class, fn ($mail) => $mail->hasTo('inactive@example.com'));
        $this->assertSame(2, Notification::where('type', 'booking_created')->count());
    }

    public function test_email_master_switch_keeps_in_app_booking_alerts_enabled(): void
    {
        Mail::fake();
        SystemSetting::writeMany([
            'email_notifications' => false,
            'booking_notifications' => true,
        ]);
        $this->createStaff('admin@example.com', 'admin', 'active');

        $result = app(StaffNotificationService::class)->notifyNewBooking($this->createBooking());

        $this->assertSame(['emails_queued' => 0, 'in_app_created' => 1], $result);
        Mail::assertNothingQueued();
        $this->assertDatabaseHas('notifications', [
            'type' => 'booking_created',
            'title' => 'New Confirmed Booking',
        ]);
    }

    public function test_booking_switch_disables_both_optional_staff_alert_channels(): void
    {
        Mail::fake();
        SystemSetting::writeMany([
            'email_notifications' => true,
            'booking_notifications' => false,
        ]);
        $this->createStaff('admin@example.com', 'admin', 'active');

        $result = app(StaffNotificationService::class)->notifyNewBooking($this->createBooking());

        $this->assertSame(['emails_queued' => 0, 'in_app_created' => 0], $result);
        Mail::assertNothingQueued();
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_unpaid_online_booking_does_not_notify_staff(): void
    {
        Mail::fake();
        SystemSetting::writeMany([
            'email_notifications' => true,
            'booking_notifications' => true,
        ]);
        $this->createStaff('admin@example.com', 'admin', 'active');

        $booking = $this->createBooking(paid: false);
        $result = app(StaffNotificationService::class)->notifyNewBooking($booking);

        $this->assertSame(['emails_queued' => 0, 'in_app_created' => 0], $result);
        Mail::assertNothingQueued();
        $this->assertDatabaseCount('notifications', 0);
        $this->assertNull($booking->fresh()->staff_booking_notified_at);
    }

    public function test_confirmed_booking_notification_is_sent_only_once(): void
    {
        Mail::fake();
        SystemSetting::writeMany([
            'email_notifications' => true,
            'booking_notifications' => true,
        ]);
        $this->createStaff('admin@example.com', 'admin', 'active');

        $booking = $this->createBooking();
        $service = app(StaffNotificationService::class);

        $this->assertSame(
            ['emails_queued' => 1, 'in_app_created' => 1],
            $service->notifyNewBooking($booking)
        );
        $this->assertSame(
            ['emails_queued' => 0, 'in_app_created' => 0],
            $service->notifyNewBooking($booking->fresh())
        );

        Mail::assertQueued(NewBookingAdminNotification::class, 1);
        $this->assertSame(1, Notification::where('type', 'booking_created')->count());
        $this->assertNotNull($booking->fresh()->staff_booking_notified_at);
    }

    public function test_staff_visibility_hides_only_unpaid_online_bookings(): void
    {
        $unpaidOnline = $this->createBooking(paid: false, source: 'online');
        $paidOnline = $this->createBooking(paid: true, source: 'online');
        $walkIn = $this->createBooking(paid: false, source: 'walk_in');

        $visibleIds = Booking::visibleToStaff()->pluck('id');

        $this->assertFalse($visibleIds->contains($unpaidOnline->id));
        $this->assertTrue($visibleIds->contains($paidOnline->id));
        $this->assertTrue($visibleIds->contains($walkIn->id));
    }

    private function createStaff(string $email, string $role, string $status): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $email,
            'password' => Hash::make('StagingPassword123!'),
            'role' => $role,
            'status' => $status,
        ]);
    }

    private function createBooking(bool $paid = true, string $source = 'online'): Booking
    {
        $booking = Booking::create([
            'check_in' => now()->addDay(),
            'check_out' => now()->addDays(2),
            'number_of_guests' => 2,
            'booking_status' => $paid ? 'confirmed' : 'pending',
            'reservation_status' => $paid ? 'confirmed' : 'pending_verification',
            'total_amount' => 1500,
            'downpayment_percentage' => 50,
            'booking_source' => $source,
        ]);

        BookingGuest::create([
            'booking_id' => $booking->id,
            'name' => 'Test Guest',
            'email' => 'guest@example.com',
            'phone' => '09171234567',
            'is_primary' => true,
        ]);
        $room = Room::create([
            'room_number' => 'NOTIFY-'.strtoupper(substr(uniqid(), -8)),
            'room_type' => 'deluxe',
            'capacity' => 2,
            'price_per_night' => 1500,
            'floor' => 1,
            'status' => 'available',
            'description' => 'Notification preference test room',
        ]);
        BookingRoom::create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'requested_room_type' => 'deluxe',
            'price_per_night' => 1500,
            'nights' => 1,
            'subtotal' => 1500,
        ]);
        Payment::create([
            'booking_id' => $booking->id,
            'amount' => 750,
            'payment_type' => 'downpayment',
            'payment_method' => 'gcash',
            'payment_status' => $paid ? 'completed' : 'pending',
            'paid_amount' => $paid ? 750 : null,
            'paid_at' => $paid ? now() : null,
            'provider' => 'manual_gcash',
        ]);

        return $booking;
    }
}

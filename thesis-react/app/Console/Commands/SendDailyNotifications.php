<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\NotificationHelper;
use App\Models\Booking;
use App\Models\Payment;
use Carbon\Carbon;

class SendDailyNotifications extends Command
{
    protected $signature   = 'notifications:daily
                                {--summary      : Send daily summary to admin}
                                {--arrivals     : Send arrival + checkout alerts to receptionist}
                                {--late-checkin : Send late check-in alerts}
                                {--pending      : Send pending payment reminders}
                                {--all          : Run all of the above}';

    protected $description = 'Send scheduled daily notifications to admin and receptionist';

    public function handle(): void
    {
        $all = $this->option('all');

        if ($all || $this->option('summary')) {
            $this->sendDailySummary();
        }

        if ($all || $this->option('arrivals')) {
            $this->sendArrivalAlerts();
            $this->sendCheckoutAlerts();
        }

        if ($all || $this->option('late-checkin')) {
            $this->sendLateCheckinAlerts();
        }

        if ($all || $this->option('pending')) {
            $this->sendPendingPaymentReminders();
        }

        $this->info('Daily notifications sent.');
    }

    // ── Daily Summary for Admin ───────────────────────────────────────────────
    private function sendDailySummary(): void
    {
        $today = Carbon::today();

        $checkins  = Booking::whereDate('check_in', $today)
                            ->where('booking_status', 'confirmed')
                            ->count();

        $checkouts = Booking::whereDate('check_out', $today)
                            ->where('booking_status', 'checked_in')
                            ->count();

        $inHouse   = Booking::where('booking_status', 'checked_in')->count();

        // Pending = payment record created but manual GCash approval is incomplete.
        $pendingPayments = Payment::where('payment_status', 'pending')->count();

        // ✅ FIXED: was ('payment_status', 'accepted') + verified_at
        // Manual GCash approval marks payments completed and records paid_at.
        $revenue = Payment::where('payment_status', 'completed')
                          ->whereDate('paid_at', $today)
                          ->sum('amount');

        NotificationHelper::dailySummary([
            'checkins'         => $checkins,
            'checkouts'        => $checkouts,
            'in_house'         => $inHouse,
            'revenue'          => '₱' . number_format($revenue, 2),
            'pending_payments' => $pendingPayments,
        ]);

        $this->line("Daily summary sent (checkins: {$checkins}, checkouts: {$checkouts}).");
    }

    // ── Arrival Alerts for Receptionist ──────────────────────────────────────
    private function sendArrivalAlerts(): void
    {
        $arrivals = Booking::whereDate('check_in', Carbon::today())
                           ->whereIn('booking_status', ['confirmed', 'pending'])
                           ->with(['primaryGuest', 'bookingRooms.room'])
                           ->get();

        foreach ($arrivals as $booking) {
            $alreadySent = \App\Models\Notification::where('type', 'guest_arriving_today')
                ->where('data->booking_id', $booking->reference_number)
                ->whereDate('created_at', Carbon::today())
                ->exists();

            if ($alreadySent) continue;

            NotificationHelper::guestArrivingToday([
                'id'         => $booking->reference_number,
                'guest_name' => $this->getGuestName($booking),
                'room'       => $this->getRoomNumber($booking),
                'check_in'   => Carbon::parse($booking->check_in)->format('M j'),
            ]);
        }

        $this->line("Arrival alerts sent: {$arrivals->count()}");
    }

    private function sendCheckoutAlerts(): void
    {
        $checkouts = Booking::whereDate('check_out', Carbon::today())
                            ->where('booking_status', 'checked_in')
                            ->with(['primaryGuest', 'bookingRooms.room'])
                            ->get();

        foreach ($checkouts as $booking) {
            $alreadySent = \App\Models\Notification::where('type', 'guest_checking_out_today')
                ->where('data->booking_id', $booking->reference_number)
                ->whereDate('created_at', Carbon::today())
                ->exists();

            if ($alreadySent) continue;

            NotificationHelper::guestCheckingOutToday([
                'id'         => $booking->reference_number,
                'guest_name' => $this->getGuestName($booking),
                'room'       => $this->getRoomNumber($booking),
                'check_out'  => Carbon::parse($booking->check_out)->format('M j'),
            ]);
        }

        $this->line("Checkout alerts sent: {$checkouts->count()}");
    }

    // ── Late Check-in Alerts (after 6 PM) ────────────────────────────────────
    private function sendLateCheckinAlerts(): void
    {
        $lateArrivals = Booking::whereDate('check_in', Carbon::today())
                               ->where('booking_status', 'confirmed')
                               ->with(['primaryGuest', 'bookingRooms.room'])
                               ->get();

        foreach ($lateArrivals as $booking) {
            NotificationHelper::guestLateCheckin([
                'id'         => $booking->reference_number,
                'guest_name' => $this->getGuestName($booking),
                'room'       => $this->getRoomNumber($booking),
            ]);
        }

        $this->line("Late check-in alerts sent: {$lateArrivals->count()}");
    }

    // ── Pending Payment Reminders ─────────────────────────────────────────────
    private function sendPendingPaymentReminders(): void
    {
        // These are bookings where the guest has not completed GCash payment yet
        $count = Payment::where('payment_status', 'pending')->count();
        NotificationHelper::paymentPendingSummary($count);
        $this->line("Pending payment reminder sent: {$count} pending.");
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function getGuestName(Booking $booking): string
    {
        return $booking->primaryGuest?->name ?? 'Guest';
    }

    private function getRoomNumber(Booking $booking): string
    {
        $firstRoom = $booking->bookingRooms?->first();
        return $firstRoom?->room?->room_number
            ?? $firstRoom?->room?->name
            ?? $firstRoom?->room?->number
            ?? 'N/A';
    }
}

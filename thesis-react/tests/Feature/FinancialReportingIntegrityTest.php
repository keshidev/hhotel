<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Services\FinancialReportingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinancialReportingIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_financial_summary_subtracts_explicit_refunds_once(): void
    {
        $booking = $this->booking('BK-FINANCIAL-SUMMARY');
        $this->payment($booking, 1000, Payment::TYPE_DOWNPAYMENT, 'completed');
        $this->payment($booking, 300, Payment::TYPE_REFUND, 'refunded');

        $summary = app(FinancialReportingService::class)->summary();

        $this->assertSame(1000.0, $summary['gross_revenue']);
        $this->assertSame(300.0, $summary['total_refunds']);
        $this->assertSame(700.0, $summary['net_revenue']);
    }

    public function test_refunded_source_status_is_not_counted_as_a_second_refund(): void
    {
        $booking = $this->booking('BK-FINANCIAL-NO-DOUBLE-REFUND');
        $this->payment($booking, 500, Payment::TYPE_DOWNPAYMENT, 'refunded');
        $this->payment($booking, 500, Payment::TYPE_REFUND, 'refunded');

        $summary = app(FinancialReportingService::class)->summary();

        $this->assertSame(0.0, $summary['gross_revenue']);
        $this->assertSame(500.0, $summary['total_refunds']);
        $this->assertSame(-500.0, $summary['net_revenue']);
    }

    public function test_revenue_report_keeps_negative_net_and_includes_refund_only_activity(): void
    {
        $this->actingAdmin();
        $booking = $this->booking('BK-FINANCIAL-REFUND-ONLY');
        $this->payment($booking, 250, Payment::TYPE_REFUND, 'refunded');

        $date = now()->toDateString();

        $this->getJson("/api/admin/reports/revenue?start_date={$date}&end_date={$date}")
            ->assertOk()
            ->assertJsonPath('data.stats.gross_revenue', 0)
            ->assertJsonPath('data.stats.total_refunds', 250)
            ->assertJsonPath('data.stats.total_revenue', -250)
            ->assertJsonPath('data.stats.total_bookings', 1)
            ->assertJsonPath('data.transactions.0.reference', $booking->reference_number)
            ->assertJsonPath('data.transactions.0.amount_collected', 0)
            ->assertJsonPath('data.transactions.0.amount_refunded', 250)
            ->assertJsonPath('data.transactions.0.net_amount', -250);
    }

    public function test_dashboard_uses_the_same_canonical_financial_summary(): void
    {
        $this->actingAdmin();
        $booking = $this->booking('BK-FINANCIAL-DASHBOARD');
        $this->payment($booking, 100, Payment::TYPE_DOWNPAYMENT, 'completed');
        $this->payment($booking, 150, Payment::TYPE_REFUND, 'refunded');

        $this->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('stats.total_gross_revenue', 100)
            ->assertJsonPath('stats.total_refunds', 150)
            ->assertJsonPath('stats.total_revenue', -50)
            ->assertJsonPath('stats.monthly_gross_revenue', 100)
            ->assertJsonPath('stats.monthly_refunds', 150)
            ->assertJsonPath('stats.monthly_revenue', -50);
    }

    public function test_revenue_chart_uses_explicit_refund_rows_without_double_subtraction(): void
    {
        $this->actingAdmin();
        $booking = $this->booking('BK-FINANCIAL-CHART');
        $this->payment($booking, 100, Payment::TYPE_DOWNPAYMENT, 'completed');
        $this->payment($booking, 150, Payment::TYPE_REFUND, 'refunded');
        $this->payment($booking, 999, Payment::TYPE_DOWNPAYMENT, 'refunded');

        $this->getJson('/api/admin/dashboard/revenue-chart?period=day')
            ->assertOk()
            ->assertJsonPath('0.total', -50);
    }

    private function actingAdmin(): User
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function booking(string $reference): Booking
    {
        return Booking::create([
            'reference_number' => $reference,
            'check_in' => now()->toDateString(),
            'check_out' => now()->addDay()->toDateString(),
            'number_of_guests' => 1,
            'booking_status' => 'confirmed',
            'reservation_status' => 'confirmed',
            'total_amount' => 1000,
            'tax_rate' => 0.12,
        ]);
    }

    private function payment(Booking $booking, float $amount, string $type, string $status): Payment
    {
        return Payment::create([
            'booking_id' => $booking->id,
            'amount' => $amount,
            'payment_type' => $type,
            'payment_method' => 'gcash',
            'payment_status' => $status,
            'provider' => 'manual_gcash',
            'paid_at' => now(),
        ]);
    }
}

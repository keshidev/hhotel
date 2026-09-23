<?php

namespace Tests\Feature;

use App\Mail\BookingConfirmation;
use App\Models\Booking;
use App\Models\BookingGuest;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class BookingConfirmationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmation_email_has_bounded_retry_policy(): void
    {
        $mail = new BookingConfirmation($this->createBooking());

        $this->assertInstanceOf(ShouldQueueAfterCommit::class, $mail);
        $this->assertSame(5, $mail->tries);
        $this->assertSame(60, $mail->timeout);
        $this->assertSame(5, $mail->maxExceptions);
        $this->assertSame([60, 300, 900, 1800], $mail->backoff());
    }

    public function test_queueing_confirmation_records_delivery_state(): void
    {
        config()->set('queue.default', 'database');

        $booking = $this->createBooking();

        $this->assertTrue(Schema::hasTable('jobs'));
        $this->assertTrue(Schema::hasTable('failed_jobs'));

        Mail::to('confirmation@example.com')->queue(new BookingConfirmation($booking));

        $booking->refresh();

        $this->assertSame('queued', $booking->confirmation_email_status);
        $this->assertNotNull($booking->confirmation_email_queued_at);
        $this->assertNull($booking->confirmation_email_sent_at);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_successful_confirmation_send_records_delivery(): void
    {
        config()->set('mail.default', 'array');

        $booking = $this->createBooking();

        Mail::to('confirmation@example.com')->sendNow(new BookingConfirmation($booking));

        $booking->refresh();

        $this->assertSame('sent', $booking->confirmation_email_status);
        $this->assertSame(1, $booking->confirmation_email_attempts);
        $this->assertNotNull($booking->confirmation_email_sent_at);
        $this->assertNull($booking->confirmation_email_failed_at);
        $this->assertNull($booking->confirmation_email_last_error);
    }

    public function test_transient_send_failure_records_retry_state_and_rethrows(): void
    {
        $booking = $this->createBooking();
        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldReceive('send')->once()->andThrow(new RuntimeException('Temporary SMTP failure'));

        try {
            (new BookingConfirmation($booking))->send($mailer);
            $this->fail('Expected the SMTP exception to be rethrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('Temporary SMTP failure', $e->getMessage());
        }

        $booking->refresh();

        $this->assertSame('retrying', $booking->confirmation_email_status);
        $this->assertSame(1, $booking->confirmation_email_attempts);
        $this->assertSame('Temporary SMTP failure', $booking->confirmation_email_last_error);
        $this->assertNull($booking->confirmation_email_failed_at);
    }

    public function test_exhausted_confirmation_delivery_records_terminal_failure(): void
    {
        $booking = $this->createBooking();

        (new BookingConfirmation($booking))->failed(new RuntimeException('SMTP retries exhausted'));

        $booking->refresh();

        $this->assertSame('failed', $booking->confirmation_email_status);
        $this->assertNotNull($booking->confirmation_email_failed_at);
        $this->assertSame('SMTP retries exhausted', $booking->confirmation_email_last_error);
    }

    private function createBooking(): Booking
    {
        $booking = Booking::create([
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(2)->toDateString(),
            'number_of_guests' => 1,
            'booking_status' => 'confirmed',
            'reservation_status' => 'confirmed',
            'total_amount' => 1000,
        ]);

        BookingGuest::create([
            'booking_id' => $booking->id,
            'name' => 'Confirmation Guest',
            'email' => 'confirmation@example.com',
            'phone' => '09170000001',
            'is_primary' => true,
        ]);

        return $booking->load(['primaryGuest', 'bookingRooms.room', 'payments', 'promoCode']);
    }
}

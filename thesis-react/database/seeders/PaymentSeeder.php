<?php

namespace Database\Seeders;

use App\Models\Payment;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class PaymentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Payment for Booking 1 - Downpayment completed
        Payment::create([
            'booking_id' => 1,
            'amount' => 2250.00, // 50% downpayment
            'payment_type' => 'downpayment',
            'payment_method' => 'gcash',
            'payment_status' => 'completed',
            'transaction_reference' => 'GCASH' . time() . '001',
            'proof_image' => 'payments/gcash-proof-001.jpg',
            'paid_at' => Carbon::now()->subDays(5),
            'verified_by' => 2,
            'verified_at' => Carbon::now()->subDays(5)->addHours(2),
            'notes' => 'Verified GCash payment',
        ]);

        // Payment for Booking 2 - Full payment completed
        Payment::create([
            'booking_id' => 2,
            'amount' => 12500.00,
            'payment_type' => 'full_payment',
            'payment_method' => 'credit_card',
            'payment_status' => 'completed',
            'transaction_reference' => 'CC' . time() . '002',
            'proof_image' => null,
            'paid_at' => Carbon::now()->subDays(10),
            'verified_by' => 3,
            'verified_at' => Carbon::now()->subDays(10)->addMinutes(30),
            'notes' => 'Credit card payment processed successfully',
        ]);

        // Payment for Booking 3 - Full payment (checked out)
        Payment::create([
            'booking_id' => 3,
            'amount' => 6000.00,
            'payment_type' => 'downpayment',
            'payment_method' => 'bank_transfer',
            'payment_status' => 'completed',
            'transaction_reference' => 'BT' . time() . '003',
            'proof_image' => 'payments/bank-transfer-003.jpg',
            'paid_at' => Carbon::now()->subDays(25),
            'verified_by' => 2,
            'verified_at' => Carbon::now()->subDays(24),
            'notes' => 'Bank transfer verified',
        ]);

        Payment::create([
            'booking_id' => 3,
            'amount' => 6000.00,
            'payment_type' => 'full_payment',
            'payment_method' => 'cash',
            'payment_status' => 'completed',
            'transaction_reference' => 'CASH' . time() . '004',
            'proof_image' => null,
            'paid_at' => Carbon::now()->subDays(7),
            'verified_by' => 2,
            'verified_at' => Carbon::now()->subDays(7),
            'notes' => 'Balance paid in cash upon checkout',
        ]);

        // Payment for Booking 4 - Downpayment completed
        Payment::create([
            'booking_id' => 4,
            'amount' => 12250.00, // 50% downpayment
            'payment_type' => 'downpayment',
            'payment_method' => 'gcash',
            'payment_status' => 'completed',
            'transaction_reference' => 'GCASH' . time() . '005',
            'proof_image' => 'payments/gcash-proof-005.jpg',
            'paid_at' => Carbon::now()->subDays(15),
            'verified_by' => 4,
            'verified_at' => Carbon::now()->subDays(15)->addHours(1),
            'notes' => 'Anniversary package downpayment',
        ]);

        // Payment for Booking 5 - Pending payment
        Payment::create([
            'booking_id' => 5,
            'amount' => 2500.00,
            'payment_type' => 'downpayment',
            'payment_method' => 'gcash',
            'payment_status' => 'pending',
            'transaction_reference' => null,
            'proof_image' => 'payments/gcash-proof-006.jpg',
            'paid_at' => null,
            'verified_by' => null,
            'verified_at' => null,
            'notes' => 'Awaiting verification',
        ]);

        // Payment for Booking 6 - Full payment completed
        Payment::create([
            'booking_id' => 6,
            'amount' => 20000.00,
            'payment_type' => 'full_payment',
            'payment_method' => 'bank_transfer',
            'payment_status' => 'completed',
            'transaction_reference' => 'BT' . time() . '007',
            'proof_image' => 'payments/bank-transfer-007.jpg',
            'paid_at' => Carbon::now()->subDays(20),
            'verified_by' => 3,
            'verified_at' => Carbon::now()->subDays(19),
            'notes' => 'Corporate booking - full payment via bank transfer',
        ]);

        // Payment for Booking 7 - Downpayment completed
        Payment::create([
            'booking_id' => 7,
            'amount' => 3750.00,
            'payment_type' => 'downpayment',
            'payment_method' => 'credit_card',
            'payment_status' => 'completed',
            'transaction_reference' => 'CC' . time() . '008',
            'proof_image' => null,
            'paid_at' => Carbon::now()->subDays(2),
            'verified_by' => 4,
            'verified_at' => Carbon::now()->subDays(2)->addMinutes(15),
            'notes' => 'Credit card processed',
        ]);

        // Payment for Booking 8 - Refunded (cancelled booking)
        Payment::create([
            'booking_id' => 8,
            'amount' => 2250.00,
            'payment_type' => 'downpayment',
            'payment_method' => 'gcash',
            'payment_status' => 'completed',
            'transaction_reference' => 'GCASH' . time() . '009',
            'proof_image' => 'payments/gcash-proof-009.jpg',
            'paid_at' => Carbon::now()->subDays(8),
            'verified_by' => 2, 
            'verified_at' => Carbon::now()->subDays(8),
            'notes' => 'Original payment before cancellation',
        ]);

        Payment::create([
            'booking_id' => 8,
            'amount' => 1800.00, // Partial refund after 20% cancellation fee
            'payment_type' => 'refund',
            'payment_method' => 'gcash',
            'payment_status' => 'completed',
            'transaction_reference' => 'REFUND' . time() . '010',
            'proof_image' => 'payments/refund-proof-010.jpg',
            'paid_at' => Carbon::now()->subDays(3),
            'verified_by' => 1,
            'verified_at' => Carbon::now()->subDays(3),
            'notes' => 'Refund processed after 20% cancellation fee deduction',
        ]);

        // Payment for Booking 9 - Downpayment completed
        Payment::create([
            'booking_id' => 9,
            'amount' => 8250.00,
            'payment_type' => 'downpayment',
            'payment_method' => 'bank_transfer',
            'payment_status' => 'completed',
            'transaction_reference' => 'BT' . time() . '011',
            'proof_image' => 'payments/bank-transfer-011.jpg',
            'paid_at' => Carbon::now()->subDays(12),
            'verified_by' => 3,
            'verified_at' => Carbon::now()->subDays(11),
            'notes' => 'Corporate group booking downpayment',
        ]);

        // Payment for Booking 10 - Full payment (checked out)
        Payment::create([
            'booking_id' => 10,
            'amount' => 2250.00,
            'payment_type' => 'downpayment',
            'payment_method' => 'gcash',
            'payment_status' => 'completed',
            'transaction_reference' => 'GCASH' . time() . '012',
            'proof_image' => 'payments/gcash-proof-012.jpg',
            'paid_at' => Carbon::now()->subDays(18),
            'verified_by' => 4,
            'verified_at' => Carbon::now()->subDays(18),
            'notes' => 'Downpayment received',
        ]);

        Payment::create([
            'booking_id' => 10,
            'amount' => 2250.00,
            'payment_type' => 'full_payment',
            'payment_method' => 'cash',
            'payment_status' => 'completed',
            'transaction_reference' => 'CASH' . time() . '013',
            'proof_image' => null,
            'paid_at' => Carbon::now()->subDays(2),
            'verified_by' => 4,
            'verified_at' => Carbon::now()->subDays(2),
            'notes' => 'Balance paid at checkout',
        ]);

        // Failed payment example
        Payment::create([
            'booking_id' => 5,
            'amount' => 2500.00,
            'payment_type' => 'downpayment',
            'payment_method' => 'credit_card',
            'payment_status' => 'failed',
            'transaction_reference' => 'CC' . time() . '014',
            'proof_image' => null,
            'paid_at' => null,
            'verified_by' => null,
            'verified_at' => null,
            'notes' => 'Credit card declined - insufficient funds',
        ]);
    }
}
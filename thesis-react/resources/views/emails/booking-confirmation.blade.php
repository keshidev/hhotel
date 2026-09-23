@extends('emails.layouts.email')

@section('email_title', 'Booking Confirmation')
@section('preheader', 'Your H+ Hotel booking is confirmed. Review your stay and payment details.')
@section('heading', 'Booking Confirmation')
@section('subheading', 'Your reservation details')

@section('extra_styles')
    .summary-total { font-size:15px; font-weight:700; color:#0f172a; }
    .summary-discount { color:#15803d !important; font-weight:700; }
    .mail-policy-item { padding:10px 0; border-bottom:1px solid #e2e8f0; }
    .mail-policy-item:first-child { padding-top:0; }
    .mail-policy-item:last-child { border-bottom:none; padding-bottom:0; }
    .mail-policy-item strong { display:block; color:#0f172a; font-size:13px; margin-bottom:4px; }
    .mail-policy-item p { margin:0; color:#334155; line-height:1.6; font-size:13px; }
@endsection

@section('content')
@php
    $statusLabelMap = [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'checked_in' => 'Checked In',
        'checked_out' => 'Checked Out',
        'no_show' => 'No-Show',
        'cancelled' => 'Cancelled',
    ];
    $statusClassMap = [
        'pending' => 'mail-badge-warning',
        'confirmed' => 'mail-badge-success',
        'checked_in' => 'mail-badge-success',
        'checked_out' => 'mail-badge-success',
        'no_show' => 'mail-badge-danger',
        'cancelled' => 'mail-badge-danger',
    ];

    $status = (string) ($booking->booking_status ?? 'pending');
    $statusLabel = $statusLabelMap[$status] ?? ucfirst(str_replace('_', ' ', $status));
    $statusClass = $statusClassMap[$status] ?? 'mail-badge-warning';

    $checkIn = $booking->check_in ? \Carbon\Carbon::parse($booking->check_in) : null;
    $checkOut = $booking->check_out ? \Carbon\Carbon::parse($booking->check_out) : null;
    $nights = $checkIn && $checkOut ? max(1, $checkIn->diffInDays($checkOut)) : 0;
    $canShowRoomNumber = (string) ($booking->room_assignment_status ?? 'pending_assignment') === 'assigned';

    $roomSubtotal = (float) $booking->bookingRooms->sum(fn ($line) => (float) ($line->subtotal ?? 0));
    $discountAmount = (float) ($booking->discount_amount ?? 0);
    $totalAmount = (float) ($booking->total_amount ?? 0);
    $taxAmount = (float) ($booking->tax_amount ?? 0);

    $addonsBreakdown = is_array($booking->addons_breakdown) ? $booking->addons_breakdown : [];
    $roomLines = $booking->bookingRooms->map(function ($line) use ($addonsBreakdown, $canShowRoomNumber) {
        $room = $line->room;
        $roomAddons = [];
        foreach (array_filter([
            $line->id !== null ? (string) ((int) $line->id) : null,
            $line->room_id !== null ? (string) ((int) $line->room_id) : null,
        ]) as $roomKey) {
            if (isset($addonsBreakdown[$roomKey]) && is_array($addonsBreakdown[$roomKey])) {
                $roomAddons = $addonsBreakdown[$roomKey];
                break;
            }
        }

        $resolvedRoomType = $room?->room_type ?? $line->requested_room_type;

        $amenities = $room?->amenities ?? [];
        if (is_string($amenities)) {
            $amenities = json_decode($amenities, true) ?? [];
        }
        if (!is_array($amenities)) {
            $amenities = [];
        }

        $bedType = match ((string) ($resolvedRoomType ?? '')) {
            'superior_twin' => 'Twin Beds',
            'superior_queen' => 'Queen Bed',
            'family' => 'Family Bed Setup',
            'executive_suite', 'premier', 'deluxe' => 'King Bed',
            default => 'Standard Bed',
        };

        $roomAddons = collect($roomAddons)->filter(fn ($addon) => is_array($addon))->map(function (array $addon) {
            $quantity = max(1, (int) ($addon['quantity'] ?? 1));
            $price = round((float) ($addon['price'] ?? 0), 2);
            $lineTotal = round((float) ($addon['line_total'] ?? ($price * $quantity)), 2);
            return [
                'name' => (string) ($addon['name'] ?? 'Add-on'),
                'description' => $addon['description'] ?? null,
                'quantity' => $quantity,
                'price' => $price,
                'line_total' => $lineTotal,
            ];
        })->values();

        return [
            'booking_room_id' => (int) $line->id,
            'room_number' => $canShowRoomNumber ? $room?->room_number : null,
            'room_type' => $resolvedRoomType,
            'floor' => $canShowRoomNumber ? $room?->floor : null,
            'bed_type' => $bedType,
            'amenities' => collect($amenities)->map(fn ($value) => (string) $value)->filter()->values()->all(),
            'addons' => $roomAddons->all(),
            'addons_subtotal' => (float) $roomAddons->sum('line_total'),
            'subtotal' => (float) ($line->subtotal ?? 0),
            'price_per_night' => (float) ($line->price_per_night ?? 0),
            'nights' => (int) ($line->nights ?? 0),
        ];
    })->unique('booking_room_id')->values();

    $allAddons = $roomLines->flatMap(function (array $line) use ($canShowRoomNumber) {
        return collect($line['addons'])->map(function (array $addon) use ($line, $canShowRoomNumber) {
            $roomTypeLabel = ucfirst(str_replace('_', ' ', (string) ($line['room_type'] ?? 'Room')));
            $addon['room_label'] = ($canShowRoomNumber && !empty($line['room_number']))
                ? ($roomTypeLabel . ' - Room ' . $line['room_number'])
                : $roomTypeLabel;
            return $addon;
        });
    })->values();
    $addonsSubtotal = (float) $allAddons->sum('line_total');
    $subtotalAmount = round($roomSubtotal + $addonsSubtotal, 2);
    $netSubtotalAfterDiscount = round(max(0, $subtotalAmount - $discountAmount), 2);
    $displayTotal = $totalAmount > 0 ? $totalAmount : $netSubtotalAfterDiscount;

    if ($taxAmount <= 0 && $displayTotal > 0) {
        $taxAmount = round($displayTotal - ($displayTotal / 1.12), 2);
    }

    $guest = $booking->primaryGuest;
    $childrenAges = collect(is_array($booking->children_ages) ? $booking->children_ages : [])
        ->map(fn ($age) => (int) $age)
        ->filter(fn ($age) => $age >= 1 && $age <= 17)
        ->values();
    $childrenCount = $childrenAges->count();
    $adultsCount = max(0, (int) ($booking->number_of_guests ?? 0) - $childrenCount);
    $freeChildrenCount = min(2, $childrenAges->filter(fn ($age) => $age >= 1 && $age <= 7)->count());
    $chargedChildrenCount = max(0, $childrenCount - $freeChildrenCount);
    $latestPayment = $booking->payments->sortByDesc('created_at')->first();
    $totalPaid = (float) $booking->payments
        ->whereIn('payment_status', ['completed', 'verified'])
        ->sum('amount');
    $verifiedAt = $latestPayment?->verified_at ?? $latestPayment?->paid_at ?? null;
    $paymentMethod = strtoupper((string) ($latestPayment?->payment_method ?? 'N/A'));

    $policyCollection = collect($policySettings ?? [])->filter(function ($policy) {
        return is_array($policy) && !empty(trim((string) ($policy['value'] ?? '')));
    })->values();

    $policyItemsCollection = collect($policiesItems ?? [])->filter(function ($policy) {
        return is_array($policy) && (
            !empty(trim((string) ($policy['title'] ?? '')))
            || !empty(trim((string) ($policy['body'] ?? '')))
        );
    })->map(function ($policy) {
        return [
            'title' => (string) ($policy['title'] ?? 'Policy'),
            'body' => (string) ($policy['body'] ?? ''),
        ];
    })->values();

    $findPolicy = function (array $keys) use ($policyCollection): ?array {
        foreach ($keys as $key) {
            $found = $policyCollection->first(function ($policy) use ($key) {
                $policyKey = strtolower((string) ($policy['key'] ?? ''));
                $policyLabel = strtolower((string) ($policy['label'] ?? ''));
                return $policyKey === strtolower($key) || str_contains($policyKey, strtolower($key)) || str_contains($policyLabel, strtolower($key));
            });
            if ($found) {
                return $found;
            }
        }
        return null;
    };

    $cancellationPolicy = $findPolicy(['policy_cancellation', 'cancellation']);
    $rebookingPolicy = $findPolicy(['policy_rebooking', 'rebooking']);
    $guaranteePolicy = $findPolicy(['policy_downpayment', 'guarantee']);
    $childrenPolicy = $findPolicy(['policy_children', 'children']);

    $usedPolicyKeys = collect([$cancellationPolicy, $rebookingPolicy, $guaranteePolicy, $childrenPolicy])
        ->filter()
        ->map(fn ($policy) => (string) ($policy['key'] ?? ''))
        ->all();
    $otherPolicies = $policyCollection->filter(function ($policy) use ($usedPolicyKeys) {
        $key = (string) ($policy['key'] ?? '');
        return !in_array($key, $usedPolicyKeys, true);
    })->values();
@endphp

    <p class="mail-text">Dear {{ $guest?->name ?? 'Valued Guest' }}, your booking is confirmed.</p>

    <div class="mail-section">
        <h2 class="mail-section-title">Header</h2>
        <div class="mail-panel">
            <div class="mail-row">
                <span class="mail-label">Booking Reference Number</span>
                <span class="mail-value mail-code">{{ $booking->reference_number }}</span>
            </div>
            <div class="mail-row">
                <span class="mail-label">Booking Status</span>
                <span class="mail-value"><span class="mail-badge {{ $statusClass }}">{{ $statusLabel }}</span></span>
            </div>
        </div>
    </div>

    <div class="mail-section">
        <h2 class="mail-section-title">Stay Details</h2>
        <div class="mail-panel">
            <div class="mail-row"><span class="mail-label">Check-in Date</span><span class="mail-value">{{ $checkIn?->format('F j, Y') ?? 'N/A' }}</span></div>
            <div class="mail-row"><span class="mail-label">Check-out Date</span><span class="mail-value">{{ $checkOut?->format('F j, Y') ?? 'N/A' }}</span></div>
            <div class="mail-row"><span class="mail-label">Number of Nights</span><span class="mail-value">{{ $nights }}</span></div>
            <div class="mail-row"><span class="mail-label">Number of Guests</span><span class="mail-value">{{ (int) ($booking->number_of_guests ?? 0) }}</span></div>
            @if($childrenCount > 0)
                <div class="mail-row"><span class="mail-label">Adults</span><span class="mail-value">{{ $adultsCount }}</span></div>
                <div class="mail-row"><span class="mail-label">Children (Ages 1-7, Free Max 2)</span><span class="mail-value">{{ $freeChildrenCount }} Free</span></div>
                <div class="mail-row"><span class="mail-label">Children Counted as Adults for Occupancy</span><span class="mail-value">{{ $chargedChildrenCount }}</span></div>
            @endif
        </div>
    </div>

    <div class="mail-section">
        <h2 class="mail-section-title">Room Details</h2>
        <div class="mail-panel">
            @foreach($roomLines as $line)
                <div class="mail-row"><span class="mail-label">Room Type</span><span class="mail-value">{{ ucfirst(str_replace('_', ' ', (string) ($line['room_type'] ?? ''))) }}</span></div>
                @if($canShowRoomNumber)
                    <div class="mail-row"><span class="mail-label">Room Number</span><span class="mail-value">{{ $line['room_number'] ?? 'N/A' }}</span></div>
                    <div class="mail-row"><span class="mail-label">Floor</span><span class="mail-value">{{ $line['floor'] ?? 'N/A' }}</span></div>
                    <div class="mail-row"><span class="mail-label">Bed Type</span><span class="mail-value">{{ $line['bed_type'] }}</span></div>
                    <div class="mail-row"><span class="mail-label">Room Amenities</span><span class="mail-value">{{ !empty($line['amenities']) ? implode(', ', $line['amenities']) : 'N/A' }}</span></div>
                @endif
                @if(!$loop->last)
                    <hr class="mail-divider">
                @endif
            @endforeach
            @unless($canShowRoomNumber)
                <p class="mail-note">Your specific room will be assigned shortly after payment confirmation.</p>
            @endunless
        </div>
    </div>

    <div class="mail-section">
        <h2 class="mail-section-title">Items Table</h2>
        <div class="mail-panel">
            @foreach($roomLines as $line)
                <div class="mail-row">
                    <span class="mail-label">
                        @if($canShowRoomNumber && !empty($line['room_number']))
                            {{ ucfirst(str_replace('_', ' ', (string) ($line['room_type'] ?? 'Room'))) }} - Room {{ $line['room_number'] }}
                        @else
                            {{ ucfirst(str_replace('_', ' ', (string) ($line['room_type'] ?? 'Room'))) }}
                        @endif
                    </span>
                    <span class="mail-value">
                        {{ max(1, (int) ($line['nights'] ?? 1)) }} x &#8369;{{ number_format((float) ($line['price_per_night'] ?? $line['subtotal']), 2) }}<br>
                        &#8369;{{ number_format((float) $line['subtotal'], 2) }}
                    </span>
                </div>
            @endforeach
            @foreach($allAddons as $addon)
                <div class="mail-row">
                    <span class="mail-label">
                        {{ $addon['name'] }} - {{ $addon['room_label'] }}
                        @if(!empty($addon['description']))
                            <br><span class="mail-muted">{{ $addon['description'] }}</span>
                        @endif
                    </span>
                    <span class="mail-value">
                        {{ (int) $addon['quantity'] }} x &#8369;{{ number_format((float) $addon['price'], 2) }}<br>
                        &#8369;{{ number_format((float) $addon['line_total'], 2) }}
                    </span>
                </div>
            @endforeach
        </div>
    </div>

    <div class="mail-section">
        <h2 class="mail-section-title">Guest Information</h2>
        <div class="mail-panel">
            <div class="mail-row"><span class="mail-label">Full Name</span><span class="mail-value">{{ $guest?->name ?? 'N/A' }}</span></div>
            <div class="mail-row"><span class="mail-label">Email</span><span class="mail-value">{{ $guest?->email ?? 'N/A' }}</span></div>
            <div class="mail-row"><span class="mail-label">Phone Number</span><span class="mail-value">{{ $guest?->phone ?? 'N/A' }}</span></div>
            <div class="mail-row"><span class="mail-label">Special Requests</span><span class="mail-value">{{ $booking->special_requests ?: 'None' }}</span></div>
        </div>
    </div>

    <div class="mail-section">
        <h2 class="mail-section-title">Payment Information</h2>
        <div class="mail-panel">
            <div class="mail-row"><span class="mail-label">Payment Method</span><span class="mail-value">{{ $paymentMethod }}</span></div>
            <div class="mail-row"><span class="mail-label">Payment Status</span><span class="mail-value">{{ ucfirst((string) ($latestPayment?->payment_status ?? 'pending')) }}</span></div>
            <div class="mail-row"><span class="mail-label">Amount Paid</span><span class="mail-value">&#8369;{{ number_format($totalPaid, 2) }}</span></div>
            <div class="mail-row"><span class="mail-label">Date Verified</span><span class="mail-value">{{ $verifiedAt ? \Carbon\Carbon::parse($verifiedAt)->format('F j, Y g:i A') : 'Pending verification' }}</span></div>
        </div>
    </div>

    <div class="mail-section">
        <h2 class="mail-section-title">Price Summary</h2>
        <div class="mail-panel">
            <div class="mail-row"><span class="mail-label">Rooms Subtotal</span><span class="mail-value">&#8369;{{ number_format($roomSubtotal, 2) }}</span></div>
            <div class="mail-row"><span class="mail-label">Add-Ons Subtotal</span><span class="mail-value">&#8369;{{ number_format($addonsSubtotal, 2) }}</span></div>
            <div class="mail-row"><span class="mail-label">Subtotal</span><span class="mail-value">&#8369;{{ number_format($subtotalAmount, 2) }}</span></div>
            @if($discountAmount > 0)
                <div class="mail-row"><span class="mail-label summary-discount">Promo Discount</span><span class="mail-value summary-discount">-&#8369;{{ number_format($discountAmount, 2) }}</span></div>
            @endif
            <div class="mail-row"><span class="mail-label">Taxes and Fees (included)</span><span class="mail-value">&#8369;{{ number_format($taxAmount, 2) }}</span></div>
            <div class="mail-row"><span class="mail-label summary-total">Total</span><span class="mail-value summary-total">&#8369;{{ number_format($displayTotal, 2) }}</span></div>
        </div>
    </div>

    <div class="mail-section">
        <h2 class="mail-section-title">House Rules & Policies</h2>
        <div class="mail-panel">
            @if($policyItemsCollection->isNotEmpty())
                @foreach($policyItemsCollection as $policy)
                    <div class="mail-policy-item">
                        <strong>{{ $policy['title'] }}</strong>
                        <p>{{ $policy['body'] }}</p>
                    </div>
                @endforeach
            @else
                @php
                    $fallbackPolicies = collect([
                        ['title' => 'Cancellation Policy', 'body' => $cancellationPolicy['value'] ?? ''],
                        ['title' => 'Rebooking Policy', 'body' => $rebookingPolicy['value'] ?? ''],
                        ['title' => 'Guarantee Policy', 'body' => $guaranteePolicy['value'] ?? ''],
                        ['title' => 'Children Policy', 'body' => $childrenPolicy['value'] ?? ''],
                    ])->merge(
                        $otherPolicies->map(fn ($policy) => [
                            'title' => $policy['label'] ?? ucfirst(str_replace('_', ' ', (string) ($policy['key'] ?? 'Policy'))),
                            'body' => (string) ($policy['value'] ?? ''),
                        ])
                    )->filter(fn ($policy) => trim((string) ($policy['body'] ?? '')) !== '')->values();
                @endphp
                @foreach($fallbackPolicies as $policy)
                    <div class="mail-policy-item">
                        <strong>{{ $policy['title'] }}</strong>
                        <p>{{ $policy['body'] }}</p>
                    </div>
                @endforeach
            @endif
        </div>
    </div>
@endsection

import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { AlertCircle, CheckCircle, Clock } from 'lucide-react';
import clientBookingService from '../services/client/clientBookingService';
import { formatCurrency } from '../utils/currency';
import { showBookingConfirmedToast } from '../utils/showToast';
import { useCms } from '../context/CmsContext';
import BookingProgress from '../components/BookingProgress';
import { readGuestBookingSession, rememberGuestBookingSession } from '../utils/guestBookingSession';
import './Confirmation.css';

const Confirmation = () => {
  const location = useLocation();
  const navigate = useNavigate();
  const { get, taxRate } = useCms();

  const [booking, setBooking] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [logoSrc, setLogoSrc] = useState('/images/logo/logo-withoutbg.png');
  const bookingToastShownRef = useRef(false);

  const resolveLookups = useCallback(() => {
    const storedLookup = readGuestBookingSession();
    const stateRef = location.state?.reservationId
      || location.state?.referenceNumber
      || location.state?.bookingReference
      || '';
    const stateEmail = location.state?.email
      || location.state?.guestEmail
      || '';

    const candidates = [{
      referenceNumber: stateRef || storedLookup.referenceNumber,
      email: stateEmail || storedLookup.email,
    }];
    const seen = new Set();

    return candidates
      .map(({ email, referenceNumber }) => ({
        email: String(email || '').trim().toLowerCase(),
        referenceNumber: String(referenceNumber || '').trim(),
      }))
      .filter(({ email, referenceNumber }) => {
        if (!email || !referenceNumber) return false;

        const key = `${email}|${referenceNumber.toLowerCase()}`;
        if (seen.has(key)) return false;

        seen.add(key);
        return true;
      });
  }, [location.state]);

  const fetchConfirmation = useCallback(async () => {
    const lookups = resolveLookups();

    if (lookups.length === 0) {
      setBooking(null);
      setError('Booking lookup details are missing. Please open My Bookings and search your reservation.');
      setLoading(false);
      return;
    }

    try {
      setLoading(true);
      setError('');
      setBooking(null);

      for (const { email, referenceNumber } of lookups) {
        try {
          const response = await clientBookingService.checkBookingStatus(email, referenceNumber);
          if (!response?.success || !response?.data) {
            setError(response?.message || 'Unable to load booking confirmation details.');
            return;
          }

          rememberGuestBookingSession({ email, referenceNumber, bookingId: response.data?.id });
          setBooking(response.data);
          return;
        } catch (err) {
          const status = err?.response?.status;
          if (status === 404) continue;

          if (status === 429) {
            setError('Too many confirmation checks. Please wait a minute, then retry.');
          } else {
            setError(err?.response?.data?.message || 'Failed to load booking confirmation details.');
          }
          return;
        }
      }

      setError('Booking not found. Please check your reservation reference and email.');
    } finally {
      setLoading(false);
    }
  }, [resolveLookups]);

  useEffect(() => {
    fetchConfirmation();
  }, [fetchConfirmation]);

  const shouldShowBookingToast = Boolean(
    location.state?.bookingId
    || location.state?.bookingReference
  ) && !location.state?.reservationId;

  useEffect(() => {
    if (!shouldShowBookingToast || bookingToastShownRef.current || !booking?.reference_number) {
      return;
    }

    showBookingConfirmedToast(booking.reference_number, '/my-booking');
    bookingToastShownRef.current = true;
  }, [booking?.reference_number, shouldShowBookingToast]);

  const primaryGuest = booking?.primary_guest || {};
  const rooms = booking?.bookingRooms || [];
  const totalAmount = parseFloat(booking?.total_amount || 0);
  const discountAmount = parseFloat(booking?.discount_amount || 0);
  const totalPaid = parseFloat(booking?.total_paid || 0);
  const remainingBalance = parseFloat(booking?.remaining_balance || 0);
  const latestPayment = (booking?.payments || [])[0] || {};

  const normalizeRoomAddons = useCallback((bookingRoom) => {
    const directAddons = Array.isArray(bookingRoom?.addons)
      ? bookingRoom.addons
      : Array.isArray(bookingRoom?.room_addons)
        ? bookingRoom.room_addons
        : [];

    if (directAddons.length > 0) {
      return directAddons;
    }

    const breakdown = booking?.addons_breakdown && typeof booking.addons_breakdown === 'object'
      ? booking.addons_breakdown
      : {};

    const keys = [bookingRoom?.booking_room_id, bookingRoom?.id, bookingRoom?.room_id, bookingRoom?.room?.id]
      .filter(v => v !== null && v !== undefined)
      .map(v => String(v));

    for (const key of keys) {
      if (Array.isArray(breakdown[key])) {
        return breakdown[key];
      }
    }

    return [];
  }, [booking?.addons_breakdown]);

  const roomLines = useMemo(() => {
    const seenRoomLines = new Set();
    const uniqueRooms = rooms.filter((bookingRoom, roomIndex) => {
      const identity = String(
        bookingRoom?.booking_room_id
        ?? bookingRoom?.id
        ?? `${bookingRoom?.room_id || bookingRoom?.room?.id || 'room'}-${roomIndex}`
      );

      if (seenRoomLines.has(identity)) {
        return false;
      }
      seenRoomLines.add(identity);
      return true;
    });

    return uniqueRooms.map((bookingRoom, roomIndex) => {
      const room = bookingRoom?.room || {};
      const roomTypeLabel = (room?.room_type || bookingRoom?.requested_room_type)
        ? String(room?.room_type || bookingRoom?.requested_room_type).replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
        : 'Room';
      const roomAddons = normalizeRoomAddons(bookingRoom).map((addon, addonIndex) => {
        const quantity = Math.max(1, parseInt(addon?.quantity ?? 1, 10) || 1);
        const unitPrice = parseFloat(addon?.price || 0);
        const lineTotal = Number.isFinite(parseFloat(addon?.line_total))
          ? parseFloat(addon?.line_total)
          : unitPrice * quantity;

        return {
          key: `${roomIndex}-${addonIndex}-${addon?.id || addon?.name || 'addon'}`,
          name: addon?.name || 'Add-on',
          quantity,
          unitPrice,
          lineTotal,
        };
      });

      return {
        key: bookingRoom?.id || bookingRoom?.booking_room_id || roomIndex,
        roomNumber: room?.room_number || null,
        roomType: roomTypeLabel,
        nights: parseInt(bookingRoom?.nights || 0, 10) || 0,
        pricePerNight: parseFloat(bookingRoom?.price_per_night || 0),
        subtotal: parseFloat(bookingRoom?.subtotal || 0),
        addons: roomAddons,
      };
    });
  }, [rooms, normalizeRoomAddons]);

  const allAddons = roomLines.flatMap((line) => line.addons);
  const roomSubtotal = roomLines.reduce((sum, line) => sum + line.subtotal, 0);
  const addonsSubtotal = allAddons.reduce((sum, addon) => sum + addon.lineTotal, 0);
  const subtotalAmount = roomSubtotal + addonsSubtotal;
  const discountedSubtotal = Math.max(0, subtotalAmount - discountAmount);
  const displayTotal = totalAmount > 0 ? totalAmount : discountedSubtotal;
  const storedTaxAmount = parseFloat(booking?.tax_amount || 0);
  const hasTaxSnapshot = booking?.tax_rate !== null && booking?.tax_rate !== undefined;
  const bookingTaxRate = hasTaxSnapshot ? Number(booking.tax_rate) : taxRate;
  const taxableSubtotal = Math.max(0, displayTotal);
  const taxAmount = hasTaxSnapshot
    ? storedTaxAmount
    : (taxableSubtotal > 0 && bookingTaxRate > 0
      ? taxableSubtotal - (taxableSubtotal / (1 + bookingTaxRate))
      : 0);

  const checkIn = booking?.check_in ? new Date(booking.check_in) : null;
  const checkOut = booking?.check_out ? new Date(booking.check_out) : null;
  const computedNights = checkIn && checkOut
    ? Math.max(1, Math.ceil((checkOut - checkIn) / (1000 * 60 * 60 * 24)))
    : 0;
  const numberOfNights = roomLines[0]?.nights || computedNights;
  const numberOfGuests = parseInt(booking?.number_of_guests || 0, 10) || 0;
  const childrenAges = Array.isArray(booking?.children_ages)
    ? booking.children_ages.map((age) => Number(age)).filter((age) => age >= 1 && age <= 17)
    : [];
  const childrenCount = childrenAges.length;
  const adultsCount = Math.max(0, numberOfGuests - childrenCount);
  const eligibleFreeChildren = childrenAges.filter((age) => age >= 1 && age <= 7).length;
  const freeChildrenCount = Math.min(2, eligibleFreeChildren);
  const chargedChildrenCount = Math.max(0, childrenCount - freeChildrenCount);
  const roomAssignmentPending = String(booking?.room_assignment_status || 'pending_assignment') !== 'assigned';

  const bookingStatus = String(booking?.booking_status || 'pending');
  const bookingStatusConfig = {
    confirmed: { label: 'Confirmed', cls: 'status-confirmed', icon: CheckCircle },
    checked_in: { label: 'Checked In', cls: 'status-confirmed', icon: CheckCircle },
    checked_out: { label: 'Checked Out', cls: 'status-confirmed', icon: CheckCircle },
    pending: { label: 'Pending', cls: 'status-pending', icon: Clock },
    cancelled: { label: 'Cancelled', cls: 'status-failed', icon: AlertCircle },
  }[bookingStatus] || { label: 'Pending', cls: 'status-pending', icon: Clock };
  const StatusIcon = bookingStatusConfig.icon;

  const paymentMethod = (latestPayment?.payment_method || 'N/A').toUpperCase();
  const paymentStatus = String(latestPayment?.lifecycle_status || latestPayment?.payment_status || 'pending');
  const paymentStatusLabel = {
    paid: 'Completed',
    completed: 'Completed',
    pending: 'Pending',
    authorized: 'Authorized',
    capture_pending: 'Capture Pending',
    capture_unknown: 'Capture Status Unknown',
    paid_under_review: 'Paid - Under Review',
    refund_required: 'Paid - Refund Required',
    assignment_failed: 'Paid - Assignment Required',
    failed: 'Failed',
    refunded: 'Refunded',
  }[paymentStatus] || paymentStatus;
  const paymentCompletedAt = latestPayment?.verified_at || latestPayment?.paid_at || null;
  const paidAtLabel = paymentCompletedAt
    ? new Date(paymentCompletedAt).toLocaleString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    })
    : 'Pending verification';

  const createdDateLabel = booking?.created_at
    ? new Date(booking.created_at).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })
    : 'N/A';
  const checkInLabel = checkIn
    ? checkIn.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })
    : 'N/A';
  const checkOutLabel = checkOut
    ? checkOut.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })
    : 'N/A';

  const checkInTimeLabel = get('policy_checkin', 'After 3:00 PM');
  const checkOutTimeLabel = get('policy_checkout', 'Before 12:00 PM');

  const hotelAddress = get('hotel_address', 'One Nenita Place 89 Road 1 Bagong Pagasa, Quezon City');
  const hotelPhone = get('hotel_phone', '+63 917 809 9482');
  const hotelEmail = get('hotel_email', 'keshigallardo@gmail.com');
  const hotelWebsite = get('hotel_website', 'https://hhotelbooking.com');

  const tableRows = useMemo(() => {
    const roomRows = roomLines.map((line, idx) => ({
      key: `room-${line.key}`,
      itemNo: idx + 1,
      description: roomAssignmentPending || !line.roomNumber
        ? `${line.roomType}`
        : `${line.roomType} - Room ${line.roomNumber}`,
      qty: Math.max(1, line.nights || numberOfNights || 1),
      unitPrice: line.pricePerNight > 0 ? line.pricePerNight : line.subtotal,
      total: line.subtotal,
    }));

    if (allAddons.length === 0) return roomRows;

    const addonRows = allAddons.map((addon, idx) => ({
      key: `addon-${addon.key}`,
      itemNo: roomRows.length + idx + 1,
      description: addon.name,
      qty: addon.quantity,
      unitPrice: addon.unitPrice,
      total: addon.lineTotal,
    }));

    return [...roomRows, ...addonRows];
  }, [roomLines, allAddons, numberOfNights, roomAssignmentPending]);

  const fillerRowCount = Math.max(0, 10 - tableRows.length);

  if (loading && !booking) {
    return (
      <div className="confirmation-system-page">
        <BookingProgress currentStep={6} />
        <div className="confirmation-paper">
          <p className="invoice-muted">Loading booking confirmation...</p>
        </div>
      </div>
    );
  }

  if (error && !booking) {
    return (
      <div className="confirmation-system-page">
        <BookingProgress currentStep={6} />
        <div className="confirmation-paper">
          <h2 className="invoice-title">Booking Confirmation</h2>
          <p className="invoice-muted">{error}</p>
          <div className="confirmation-error-actions">
            <button type="button" onClick={fetchConfirmation}>Retry loading confirmation</button>
            <button type="button" className="secondary" onClick={() => navigate('/my-booking')}>
              Return to My Bookings
            </button>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="confirmation-system-page">
      <BookingProgress currentStep={6} />
      <div className="confirmation-paper">
        <section className="invoice-top">
          <div className="invoice-brand">
            <img
              src={logoSrc}
              alt="H+ Hotel"
              className="invoice-logo"
              onError={() => setLogoSrc('/images/logo/logo.jpg')}
            />
            <div className="invoice-brand-meta">
              <p>{hotelAddress}</p>
              <p>{hotelPhone}</p>
              <p>{hotelEmail}</p>
              <p>{hotelWebsite}</p>
            </div>
          </div>

          <div className="invoice-meta">
            <h1>BOOKING CONFIRMATION</h1>
            <div className="invoice-meta-row"><span>DATE:</span><strong>{createdDateLabel}</strong></div>
            <div className="invoice-meta-row"><span>REFERENCE #:</span><strong>{booking?.reference_number || 'N/A'}</strong></div>
          </div>
        </section>

        <section className="invoice-block-grid">
          <div className="invoice-block">
            <div className="invoice-block-head">GUEST INFO</div>
            <div className="invoice-block-body">
              <div className="invoice-line"><span>Full Name</span><strong>{primaryGuest?.name || 'N/A'}</strong></div>
              <div className="invoice-line"><span>Email Address</span><strong>{primaryGuest?.email || 'N/A'}</strong></div>
              <div className="invoice-line"><span>Phone Number</span><strong>{primaryGuest?.phone || 'N/A'}</strong></div>
              <div className="invoice-line"><span>Number of Guests</span><strong>{numberOfGuests}</strong></div>
              {childrenCount > 0 && (
                <>
                  <div className="invoice-line"><span>Adults</span><strong>{adultsCount}</strong></div>
                  <div className="invoice-line"><span>Children (Ages 1-7, Free Max 2)</span><strong>{freeChildrenCount} Free</strong></div>
                  <div className="invoice-line"><span>Children Counted as Adults for Occupancy</span><strong>{chargedChildrenCount}</strong></div>
                </>
              )}
            </div>
          </div>

          <div className="invoice-block">
            <div className="invoice-block-head">STAY INFO</div>
            <div className="invoice-block-body">
              <div className="invoice-line"><span>Check-in</span><strong>{checkInLabel} ({checkInTimeLabel})</strong></div>
              <div className="invoice-line"><span>Check-out</span><strong>{checkOutLabel} ({checkOutTimeLabel})</strong></div>
              <div className="invoice-line"><span>Duration</span><strong>{numberOfNights} Night(s)</strong></div>
              <div className="invoice-line">
                <span>Room</span>
                <strong>
                  {roomAssignmentPending
                    ? (roomLines.map((line) => line.roomType).filter(Boolean).join(', ') || 'N/A')
                    : roomLines.map((line) => `${line.roomType}${line.roomNumber ? ` - Room ${line.roomNumber}` : ''}`).join(', ')}
                </strong>
              </div>
              {roomAssignmentPending && (
                <p className="summary-note" style={{ marginTop: '0.5rem' }}>
                  Your specific room will be assigned shortly after payment confirmation.
                </p>
              )}
              <div className="invoice-line">
                <span>Booking Status</span>
                <strong className={`status-badge ${bookingStatusConfig.cls}`}>
                  <StatusIcon size={13} />
                  {bookingStatusConfig.label}
                </strong>
              </div>
            </div>
          </div>
        </section>

        <section className="invoice-table-section">
          <div className="invoice-table-wrap">
            <table className="invoice-table">
              <thead>
                <tr>
                  <th>ITEM #</th>
                  <th>DESCRIPTION</th>
                  <th>QTY</th>
                  <th>UNIT PRICE</th>
                  <th>TOTAL</th>
                </tr>
              </thead>
              <tbody>
                {tableRows.map((row) => (
                  <tr key={row.key}>
                    <td>{row.itemNo}</td>
                    <td>{row.description}</td>
                    <td>{row.qty}</td>
                    <td>{row.unitPrice === null ? '-' : formatCurrency(row.unitPrice)}</td>
                    <td>{row.total === null ? '-' : formatCurrency(row.total)}</td>
                  </tr>
                ))}
                {Array.from({ length: fillerRowCount }).map((_, idx) => (
                  <tr key={`filler-${idx}`} className="filler-row">
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="invoice-summary">
            <div className="summary-row">
              <span>ROOMS</span>
              <strong>{formatCurrency(roomSubtotal)}</strong>
            </div>
            {addonsSubtotal > 0 && (
              <div className="summary-row">
                <span>ADD-ONS SUBTOTAL</span>
                <strong>{formatCurrency(addonsSubtotal)}</strong>
              </div>
            )}
            <div className="summary-row">
              <span>SUBTOTAL</span>
              <strong>{formatCurrency(subtotalAmount)}</strong>
            </div>
            {discountAmount > 0 && (
              <div className="summary-row summary-discount">
                <span>PROMO DISCOUNT</span>
                <strong>-{formatCurrency(discountAmount)}</strong>
              </div>
            )}
            <div className="summary-row">
              <span>TAXES & FEES</span>
              <strong>{formatCurrency(taxAmount)}</strong>
            </div>
            <p className="summary-note">Included in room price</p>
            <div className="summary-row summary-total">
              <span>TOTAL</span>
              <strong>{formatCurrency(displayTotal)}</strong>
            </div>
            <div className="summary-row summary-paid">
              <span>AMOUNT PAID</span>
              <strong>{formatCurrency(totalPaid)}</strong>
            </div>
            <div className={`summary-row summary-balance ${remainingBalance > 0 ? 'is-open' : 'is-settled'}`}>
              <span>REMAINING BALANCE</span>
              <strong>{formatCurrency(remainingBalance)}</strong>
            </div>
            {remainingBalance > 0 && <p className="summary-note">Payable at check-in</p>}
          </div>
        </section>

        <section className="invoice-footer-grid">
          <div className="payment-panel">
            <h3>Payment Info:</h3>
            <p><span>Reference:</span> {booking?.reference_number || 'N/A'}</p>
            <p><span>Payment Method:</span> {paymentMethod}</p>
            <p><span>Payment Status:</span> {paymentStatusLabel}</p>
            <p><span>Paid At:</span> {paidAtLabel}</p>
          </div>

          <div className="thank-you-panel" aria-hidden="true">
            <p>THANK<br />YOU</p>
          </div>
        </section>

        <footer className="invoice-bottom">
          <p>
            If you have any questions about this booking, please contact {hotelEmail} or call {hotelPhone}.
          </p>
          <p className="invoice-thanks">Thank you for your booking!</p>
          <p className="invoice-copy">Generated by H+ Hotel Reservation System</p>
        </footer>
      </div>
    </div>
  );
};

export default Confirmation;

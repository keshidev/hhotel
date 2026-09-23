import React, { useEffect, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';
import Button from '../components/Button';
import clientBookingService from '../services/client/clientBookingService';
import { showToast } from '../utils/showToast';
import { readGuestBookingSession } from '../utils/guestBookingSession';
import './BookingActionForms.css';

const BookingCancellationRequest = () => {
  const navigate = useNavigate();
  const location = useLocation();
  const [booking, setBooking] = useState(null);
  const [loading, setLoading] = useState(true);
  const [reason, setReason] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [recipient, setRecipient] = useState({ name: '', account: '', confirmed: false });
  const [recipientError, setRecipientError] = useState('');

  const storedLookup = readGuestBookingSession();
  const email = location.state?.email || storedLookup.email;
  const referenceNumber = location.state?.reservationId || storedLookup.referenceNumber;

  useEffect(() => {
    const loadBooking = async () => {
      if (!email || !referenceNumber) {
        navigate('/my-booking');
        return;
      }

      try {
        const response = await clientBookingService.checkBookingStatus(email, referenceNumber);
        if (response?.success && response?.data) {
          if (response.data.cancellation_attempted) {
            if (response.data.booking_status === 'cancelled') {
              showToast('This booking is already cancelled.', 'info');
            } else if (response.data.cancellation_request_status) {
              showToast('A cancellation request is already in progress for this booking.', 'info');
            } else {
              showToast('You already submitted a cancellation request for this booking.', 'info');
            }
            navigate('/booking-details', { state: { email, reservationId: referenceNumber } });
            return;
          }

          setBooking(response.data);
          setRecipient({
            name: response.data.primary_guest?.name || '',
            account: response.data.primary_guest?.phone || '',
            confirmed: false,
          });
        } else {
          showToast('Booking was not found.', 'error');
          navigate('/my-booking');
        }
        } catch {
        showToast('Unable to load booking details.', 'error');
        navigate('/my-booking');
      } finally {
        setLoading(false);
      }
    };

    loadBooking();
  }, [email, referenceNumber, navigate]);

  const onSubmit = async (event) => {
    event.preventDefault();
    if (submitting) return;

    const normalizedReason = reason.trim();
    if (normalizedReason.length < 5) {
      showToast('Cancellation reason must be at least 5 characters.', 'error');
      return;
    }

    const bookingId = booking?.id;
    if (booking?.cancellation_refund_recipient?.required) {
      const digits = recipient.account.replace(/\D/g, '');
      if (recipient.name.trim().length < 2 || recipient.name.trim().length > 120 || !/^(09\d{9}|639\d{9})$/.test(digits)) {
        setRecipientError('Your booking contact details need updating before this refund request can be submitted. Please contact the hotel.');
        return;
      }
      if (!recipient.confirmed) {
        setRecipientError('Confirm that the auto-filled guest name and contact number are correct for your GCash refund.');
        return;
      }
    }
    if (!bookingId) {
      showToast('Booking ID is missing. Please reload and try again.', 'error');
      return;
    }

    setSubmitting(true);

    try {
      const response = await clientBookingService.cancelBooking(bookingId, {
        reason: normalizedReason,
        refundRecipient: booking.cancellation_refund_recipient?.required ? { ...recipient, name: recipient.name.trim() } : undefined,
      });

      if (response?.success) {
        const requestStatus =
          response?.data?.request_status ||
          response?.data?.cancellation_state ||
          '';

        if (['pending_approval', 'approved', 'refund_pending', 'refunded'].includes(requestStatus)) {
          showToast(
            'Cancellation request submitted and is pending admin approval. Your booking is not cancelled yet.',
            'info'
          );
        } else if (requestStatus === 'cancelled' || response?.data?.booking_status === 'cancelled') {
          showToast('Booking cancelled successfully.', 'success');
        } else {
          showToast(response?.message || 'Cancellation request submitted.', 'success');
        }

        navigate('/booking-details', {
          state: { email, reservationId: referenceNumber },
        });
      } else {
        showToast(response?.message || 'Failed to cancel booking.', 'error');
      }
    } catch (error) {
      const validationError = Object.values(error.response?.data?.errors || {}).flat()[0];
      setRecipientError(validationError || error.response?.data?.message || 'Failed to cancel booking.');
      showToast(validationError || error.response?.data?.message || 'Failed to cancel booking.', 'error');
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) {
    return <div className="booking-action-page"><div className="booking-action-container">Loading cancellation form...</div></div>;
  }

  if (!booking) return null;

  const guest = booking.primary_guest || {};
  const bookingRoomLines = Array.isArray(booking.bookingRooms) ? booking.bookingRooms : [];
  const primaryRoom = bookingRoomLines[0]?.room || {};
  const formatRoomLineLabel = (line) => {
    const room = line?.room || {};
    const roomType = room?.room_type || line?.requested_room_type;
    const roomTypeLabel = roomType
      ? String(roomType).replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase())
      : 'Room';
    return room?.room_number
      ? `${roomTypeLabel} - Room ${room.room_number}`
      : roomTypeLabel;
  };
  const isNonRefundable = Boolean(booking.cancellation_non_refundable);

  return (
    <div className="booking-action-page">
      <div className="booking-action-container">
        <button className="booking-action-back" onClick={() => navigate('/booking-details', { state: { email, reservationId: referenceNumber } })}>
          <ArrowLeft size={20} /><span>Back to Booking Details</span>
        </button>

        <form className="booking-action-card" onSubmit={onSubmit}>
          <h1 className="booking-action-title">Cancellation Form</h1>
          <p className="booking-action-subtitle">Review your booking, explain the cancellation, and confirm refund details when applicable.</p>
          {isNonRefundable && (
            <div className="booking-action-warning">
              This booking is non-refundable because the request is within 24 hours of check-in time.
            </div>
          )}

          <div className="booking-action-grid">
            <div className="booking-field">
              <label>Reference Number</label>
              <input readOnly value={booking.reference_number || ''} />
            </div>
            <div className="booking-field">
              <label>Status</label>
              <input readOnly value={booking.booking_status || ''} />
            </div>
            <div className="booking-field">
              <label>Guest Name</label>
              <input readOnly value={guest.name || ''} />
            </div>
            <div className="booking-field">
              <label>Email</label>
              <input readOnly value={guest.email || ''} />
            </div>
            <div className="booking-field">
              <label>Check-in</label>
              <input readOnly value={booking.check_in || ''} />
            </div>
            <div className="booking-field">
              <label>Check-out</label>
              <input readOnly value={booking.check_out || ''} />
            </div>
            <div className="booking-field full">
              <label>Room</label>
              {bookingRoomLines.length <= 1 ? (
                <input readOnly value={primaryRoom.room_number ? `Room ${primaryRoom.room_number}` : 'N/A'} />
              ) : (
                <div className="booking-room-list" aria-label="Assigned rooms">
                  {bookingRoomLines.map((line, index) => (
                    <div key={line.booking_room_id || line.id || index} className="booking-room-item">
                      {formatRoomLineLabel(line)}
                    </div>
                  ))}
                </div>
              )}
            </div>
            <div className="booking-field full">
              <label htmlFor="cancellation-reason">Cancellation Reason *</label>
              <textarea
                id="cancellation-reason"
                value={reason}
                onChange={(event) => setReason(event.target.value)}
                placeholder="Provide the reason for cancellation"
                minLength={5}
                maxLength={500}
                required
              />
            </div>
          </div>

          {booking.cancellation_refund_recipient?.required && (
            <section className="guest-refund-recipient">
              <h2>GCash refund recipient</h2>
              <p>Your guest name and contact number are automatically filled from your booking. Check the box below to confirm these details for your refund.</p>
              <div className="booking-action-grid">
                <div className="booking-field">
                  <label htmlFor="guest-refund-name">Recipient name *</label>
                  <input id="guest-refund-name" value={recipient.name} readOnly autoComplete="off" />
                  <small className="form-hint">Guest name from your booking.</small>
                </div>
                <div className="booking-field">
                  <label htmlFor="guest-refund-account">Recipient GCash number *</label>
                  <input id="guest-refund-account" value={recipient.account} readOnly autoComplete="off" />
                  <small className="form-hint">Contact number from your booking.</small>
                </div>
              </div>
              <label className="refund-recipient-confirmation">
                <input type="checkbox" checked={recipient.confirmed} disabled={submitting} onChange={(event) => { setRecipient({ ...recipient, confirmed: event.target.checked }); setRecipientError(''); }} />
                <span>I confirm my guest name and booking contact number are correct for my GCash refund.</span>
              </label>
              <p className="form-hint">If your GCash account uses different details, contact the hotel before confirming.</p>
            </section>
          )}
          {recipientError && <p role="alert" className="form-hint-error">{recipientError}</p>}

          <div className="booking-action-footer">
            <Button
              type="button"
              variant="secondary"
              className="booking-action-btn-back"
              disabled={submitting}
              onClick={() => navigate('/booking-details', { state: { email, reservationId: referenceNumber } })}
            >
              Back
            </Button>
            <Button type="submit" variant="danger" disabled={submitting}>
              {submitting ? 'Submitting...' : 'Submit Cancellation'}
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
};

export default BookingCancellationRequest;

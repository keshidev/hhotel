import React, { useEffect, useMemo, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';
import Button from '../components/Button';
import clientBookingService from '../services/client/clientBookingService';
import { showToast } from '../utils/showToast';
import { readGuestBookingSession } from '../utils/guestBookingSession';
import './BookingActionForms.css';

const getBookingRoomLineId = (line) => line?.booking_room_id ?? line?.id ?? null;

const getAssignedRoomId = (line) => line?.room_id ?? line?.room?.id ?? null;

const getRoomNumber = (line) => line?.room?.room_number || 'N/A';

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

const BookingRebookingRequest = () => {
  const navigate = useNavigate();
  const location = useLocation();
  const [booking, setBooking] = useState(null);
  const [loading, setLoading] = useState(true);
  const [availableRooms, setAvailableRooms] = useState([]);
  const [selectedRoomLineIds, setSelectedRoomLineIds] = useState([]);
  const [requestedRoomByLineId, setRequestedRoomByLineId] = useState({});
  const [reason, setReason] = useState('');
  const [roomLoadWarning, setRoomLoadWarning] = useState('');
  const [selectionError, setSelectionError] = useState('');
  const [lineErrors, setLineErrors] = useState({});
  const [submitting, setSubmitting] = useState(false);

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
        if (!response?.success || !response?.data) {
          showToast('Booking was not found.', 'error');
          navigate('/my-booking');
          return;
        }

        const bookingData = response.data;
        if (String(bookingData.booking_status || '').toLowerCase() !== 'confirmed') {
          showToast('Room changes are available only after payment is verified and the booking is confirmed.', 'warning');
          navigate('/booking-details', { state: { email, reservationId: referenceNumber } });
          return;
        }

        if (bookingData.lifecycle_locked_for_cancellation) {
          showToast('Room changes are blocked while a cancellation request is in progress for this booking.', 'warning');
          navigate('/booking-details', { state: { email, reservationId: referenceNumber } });
          return;
        }

        if (bookingData.has_been_rebooked) {
          showToast('This booking has already used its one allowed room change.', 'info');
          navigate('/booking-details', { state: { email, reservationId: referenceNumber } });
          return;
        }

        if (bookingData.rebooking_attempted || bookingData.rebooking_request_status) {
          showToast('An open room change request already exists for this booking.', 'info');
          navigate('/booking-details', { state: { email, reservationId: referenceNumber } });
          return;
        }

        setBooking(bookingData);

        const lines = Array.isArray(bookingData.bookingRooms) ? bookingData.bookingRooms : [];
        const initialSelected = lines
          .map((line) => String(getBookingRoomLineId(line)))
          .filter((id) => id && id !== 'null' && id !== 'undefined');

        // Single-room stays pre-check one room; multi-room can select any subset.
        if (initialSelected.length === 1) {
          setSelectedRoomLineIds(initialSelected);
        } else {
          setSelectedRoomLineIds([]);
        }
        setRequestedRoomByLineId({});
        setLineErrors({});
        setSelectionError('');

        try {
          const roomResponse = await clientBookingService.getAvailableRooms(
            bookingData.check_in,
            bookingData.check_out,
            bookingData.number_of_guests
          );
          setAvailableRooms(Array.isArray(roomResponse?.data) ? roomResponse.data : []);
          setRoomLoadWarning('');
        } catch (roomError) {
          setAvailableRooms([]);
          setRoomLoadWarning(
            roomError?.response?.data?.errors?.check_in?.[0] ||
            roomError?.response?.data?.message ||
            'Available rooms could not be loaded for this booking.'
          );
        }
      } catch {
        showToast('Unable to load room change form.', 'error');
        navigate('/my-booking');
      } finally {
        setLoading(false);
      }
    };

    loadBooking();
  }, [email, referenceNumber, navigate]);

  const bookingRoomLines = useMemo(() => {
    if (!booking) return [];
    return Array.isArray(booking.bookingRooms) ? booking.bookingRooms : [];
  }, [booking]);

  const rebookingDeadline = useMemo(() => {
    if (!booking?.created_at) return null;
    const createdAt = new Date(booking.created_at);
    if (Number.isNaN(createdAt.getTime())) return null;
    return new Date(createdAt.getTime() + (24 * 60 * 60 * 1000));
  }, [booking?.created_at]);

  const isRebookingWindowExpired = useMemo(() => {
    if (!rebookingDeadline) return false;
    return Date.now() > rebookingDeadline.getTime();
  }, [rebookingDeadline]);

  const currentBookingRoomIds = useMemo(() => {
    const ids = new Set();
    bookingRoomLines.forEach((line) => {
      const roomId = Number(getAssignedRoomId(line));
      if (Number.isFinite(roomId)) ids.add(roomId);
    });
    return ids;
  }, [bookingRoomLines]);

  const selectedLineIdSet = useMemo(() => new Set(selectedRoomLineIds), [selectedRoomLineIds]);

  const selectedRequestedRoomIdsByOtherLine = (lineId) => {
    const ids = new Set();
    Object.entries(requestedRoomByLineId).forEach(([candidateLineId, roomIdValue]) => {
      if (candidateLineId === lineId) return;
      if (!selectedLineIdSet.has(candidateLineId)) return;
      const roomId = Number(roomIdValue);
      if (Number.isFinite(roomId)) ids.add(roomId);
    });
    return ids;
  };

  const optionsForLine = (lineId) => {
    const otherSelected = selectedRequestedRoomIdsByOtherLine(lineId);
    return availableRooms.filter((room) => {
      const roomId = Number(room.id);
      if (!Number.isFinite(roomId)) return false;
      if (currentBookingRoomIds.has(roomId)) return false;
      if (otherSelected.has(roomId)) return false;
      return true;
    });
  };

  const toggleRoomLine = (lineId, checked) => {
    const lineIdStr = String(lineId);
    setSelectionError('');

    if (checked) {
      setSelectedRoomLineIds((prev) => (prev.includes(lineIdStr) ? prev : [...prev, lineIdStr]));
      setLineErrors((prev) => {
        const next = { ...prev };
        delete next[lineIdStr];
        return next;
      });
      return;
    }

    setSelectedRoomLineIds((prev) => prev.filter((id) => id !== lineIdStr));
    setRequestedRoomByLineId((prev) => {
      const next = { ...prev };
      delete next[lineIdStr];
      return next;
    });
    setLineErrors((prev) => {
      const next = { ...prev };
      delete next[lineIdStr];
      return next;
    });
  };

  const updateRequestedRoomForLine = (lineId, requestedRoomId) => {
    const lineIdStr = String(lineId);
    setRequestedRoomByLineId((prev) => ({
      ...prev,
      [lineIdStr]: requestedRoomId,
    }));
    setLineErrors((prev) => {
      const next = { ...prev };
      delete next[lineIdStr];
      return next;
    });
  };

  const onSubmit = async (event) => {
    event.preventDefault();
    if (submitting) return;

    if (isRebookingWindowExpired) {
      showToast('The 24-hour room change window for this booking has expired.', 'warning');
      return;
    }

    if (!booking?.id) {
      showToast('Booking ID is missing. Please reload and try again.', 'error');
      return;
    }

    if (selectedRoomLineIds.length === 0) {
      setSelectionError('Please select at least one room to change.');
      showToast('Please select at least one room to change.', 'error');
      return;
    }

    const nextLineErrors = {};
    const roomChanges = [];
    selectedRoomLineIds.forEach((lineId) => {
      const line = bookingRoomLines.find((item) => String(getBookingRoomLineId(item)) === lineId);
      const bookingRoomId = Number(getBookingRoomLineId(line));
      const requestedRoomId = Number(requestedRoomByLineId[lineId]);

      if (!Number.isInteger(requestedRoomId) || requestedRoomId <= 0) {
        nextLineErrors[lineId] = `Please select a new room for Room ${getRoomNumber(line)}.`;
        return;
      }

      roomChanges.push({
        booking_room_id: bookingRoomId,
        requested_room_id: requestedRoomId,
      });
    });

    if (Object.keys(nextLineErrors).length > 0) {
      setLineErrors(nextLineErrors);
      showToast('Please complete the required room selections.', 'error');
      return;
    }

    setSubmitting(true);

    try {
      const response = await clientBookingService.requestRebooking(booking.id, {
        roomChanges,
        reason: reason.trim() || undefined,
      });

      if (response?.success) {
        showToast('Room change request submitted. Front desk will review it.', 'success');
        navigate('/booking-details', {
          state: { email, reservationId: referenceNumber },
        });
      } else {
        showToast(response?.message || 'Failed to submit room change request.', 'error');
      }
    } catch (error) {
      showToast(error.response?.data?.message || 'Failed to submit room change request.', 'error');
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) {
    return <div className="booking-action-page"><div className="booking-action-container">Loading room change form...</div></div>;
  }

  if (!booking) return null;

  const guest = booking.primary_guest || {};

  return (
    <div className="booking-action-page">
      <div className="booking-action-container">
        <button className="booking-action-back" onClick={() => navigate('/booking-details', { state: { email, reservationId: referenceNumber } })}>
          <ArrowLeft size={20} /><span>Back to Booking Details</span>
        </button>

        <form className="booking-action-card" onSubmit={onSubmit}>
          <h1 className="booking-action-title">Room Change Request</h1>
          <p className="booking-action-subtitle">Choose a different room for the same stay dates. Dates cannot be changed here.</p>
          <div className="booking-action-warning">
            A room change is allowed once, after payment is verified, and must be requested within 24 hours of the original booking.
          </div>
          {isRebookingWindowExpired && (
            <div className="booking-action-warning">
              The 24-hour room change window for this booking has expired.
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
              <label>Which Rooms Would You Like To Change? *</label>
              <div className="booking-room-checkbox-list">
                {bookingRoomLines.map((line, index) => {
                  const lineId = String(getBookingRoomLineId(line));
                  const inputId = `room-line-${lineId || index}`;
                  const checked = selectedLineIdSet.has(lineId);
                  const roomOptions = optionsForLine(lineId);
                  const selectedRequestedRoom = requestedRoomByLineId[lineId] ?? '';

                  return (
                    <div key={lineId || index} className="booking-room-checkbox-block">
                      <label className="booking-room-checkbox-item" htmlFor={inputId}>
                        <input
                          id={inputId}
                          type="checkbox"
                          checked={checked}
                          onChange={(event) => toggleRoomLine(lineId, event.target.checked)}
                        />
                        <span>{formatRoomLineLabel(line)}</span>
                      </label>

                      {checked && (
                        <div className="booking-room-picker">
                          <label>New Room For Room {getRoomNumber(line)} *</label>
                          <select
                            value={selectedRequestedRoom}
                            onChange={(event) => updateRequestedRoomForLine(lineId, event.target.value)}
                            required
                          >
                            <option value="">Select an available room</option>
                            {roomOptions.map((room) => (
                              <option key={room.id} value={room.id}>
                                Room {room.room_number} - {room.room_type} - PHP {Number(room.price_per_night || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                              </option>
                            ))}
                          </select>
                          {lineErrors[lineId] && <small className="form-hint form-hint-error">{lineErrors[lineId]}</small>}
                        </div>
                      )}
                    </div>
                  );
                })}
              </div>
              {selectionError && <small className="form-hint form-hint-error">{selectionError}</small>}
              {roomLoadWarning && <small className="form-hint">{roomLoadWarning}</small>}
            </div>

            <div className="booking-field full">
              <label>Reason (Optional)</label>
              <textarea
                value={reason}
                onChange={(event) => setReason(event.target.value)}
                placeholder="Add a note for your room-change request"
                maxLength={1000}
              />
            </div>
          </div>

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
            <Button type="submit" variant="primary" disabled={isRebookingWindowExpired || submitting}>
              {isRebookingWindowExpired
                ? 'Room Change Unavailable'
                : submitting
                  ? 'Submitting...'
                  : 'Submit Room Change'}
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
};

export default BookingRebookingRequest;

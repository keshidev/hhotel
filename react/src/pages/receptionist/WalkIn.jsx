import React, { useState, useEffect, useMemo, useRef } from 'react';
import {
  UserPlus, Users, Mail, Phone,
  BedDouble, Check, X, Search, Clock, Sun, Moon,
  ImageOff, AlertCircle, AlertTriangle, Tag,
} from 'lucide-react';
import './WalkIn.css';
import receptionistApi from '../../services/receptionistApi';
import { getRoleToken } from '../../services/authStorage';
import { StaffDateTimePicker } from '../../components/StaffDatePicker';

// ─── Toast ────────────────────────────────────────────────────────────────────
const showToast = (message, type = 'success') => {
  const toast = document.createElement('div');
  toast.className = `simple-toast toast-${type}`;
  toast.textContent = message;
  document.body.appendChild(toast);
  setTimeout(() => toast.classList.add('show'), 10);
  setTimeout(() => {
    toast.classList.remove('show');
    setTimeout(() => { if (toast.parentNode) document.body.removeChild(toast); }, 300);
  }, 3000);
};

// ─── Helpers ──────────────────────────────────────────────────────────────────

/** Format Date object → "YYYY-MM-DDTHH:MM" for datetime-local inputs */
const toDatetimeLocal = (date) => {
  const pad = (n) => String(n).padStart(2, '0');
  return (
    `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}` +
    `T${pad(date.getHours())}:${pad(date.getMinutes())}`
  );
};

/** Parse datetime-local string → Date */
const fromDatetimeLocal = (str) => new Date(str);

const WALK_IN_CHECK_IN_GRACE_MINUTES = 15;
const WALK_IN_CHECK_IN_GRACE_MS = WALK_IN_CHECK_IN_GRACE_MINUTES * 60 * 1000;

const getWalkInCheckInError = (value, referenceDate = new Date()) => {
  const checkIn = fromDatetimeLocal(value);
  if (Number.isNaN(checkIn.getTime())) return 'Valid check-in date and time required';

  const earliestCheckIn = new Date(referenceDate);
  earliestCheckIn.setSeconds(0, 0);
  earliestCheckIn.setTime(earliestCheckIn.getTime() - WALK_IN_CHECK_IN_GRACE_MS);

  return checkIn < earliestCheckIn
    ? `Check-in cannot be more than ${WALK_IN_CHECK_IN_GRACE_MINUTES} minutes in the past`
    : null;
};

/** Hours difference between two Date objects (can be fractional) */
const hoursDiff = (from, to) => (to - from) / (1000 * 60 * 60);

/** Format duration hours → "Xh Ym" */
const fmtDuration = (hours) => {
  const h = Math.floor(hours);
  const m = Math.round((hours - h) * 60);
  return m > 0 ? `${h}h ${m}m` : `${h}h`;
};

const formatRoomType = (type) =>
  type ? type.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()) : '-';

const formatPeso = (value) =>
  `\u20B1${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const extractInclusiveTax = (value) => {
  const amount = Number(value || 0);
  if (amount <= 0) return 0;
  return amount - (amount / 1.12);
};

const getStayRate = (room, stayType) => {
  const nightlyRate = Number(room?.price_per_night || 0);
  const configuredDayTour = Number(room?.price_day_tour || 0);
  const dayTourRate = configuredDayTour > 0 ? configuredDayTour : (nightlyRate * 0.65);
  return stayType === 'day_use' ? dayTourRate : nightlyRate;
};
const getRoomImageUrl = (room) => {
  if (Array.isArray(room.image_urls) && room.image_urls.length > 0) return room.image_urls[0] || null;
  if (room.image_url) return room.image_url;
  if (Array.isArray(room.images) && room.images.length > 0)
    return room.images[0]?.url || room.images[0]?.path || room.images[0] || null;
  if (room.photo) return room.photo;
  return null;
};

const extractErrorMessage = (json, fallback) => {
  if (!json) return fallback;
  if (json.errors) {
    const first = Object.values(json.errors).flat()[0];
    if (first) return first;
  }
  return json.message || fallback;
};

const createRequestKey = () => globalThis.crypto?.randomUUID?.()
  ?? 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (character) => {
    const random = Math.floor(Math.random() * 16);
    return (character === 'x' ? random : ((random & 0x3) | 0x8)).toString(16);
  });

// ─────────────────────────────────────────────────────────────────────────────

const WalkIn = () => {
  const now = new Date();

  // Default day-use check-out = now + 12 hours
  const defaultDayUseCheckOut = new Date(now.getTime() + 12 * 60 * 60 * 1000);

  const [step, setStep]                     = useState(1);
  const [availableRooms, setAvailableRooms] = useState([]);
  const [selectedRooms, setSelectedRooms]   = useState([]);
  const [activeFilter, setActiveFilter]     = useState('all');
  const [loading, setLoading]               = useState(false);
  const [searchingRooms, setSearchingRooms] = useState(false);
  const [serverError, setServerError]       = useState(null);

  // Midnight-crossing modal
  const [midnightModal, setMidnightModal]   = useState(false);

  // Promo code
  const [promoInput,   setPromoInput]   = useState('');
  const [promoResult,  setPromoResult]  = useState(null);
  const [promoLoading, setPromoLoading] = useState(false);
  const [promoError,   setPromoError]   = useState('');

  const [form, setForm] = useState({
    name:            '',
    email:           '',
    phone:           '',
    stayType:        'day_use',           // 'day_use' | 'overnight'
    checkIn:         toDatetimeLocal(now),
    checkOut:        toDatetimeLocal(defaultDayUseCheckOut),
    numberOfGuests:  1,
    paymentMethod:   'cash',
    amountTendered:  '',
    paymentReference:'',
    gcashSenderName: '',
    gcashPaidAt: toDatetimeLocal(now),
    merchantRecordConfirmed: false,
    specialRequests: '',
  });
  const requestKey = useRef(createRequestKey());

  const [errors, setErrors] = useState({});
  const [bookingSuccess, setBookingSuccess] = useState(null);

  const resetWalkInForm = () => {
    const nowReset = new Date();
    setStep(1);
    setForm({
      name: '',
      email: '',
      phone: '',
      stayType: 'day_use',
      checkIn: toDatetimeLocal(nowReset),
      checkOut: toDatetimeLocal(new Date(nowReset.getTime() + 12 * 60 * 60 * 1000)),
      numberOfGuests: 1,
      paymentMethod: 'cash',
      amountTendered: '',
      paymentReference: '',
      gcashSenderName: '',
      gcashPaidAt: toDatetimeLocal(nowReset),
      merchantRecordConfirmed: false,
      specialRequests: '',
    });
    setSelectedRooms([]);
    setAvailableRooms([]);
    setErrors({});
    setServerError(null);
    setPromoInput('');
    setPromoResult(null);
    setPromoError('');
    setBookingSuccess(null);
    requestKey.current = createRequestKey();
  };

  const ensureReceptionistToken = () => {
    const token = getRoleToken('receptionist');
    if (!token) {
      const msg = 'Your session has expired. Please sign in again.';
      console.warn('[WalkIn] Missing receptionist token. Redirecting to /login.');
      setServerError({ message: msg, details: null });
      showToast(msg, 'error');
      window.location.href = '/login';
      return null;
    }
    return token;
  };

  // ── Derived values ────────────────────────────────────────────────────────
  const checkInDate  = useMemo(() => fromDatetimeLocal(form.checkIn),  [form.checkIn]);
  const checkOutDate = useMemo(() => fromDatetimeLocal(form.checkOut), [form.checkOut]);
  const duration     = useMemo(() => hoursDiff(checkInDate, checkOutDate), [checkInDate, checkOutDate]);
  const billingNights = useMemo(() => {
    if (form.stayType === 'day_use') return 1;
    const ms = checkOutDate - checkInDate;
    return Math.max(1, Math.ceil(ms / (1000 * 60 * 60 * 24)));
  }, [form.stayType, checkInDate, checkOutDate]);
  const crossesMidnight = useMemo(() => {
    if (form.stayType !== 'day_use') return false;
    return checkOutDate.getDate() !== checkInDate.getDate() ||
           checkOutDate.getMonth() !== checkInDate.getMonth();
  }, [form.stayType, checkInDate, checkOutDate]);
  const selectedCapacity = useMemo(
    () => selectedRooms.reduce((totalCapacity, room) => totalCapacity + Math.max(0, Number(room.capacity) || 0), 0),
    [selectedRooms]
  );
  const guestCountExceedsCapacity = selectedRooms.length > 0 && Number(form.numberOfGuests) > selectedCapacity;

  // ── Toast styles ──────────────────────────────────────────────────────────
  useEffect(() => {
    const styleId = 'simple-toast-styles';
    if (!document.getElementById(styleId)) {
      const style = document.createElement('style');
      style.id = styleId;
      style.textContent = `
        .simple-toast{position:fixed;top:20px;right:20px;padding:16px 24px;border-radius:8px;
          color:white;font-size:14px;font-weight:500;box-shadow:0 10px 40px rgba(0,0,0,.2);
          z-index:9999;transform:translateX(400px);opacity:0;transition:all .3s ease;}
        .simple-toast.show{transform:translateX(0);opacity:1;}
        .toast-success{background:#10b981;}.toast-error{background:#ef4444;}
        .toast-warning{background:#f59e0b;}
      `;
      document.head.appendChild(style);
    }
  }, []);

  // ── Stay type switch ──────────────────────────────────────────────────────
  const handleStayTypeChange = (type) => {
    const ci = fromDatetimeLocal(form.checkIn);
    let co;
    if (type === 'day_use') {
      co = new Date(ci.getTime() + 12 * 60 * 60 * 1000);
    } else {
      co = new Date(ci.getTime() + 24 * 60 * 60 * 1000);
    }
    setForm((f) => ({ ...f, stayType: type, checkOut: toDatetimeLocal(co) }));
    setPromoResult(null); setPromoInput(''); setPromoError('');
  };

  // ── Check-in change ───────────────────────────────────────────────────────
  const handleCheckInChange = (val) => {
    const ci = fromDatetimeLocal(val);
    let co;
    if (form.stayType === 'day_use') {
      co = new Date(ci.getTime() + 12 * 60 * 60 * 1000);
    } else {
      // keep the same duration when shifting check-in
      const prevDuration = checkOutDate - checkInDate;
      co = new Date(ci.getTime() + prevDuration);
    }
    setForm((f) => ({ ...f, checkIn: val, checkOut: toDatetimeLocal(co) }));
  };

  // ── Validation ────────────────────────────────────────────────────────────
  const validateForm = () => {
    const errs = {};
    if (!form.name.trim() || form.name.trim().length < 2) errs.name = 'Guest name is required (min 2 chars)';
    if (!form.email.trim() || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) errs.email = 'Valid email required';
    if (!form.phone.trim()) errs.phone = 'Phone number is required';
    else if (!/^\d{11}$/.test(form.phone.trim())) errs.phone = 'Must be exactly 11 digits';
    else if (!form.phone.trim().startsWith('09')) errs.phone = 'Must start with 09';

    const checkInError = getWalkInCheckInError(form.checkIn);
    if (checkInError) errs.checkIn = checkInError;
    if (duration <= 0)      errs.checkOut = 'Check-out must be after check-in';
    if (form.stayType === 'day_use' && duration > 12)
      errs.checkOut = 'Day use cannot exceed 12 hours';
    if (!form.numberOfGuests || form.numberOfGuests < 1) errs.numberOfGuests = 'At least 1 guest required';

    setErrors(errs);
    return Object.keys(errs).length === 0;
  };

  // ── Search rooms ──────────────────────────────────────────────────────────
  const searchAvailableRooms = async () => {
    setServerError(null);
    if (!validateForm()) { showToast('Please fix the errors in the form', 'error'); return; }

    // Warn about midnight crossing before proceeding
    if (crossesMidnight) { setMidnightModal(true); return; }

    await doSearchRooms();
  };

  const doSearchRooms = async () => {
    setSearchingRooms(true);
    try {
      if (!ensureReceptionistToken()) return;

      const res = await receptionistApi.get('/receptionist/rooms/available', {
        params: {
          check_in: form.checkIn,
          check_out: form.checkOut,
          number_of_guests: form.numberOfGuests,
          stay_type: form.stayType,
        },
      });

      const json  = res.data;
      const rooms = Array.isArray(json) ? json : (json.data ?? []);
      setAvailableRooms(rooms);
      setStep(2);
      showToast(`${rooms.length} room${rooms.length !== 1 ? 's' : ''} available`, 'success');
    } catch (err) {
      const status = err?.response?.status;
      const json = err?.response?.data ?? null;
      const msg = extractErrorMessage(
        json,
        status ? `Server error ${status}` : 'Network error - could not reach the server'
      );
      showToast(msg, 'error');
      setServerError({ message: msg, details: json?.errors ?? null });
    } finally {
      setSearchingRooms(false);
    }
  };

  // ── Submit booking ────────────────────────────────────────────────────────
  const handleSubmitBooking = async () => {
    const checkInError = getWalkInCheckInError(form.checkIn);
    if (checkInError) {
      setErrors((currentErrors) => ({ ...currentErrors, checkIn: checkInError }));
      setSelectedRooms([]);
      setAvailableRooms([]);
      setStep(1);
      showToast(`${checkInError}. Please search for rooms again.`, 'error');
      return;
    }
    if (selectedRooms.length === 0) { showToast('Please select at least one room', 'error'); return; }
    if (guestCountExceedsCapacity) {
      showToast(`Selected rooms allow only ${selectedCapacity} guest(s). Please select more rooms.`, 'error');
      return;
    }
    if (!paymentValidation.isValid) {
      showToast(
        paymentValidation.amountError || paymentValidation.referenceError || 'Please complete payment details before confirming.',
        'error'
      );
      return;
    }

    setLoading(true);
    try {
      if (!ensureReceptionistToken()) return;

      const payload = {
        guest_name:       form.name,
        guest_email:      form.email,
        guest_phone:      form.phone,
        check_in:         form.checkIn,
        check_out:        form.checkOut,
        number_of_guests: form.numberOfGuests,
        stay_type:        form.stayType,
        room_ids:         selectedRooms.map((r) => r.id),
        payment_method:   form.paymentMethod,
        amount_tendered:  paymentValidation.parsedAmount,
        payment_reference: form.paymentReference.trim() || null,
        gcash_sender_name: form.paymentMethod === 'gcash' ? form.gcashSenderName.trim() : null,
        gcash_paid_at: form.paymentMethod === 'gcash' ? form.gcashPaidAt : null,
        merchant_record_confirmed: form.paymentMethod === 'gcash' ? form.merchantRecordConfirmed : null,
        idempotency_key: requestKey.current,
        special_requests: form.specialRequests || null,
        promo_code:       promoResult?.valid ? promoInput.trim().toUpperCase() : null,
      };

      const res = await receptionistApi.post('/receptionist/walk-in', payload);
      const data = res.data;
      const payment = data?.data?.payment ?? {};

      showToast(
        `${form.stayType === 'day_use' ? 'Day use' : 'Walk-in'} booking #${data?.data?.booking_reference ?? ''} created!`,
        'success'
      );

      setBookingSuccess({
        bookingReference: data?.data?.booking_reference ?? '',
        amountCharged: Number(payment?.amount_charged ?? total),
        amountReceived: Number(payment?.amount_tendered ?? paymentValidation.parsedAmount),
        changeDue: Number(payment?.change_due ?? paymentValidation.changeDue ?? 0),
        paymentMethod: payment?.payment_method ?? form.paymentMethod,
        paymentReference: payment?.payment_reference ?? null,
      });
    } catch (err) {
      const status = err?.response?.status;
      const json = err?.response?.data ?? null;
      showToast(
        extractErrorMessage(
          json,
          status ? `Server error ${status}` : 'Network error - could not reach the server'
        ),
        'error'
      );
    } finally {
      setLoading(false);
    }
  };

  // ── Room helpers ──────────────────────────────────────────────────────────
  const toggleRoom = (room) => {
    const exists = selectedRooms.some((r) => r.id === room.id);
    setSelectedRooms(exists ? selectedRooms.filter((r) => r.id !== room.id) : [...selectedRooms, room]);
  };

  // ── Promo ─────────────────────────────────────────────────────────────────
  const applyPromo = async () => {
    if (!promoInput.trim()) { setPromoError('Please enter a promo code.'); return; }
    if (selectedRooms.length === 0) { setPromoError('Please select a room first.'); return; }
    setPromoLoading(true); setPromoError(''); setPromoResult(null);
    try {
      if (!ensureReceptionistToken()) return;

      const subtotal = selectedRooms.reduce((sum, room) => {
        const price = getStayRate(room, form.stayType);
        return sum + (price * billingNights);
      }, 0);

      const res = await receptionistApi.post('/receptionist/promo-codes/validate', {
        code:           promoInput.trim().toUpperCase(),
        guest_email:    form.email || 'receptionist@walkin.com',
        check_in:       form.checkIn.split('T')[0],
        check_out:      form.checkOut.split('T')[0],
        subtotal,
        booking_source: 'walk_in',
      });
      const data = res.data;
      if (data.valid) { setPromoResult(data); showToast(data.savings_label || 'Promo applied!', 'success'); }
      else            { setPromoError(data.message || 'Invalid promo code.'); }
    } catch (err) {
      const status = err?.response?.status;
      const json = err?.response?.data ?? null;
      setPromoError(
        extractErrorMessage(
          json,
          status ? `Server error ${status}` : 'Could not validate promo code.'
        )
      );
    }
    finally  { setPromoLoading(false); }
  };

  // ── Totals ────────────────────────────────────────────────────────────────
  const { subtotal, discount, total, taxesAndFees } = useMemo(() => {
    const sub = selectedRooms.reduce((s, room) => {
      const price = getStayRate(room, form.stayType);
      return s + (price * billingNights);
    }, 0);
    const disc  = promoResult?.valid ? (promoResult.discount_amount || 0) : 0;
    const tot   = Math.max(0, sub - disc);
    return {
      subtotal: sub,
      discount: disc,
      total: tot,
      taxesAndFees: extractInclusiveTax(tot),
    };
  }, [selectedRooms, promoResult, billingNights, form.stayType]);

  const isNonCashMethod = form.paymentMethod === 'gcash';

  useEffect(() => {
    if (!isNonCashMethod || total < 0) return;
    const exactAmount = total.toFixed(2);
    setForm((prev) => {
      if (prev.amountTendered === exactAmount) return prev;
      return { ...prev, amountTendered: exactAmount };
    });
  }, [isNonCashMethod, total]);

  const paymentValidation = useMemo(() => {
    const method = String(form.paymentMethod || '').trim();
    const amountRaw = String(form.amountTendered ?? '').trim();
    const referenceRaw = String(form.paymentReference ?? '').trim();
    const needsReference = method === 'gcash';
    const parsedAmount = Number.parseFloat(amountRaw);
    const hasNumericAmount = amountRaw !== '' && Number.isFinite(parsedAmount);

    let amountError = '';
    if (!amountRaw) {
      amountError = 'Amount tendered is required.';
    } else if (!hasNumericAmount || parsedAmount < 0) {
      amountError = 'Amount tendered must be a valid amount.';
    } else if (parsedAmount + 0.009 < total) {
      amountError = `Amount tendered must be at least ${formatPeso(total)}. Please collect the full payment before confirming.`;
    } else if (needsReference && Math.abs(parsedAmount - total) > 0.009) {
      amountError = `GCash payment must exactly match ${formatPeso(total)}.`;
    }

    const referenceError = needsReference && !referenceRaw
      ? 'GCash reference number is required.'
      : '';
    const senderError = needsReference && String(form.gcashSenderName ?? '').trim().length < 2
      ? 'GCash sender name is required.'
      : '';
    const paidAtError = needsReference && !form.gcashPaidAt
      ? 'GCash payment date and time are required.'
      : '';
    const confirmationError = needsReference && !form.merchantRecordConfirmed
      ? 'Confirm that the payment matches the official hotel GCash record.'
      : '';

    const changeDue = hasNumericAmount ? Math.max(0, parsedAmount - total) : 0;
    const isValid = Boolean(method) && !amountError && !referenceError && !senderError && !paidAtError && !confirmationError;

    return {
      isValid,
      needsReference,
      amountError,
      referenceError,
      senderError,
      paidAtError,
      confirmationError,
      changeDue,
      parsedAmount: hasNumericAmount ? parsedAmount : 0,
    };
  }, [form.paymentMethod, form.amountTendered, form.paymentReference, form.gcashSenderName, form.gcashPaidAt, form.merchantRecordConfirmed, total]);

  const canConfirmBooking = selectedRooms.length > 0
    && !guestCountExceedsCapacity
    && !loading
    && paymentValidation.isValid;

  // ─────────────────────────────────────────────────────────────────────────
  return (
    <div className="walk-in-page">
      <div className="wi-page-header">
        <div>
          <h1 className="wi-page-title">Walk-In Booking</h1>
          <p className="wi-page-subtitle">Create instant bookings for walk-in guests</p>
        </div>
      </div>

      {/* ── MIDNIGHT CROSSING MODAL ──────────────────────────────────────── */}
      {midnightModal && (
        <div style={{
          position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)',
          backdropFilter: 'blur(4px)', zIndex: 9999,
          display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '1rem',
        }} onClick={() => setMidnightModal(false)}>
          <div style={{
            background: 'var(--color-surface)', borderRadius: 12, maxWidth: 460, width: '100%',
            padding: '1.75rem', boxShadow: '0 20px 60px rgba(0,0,0,0.2)', fontFamily: "'Inter', sans-serif", color: 'var(--color-text-primary)',
          }} onClick={(e) => e.stopPropagation()}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem', marginBottom: '1rem' }}>
              <AlertTriangle size={22} style={{ color: '#f59e0b', flexShrink: 0 }} />
              <h3 style={{ margin: 0, fontFamily: "'Inter', sans-serif", fontSize: '1.0625rem', fontWeight: 700, color: 'var(--color-text-primary)' }}>Stay Crosses Midnight</h3>
            </div>
            <p style={{ color: 'var(--color-text-secondary)', fontFamily: "'Inter', sans-serif", fontSize: '0.875rem', fontWeight: 400, marginBottom: '1.25rem', lineHeight: 1.6 }}>
              This day use stay goes past midnight
              ({fromDatetimeLocal(form.checkIn).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
              {' → '}
              {fromDatetimeLocal(form.checkOut).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}).
              How would you like to classify this booking?
            </p>
            <div style={{ display: 'flex', flexDirection: 'column', gap: '0.65rem' }}>
              <button
                style={{ padding: '0.75rem 1rem', background: '#eef2ff', border: '1px solid #1a4bcc', borderRadius: 8, fontWeight: 500, cursor: 'pointer', textAlign: 'left', fontFamily: "'Inter', sans-serif", fontSize: '0.875rem', color: '#1a4bcc' }}
                onClick={() => { setMidnightModal(false); doSearchRooms(); }}
              >
                <Sun size={16} style={{ verticalAlign: 'middle', marginRight: 6 }} />
                Keep as Day Use (12-hour rate)
              </button>
              <button
                style={{ padding: '0.75rem 1rem', background: '#1a4bcc', border: '1px solid #1a4bcc', borderRadius: 8, fontWeight: 500, cursor: 'pointer', textAlign: 'left', fontFamily: "'Inter', sans-serif", fontSize: '0.875rem', color: '#fff' }}
                onClick={() => {
                  setMidnightModal(false);
                  setForm((f) => ({ ...f, stayType: 'overnight' }));
                  showToast('Converted to overnight stay.', 'success');
                  doSearchRooms();
                }}
              >
                <Moon size={16} style={{ verticalAlign: 'middle', marginRight: 6 }} />
                Convert to Overnight Stay
              </button>
              <button
                style={{ padding: '0.625rem 1.25rem', background: 'var(--color-surface)', border: '1px solid var(--color-border)', borderRadius: 8, cursor: 'pointer', fontFamily: "'Inter', sans-serif", fontSize: '0.875rem', fontWeight: 500, color: 'var(--color-text-secondary)' }}
                onClick={() => setMidnightModal(false)}
              >
                Cancel — edit dates
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ── STEP 1 ──────────────────────────────────────────────────────── */}
      {bookingSuccess && (
        <div className="wi-success-overlay" onClick={resetWalkInForm}>
          <div className="wi-success-card" onClick={(e) => e.stopPropagation()}>
            <h3 className="wi-success-title">Booking Confirmed {bookingSuccess.bookingReference}</h3>

            <div className="wi-success-line">
              <span>Amount Charged:</span>
              <strong>{formatPeso(bookingSuccess.amountCharged)}</strong>
            </div>
            <div className="wi-success-line">
              <span>Amount Received:</span>
              <strong>{formatPeso(bookingSuccess.amountReceived)}</strong>
            </div>
            <div className={`wi-success-line wi-change-due ${bookingSuccess.changeDue > 0 ? 'has-change' : ''}`}>
              <span>Change Due:</span>
              <strong>{formatPeso(bookingSuccess.changeDue)}</strong>
            </div>

            {bookingSuccess.paymentReference && (
              <div className="wi-success-meta">
                Reference: <strong>{bookingSuccess.paymentReference}</strong>
              </div>
            )}

            {bookingSuccess.changeDue > 0 && (
              <p className="wi-success-note">
                Please return {formatPeso(bookingSuccess.changeDue)} to the guest.
              </p>
            )}

            <button className="btn-primary btn-large" onClick={resetWalkInForm}>
              <Check size={18} />Done
            </button>
          </div>
        </div>
      )}
      {step === 1 && (
        <div className="walk-in-content">
          <div className="form-card">
            <div className="form-card-header">
              <UserPlus size={24} />
              <h2>Guest Information</h2>
            </div>

            {serverError && (
              <div className="server-error-banner">
                <AlertCircle size={18} />
                <div className="banner-body">
                  <strong>{serverError.message}</strong>
                  {serverError.details && (
                    <ul className="server-error-list">
                      {Object.entries(serverError.details).map(([field, msgs]) =>
                        [].concat(msgs).map((msg, i) => (
                          <li key={`${field}-${i}`}><span className="error-field">{field.replace(/_/g, ' ')}:</span> {msg}</li>
                        ))
                      )}
                    </ul>
                  )}
                </div>
                <button className="banner-close" onClick={() => setServerError(null)}><X size={16} /></button>
              </div>
            )}

            <div className="form-grid">

              {/* Name */}
              <div className="form-group full-width">
                <label>Guest Name *</label>
                <input
                  type="text" value={form.name}
                  onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
                  placeholder="Enter full name"
                  className={errors.name ? 'error-input' : ''}
                />
                {errors.name && <span className="error">{errors.name}</span>}
              </div>

              {/* Email */}
              <div className="form-group">
                <label>Email Address *</label>
                <div className="input-with-icon">
                  <Mail size={18} />
                  <input
                    type="email" value={form.email}
                    onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))}
                    placeholder="guest@example.com"
                    className={errors.email ? 'error-input' : ''}
                  />
                </div>
                {errors.email && <span className="error">{errors.email}</span>}
              </div>

              {/* Phone */}
              <div className="form-group">
                <label>Phone Number *</label>
                <div className="input-with-icon">
                  <Phone size={18} />
                  <input
                    type="tel" inputMode="numeric" maxLength={11}
                    value={form.phone}
                    onChange={(e) => setForm((f) => ({ ...f, phone: e.target.value.replace(/\D/g, '').slice(0, 11) }))}
                    placeholder="09XXXXXXXXX"
                    className={errors.phone ? 'error-input' : ''}
                  />
                </div>
                {errors.phone && <span className="error">{errors.phone}</span>}
              </div>

              {/* Stay Type */}
              <div className="form-group full-width">
                <label>Stay Type *</label>
                <div style={{ display: 'flex', gap: '1rem', marginTop: '0.35rem' }}>
                  <label style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', cursor: 'pointer', fontWeight: form.stayType === 'day_use' ? 700 : 400 }}>
                    <input
                      type="radio" name="stayType" value="day_use"
                      checked={form.stayType === 'day_use'}
                      onChange={() => handleStayTypeChange('day_use')}
                    />
                    Day Use (12 hours)
                  </label>
                  <label style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', cursor: 'pointer', fontWeight: form.stayType === 'overnight' ? 700 : 400 }}>
                    <input
                      type="radio" name="stayType" value="overnight"
                      checked={form.stayType === 'overnight'}
                      onChange={() => handleStayTypeChange('overnight')}
                    />
                    Overnight Stay
                  </label>
                </div>
              </div>

              <div className="form-group">
                <label>Check-In *</label>
                <StaffDateTimePicker
                  value={form.checkIn}
                  onChange={handleCheckInChange}
                  min={toDatetimeLocal(new Date(Date.now() - WALK_IN_CHECK_IN_GRACE_MS))}
                  ariaLabel="Select walk-in check-in date and time"
                  invalid={Boolean(errors.checkIn)}
                />
                {errors.checkIn && <span className="error">{errors.checkIn}</span>}
              </div>

              <div className="form-group">
                <label>Check-Out *</label>
                <StaffDateTimePicker
                  value={form.checkOut}
                  onChange={(value) => setForm((current) => ({ ...current, checkOut: value }))}
                  min={form.checkIn}
                  ariaLabel="Select walk-in check-out date and time"
                  invalid={Boolean(errors.checkOut)}
                />
                {duration > 0 && (
                  <span className="info-text" style={{ color: crossesMidnight ? '#f59e0b' : '#6b7280' }}>
                    {crossesMidnight && '⚠ Crosses midnight · '}
                    Duration: {fmtDuration(duration)}
                    {form.stayType === 'day_use' && duration > 12 && (
                      <span style={{ color: '#ef4444' }}> — exceeds 12h limit</span>
                    )}
                  </span>
                )}
                {errors.checkOut && <span className="error">{errors.checkOut}</span>}
              </div>

              {/* Guests */}
              <div className="form-group">
                <label>Number of Guests *</label>
                <div className="input-with-icon">
                  <Users size={18} />
                  <input
                    type="number" min="1"
                    value={form.numberOfGuests}
                    onChange={(e) => setForm((f) => ({ ...f, numberOfGuests: parseInt(e.target.value) || 1 }))}
                    className={errors.numberOfGuests ? 'error-input' : ''}
                  />
                </div>
                {errors.numberOfGuests && <span className="error">{errors.numberOfGuests}</span>}
              </div>

              {/* Special requests */}
              <div className="form-group full-width">
                <label>Special Requests</label>
                <textarea
                  rows="2"
                  value={form.specialRequests}
                  onChange={(e) => setForm((f) => ({ ...f, specialRequests: e.target.value }))}
                  placeholder="Any special requests or notes..."
                />
              </div>

            </div>

            <div className="form-actions">
              <button className="btn-primary btn-large" onClick={searchAvailableRooms} disabled={searchingRooms}>
                {searchingRooms
                  ? <><div className="btn-spinner" />Searching Rooms...</>
                  : <><Search size={20} />Search Available Rooms</>
                }
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ── STEP 2 ──────────────────────────────────────────────────────── */}
      {step === 2 && (
        <div className="walk-in-content">
          <div className="room-selection-container">

            {/* Room list */}
            <div className="room-list-section">
              <div className="wi-room-header">
                <h2><BedDouble size={22} /> Available Rooms ({availableRooms.length})</h2>
              </div>

              <div className="wi-filter-bar">
                <button
                  className={`wi-filter-btn ${activeFilter === 'all' ? 'wi-filter-btn--active' : ''}`}
                  onClick={() => setActiveFilter('all')}
                >All Rooms ({availableRooms.length})</button>
                {[...new Set(availableRooms.map((r) => r.room_type))].map((type) => (
                  <button
                    key={type}
                    className={`wi-filter-btn ${activeFilter === type ? 'wi-filter-btn--active' : ''}`}
                    onClick={() => setActiveFilter(type)}
                  >
                    {formatRoomType(type)} ({availableRooms.filter((r) => r.room_type === type).length})
                  </button>
                ))}
              </div>

              <div className="walkIns-grid">
                {(activeFilter === 'all' ? availableRooms : availableRooms.filter((r) => r.room_type === activeFilter))
                  .map((room) => {
                    const isSelected   = selectedRooms.some((r) => r.id === room.id);
                    const imageUrl     = getRoomImageUrl(room);
                    const displayPrice = getStayRate(room, form.stayType);
                    const amenities    = Array.isArray(room.amenities) ? room.amenities : [];

                    return (
                      <div key={room.id} className={`walkIns-card ${isSelected ? 'selected' : ''}`}>
                        <div className="room-image-wrapper">
                          {imageUrl
                            ? <img src={imageUrl} alt={`Room ${room.room_number}`} className="room-image"
                                onError={(e) => { e.currentTarget.style.display='none'; e.currentTarget.nextSibling.style.display='flex'; }} />
                            : null}
                          <div className="room-image-placeholder" style={{ display: imageUrl ? 'none' : 'flex' }}>
                            <ImageOff size={28} /><span>No Image</span>
                          </div>
                          <div className="room-capacity-badge"><Users size={12} /><span>Up to {room.capacity} guests</span></div>
                          {isSelected && <div className="room-selected-badge"><Check size={14} /></div>}
                        </div>
                        <div className="walkIns-body">
                          <div className="walkIns-name-row">
                            <div>
                              <h3 className="walkIns-room-number">
                                {formatRoomType(room.room_type)}
                                <span className="walkIns-room-suffix"> - Room {room.room_number}</span>
                              </h3>
                              <span className="room-floor-tag">Floor {room.floor ?? '-'}</span>
                            </div>
                            <div className="walkIns-price-block">
                              <span className="walkIns-price">{formatPeso(displayPrice)}</span>
                              <span className="walkIns-per">per {form.stayType === 'day_use' ? 'day use' : 'night'}</span>
                            </div>
                          </div>
                          {amenities.length > 0 && (
                            <div className="walkIns-amenities">
                              {amenities.slice(0, 5).map((a, i) => (
                                <span key={i} className="walkIns-amenity-pill">
                                  {String(a).replace(/_/g, ' ').replace(/\w/g, (c) => c.toUpperCase())}
                                </span>
                              ))}
                              {amenities.length > 5 && <span className="walkIns-amenity-pill walkIns-amenity-more">+{amenities.length - 5}</span>}
                            </div>
                          )}
                          <button
                            className={`walkIns-select-btn ${isSelected ? 'walkIns-select-btn--selected' : ''}`}
                            onClick={() => toggleRoom(room)}
                          >
                            {isSelected ? <><Check size={16} /> Selected</> : 'Select Room'}
                          </button>
                        </div>
                      </div>
                    );
                  })}
              </div>
            </div>

            {/* Booking Summary sidebar */}
            <div className="booking-summary">
              <h3>Booking Summary</h3>

              {/* Stay type badge */}
              <div className={`day-tour-badge ${form.stayType === 'overnight' ? 'overnight-badge' : ''}`}
                   style={{ background: '#eef2ff', color: '#1a4bcc', border: '1px solid #1a4bcc' }}>
                {form.stayType === 'day_use' ? <Sun size={15} /> : <Moon size={15} />}
                <span>{form.stayType === 'day_use' ? 'Day Use' : 'Overnight Stay'}</span>
              </div>

              <div className="summary-section">
                <h4>Guest Details</h4>
                <div className="summary-item"><span>Name:</span><strong>{form.name}</strong></div>
                <div className="summary-item"><span>Email:</span><span>{form.email}</span></div>
                <div className="summary-item"><span>Phone:</span><span>{form.phone}</span></div>
              </div>

              <div className="summary-section">
                <h4>Stay Details</h4>
                <div className="summary-item">
                  <span>Check-in:</span>
                  <strong>{fromDatetimeLocal(form.checkIn).toLocaleString('en-PH', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}</strong>
                </div>
                <div className="summary-item">
                  <span>Check-out:</span>
                  <strong>{fromDatetimeLocal(form.checkOut).toLocaleString('en-PH', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}</strong>
                </div>
                <div className="summary-item">
                  <span>Duration:</span>
                  <strong style={{ color: duration > 12 && form.stayType === 'day_use' ? '#ef4444' : 'inherit' }}>
                    {fmtDuration(duration)}
                  </strong>
                </div>
                {crossesMidnight && form.stayType === 'day_use' && (
                  <div className="summary-item" style={{ color: '#f59e0b', fontSize: '0.82rem' }}>
                    ⚠ Stay crosses midnight
                  </div>
                )}
                <div className="summary-item"><span>Guests:</span><strong>{form.numberOfGuests}</strong></div>
              </div>

              {selectedRooms.length > 0 && (
                <>
                  <div className="summary-section">
                    <h4>Selected Rooms ({selectedRooms.length})</h4>
                    {selectedRooms.map((room) => {
                      const price = getStayRate(room, form.stayType);
                      return (
                        <div key={room.id} className="selected-room-item">
                          <div>
                            <strong>Room {room.room_number}</strong>
                            <span className="room-type-badge">{formatRoomType(room.room_type)}</span>
                          </div>
                          <span className="room-price-sm">{formatPeso(price * billingNights)}</span>
                        </div>
                      );
                    })}
                    <div className={`wi-capacity-summary ${guestCountExceedsCapacity ? 'wi-capacity-summary--error' : ''}`}>
                      <Users size={16} />
                      <span>
                        Room capacity: <strong>{selectedCapacity}</strong> guest(s) for <strong>{form.numberOfGuests}</strong> guest(s)
                      </span>
                    </div>
                    {guestCountExceedsCapacity && (
                      <p className="wi-capacity-error">
                        Select more rooms or reduce the guest count before confirming.
                      </p>
                    )}
                  </div>

                  {/* Promo */}
                  <div className="summary-section">
                    <h4>Promo Code</h4>
                    {promoResult?.valid ? (
                      <div className="wi-promo-applied">
                        <div>
                          <span className="wi-promo-tag">{promoResult.code_label}</span>
                          <span className="wi-promo-savings">{promoResult.savings_label}</span>
                        </div>
                        <button className="wi-promo-remove" onClick={() => { setPromoResult(null); setPromoInput(''); setPromoError(''); }}>✕ Remove</button>
                      </div>
                    ) : (
                      <>
                        <div className="wi-promo-row">
                          <input type="text" className="wi-promo-input" placeholder="Enter promo code"
                            value={promoInput}
                            onChange={(e) => { setPromoInput(e.target.value.toUpperCase()); setPromoError(''); }}
                            onKeyDown={(e) => e.key === 'Enter' && applyPromo()}
                          />
                          <button className="wi-promo-btn" onClick={applyPromo} disabled={promoLoading}>
                            {promoLoading ? '...' : 'Apply'}
                          </button>
                        </div>
                        {promoError && <p className="wi-promo-error">{promoError}</p>}
                      </>
                    )}
                  </div>
                  {/* Payment totals */}
                  <div className="summary-section">
                    <h4>Payment</h4>
                    <div className="summary-item"><span>Subtotal:</span><span>{formatPeso(subtotal)}</span></div>
                    {discount > 0 && (
                      <div className="summary-item" style={{ color: '#16a34a' }}>
                        <span>Promo Discount:</span><span>-{formatPeso(discount)}</span>
                      </div>
                    )}
                    <div className="summary-item"><span>Taxes and Fees (Included):</span><span>{formatPeso(taxesAndFees)}</span></div>
                    <div className="summary-item total">
                      <span>Total:</span><strong>{formatPeso(total)}</strong>
                    </div>
                    <p style={{ fontSize: '0.72rem', color: '#aaa', margin: '0.25rem 0 0', textAlign: 'right' }}>
                      Taxes and fees are included in the room price.
                    </p>
                  </div>

                  <div className="summary-section wi-payment-collection">
                    <h4>Payment Collection *</h4>

                    <div className="wi-payment-field">
                      <label>Payment Method</label>
                      <select
                          value={form.paymentMethod}
                          onChange={(e) => setForm((f) => ({ ...f, paymentMethod: e.target.value }))}
                        >
                          <option value="cash">Cash</option>
                          <option value="gcash">GCash</option>
                        </select>
                    </div>

                    <div className="wi-payment-field">
                      <label>Amount Tendered *</label>
                      <input
                        type="number"
                        min="0"
                        step="0.01"
                        value={form.amountTendered}
                        readOnly={isNonCashMethod}
                        onChange={(e) => setForm((f) => ({ ...f, amountTendered: e.target.value }))}
                        className={paymentValidation.amountError ? 'error-input' : ''}
                        placeholder="Enter amount received"
                      />
                      {paymentValidation.amountError && <span className="error">{paymentValidation.amountError}</span>}
                      {isNonCashMethod && (
                        <span className="info-text">
                          GCash payment should be for the exact booking amount.
                        </span>
                      )}
                    </div>

                    <div className="summary-item wi-change-preview">
                      <span>Change Due</span>
                      <strong className={paymentValidation.changeDue > 0 ? 'wi-change-positive' : ''}>
                        {formatPeso(paymentValidation.changeDue)}
                      </strong>
                    </div>

                    {isNonCashMethod && (
                      <div style={{ display:'grid', gap:'0.8rem', padding:'0.9rem', border:'1px solid #bfdbfe', borderRadius:10, background:'#eff6ff' }}>
                        <div className="wi-payment-field">
                          <label>GCash Transaction / Reference Number *</label>
                          <input type="text" value={form.paymentReference} onChange={(e) => setForm((f) => ({ ...f, paymentReference: e.target.value }))} className={paymentValidation.referenceError ? 'error-input' : ''} placeholder="Enter transaction reference" maxLength={80} />
                          {paymentValidation.referenceError && <span className="error">{paymentValidation.referenceError}</span>}
                        </div>
                        <div className="wi-payment-field">
                          <label>GCash Sender Name *</label>
                          <input type="text" value={form.gcashSenderName} onChange={(e) => setForm((f) => ({ ...f, gcashSenderName: e.target.value }))} className={paymentValidation.senderError ? 'error-input' : ''} placeholder="Enter sender name" maxLength={120} />
                          {paymentValidation.senderError && <span className="error">{paymentValidation.senderError}</span>}
                        </div>
                        <div className="wi-payment-field">
                          <label>Date and Time Paid *</label>
                          <StaffDateTimePicker
                            value={form.gcashPaidAt}
                            onChange={(value) => setForm((current) => ({ ...current, gcashPaidAt: value }))}
                            max={toDatetimeLocal(new Date())}
                            ariaLabel="Select GCash payment date and time"
                            invalid={Boolean(paymentValidation.paidAtError)}
                          />
                          {paymentValidation.paidAtError && <span className="error">{paymentValidation.paidAtError}</span>}
                        </div>
                        <label style={{ display:'flex', alignItems:'flex-start', gap:'0.55rem', fontSize:'0.78rem', lineHeight:1.45, color:'#334155' }}>
                          <input type="checkbox" checked={form.merchantRecordConfirmed} onChange={(e) => setForm((f) => ({ ...f, merchantRecordConfirmed: e.target.checked }))} style={{ marginTop:2 }} />
                          I matched the reference, sender, amount, and paid time against the official hotel GCash merchant record.
                        </label>
                        {paymentValidation.confirmationError && <span className="error">{paymentValidation.confirmationError}</span>}
                      </div>
                    )}
                  </div>
                </>
              )}

              <div className="summary-actions">
                <button className="btn-secondary" onClick={() => setStep(1)}><X size={18} />Back</button>
                <button
                  className="btn-primary"
                  onClick={handleSubmitBooking}
                  disabled={!canConfirmBooking}
                >
                  {loading ? <><div className="btn-spinner" />Creating...</> : <><Check size={18} />Confirm Booking</>}
                </button>
              </div>
            </div>

          </div>
        </div>
      )}
    </div>
  );
};

export default WalkIn;







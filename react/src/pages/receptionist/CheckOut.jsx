import { useNotificationTarget } from '../../hooks/useNotificationTarget';
import { useState, useEffect, useRef } from 'react';
import {
  Search, User, Loader2, AlertCircle, ArrowRight,
  CreditCard, CheckSquare, Square, Users, CheckCircle2,
  Plus, Trash2,
} from 'lucide-react';
import checkOutService from '../../services/receptionist/checkOutService';
import receptionistApi from '../../services/receptionistApi';
import StatusBadge from '../../components/StatusBadge';
import '../receptionist/Reservation.css';
import './CheckOut.css';

// ── helpers ───────────────────────────────────────────────────────────────────
const fmt = (n) =>
  `₱${Number(n ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const formatDate = (dateStr) => {
  if (!dateStr) return '-';
  return new Date(dateStr).toLocaleDateString('en-US', {
    month: 'short', day: '2-digit', year: 'numeric',
  });
};

const formatDateTime = (dateStr) => {
  if (!dateStr) return '-';
  return new Date(dateStr).toLocaleString('en-US', {
    month: 'short',
    day: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
};

const toDateInputValue = (dateValue) => {
  const d = new Date(dateValue);
  const year = d.getFullYear();
  const month = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
};

const toDatetimeLocal = (dateValue = new Date()) => {
  const date = new Date(dateValue);
  const pad = (value) => String(value).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
};

const createRequestKey = () => globalThis.crypto?.randomUUID?.()
  ?? 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (character) => {
    const random = Math.floor(Math.random() * 16);
    return (character === 'x' ? random : ((random & 0x3) | 0x8)).toString(16);
  });

const getNights = (checkIn, checkOut) => {
  if (!checkIn || !checkOut) return 0;
  return Math.round((new Date(checkOut) - new Date(checkIn)) / 86400000);
};

const deriveLineCheckout = (checkIn, storedNights) => {
  const checkInDate = new Date(checkIn);
  const nights = Number(storedNights);
  if (!checkIn || Number.isNaN(checkInDate.getTime()) || !Number.isFinite(nights) || nights <= 0) {
    return null;
  }

  const derived = new Date(checkInDate);
  derived.setDate(derived.getDate() + Math.round(nights));
  return derived.toISOString();
};

const isSettled = (v) => Number.isFinite(Number(v)) && Number(v) <= 0.009;

const mapBooking = (booking) => {
  const bookingRooms = Array.isArray(booking.booking_rooms) ? booking.booking_rooms : [];
  const room = bookingRooms[0]?.room;
  const payments = booking.payments || [];
  const latestPayment = payments[0];
  const isDayUse = booking.stay_type === 'day_use' || Boolean(booking.is_day_tour);
  const nights = isDayUse ? 0 : Math.max(1, getNights(booking.check_in, booking.check_out));
  const totalAmount = parseFloat(booking.total_amount ?? 0);
  const totalPaid = payments
    .filter((p) => ['completed', 'verified'].includes(p.payment_status))
    .reduce((sum, p) => sum + parseFloat(p.amount ?? 0), 0);
  const remainingBalance = Math.max(
    0,
    parseFloat(booking.remaining_balance ?? (totalAmount - totalPaid))
  );

  const roomLines = bookingRooms.map((line) => {
    const lineRoom = line?.room || null;
    const fallbackLineCheckout = deriveLineCheckout(booking.check_in, line?.nights);
    const effectiveCheckOut = line?.extended_checkout || fallbackLineCheckout || booking.check_out;
    const lineStatus = (line?.room_status || 'active').toLowerCase() === 'checked_out'
      ? 'checked_out'
      : 'active';
    const pricePerNight = Number(line?.price_per_night ?? 0);
    const storedNights = Number(line?.nights ?? 0);
    const derivedNights = isDayUse
      ? 0
      : Math.max(1, Number.isFinite(storedNights) && storedNights > 0
        ? Math.round(storedNights)
        : getNights(booking.check_in, effectiveCheckOut));
    const computedSubtotal = Number((pricePerNight * derivedNights).toFixed(2));
    const storedSubtotal = Number(line?.subtotal ?? 0);
    const roomSubtotal = line?.extended_checkout
      ? computedSubtotal
      : (storedSubtotal > 0 ? storedSubtotal : computedSubtotal);

    return {
      bookingRoomId: line?.id ?? null,
      roomId: line?.room_id ?? lineRoom?.id ?? null,
      roomNumber: lineRoom?.room_number || null,
      roomType: lineRoom?.room_type || line?.requested_room_type || 'Room',
      checkIn: booking.check_in,
      checkOut: effectiveCheckOut,
      lineStatus,
      checkedOutAt: line?.checked_out_at || null,
      extensionCharge: Number(line?.extension_charge ?? 0),
      pricePerNight,
      nights: derivedNights,
      roomSubtotal,
    };
  });

  const activeRooms = roomLines.filter((line) => line.lineStatus === 'active').length;

  return {
    id: booking.id,
    referenceNumber: booking.reference_number,
    name: booking.primary_guest?.name ?? 'Unknown Guest',
    room: room ? `Room ${room.room_number}` : 'N/A',
    roomType: room?.room_type ?? '',
    stayStart: formatDate(booking.check_in),
    stayEnd: isDayUse ? formatDate(booking.check_in) : formatDate(booking.check_out),
    nights,
    isDayUse,
    totalAmount,
    totalPaid,
    remainingBalance,
    paymentMethod: latestPayment
      ? `${latestPayment.payment_method ?? 'N/A'}${latestPayment.transaction_reference ? ` - ${latestPayment.transaction_reference}` : ''}`
      : 'N/A',
    paymentStatus: latestPayment?.payment_status ?? 'pending',
    rooms: roomLines,
    roomCount: roomLines.length,
    activeRooms,
    isFullyCheckedOut: roomLines.length > 0 ? activeRooms === 0 : booking.booking_status === 'checked_out',
    raw: booking,
  };
};

const showToast = (message, type = 'success') => {
  const existing = document.getElementById('checkout-toast');
  if (existing) existing.remove();
  const toast = document.createElement('div');
  toast.id = 'checkout-toast';
  toast.style.cssText = `
    position:fixed;top:20px;right:20px;padding:14px 22px;border-radius:8px;
    color:#fff;font-size:14px;font-weight:500;z-index:9999;
    box-shadow:0 8px 30px rgba(0,0,0,0.18);
    background:${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#f59e0b'};
    transition:opacity 0.3s ease;
  `;
  toast.textContent = message;
  document.body.appendChild(toast);
  setTimeout(() => {
    toast.style.opacity = '0';
    setTimeout(() => toast.remove(), 300);
  }, 3500);
};

const C = {
  primary: '#1a4bcc',
  text: '#0d1b3e',
  textSec: '#4a5568',
  textMute: '#64748b',
  border: '#dde3f0',
  borderLight: '#e8eef7',
  bg: '#f7f9ff',
  surface: '#fff',
  blue: '#1565c0',
  blueBg: 'rgba(33,150,243,0.07)',
  blueBorder: 'rgba(33,150,243,0.2)',
  green: '#4caf50',
  amber: '#f59e0b',
  amberBg: 'rgba(245,158,11,0.08)',
  amberBorder: 'rgba(245,158,11,0.25)',
  red: '#ef4444',
  redBg: 'rgba(239,68,68,0.07)',
  redBorder: 'rgba(239,68,68,0.2)',
};

// ── Preset extra charge categories ───────────────────────────────────────────
const CHARGE_PRESETS = [
  'Broken Item',
  'Lost Key / Card',
  'Extra Towel / Linen',
  'Room Damage',
  'Late Check-Out Fee',
  'Mini Bar Consumption',
  'Laundry Service',
  'Room Service',
  'Parking Fee',
  'Other',
];

// ── component ─────────────────────────────────────────────────────────────────
export default function CheckOut() {
  // ── single guest flow ──
  useNotificationTarget((target) => { setQuery(target.search); setGuest(null); setDone(false); setExtraCharges([]); setConfirmModal(false); setBulkMode(false); });
  const [query, setQuery]               = useState('');
  const [results, setResults]           = useState([]);
  const [searching, setSearching]       = useState(false);
  const [searchError, setSearchError]   = useState(null);
  const [guest, setGuest]               = useState(null);
  const [actionLoading, setActionLoading] = useState(false);
  const [done, setDone]                 = useState(false);
  const [confirmModal, setConfirmModal] = useState(false);
  const [roomCheckoutModal, setRoomCheckoutModal] = useState(null);
  const [roomExtendModal, setRoomExtendModal] = useState(null);
  const [roomActionLoading, setRoomActionLoading] = useState(false);
  const [extendDate, setExtendDate] = useState('');
  const [extendReason, setExtendReason] = useState('');

  // ── settle balance modal ──
  const [settleModal, setSettleModal]   = useState(false);
  const [settleLoading, setSettleLoading] = useState(false);
  const [settleAmount, setSettleAmount] = useState('');
  const [settleMethod, setSettleMethod] = useState('cash');
  const [settleNote, setSettleNote]     = useState('');
  const [settleGcashReference, setSettleGcashReference] = useState('');
  const [settleGcashSender, setSettleGcashSender] = useState('');
  const [settleGcashPaidAt, setSettleGcashPaidAt] = useState(toDatetimeLocal());
  const [settleMerchantConfirmed, setSettleMerchantConfirmed] = useState(false);
  const settleRequestKey = useRef(createRequestKey());

  // ── extra charges ─────────────────────────────────────────────────────────
  const [extraCharges, setExtraCharges] = useState([]);
  const [chargeLoading, setChargeLoading] = useState(false);
  const chargeRequestKey = useRef(createRequestKey());

  // today departures / bulk ──
  const [todayDepartures, setTodayDepartures]       = useState([]);
  const [departuresLoading, setDeparturesLoading]   = useState(false);
  const [bulkMode, setBulkMode]                     = useState(false);
  const [selectedIds, setSelectedIds]               = useState(new Set());
  const [bulkConfirmModal, setBulkConfirmModal]     = useState(false);
  const [bulkLoading, setBulkLoading]               = useState(false);
  const [bulkResults, setBulkResults]               = useState(null);

  const debounceRef = useRef(null);
  const dropdownRef = useRef(null);

  // ── derived ──
  const settled   = todayDepartures.filter((g) => isSettled(g.remainingBalance) && (g.roomCount ?? 1) <= 1);
  const unsettled = todayDepartures.filter((g) => !isSettled(g.remainingBalance) || (g.roomCount ?? 1) > 1);
  const selectedSettled = settled.filter((g) => selectedIds.has(g.id));

  // Extra charges total
  const extraTotal = extraCharges.reduce((s, c) => s + (parseFloat(c.amount) || 0), 0);

  // Effective remaining = original balance + extra charges not yet saved
  const effectiveRemaining = (guest?.remainingBalance ?? 0) + extraTotal;
  const roomForExtension = roomExtendModal
    ? (
      guest?.rooms?.find((line) => line.bookingRoomId === roomExtendModal.bookingRoomId)
      ?? guest?.rooms?.find((line) => line.roomId === roomExtendModal.roomId)
    )
    : null;

  const extensionPreview = (() => {
    if (!roomForExtension || !extendDate) {
      return { additionalNights: 0, additionalCharge: 0 };
    }

    const currentCheckout = new Date(roomForExtension.checkOut);
    const selectedCheckout = new Date(`${extendDate}T${String(currentCheckout.getHours()).padStart(2, '0')}:${String(currentCheckout.getMinutes()).padStart(2, '0')}:00`);
    const additionalNights = Math.max(
      0,
      Math.ceil((selectedCheckout - currentCheckout) / 86400000)
    );
    const additionalCharge = additionalNights * Number(roomForExtension.pricePerNight || 0);

    return { additionalNights, additionalCharge };
  })();

  // ── loaders ──
  const loadTodayDepartures = async () => {
    setDeparturesLoading(true);
    try {
      const res = await checkOutService.getTodayDepartures();
      const bookings = res.data?.data ?? [];
      setTodayDepartures(bookings.map(mapBooking));
    } catch { /* non-critical */ }
    finally { setDeparturesLoading(false); }
  };

  const refreshSelectedBooking = async (id) => {
    const res = await checkOutService.getBooking(id);
    const raw = res.data;
    if (!raw) return;
    const mapped = mapBooking(raw);
    setGuest(mapped);
    setSettleAmount(String(Math.max(0, Number(mapped.remainingBalance ?? 0))));
  };

  useEffect(() => { loadTodayDepartures(); }, []);

  // ── search ──
  useEffect(() => {
    if (query.trim().length < 2) { setResults([]); return; }
    clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(async () => {
      setSearching(true);
      setSearchError(null);
      try {
        const res = await checkOutService.searchGuests(query);
        const bookings = res.data?.data ?? [];
        setResults(bookings.map(mapBooking));
      } catch {
        setSearchError('Failed to search guests.');
        setResults([]);
      } finally { setSearching(false); }
    }, 400);
    return () => clearTimeout(debounceRef.current);
  }, [query]);

  useEffect(() => {
    const handler = (e) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target))
        setResults([]);
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, []);

  // ── single guest handlers ──
  const handleSelect = (g) => {
    setGuest(g);
    setQuery(g.name);
    setResults([]);
    setDone(false);
    setConfirmModal(false);
    setRoomCheckoutModal(null);
    setRoomExtendModal(null);
    setExtraCharges([]);
    chargeRequestKey.current = createRequestKey();
    setSettleAmount(String(Math.max(0, Number(g.remainingBalance ?? 0))));
    setSettleMethod('cash');
    setSettleNote('');
  };

  // ── Extra charge helpers ──────────────────────────────────────────────────
  const addExtraCharge = () => {
    chargeRequestKey.current = createRequestKey();
    setExtraCharges(prev => [...prev, {
      id:          Date.now(),
      description: '',
      category:    'Broken Item',
      amount:      '',
    }]);
  };

  const updateCharge = (id, field, value) => {
    chargeRequestKey.current = createRequestKey();
    setExtraCharges(prev => prev.map(c => c.id === id ? { ...c, [field]: value } : c));
  };

  const removeCharge = (id) => {
    chargeRequestKey.current = createRequestKey();
    setExtraCharges(prev => prev.filter(c => c.id !== id));
  };

  // Save extra charges to backend then refresh balance
  const saveExtraCharges = async () => {
    const valid = extraCharges.filter(c => c.description.trim() && parseFloat(c.amount) > 0);
    if (valid.length === 0) {
      showToast('Please fill in description and amount for all charges.', 'warning');
      return;
    }

    setChargeLoading(true);
    try {
      const response = await receptionistApi.post(`/receptionist/bookings/${guest.id}/extra-charges`, {
        idempotency_key: chargeRequestKey.current,
        charges: valid.map(c => ({
          description: c.description.trim(),
          category:    c.category,
          amount:      parseFloat(c.amount),
        })),
      });

      showToast(response.data?.message || `${valid.length} extra charge(s) recorded.`, 'success');
      setExtraCharges([]);
      chargeRequestKey.current = createRequestKey();

      // Refresh guest balance from backend
      await refreshSelectedBooking(guest.id);
      await loadTodayDepartures();
    } catch (error) {
      const validation = error.response?.data?.errors;
      showToast(
        validation
          ? Object.values(validation).flat()[0]
          : error.response?.data?.message || 'Network error — could not save charges.',
        'error',
      );
    } finally {
      setChargeLoading(false);
    }
  };

  // ── checkout handlers ──
  const handleCheckOut = async () => {
    if (!guest) return;
    if (effectiveRemaining > 0.009) {
      showToast('Settle the remaining balance first before check-out.', 'warning');
      return;
    }

    setActionLoading(true);
    try {
      await checkOutService.finalizeCheckOut(guest.id ?? guest.referenceNumber);
      setDone(true);
      setConfirmModal(false);
      showToast(`${guest.name} checked out successfully!`, 'success');
      loadTodayDepartures();
    } catch (err) {
      showToast(err.response?.data?.message || 'Failed to finalize check-out.', 'error');
      setConfirmModal(false);
    } finally {
      setActionLoading(false);
    }
  };

  const openRoomCheckoutModal = (line) => {
    setRoomCheckoutModal(line);
  };

  const openRoomExtendModal = (line) => {
    setRoomExtendModal(line);
    setExtendReason('');
    if (line?.checkOut) {
      const nextDay = new Date(line.checkOut);
      nextDay.setDate(nextDay.getDate() + 1);
      setExtendDate(toDateInputValue(nextDay));
    } else {
      setExtendDate('');
    }
  };

  const handleRoomCheckout = async () => {
    if (!guest || !roomCheckoutModal) return;
    if (!roomCheckoutModal.roomId) {
      showToast('Selected room is missing an assigned room number.', 'error');
      return;
    }

    setRoomActionLoading(true);
    try {
      await checkOutService.checkoutRoom(guest.id ?? guest.referenceNumber, roomCheckoutModal.roomId, {
        booking_room_id: roomCheckoutModal.bookingRoomId ?? null,
      });
      await refreshSelectedBooking(guest.id ?? guest.referenceNumber);
      await loadTodayDepartures();
      setRoomCheckoutModal(null);
      showToast(`Room ${roomCheckoutModal.roomType} - ${roomCheckoutModal.roomNumber || 'TBA'} checked out.`, 'success');
    } catch (err) {
      showToast(err.response?.data?.message || 'Failed to check out room.', 'error');
    } finally {
      setRoomActionLoading(false);
    }
  };

  const handleExtendRoom = async () => {
    if (!guest || !roomExtendModal || !extendDate) return;
    if (!roomExtendModal.roomId) {
      showToast('Selected room is missing an assigned room number.', 'error');
      return;
    }

    if (extendReason.trim().length < 3) {
      showToast('Please enter a short reason for the stay extension.', 'warning');
      return;
    }

    const currentDate = new Date(roomExtendModal.checkOut);
    const requestedDate = new Date(`${extendDate}T${String(currentDate.getHours()).padStart(2, '0')}:${String(currentDate.getMinutes()).padStart(2, '0')}:00`);
    if (requestedDate <= currentDate) {
      showToast('New check-out date must be after the current check-out date.', 'warning');
      return;
    }

    setRoomActionLoading(true);
    try {
      await checkOutService.extendRoomStay(guest.id ?? guest.referenceNumber, roomExtendModal.roomId, {
        new_checkout_date: extendDate,
        reason: extendReason.trim(),
        booking_room_id: roomExtendModal.bookingRoomId ?? null,
      });
      await refreshSelectedBooking(guest.id ?? guest.referenceNumber);
      await loadTodayDepartures();
      showToast(
        `Stay extended for ${roomExtendModal.roomType} - ${roomExtendModal.roomNumber || 'TBA'}. New check-out: ${formatDate(requestedDate)}. Additional charge: ${fmt(extensionPreview.additionalCharge)}.`,
        'success'
      );
      setRoomExtendModal(null);
    } catch (err) {
      showToast(err.response?.data?.message || 'Failed to extend room stay.', 'error');
    } finally {
      setRoomActionLoading(false);
    }
  };

  const handleSettleBalance = async () => {
    if (!guest || settleLoading) return;
    const requestedAmount = Number(settleAmount);
    if (!Number.isFinite(requestedAmount) || requestedAmount <= 0) {
      showToast('Enter a valid payment amount.', 'warning'); return;
    }
    if (!settleNote.trim()) {
      showToast('Please enter a payment note.', 'warning'); return;
    }
    if (settleMethod === 'gcash' && settleGcashReference.trim().length < 6) {
      showToast('Enter the GCash transaction reference.', 'warning'); return;
    }
    if (settleMethod === 'gcash' && settleGcashSender.trim().length < 2) {
      showToast('Enter the GCash sender name.', 'warning'); return;
    }
    if (settleMethod === 'gcash' && !settleGcashPaidAt) {
      showToast('Enter the GCash payment date and time.', 'warning'); return;
    }
    if (settleMethod === 'gcash' && !settleMerchantConfirmed) {
      showToast('Confirm that you matched the payment with the official hotel GCash record.', 'warning'); return;
    }
    setSettleLoading(true);
    try {
      const bookingKey = guest.id ?? guest.referenceNumber;
      let latest = null;
      try {
        const latestRes = await checkOutService.getBooking(bookingKey);
        latest = mapBooking(latestRes.data);
        setGuest(latest);
      } catch { /* continue with current snapshot */ }

      const effectiveRem = Number(latest?.remainingBalance ?? guest.remainingBalance ?? 0);
      if (isSettled(effectiveRem)) {
        setGuest(prev => prev ? { ...prev, remainingBalance: 0, totalPaid: Number(prev.totalAmount ?? prev.totalPaid ?? 0), paymentStatus: 'completed' } : prev);
        setSettleAmount('0');
        setSettleModal(false);
        showToast('Balance is already settled. You can finalize check-out now.', 'success');
        await loadTodayDepartures();
        return;
      }
      if (requestedAmount - effectiveRem > 0.009) {
        setSettleAmount(String(effectiveRem));
        showToast(`Amount cannot exceed remaining balance (${fmt(effectiveRem)}).`, 'warning');
        return;
      }
      const res = await checkOutService.settleBalance(bookingKey, {
        amount: requestedAmount,
        payment_method: settleMethod,
        notes: settleNote.trim(),
        idempotency_key: settleRequestKey.current,
        gcash_reference: settleMethod === 'gcash' ? settleGcashReference.trim() : null,
        gcash_sender_name: settleMethod === 'gcash' ? settleGcashSender.trim() : null,
        gcash_paid_at: settleMethod === 'gcash' ? settleGcashPaidAt : null,
        merchant_record_confirmed: settleMethod === 'gcash' ? settleMerchantConfirmed : null,
      });
      const apiData = res?.data ?? {};
      const nextRemaining = Number(apiData.remaining_balance ?? guest.remainingBalance ?? 0);
      const nextTotalPaid = Number(apiData.total_paid ?? (guest.totalPaid + requestedAmount));
      setGuest(prev => prev ? {
        ...prev,
        totalAmount:      Number(apiData.total_amount ?? prev.totalAmount),
        totalPaid:        nextTotalPaid,
        remainingBalance: Math.max(0, nextRemaining),
        paymentMethod:    settleMethod,
        paymentStatus:    nextRemaining > 0.009 ? 'partial' : 'completed',
      } : prev);
      setSettleAmount(String(Math.max(0, nextRemaining)));
      showToast(res.message || 'Balance payment recorded.', 'success');
      try { await refreshSelectedBooking(bookingKey); } catch { /* non-blocking */ }
      await loadTodayDepartures();
      setSettleModal(false);
      setSettleNote('');
      setSettleGcashReference('');
      setSettleGcashSender('');
      setSettleMerchantConfirmed(false);
      settleRequestKey.current = createRequestKey();
    } catch (err) {
      const msg = err.response?.data?.message || 'Failed to record payment.';
      const backendRemaining = Number(err.response?.data?.remaining_balance);
      if (/no remaining balance to settle/i.test(msg) || isSettled(backendRemaining)) {
        setGuest(prev => prev ? { ...prev, totalPaid: Number(prev.totalAmount ?? prev.totalPaid ?? 0), remainingBalance: 0, paymentStatus: 'completed' } : prev);
        setSettleAmount('0');
        try { await refreshSelectedBooking(guest.id ?? guest.referenceNumber); } catch { /* ignore */ }
        await loadTodayDepartures();
        setSettleModal(false);
        showToast('Balance is already settled. You can finalize check-out now.', 'success');
        return;
      }
      if (/amount cannot exceed/i.test(msg) && Number.isFinite(backendRemaining) && backendRemaining > 0.009) {
        setSettleAmount(String(backendRemaining));
        showToast(`Amount exceeds remaining balance. Updated to ${fmt(backendRemaining)}.`, 'warning');
        return;
      }
      showToast(msg, 'error');
    } finally { setSettleLoading(false); }
  };

  // ── bulk handlers ──
  const toggleSelectAll = () => {
    if (selectedIds.size === settled.length) setSelectedIds(new Set());
    else setSelectedIds(new Set(settled.map(g => g.id)));
  };

  const toggleOne = (id) => {
    setSelectedIds(prev => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  };

  const handleBulkCheckOut = async () => {
    if (selectedSettled.length === 0) return;
    setBulkLoading(true);
    const succeeded = [], failed = [];
    await Promise.allSettled(
      selectedSettled.map(async g => {
        try {
          await checkOutService.finalizeCheckOut(g.id ?? g.referenceNumber);
          succeeded.push(g);
        } catch (err) {
          failed.push({ guest: g, reason: err.response?.data?.message || 'Unknown error' });
        }
      })
    );
    setBulkResults({ succeeded, failed });
    setBulkConfirmModal(false);
    setBulkLoading(false);
    setSelectedIds(new Set());
    await loadTodayDepartures();
    if (failed.length === 0) showToast(`${succeeded.length} guest(s) checked out successfully!`, 'success');
    else showToast(`${succeeded.length} checked out, ${failed.length} failed.`, 'warning');
  };

  // ── single done screen ──
  if (done && guest && !bulkMode) return (
    <div style={{ textAlign:'center', padding:'5rem 1rem', fontFamily:'system-ui' }}>
      <h2 style={{ color:C.text, marginBottom:8 }}>Check-Out Complete</h2>
      <p style={{ color:C.textSec }}>{guest.name} checked out from {guest.room}.</p>
      <button
        onClick={() => { setGuest(null); setQuery(''); setDone(false); setExtraCharges([]); loadTodayDepartures(); }}
        style={{ marginTop:'1.5rem', background:C.primary, color:'#fff', border:'none', borderRadius:8, padding:'0.65rem 1.5rem', fontWeight:600, cursor:'pointer', fontSize:'0.9rem' }}
      >
        Check Out Another Guest
      </button>
    </div>
  );

  return (
    <div className="checkin-page">
      {/* ── Page Header ── */}
      <div className="page-header" style={{ display:'flex', justifyContent:'space-between', alignItems:'flex-start', flexWrap:'wrap', gap:'1rem' }}>
        <div>
          <h1>Check-Out</h1>
          <p className="page-subtitle">Process guest departures and settle remaining balances.</p>
        </div>
        {todayDepartures.length > 0 && (
          <button
            onClick={() => { setBulkMode(!bulkMode); setSelectedIds(new Set()); setBulkResults(null); setGuest(null); setQuery(''); setExtraCharges([]); }}
            style={{ display:'flex', alignItems:'center', gap:8, background:bulkMode ? '#1a4bcc' : 'transparent', color:bulkMode ? '#fff' : '#1a4bcc', border:'2px solid #1a4bcc', borderRadius:8, padding:'0.55rem 1.1rem', fontWeight:700, cursor:'pointer', fontSize:'0.88rem', fontFamily:'inherit', transition:'all 0.2s' }}
          >
            <Users size={16} />
            {bulkMode ? 'Exit Bulk Mode' : 'Bulk Check-Out'}
          </button>
        )}
      </div>

      {/* ══════════════════════════════════════════════════════
          BULK MODE
         ══════════════════════════════════════════════════════ */}
      {bulkMode ? (
        <div style={{ display:'flex', flexDirection:'column', gap:'1.25rem' }}>
          {bulkResults && (
            <div style={{ background:bulkResults.failed.length===0 ? '#f0fdf4' : '#fffbeb', border:`1px solid ${bulkResults.failed.length===0 ? '#86efac' : '#fcd34d'}`, borderRadius:10, padding:'1rem 1.25rem' }}>
              <div style={{ fontWeight:700, color:C.text, marginBottom:'0.4rem' }}>Bulk Check-Out Results</div>
              <div style={{ color:'#166534', fontSize:'0.9rem' }}>✓ {bulkResults.succeeded.length} guest(s) checked out successfully</div>
              {bulkResults.failed.length > 0 && (
                <div style={{ marginTop:'0.5rem' }}>
                  <div style={{ color:'#92400e', fontSize:'0.9rem', fontWeight:600 }}>✕ {bulkResults.failed.length} failed:</div>
                  {bulkResults.failed.map(({ guest: g, reason }) => (
                    <div key={g.id} style={{ color:'#92400e', fontSize:'0.85rem', marginTop:'0.25rem' }}>• {g.name} ({g.room}) — {reason}</div>
                  ))}
                </div>
              )}
            </div>
          )}

          {departuresLoading ? (
            <div style={{ display:'flex', justifyContent:'center', padding:'3rem' }}>
              <Loader2 size={28} style={{ animation:'spin 1s linear infinite', color:C.primary }} />
            </div>
          ) : todayDepartures.length === 0 ? (
            <div style={{ textAlign:'center', padding:'4rem 1rem', color:C.textMute }}>No checked-in guests found for today.</div>
          ) : (
            <>
              <div style={{ background:C.surface, border:`1px solid ${C.border}`, borderRadius:12, overflow:'hidden' }}>
                <div style={{ display:'flex', alignItems:'center', justifyContent:'space-between', padding:'0.9rem 1.25rem', borderBottom:`1px solid ${C.borderLight}`, background:C.bg, flexWrap:'wrap', gap:'0.75rem' }}>
                  <div style={{ display:'flex', alignItems:'center', gap:'0.75rem' }}>
                    <button onClick={toggleSelectAll} style={{ background:'none', border:'none', cursor:'pointer', padding:0, display:'flex', alignItems:'center', color:'#1a4bcc' }}>
                      {selectedIds.size === settled.length && settled.length > 0 ? <CheckSquare size={20} /> : <Square size={20} />}
                    </button>
                    <span style={{ fontWeight:700, color:C.text, fontSize:'0.95rem' }}>Settled Guests</span>
                    <span style={{ background:'#dcfce7', color:'#166534', fontSize:'0.75rem', fontWeight:700, padding:'2px 10px', borderRadius:20 }}>{settled.length} ready</span>
                  </div>
                  {selectedIds.size > 0 && (
                    <button onClick={() => setBulkConfirmModal(true)} style={{ display:'flex', alignItems:'center', gap:6, background:'#1a4bcc', color:'#fff', border:'none', borderRadius:8, padding:'0.55rem 1.1rem', fontWeight:700, cursor:'pointer', fontSize:'0.88rem', fontFamily:'inherit' }}>
                      <ArrowRight size={15} /> Check Out Selected ({selectedIds.size})
                    </button>
                  )}
                </div>
                {settled.length === 0 ? (
                  <div style={{ padding:'1.5rem', color:C.textMute, fontSize:'0.9rem', textAlign:'center' }}>No settled guests — all guests have remaining balances.</div>
                ) : settled.map(g => (
                  <div key={g.id} onClick={() => toggleOne(g.id)} style={{ display:'flex', alignItems:'center', gap:'1rem', padding:'0.9rem 1.25rem', borderBottom:`1px solid ${C.borderLight}`, cursor:'pointer', background:selectedIds.has(g.id) ? 'rgba(26,75,204,0.06)' : C.surface, transition:'background 0.15s' }}>
                    <span style={{ color:C.primary, flexShrink:0 }}>{selectedIds.has(g.id) ? <CheckSquare size={18} /> : <Square size={18} />}</span>
                    <div style={{ flex:1 }}>
                      <div style={{ fontWeight:600, color:C.text }}>{g.name}</div>
                      <div style={{ color:C.textMute, fontSize:'0.83rem' }}>{g.room} · {g.stayStart} → {g.stayEnd}</div>
                    </div>
                    <span style={{ background:'#dcfce7', color:'#166534', fontSize:'0.75rem', fontWeight:700, padding:'3px 10px', borderRadius:20, flexShrink:0 }}>Settled</span>
                  </div>
                ))}
              </div>

              {unsettled.length > 0 && (
                <div style={{ background:C.surface, border:`1px solid ${C.amberBorder}`, borderRadius:12, overflow:'hidden' }}>
                  <div style={{ padding:'0.9rem 1.25rem', borderBottom:`1px solid ${C.amberBorder}`, background:C.amberBg, display:'flex', alignItems:'center', gap:'0.75rem' }}>
                    <AlertCircle size={16} style={{ color:C.amber, flexShrink:0 }} />
                    <span style={{ fontWeight:700, color:C.text, fontSize:'0.95rem' }}>Needs Individual Processing</span>
                    <span style={{ background:'#fef3c7', color:'#92400e', fontSize:'0.75rem', fontWeight:700, padding:'2px 10px', borderRadius:20 }}>{unsettled.length} guest{unsettled.length > 1 ? 's' : ''}</span>
                    <span style={{ color:C.textSec, fontSize:'0.82rem', marginLeft:'auto' }}>Handle individually — collect payment first</span>
                  </div>
                  {unsettled.map(g => (
                    <div key={g.id} style={{ display:'flex', alignItems:'center', gap:'1rem', padding:'0.9rem 1.25rem', borderBottom:`1px solid ${C.borderLight}` }}>
                      <div style={{ flex:1 }}>
                        <div style={{ fontWeight:600, color:C.text }}>{g.name}</div>
                        <div style={{ color:C.textMute, fontSize:'0.83rem' }}>{g.room} · {g.stayStart} → {g.stayEnd}</div>
                      </div>
                      <div style={{ textAlign:'right', flexShrink:0, marginRight:'0.75rem' }}>
                        <div style={{ color:C.amber, fontWeight:700, fontSize:'0.9rem' }}>{fmt(g.remainingBalance)}</div>
                        <div style={{ color:C.textMute, fontSize:'0.75rem' }}>{(g.roomCount ?? 1) > 1 ? 'multi-room booking' : 'balance due'}</div>
                      </div>
                      <button onClick={() => { setBulkMode(false); handleSelect(g); }} style={{ display:'flex', alignItems:'center', gap:5, background:'#2563eb', color:'#fff', border:'none', borderRadius:7, padding:'0.5rem 0.9rem', fontWeight:600, cursor:'pointer', fontSize:'0.82rem', flexShrink:0, fontFamily:'inherit' }}>
                        <CreditCard size={13} /> Open Booking
                      </button>
                    </div>
                  ))}
                </div>
              )}
            </>
          )}
        </div>

      ) : (
        /* ══════════════════════════════════════════════════════
            SINGLE GUEST MODE
           ══════════════════════════════════════════════════════ */
        <>
          {/* Search */}
          <div className="checkin-search-wrapper" ref={dropdownRef}>
            <div className="checkin-search-box">
              {searching ? <Loader2 size={18} className="search-icon spin" /> : <Search size={18} className="search-icon" />}
              <input
                type="text"
                placeholder="Search checked-in guest name, booking ID, or email..."
                value={query}
                onChange={e => { setQuery(e.target.value); setGuest(null); setDone(false); setExtraCharges([]); }}
              />
            </div>
            {searchError && <div className="search-error"><AlertCircle size={14} /> {searchError}</div>}
            {results.length > 0 && !guest && (
              <ul className="search-dropdown">
                {results.map(g => (
                  <li key={g.id} className="search-dropdown-item" onClick={() => handleSelect(g)}>
                    <User size={15} />
                    <span>{g.name}</span>
                    <span className="dropdown-booking-id">{g.referenceNumber}</span>
                    <StatusBadge status="checked_in" label="Checked In" />
                  </li>
                ))}
              </ul>
            )}
          </div>

          {/* Expected departures strip */}
          {(todayDepartures.length > 0 || departuresLoading) && (
            <div className="expected-strip">
              <span className="expected-strip-label">Expected Departures</span>
              {departuresLoading
                ? <Loader2 size={14} className="spin" />
                : todayDepartures.map(g => (
                  <button key={g.id} className="expected-chip expected-chip--out" onClick={() => handleSelect(g)}>
                    <span className="chip-dot chip-dot--out" />
                    {g.name}
                    <span className="chip-room"> - {g.room}</span>
                  </button>
                ))
              }
            </div>
          )}

          {/* ── Guest card ── */}
          {guest && (
            <div style={{ display:'flex', flexDirection:'column', gap:'1.25rem' }}>

              {/* Booking summary card */}
              <div className="table-card" style={{ background:C.surface, borderRadius:14, border:`1px solid ${C.border}`, overflow:'hidden', width:'100%' }}>
                <div style={{ display:'flex', justifyContent:'space-between', alignItems:'flex-start', padding:'1.5rem 1.6rem', borderBottom:`1px solid ${C.borderLight}`, flexWrap:'wrap', gap:'1rem' }}>
                  <div>
                    <div style={{ fontSize:'1.4rem', fontWeight:700, color:C.text }}>{guest.name}</div>
                    <div style={{ color:C.textSec }}>{guest.room}{guest.roomType ? ` - ${guest.roomType}` : ''}</div>
                    <div style={{ color:C.textMute, fontSize:'0.9rem' }}>{guest.stayStart} - {guest.stayEnd} ({guest.isDayUse ? 'Day Use (12 hrs)' : `${guest.nights} night(s)`})</div>
                  </div>
                  <div style={{ textAlign:'right' }}>
                    <div style={{ color:C.textMute, fontSize:'0.8rem' }}>Reference</div>
                    <div style={{ color:C.text, fontWeight:700 }}>{guest.referenceNumber}</div>
                  </div>
                </div>

                {guest.roomCount > 1 && (
                  <div style={{ padding:'1rem 1.6rem', borderBottom:`1px solid ${C.borderLight}`, display:'flex', flexDirection:'column', gap:'0.6rem' }}>
                    <div style={{ display:'flex', justifyContent:'space-between', alignItems:'center' }}>
                      <strong style={{ color:C.text }}>Room Check-Out Status</strong>
                      <span style={{ fontSize:'0.8rem', color:C.textMute }}>
                        {guest.activeRooms === 0 ? 'All rooms checked out' : `${guest.activeRooms} active room(s)`}
                      </span>
                    </div>
                    {guest.rooms.map((line) => {
                      const isActive = line.lineStatus === 'active';
                      return (
                        <div
                          key={`${line.bookingRoomId}-${line.roomId}`}
                          style={{
                            display:'grid',
                            gridTemplateColumns:'1fr auto',
                            gap:'0.9rem',
                            padding:'0.75rem 0.9rem',
                            border:`1px solid ${C.borderLight}`,
                            borderRadius:10,
                            background:isActive ? '#fff' : '#f0fdf4',
                          }}
                        >
                          <div>
                            <div style={{ fontWeight:700, color:C.text }}>
                              {line.roomType} - {line.roomNumber ? `Room ${line.roomNumber}` : 'Room TBA'}
                            </div>
                            <div style={{ fontSize:'0.82rem', color:C.textSec }}>
                              Check-In: {formatDate(line.checkIn)} | Check-Out: {formatDate(line.checkOut)}
                            </div>
                            <div style={{ fontSize:'0.8rem', color:C.textSec }}>
                              {line.nights > 0
                                ? `${fmt(line.pricePerNight)} x ${line.nights} night${line.nights !== 1 ? 's' : ''} = ${fmt(line.roomSubtotal)}`
                                : 'Day Use (12 hrs)'}
                            </div>
                            {line.checkedOutAt && (
                              <div style={{ fontSize:'0.78rem', color:'#15803d' }}>
                                Checked out at {formatDateTime(line.checkedOutAt)}
                              </div>
                            )}
                            {line.extensionCharge > 0 && (
                              <div style={{ fontSize:'0.78rem', color:'#1d4ed8' }}>
                                Extension charge: {fmt(line.extensionCharge)}
                              </div>
                            )}
                          </div>
                          <div style={{ display:'flex', flexDirection:'column', gap:'0.45rem', justifyContent:'center', alignItems:'flex-end' }}>
                            {isActive ? (
                              <>
                                <span style={{ background:'#fef3c7', color:'#92400e', fontSize:'0.72rem', fontWeight:700, padding:'3px 9px', borderRadius:999 }}>Active</span>
                                <div style={{ display:'flex', gap:'0.5rem' }}>
                                  <button
                                    onClick={() => openRoomExtendModal(line)}
                                    style={{ border:'1px solid #1d4ed8', color:'#1d4ed8', background:'#eff6ff', borderRadius:7, padding:'0.4rem 0.7rem', cursor:'pointer', fontWeight:700, fontSize:'0.8rem' }}
                                  >
                                    Extend Stay
                                  </button>
                                  <button
                                    onClick={() => openRoomCheckoutModal(line)}
                                    style={{ border:'1px solid #166534', color:'#166534', background:'#ecfdf5', borderRadius:7, padding:'0.4rem 0.7rem', cursor:'pointer', fontWeight:700, fontSize:'0.8rem' }}
                                  >
                                    Check-Out Room
                                  </button>
                                </div>
                              </>
                            ) : (
                              <span style={{ background:'#16a34a', color:'#fff', fontSize:'0.72rem', fontWeight:700, padding:'4px 10px', borderRadius:999 }}>Checked Out</span>
                            )}
                          </div>
                        </div>
                      );
                    })}
                  </div>
                )}

                <div style={{ background:C.bg, padding:'1rem 1.6rem', display:'flex', flexDirection:'column', alignItems:'flex-end', gap:'0.4rem' }}>
                  <div style={{ display:'flex', justifyContent:'space-between', width:320, color:C.textSec }}><span>Total Booking Amount</span><strong>{fmt(guest.totalAmount)}</strong></div>
                  <div style={{ display:'flex', justifyContent:'space-between', width:320, color:C.textSec }}><span>Total Paid</span><strong>{fmt(guest.totalPaid)}</strong></div>
                  {extraTotal > 0 && (
                    <div style={{ display:'flex', justifyContent:'space-between', width:320, color:C.red }}><span>Pending Extra Charges</span><strong>+{fmt(extraTotal)}</strong></div>
                  )}
                  <div style={{ display:'flex', justifyContent:'space-between', width:320, fontSize:'1.05rem', fontWeight:700, color:C.text, borderTop:`2px solid ${C.border}`, paddingTop:'0.6rem' }}>
                    <span>Remaining Balance</span>
                    <span style={{ color: effectiveRemaining > 0.009 ? C.amber : C.green }}>{fmt(effectiveRemaining)}</span>
                  </div>
                </div>

                <div style={{ display:'grid', gridTemplateColumns:'1fr 320px', gap:'1.5rem', padding:'1.4rem 1.6rem', borderTop:`1px solid ${C.borderLight}`, background:C.bg, alignItems:'start' }}>
                  <div>
                    <div style={{ fontSize:'0.72rem', textTransform:'uppercase', letterSpacing:'0.07em', color:C.textMute, fontWeight:600, marginBottom:'0.85rem' }}>Latest Payment</div>
                    <div style={{ color:C.text, fontWeight:600 }}>{guest.paymentMethod}</div>
                    <div style={{ color:C.textMute, textTransform:'capitalize' }}>{guest.paymentStatus}</div>
                    {effectiveRemaining > 0.009 && (
                      <div style={{ marginTop:'0.8rem', display:'flex', alignItems:'flex-start', gap:'0.5rem', background:C.blueBg, border:`1px solid ${C.blueBorder}`, borderRadius:6, padding:'0.6rem 0.85rem', fontSize:'0.82rem', color:C.blue, lineHeight:1.5, maxWidth:560 }}>
                        <AlertCircle size={14} style={{ flexShrink:0, marginTop:2 }} />
                        <span>Remaining balance of <strong>{fmt(effectiveRemaining)}</strong> must be collected before check-out. Use <strong>Record Balance Payment</strong> to record the payment first.</span>
                      </div>
                    )}
                  </div>
                  <div style={{ display:'flex', flexDirection:'column', gap:'0.65rem' }}>
                    <button
                      onClick={() => {
                        setSettleAmount(String(Math.max(0, effectiveRemaining)));
                        setSettleNote(guest.totalPaid === 0 ? 'Full payment collected at check-out' : '');
                        setSettleMethod('cash');
                        setSettleGcashReference('');
                        setSettleGcashSender('');
                        setSettleGcashPaidAt(toDatetimeLocal());
                        setSettleMerchantConfirmed(false);
                        settleRequestKey.current = createRequestKey();
                        setSettleModal(true);
                      }}
                      disabled={settleLoading || effectiveRemaining <= 0.009}
                      style={{ display:'flex', alignItems:'center', justifyContent:'center', gap:6, background:effectiveRemaining <= 0.009 ? '#d1fae5' : '#2563eb', color:effectiveRemaining <= 0.009 ? '#065f46' : '#fff', border:'none', borderRadius:10, padding:'0.78rem 1.4rem', fontSize:'0.9rem', fontWeight:700, cursor:effectiveRemaining <= 0.009 ? 'default' : 'pointer', width:'100%', fontFamily:'inherit' }}
                    >
                      <CreditCard size={16} /> {effectiveRemaining <= 0.009 ? 'Balance Settled' : 'Record Balance Payment'}
                    </button>
                    {guest.roomCount <= 1 ? (
                      <button
                        onClick={() => setConfirmModal(true)}
                        disabled={actionLoading || effectiveRemaining > 0.009}
                        style={{ display:'flex', alignItems:'center', justifyContent:'center', gap:6, background:(actionLoading || effectiveRemaining > 0.009) ? '#ccc' : C.primary, color:'#fff', border:'none', borderRadius:10, padding:'0.78rem 1.4rem', fontSize:'0.93rem', fontWeight:700, cursor:(actionLoading || effectiveRemaining > 0.009) ? 'not-allowed' : 'pointer', width:'100%', fontFamily:'inherit' }}
                      >
                        {actionLoading
                          ? <><Loader2 size={16} style={{ animation:'spin 1s linear infinite' }} /> Processing...</>
                          : <>Finalize Check-Out <ArrowRight size={16} /></>
                        }
                      </button>
                    ) : (
                      <div style={{ width:'100%', border:`1px solid ${C.border}`, borderRadius:10, padding:'0.72rem 0.85rem', fontSize:'0.82rem', color:C.textSec, background:'#fff' }}>
                        {guest.activeRooms === 0
                          ? 'Booking is fully checked out.'
                          : 'Use the per-room actions above to check out or extend each room.'}
                      </div>
                    )}
                  </div>
                </div>
              </div>

              {/* ── Extra Charges Card ── */}
              <div style={{ background:C.surface, borderRadius:14, border:`1px solid ${C.border}`, overflow:'hidden' }}>
                {/* Header */}
                <div style={{ display:'flex', alignItems:'center', justifyContent:'space-between', padding:'1rem 1.5rem', borderBottom:`1px solid ${C.borderLight}`, background:C.bg }}>
                  <div style={{ display:'flex', alignItems:'center', gap:'0.6rem' }}>
                    <span style={{ fontWeight:700, color:C.text, fontSize:'0.95rem' }}>Extra Charges</span>
                    {extraTotal > 0 && (
                      <span style={{ background:'#fef3c7', color:'#92400e', fontSize:'0.75rem', fontWeight:700, padding:'2px 10px', borderRadius:20 }}>
                        +{fmt(extraTotal)} pending
                      </span>
                    )}
                  </div>
                  <button
                    onClick={addExtraCharge}
                    style={{ display:'flex', alignItems:'center', gap:5, background:'#1a4bcc', color:'#fff', border:'none', borderRadius:8, padding:'0.45rem 0.9rem', fontWeight:700, cursor:'pointer', fontSize:'0.83rem', fontFamily:'inherit' }}
                  >
                    <Plus size={14} /> Add Charge
                  </button>
                </div>

                {/* Charge rows */}
                <div style={{ padding: extraCharges.length === 0 ? '1.5rem' : '1rem 1.5rem', display:'flex', flexDirection:'column', gap:'0.75rem' }}>
                  {extraCharges.length === 0 ? (
                    <div style={{ textAlign:'center', color:C.textMute, fontSize:'0.88rem', padding:'0.5rem 0' }}>
                      No extra charges added. Click <strong>Add Charge</strong> to record broken items, fees, or any additional charges.
                    </div>
                  ) : (
                    extraCharges.map(charge => (
                      <div key={charge.id} style={{ display:'grid', gridTemplateColumns:'1fr 180px 120px 36px', gap:'0.6rem', alignItems:'center', background:C.bg, border:`1px solid ${C.borderLight}`, borderRadius:8, padding:'0.75rem 1rem' }}>
                        {/* Description */}
                        <div style={{ display:'flex', flexDirection:'column', gap:'0.3rem' }}>
                          <label style={{ fontSize:'0.68rem', fontWeight:700, color:C.textMute, textTransform:'uppercase', letterSpacing:'0.5px' }}>Description</label>
                          <input
                            type="text"
                            value={charge.description}
                            onChange={e => updateCharge(charge.id, 'description', e.target.value)}
                            placeholder="e.g. Broken TV remote"
                            style={{ padding:'0.45rem 0.65rem', border:`1px solid ${C.border}`, borderRadius:6, fontSize:'0.88rem', fontFamily:'inherit', background:'#fff', width:'100%' }}
                          />
                        </div>

                        {/* Category */}
                        <div style={{ display:'flex', flexDirection:'column', gap:'0.3rem' }}>
                          <label style={{ fontSize:'0.68rem', fontWeight:700, color:C.textMute, textTransform:'uppercase', letterSpacing:'0.5px' }}>Category</label>
                          <select
                            value={charge.category}
                            onChange={e => updateCharge(charge.id, 'category', e.target.value)}
                            style={{ padding:'0.45rem 0.65rem', border:`1px solid ${C.border}`, borderRadius:6, fontSize:'0.88rem', fontFamily:'inherit', background:'#fff' }}
                          >
                            {CHARGE_PRESETS.map(p => <option key={p} value={p}>{p}</option>)}
                          </select>
                        </div>

                        {/* Amount */}
                        <div style={{ display:'flex', flexDirection:'column', gap:'0.3rem' }}>
                          <label style={{ fontSize:'0.68rem', fontWeight:700, color:C.textMute, textTransform:'uppercase', letterSpacing:'0.5px' }}>Amount (₱)</label>
                          <input
                            type="number"
                            min="0"
                            step="0.01"
                            value={charge.amount}
                            onChange={e => updateCharge(charge.id, 'amount', e.target.value)}
                            placeholder="0.00"
                            style={{ padding:'0.45rem 0.65rem', border:`1px solid ${C.border}`, borderRadius:6, fontSize:'0.88rem', fontFamily:'inherit', background:'#fff', width:'100%' }}
                          />
                        </div>

                        {/* Remove */}
                        <button
                          onClick={() => removeCharge(charge.id)}
                          style={{ background:'none', border:`1px solid ${C.redBorder}`, borderRadius:6, padding:'0.45rem', cursor:'pointer', color:C.red, display:'flex', alignItems:'center', justifyContent:'center', alignSelf:'flex-end', marginBottom:'1px' }}
                          title="Remove charge"
                        >
                          <Trash2 size={14} />
                        </button>
                      </div>
                    ))
                  )}

                  {/* Save charges button */}
                  {extraCharges.length > 0 && (
                    <div style={{ display:'flex', justifyContent:'flex-end', paddingTop:'0.25rem' }}>
                      <button
                        onClick={saveExtraCharges}
                        disabled={chargeLoading}
                        style={{ display:'flex', alignItems:'center', gap:6, background:C.red, color:'#fff', border:'none', borderRadius:8, padding:'0.6rem 1.25rem', fontWeight:700, cursor:chargeLoading ? 'not-allowed' : 'pointer', fontSize:'0.88rem', fontFamily:'inherit' }}
                      >
                        {chargeLoading
                          ? <><Loader2 size={14} style={{ animation:'spin 1s linear infinite' }} /> Saving...</>
                          : <>Save & Add to Balance</>
                        }
                      </button>
                    </div>
                  )}
                </div>
              </div>

            </div>
          )}
        </>
      )}

      {/* ── Settle Balance Modal ── */}
      {settleModal && guest && (
        <div onClick={() => setSettleModal(false)} style={{ position:'fixed', inset:0, background:'rgba(0,0,0,0.4)', backdropFilter:'blur(4px)', zIndex:9999, display:'flex', alignItems:'center', justifyContent:'center', padding:'1rem' }}>
          <div onClick={e => e.stopPropagation()} style={{ background:'var(--color-surface)', borderRadius:14, border:`1px solid ${C.border}`, boxShadow:'0 20px 60px rgba(0,0,0,0.2)', width:'100%', maxWidth:520, maxHeight:'90vh', overflowY:'auto', padding:'1.6rem', fontFamily:"'Inter', sans-serif", color:'var(--color-text-primary)' }}>
            <h3 style={{ margin:'0 0 0.75rem', fontFamily:"'Inter', sans-serif", fontSize:'1.0625rem', fontWeight:700, color:'var(--color-text-primary)' }}>Record Balance Payment</h3>
            <p style={{ margin:'0 0 1rem', fontFamily:"'Inter', sans-serif", color:'var(--color-text-secondary)', fontSize:'0.875rem', fontWeight:400 }}>Remaining balance: <strong style={{ color:'var(--color-text-primary)' }}>{fmt(effectiveRemaining)}</strong></p>
            <div style={{ display:'grid', gap:'0.7rem' }}>
              <input type="number" min="0.01" step="0.01" value={settleAmount} onChange={e => setSettleAmount(e.target.value)} placeholder="Amount" style={{ padding:'0.6rem 0.75rem', border:`1px solid ${C.border}`, borderRadius:8, fontFamily:'inherit' }} />
              <select value={settleMethod} onChange={e => setSettleMethod(e.target.value)} style={{ padding:'0.6rem 0.75rem', border:`1px solid ${C.border}`, borderRadius:8, fontFamily:'inherit' }}>
                <option value="cash">Cash</option>
                <option value="gcash">GCash</option>
              </select>
              {settleMethod === 'gcash' && (
                <div style={{ display:'grid', gap:'0.7rem', padding:'0.85rem', border:`1px solid ${C.blueBorder}`, borderRadius:10, background:C.blueBg }}>
                  <strong style={{ fontSize:'0.82rem', color:C.blue }}>Official merchant GCash verification</strong>
                  <input value={settleGcashReference} onChange={e => setSettleGcashReference(e.target.value)} placeholder="GCash transaction/reference number" maxLength={80} style={{ padding:'0.6rem 0.75rem', border:`1px solid ${C.border}`, borderRadius:8, fontFamily:'inherit' }} />
                  <input value={settleGcashSender} onChange={e => setSettleGcashSender(e.target.value)} placeholder="GCash sender name" maxLength={120} style={{ padding:'0.6rem 0.75rem', border:`1px solid ${C.border}`, borderRadius:8, fontFamily:'inherit' }} />
                  <label style={{ display:'grid', gap:'0.35rem', fontSize:'0.78rem', color:C.textSec }}>
                    Date and time paid
                    <input type="datetime-local" value={settleGcashPaidAt} max={toDatetimeLocal()} onChange={e => setSettleGcashPaidAt(e.target.value)} style={{ padding:'0.6rem 0.75rem', border:`1px solid ${C.border}`, borderRadius:8, fontFamily:'inherit' }} />
                  </label>
                  <label style={{ display:'flex', alignItems:'flex-start', gap:'0.55rem', fontSize:'0.78rem', lineHeight:1.45, color:C.textSec }}>
                    <input type="checkbox" checked={settleMerchantConfirmed} onChange={e => setSettleMerchantConfirmed(e.target.checked)} style={{ marginTop:2 }} />
                    I matched the reference, sender, amount, and paid time against the official hotel GCash merchant record.
                  </label>
                </div>
              )}
              <textarea rows={3} value={settleNote} onChange={e => setSettleNote(e.target.value)} placeholder="Note (required)" style={{ padding:'0.6rem 0.75rem', border:`1px solid ${C.border}`, borderRadius:8, fontFamily:'inherit', resize:'vertical' }} />
            </div>
            <div style={{ display:'flex', gap:'0.75rem', justifyContent:'flex-end', marginTop:'1.2rem' }}>
              <button onClick={() => setSettleModal(false)} disabled={settleLoading} style={{ minWidth:108, padding:'0.625rem 1.25rem', borderRadius:8, border:`1px solid ${C.border}`, background:'var(--color-surface)', color:'var(--color-text-secondary)', cursor:'pointer', fontFamily:"'Inter', sans-serif", fontSize:'0.875rem', fontWeight:500 }}>Cancel</button>
              <button onClick={handleSettleBalance} disabled={settleLoading} style={{ padding:'0.6rem 1.2rem', borderRadius:8, border:'none', background:'#2e7d32', color:'#fff', fontWeight:500, cursor:settleLoading ? 'not-allowed' : 'pointer', fontFamily:"'Inter', sans-serif", fontSize:'0.875rem', display:'flex', alignItems:'center', gap:6 }}>
                {settleLoading ? <><Loader2 size={14} style={{ animation:'spin 1s linear infinite' }} /> Saving...</> : 'Save Payment'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ── Single Guest Confirm Check-Out Modal ── */}
      {confirmModal && guest && (
        <div onClick={() => setConfirmModal(false)} style={{ position:'fixed', inset:0, background:'rgba(0,0,0,0.4)', backdropFilter:'blur(4px)', zIndex:9999, display:'flex', alignItems:'center', justifyContent:'center', padding:'1rem' }}>
          <div onClick={e => e.stopPropagation()} style={{ background:'var(--color-surface)', borderRadius:14, border:`1px solid ${C.border}`, boxShadow:'0 20px 60px rgba(0,0,0,0.2)', width:'100%', maxWidth:420, padding:'2rem', fontFamily:"'Inter', sans-serif", color:'var(--color-text-primary)' }}>
            <h3 style={{ margin:'0 0 0.75rem', fontSize:'1.0625rem', fontFamily:"'Inter', sans-serif", fontWeight:700, color:'var(--color-text-primary)' }}>Confirm Check-Out</h3>
            <p style={{ margin:'0 0 0.4rem', fontFamily:"'Inter', sans-serif", color:'var(--color-text-secondary)', fontSize:'0.875rem', fontWeight:400 }}>You are about to check out <strong style={{ color:'var(--color-text-primary)' }}>{guest.name}</strong> from <strong style={{ color:'var(--color-text-primary)' }}>{guest.room}</strong>.</p>
            <p style={{ margin:'0 0 1.5rem', fontFamily:"'Inter', sans-serif", color:'var(--color-text-secondary)', fontSize:'0.875rem', fontWeight:400 }}>Remaining balance: <strong style={{ color:'var(--color-text-primary)' }}>{fmt(effectiveRemaining)}</strong></p>
            <div style={{ display:'flex', gap:'0.75rem', justifyContent:'flex-end' }}>
              <button onClick={() => setConfirmModal(false)} disabled={actionLoading} style={{ minWidth:108, padding:'0.625rem 1.25rem', borderRadius:8, border:`1px solid ${C.border}`, background:'var(--color-surface)', color:'var(--color-text-secondary)', cursor:'pointer', fontFamily:"'Inter', sans-serif", fontSize:'0.875rem', fontWeight:500 }}>Cancel</button>
              <button onClick={handleCheckOut} disabled={actionLoading || effectiveRemaining > 0.009} style={{ padding:'0.6rem 1.2rem', borderRadius:8, border:'none', background:'#2e7d32', color:'#fff', fontWeight:500, cursor:actionLoading ? 'not-allowed' : 'pointer', fontFamily:"'Inter', sans-serif", fontSize:'0.875rem', display:'flex', alignItems:'center', gap:6 }}>
                {actionLoading ? <><Loader2 size={14} style={{ animation:'spin 1s linear infinite' }} /> Processing...</> : <>Confirm <ArrowRight size={14} /></>}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ── Bulk Confirm Modal ── */}
      {roomCheckoutModal && guest && (
        <div onClick={() => !roomActionLoading && setRoomCheckoutModal(null)} style={{ position:'fixed', inset:0, background:'rgba(0,0,0,0.4)', backdropFilter:'blur(4px)', zIndex:9999, display:'flex', alignItems:'center', justifyContent:'center', padding:'1rem' }}>
          <div onClick={e => e.stopPropagation()} style={{ background:'var(--color-surface)', borderRadius:14, border:`1px solid ${C.border}`, boxShadow:'0 20px 60px rgba(0,0,0,0.2)', width:'100%', maxWidth:480, padding:'1.8rem', fontFamily:"'Inter', sans-serif", color:'var(--color-text-primary)' }}>
            <h3 style={{ margin:'0 0 0.75rem', fontSize:'1.0625rem', fontFamily:"'Inter', sans-serif", fontWeight:700 }}>Check Out Room?</h3>
            <p style={{ margin:'0 0 0.35rem', color:'var(--color-text-secondary)', fontSize:'0.9rem' }}>
              Room: <strong style={{ color:'var(--color-text-primary)' }}>{roomCheckoutModal.roomType} - {roomCheckoutModal.roomNumber ? `Room ${roomCheckoutModal.roomNumber}` : 'Room TBA'}</strong>
            </p>
            <p style={{ margin:'0 0 0.35rem', color:'var(--color-text-secondary)', fontSize:'0.9rem' }}>
              Booking: <strong style={{ color:'var(--color-text-primary)' }}>{guest.referenceNumber}</strong>
            </p>
            <p style={{ margin:'0 0 0.75rem', color:'var(--color-text-secondary)', fontSize:'0.9rem' }}>
              Guest: <strong style={{ color:'var(--color-text-primary)' }}>{guest.name}</strong>
            </p>
            <p style={{ margin:'0 0 1.2rem', color:'#166534', fontSize:'0.85rem' }}>This will release the room for cleaning.</p>
            <div style={{ display:'flex', gap:'0.75rem', justifyContent:'flex-end' }}>
              <button onClick={() => setRoomCheckoutModal(null)} disabled={roomActionLoading} style={{ minWidth:108, padding:'0.625rem 1.25rem', borderRadius:8, border:`1px solid ${C.border}`, background:'var(--color-surface)', color:'var(--color-text-secondary)', cursor:'pointer', fontFamily:"'Inter', sans-serif", fontSize:'0.875rem', fontWeight:500 }}>Cancel</button>
              <button onClick={handleRoomCheckout} disabled={roomActionLoading} style={{ padding:'0.6rem 1.2rem', borderRadius:8, border:'none', background:'#166534', color:'#fff', fontWeight:500, cursor:roomActionLoading ? 'not-allowed' : 'pointer', fontFamily:"'Inter', sans-serif", fontSize:'0.875rem', display:'flex', alignItems:'center', gap:6 }}>
                {roomActionLoading ? <><Loader2 size={14} style={{ animation:'spin 1s linear infinite' }} /> Processing...</> : 'Confirm Check-Out'}
              </button>
            </div>
          </div>
        </div>
      )}

      {roomExtendModal && guest && (
        <div onClick={() => !roomActionLoading && setRoomExtendModal(null)} style={{ position:'fixed', inset:0, background:'rgba(0,0,0,0.4)', backdropFilter:'blur(4px)', zIndex:9999, display:'flex', alignItems:'center', justifyContent:'center', padding:'1rem' }}>
          <div onClick={e => e.stopPropagation()} style={{ background:'var(--color-surface)', borderRadius:14, border:`1px solid ${C.border}`, boxShadow:'0 20px 60px rgba(0,0,0,0.2)', width:'100%', maxWidth:500, padding:'1.8rem', fontFamily:"'Inter', sans-serif", color:'var(--color-text-primary)' }}>
            <h3 style={{ margin:'0 0 0.75rem', fontSize:'1.0625rem', fontFamily:"'Inter', sans-serif", fontWeight:700 }}>Extend Room Stay</h3>
            <p style={{ margin:'0 0 0.35rem', color:'var(--color-text-secondary)', fontSize:'0.9rem' }}>
              Room: <strong style={{ color:'var(--color-text-primary)' }}>{roomExtendModal.roomType} - {roomExtendModal.roomNumber ? `Room ${roomExtendModal.roomNumber}` : 'Room TBA'}</strong>
            </p>
            <p style={{ margin:'0 0 0.75rem', color:'var(--color-text-secondary)', fontSize:'0.9rem' }}>
              Current Check-Out: <strong style={{ color:'var(--color-text-primary)' }}>{formatDate(roomExtendModal.checkOut)}</strong>
            </p>

            <div style={{ display:'grid', gap:'0.75rem' }}>
              <label style={{ fontSize:'0.82rem', color:C.textMute, fontWeight:700, letterSpacing:'0.03em', textTransform:'uppercase' }}>New Check-Out Date</label>
              <input
                type="date"
                value={extendDate}
                min={roomExtendModal?.checkOut ? toDateInputValue(new Date(new Date(roomExtendModal.checkOut).getTime() + 86400000)) : undefined}
                onChange={(e) => setExtendDate(e.target.value)}
                style={{ padding:'0.6rem 0.75rem', border:`1px solid ${C.border}`, borderRadius:8, fontFamily:'inherit' }}
              />
              <label style={{ fontSize:'0.82rem', color:C.textMute, fontWeight:700, letterSpacing:'0.03em', textTransform:'uppercase' }}>Extension Reason</label>
              <textarea
                rows={3}
                value={extendReason}
                onChange={(e) => setExtendReason(e.target.value)}
                placeholder="Why does this guest need to extend the stay?"
                required
                style={{ padding:'0.6rem 0.75rem', border:`1px solid ${C.border}`, borderRadius:8, fontFamily:'inherit', resize:'vertical' }}
              />
            </div>

            <div style={{ marginTop:'0.9rem', padding:'0.7rem 0.85rem', borderRadius:8, border:`1px solid ${C.borderLight}`, background:C.bg }}>
              <div style={{ display:'flex', justifyContent:'space-between', fontSize:'0.88rem', color:C.textSec }}>
                <span>Extension</span>
                <strong>{extensionPreview.additionalNights} night(s)</strong>
              </div>
              <div style={{ display:'flex', justifyContent:'space-between', fontSize:'0.92rem', color:C.text, marginTop:'0.35rem' }}>
                <span>Additional Charge</span>
                <strong>{fmt(extensionPreview.additionalCharge)}</strong>
              </div>
            </div>

            <div style={{ display:'flex', gap:'0.75rem', justifyContent:'flex-end', marginTop:'1.2rem' }}>
              <button onClick={() => setRoomExtendModal(null)} disabled={roomActionLoading} style={{ minWidth:108, padding:'0.625rem 1.25rem', borderRadius:8, border:`1px solid ${C.border}`, background:'var(--color-surface)', color:'var(--color-text-secondary)', cursor:'pointer', fontFamily:"'Inter', sans-serif", fontSize:'0.875rem', fontWeight:500 }}>Cancel</button>
              <button onClick={handleExtendRoom} disabled={roomActionLoading || !extendDate || extensionPreview.additionalNights <= 0 || extendReason.trim().length < 3} style={{ padding:'0.6rem 1.2rem', borderRadius:8, border:'none', background:'#1d4ed8', color:'#fff', fontWeight:500, cursor:roomActionLoading ? 'not-allowed' : 'pointer', fontFamily:"'Inter', sans-serif", fontSize:'0.875rem', display:'flex', alignItems:'center', gap:6 }}>
                {roomActionLoading ? <><Loader2 size={14} style={{ animation:'spin 1s linear infinite' }} /> Processing...</> : 'Confirm Extension'}
              </button>
            </div>
          </div>
        </div>
      )}

      {bulkConfirmModal && (
        <div onClick={() => !bulkLoading && setBulkConfirmModal(false)} style={{ position:'fixed', inset:0, background:'rgba(0,0,0,0.4)', backdropFilter:'blur(4px)', zIndex:9999, display:'flex', alignItems:'center', justifyContent:'center', padding:'1rem' }}>
          <div onClick={e => e.stopPropagation()} style={{ background:'var(--color-surface)', borderRadius:14, border:`1px solid ${C.border}`, boxShadow:'0 20px 60px rgba(0,0,0,0.2)', width:'100%', maxWidth:460, padding:'2rem', fontFamily:"'Inter', sans-serif", color:'var(--color-text-primary)' }}>
            <h3 style={{ margin:'0 0 0.5rem', fontFamily:"'Inter', sans-serif", fontSize:'1.0625rem', fontWeight:700, color:'var(--color-text-primary)' }}>Bulk Check-Out Confirmation</h3>
            <p style={{ margin:'0 0 1rem', fontFamily:"'Inter', sans-serif", color:'var(--color-text-secondary)', fontSize:'0.875rem', fontWeight:400 }}>
              You are about to check out <strong style={{ color:'var(--color-text-primary)' }}>{selectedSettled.length} guest(s)</strong> who have settled their balances.
            </p>
            <div style={{ background:C.bg, borderRadius:8, border:`1px solid ${C.borderLight}`, maxHeight:220, overflowY:'auto', marginBottom:'1.25rem' }}>
              {selectedSettled.map(g => (
                <div key={g.id} style={{ display:'flex', justifyContent:'space-between', alignItems:'center', padding:'0.6rem 1rem', borderBottom:`1px solid ${C.borderLight}`, fontSize:'0.88rem' }}>
                  <div><span style={{ fontWeight:600, color:C.text }}>{g.name}</span><span style={{ color:C.textMute, marginLeft:8 }}>{g.room}</span></div>
                  <span style={{ background:'#dcfce7', color:'#166534', fontSize:'0.72rem', fontWeight:700, padding:'2px 8px', borderRadius:20 }}>Settled</span>
                </div>
              ))}
            </div>
            <div style={{ display:'flex', gap:'0.75rem', justifyContent:'flex-end' }}>
              <button onClick={() => setBulkConfirmModal(false)} disabled={bulkLoading} style={{ minWidth:108, padding:'0.625rem 1.25rem', borderRadius:8, border:`1px solid ${C.border}`, background:'var(--color-surface)', color:'var(--color-text-secondary)', cursor:'pointer', fontFamily:"'Inter', sans-serif", fontSize:'0.875rem', fontWeight:500 }}>Cancel</button>
              <button onClick={handleBulkCheckOut} disabled={bulkLoading} style={{ padding:'0.6rem 1.4rem', borderRadius:8, border:'none', background:'#2e7d32', color:'#fff', fontWeight:500, cursor:bulkLoading ? 'not-allowed' : 'pointer', fontFamily:"'Inter', sans-serif", fontSize:'0.875rem', display:'flex', alignItems:'center', gap:6 }}>
                {bulkLoading
                  ? <><Loader2 size={14} style={{ animation:'spin 1s linear infinite' }} /> Processing {selectedSettled.length}...</>
                  : <><ArrowRight size={14} /> Confirm Check-Out ({selectedSettled.length})</>
                }
              </button>
            </div>
          </div>
        </div>
      )}

      <style>{`@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }`}</style>
    </div>
  );
}

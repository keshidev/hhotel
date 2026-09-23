import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { ChevronLeft, User, MapPin, FileText, AlertCircle, Loader } from 'lucide-react';
import Button from '../components/Button';
import BookingProgress from '../components/BookingProgress';
import clientBookingService from '../services/client/clientBookingService';
import { showToast } from '../utils/showToast';
import { calculateBookingEstimate } from '../utils/bookingPricing';
import { formatCurrency } from '../utils/currency';
import { formatPhilippineMobile, isPhilippineMobileInput, normalizePhilippineMobileInput } from '../utils/philippineMobile';
import { useCms } from '../context/CmsContext';
import { rememberGuestBookingSession } from '../utils/guestBookingSession';
import { completeBookingCart, getBookingCart, pauseBookingCartExpiration, persistBookingCart, restoreBookingCartToSession, suppressNextRecoveryPrompt } from '../utils/bookingCart';
import './GuestDetails.css';

const RECAPTCHA_SITE_KEY = import.meta.env.VITE_RECAPTCHA_SITE_KEY || '';

const createRequestKey = () => globalThis.crypto?.randomUUID?.()
  ?? 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (character) => {
    const random = Math.floor(Math.random() * 16);
    return (character === 'x' ? random : ((random & 0x3) | 0x8)).toString(16);
  });

const loadRecaptchaScript = () =>
  new Promise((resolve, reject) => {
    if (window.grecaptcha?.execute) {
      resolve(window.grecaptcha);
      return;
    }

    const existing = document.getElementById('recaptcha-v3-script');
    if (existing) {
      existing.addEventListener('load', () => resolve(window.grecaptcha));
      existing.addEventListener('error', reject);
      return;
    }

    const script = document.createElement('script');
    script.id = 'recaptcha-v3-script';
    script.src = `https://www.google.com/recaptcha/api.js?render=${encodeURIComponent(RECAPTCHA_SITE_KEY)}`;
    script.async = true;
    script.defer = true;
    script.onload = () => resolve(window.grecaptcha);
    script.onerror = () => reject(new Error('Failed to load reCAPTCHA script.'));
    document.head.appendChild(script);
  });

const fetchCaptchaToken = async () => {
  if (!RECAPTCHA_SITE_KEY) {
    throw new Error('reCAPTCHA site key is missing in frontend environment.');
  }

  const grecaptcha = await loadRecaptchaScript();
  return new Promise((resolve, reject) => {
    grecaptcha.ready(async () => {
      try {
        const token = await grecaptcha.execute(RECAPTCHA_SITE_KEY, { action: 'booking_submit' });
        if (!token) {
          reject(new Error('Failed to generate CAPTCHA token.'));
          return;
        }
        resolve(token);
      } catch (error) {
        reject(error);
      }
    });
  });
};

const formatDateForAPI = (dateString) => {
  const date = new Date(dateString);
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
};

const validateEmail = (email = '') => {
  const trimmedEmail = email.trim();
  if (!trimmedEmail) return false;
  const emailRegex = /^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/;
  if (!emailRegex.test(trimmedEmail)) return false;
  if (trimmedEmail.includes('..')) return false;
  const [localPart, domainPart] = trimmedEmail.split('@');
  if (!localPart || !domainPart) return false;
  if (localPart.startsWith('.') || localPart.endsWith('.')) return false;
  if (domainPart.startsWith('.') || domainPart.endsWith('.')) return false;
  if (domainPart.startsWith('-') || domainPart.endsWith('-')) return false;
  return true;
};

const DEFAULT_PRIVACY_TERMS =
  'We collect guest information such as your name, email, contact number, and booking details to process reservations, payments, and support requests. We use this data only for reservation management, guest communication, compliance, and service improvements. Your information is protected with appropriate administrative and technical safeguards, and we do not sell personal data. If you have questions or concerns about your data, please contact our support team through the hotel contact details provided on this website.';

const DEFAULT_BOOKING_CONDITIONS =
  'Reservations are confirmed only after the required GCash payment is completed and verified. Published rates, inclusions, and availability are subject to validation at the time of booking. Cancellation, rebooking, and no-show handling follow the active hotel policy shown during booking and in confirmation communications. Check-in and check-out schedules must be observed, and guests are required to present a valid government-issued ID upon arrival.';

const resolvePolicyContent = (value, fallback) => {
  const normalized = String(value ?? '').trim();
  return normalized || fallback;
};

const toPolicyParagraphs = (content) =>
  String(content || '')
    .split(/\r?\n\r?\n+/)
    .map((entry) => entry.trim())
    .filter(Boolean);

const GuestDetails = () => {
  const navigate = useNavigate();
  const { get, taxRate, downpaymentRate } = useCms();
  const [selectedRooms, setSelectedRooms] = useState([]);
  const [formData, setFormData] = useState({
    prefix: '', firstName: '', lastName: '',
    mobilePhone: '', email: '',
    country: '', address1: '', address2: '',
    city: '', zipCode: '', specialRequests: ''
  });

  const [errors, setErrors] = useState({});
  const [touched, setTouched] = useState({});
  const [agreedToPrivacy, setAgreedToPrivacy] = useState(false);
  const [agreedToBooking, setAgreedToBooking] = useState(false);
  const [showSpecialRequests, setShowSpecialRequests] = useState(false);
  const [activePolicyModal, setActivePolicyModal] = useState(null);

  // ── Booking submission state ────────────────────────────────────────────
  const [bookingStep, setBookingStep] = useState('form');
  const [childrenAges, setChildrenAges] = useState([]);
  const submissionInFlightRef = React.useRef(false);
  const idempotencyRef = React.useRef({ fingerprint: null, key: null });

  useEffect(() => {
    window.scrollTo(0, 0);
    const frame = window.requestAnimationFrame(() => window.scrollTo(0, 0));

    return () => window.cancelAnimationFrame(frame);
  }, []);
  // ────────────────────────────────────────────────────────────────────────

  useEffect(() => {
    restoreBookingCartToSession();
    const stored = sessionStorage.getItem('selectedRooms');
    if (stored) {
      const rooms = JSON.parse(stored);
      setSelectedRooms(rooms.map(room => ({
        ...room,
        price: room.price ? parseFloat(room.price) : 0,
        name: room.name || 'Room',
        description: room.description || 'Comfortable room'
      })));
    } else {
      navigate('/select-room');
    }
  }, [navigate]);

  useEffect(() => {
    const bookingRaw = sessionStorage.getItem('bookingData');
    if (!bookingRaw) {
      setChildrenAges([]);
      return;
    }

    const bookingData = JSON.parse(bookingRaw || '{}');
    const childrenCount = Math.max(0, Number(bookingData.children || 0));
    const normalizedAges = Array.isArray(bookingData.childrenAges)
      ? bookingData.childrenAges.map((age) => Math.max(1, Math.min(17, Number(age) || 1)))
      : [];

    const nextAges = normalizedAges.slice(0, childrenCount);
    while (nextAges.length < childrenCount) {
      nextAges.push(7);
    }

    setChildrenAges(nextAges);

    if (JSON.stringify(bookingData.childrenAges || []) !== JSON.stringify(nextAges)) {
      const updatedBookingData = {
        ...bookingData,
        childrenAges: nextAges,
      };
      sessionStorage.setItem('bookingData', JSON.stringify(updatedBookingData));
      persistBookingCart({ bookingData: updatedBookingData });
    }
  }, []);

  const validateField = (field, value) => {
    let error = '';
    switch (field) {
      case 'prefix':     if (!value) error = 'Please select a prefix'; break;
      case 'firstName':
        if (!value.trim())                    error = 'First name is required';
        else if (value.trim().length < 2)     error = 'First name must be at least 2 characters';
        else if (value.trim().length > 100)   error = 'First name must not exceed 100 characters';
        else if (!/^[\p{L}\s.'\u2019-]+$/u.test(value)) error = 'First name contains unsupported characters';
        break;
      case 'lastName':
        if (!value.trim())                    error = 'Last name is required';
        else if (value.trim().length < 2)     error = 'Last name must be at least 2 characters';
        else if (value.trim().length > 100)   error = 'Last name must not exceed 100 characters';
        else if (!/^[\p{L}\s.'\u2019-]+$/u.test(value)) error = 'Last name contains unsupported characters';
        break;
      case 'email':
        if (!value.trim())             error = 'Email is required';
        else if (!validateEmail(value)) error = 'Please enter a valid email address';
        break;
      case 'mobilePhone':
        if (!value.trim())                    error = 'Mobile phone is required';
        else if (!isPhilippineMobileInput(value)) error = 'Enter a Philippine mobile number: 10 digits starting with 9 after +63.';
        break;
      case 'country': if (!value) error = 'Please select a country'; break;
      case 'address1':
        if (!value.trim()) error = 'Address 1 is required';
        else if (value.trim().length < 5) error = 'Please enter a complete street address';
        break;
      case 'city':
        if (!value.trim()) error = 'City is required';
        else if (value.trim().length < 2) error = 'Please enter a valid city';
        break;
      case 'zipCode':
        if (!value.trim()) error = 'Zip / Postal Code is required';
        else if (!/^[A-Za-z0-9][A-Za-z0-9\s-]{2,19}$/.test(value.trim())) error = 'Please enter a valid Zip / Postal Code';
        break;
      default: break;
    }
    return error;
  };

  const handleInputChange = (field, value) => {
    let normalizedValue = value;
    if (field === 'mobilePhone') {
      normalizedValue = normalizePhilippineMobileInput(value);
    }
    if (field === 'zipCode') {
      normalizedValue = value.toUpperCase().slice(0, 20);
    }
    setFormData(prev => ({ ...prev, [field]: normalizedValue }));
    if (touched[field]) {
      setErrors(prev => ({ ...prev, [field]: validateField(field, normalizedValue) }));
    }
  };

  const handleBlur = (field) => {
    setTouched(prev => ({ ...prev, [field]: true }));
    setErrors(prev => ({ ...prev, [field]: validateField(field, formData[field]) }));
  };

  const calculateNights = () => {
    const bd = JSON.parse(sessionStorage.getItem('bookingData') || '{}');
    if (!bd.checkIn || !bd.checkOut) return 0;
    const diffTime = Math.abs(new Date(bd.checkOut) - new Date(bd.checkIn));
    return Math.ceil(diffTime / (1000 * 60 * 60 * 24));
  };

  const getRoomPrice = (room) => {
    const price = parseFloat(room?.price);
    return isNaN(price) ? 0 : price;
  };

  const getAddonQuantity = (addon) => Math.max(1, parseInt(addon?.quantity ?? 1, 10) || 1);
  const getAddonLineTotal = (addon) => {
    const explicitLineTotal = parseFloat(addon?.line_total);
    if (Number.isFinite(explicitLineTotal)) {
      return explicitLineTotal;
    }
    return (parseFloat(addon?.price || 0) || 0) * getAddonQuantity(addon);
  };

  const nights = calculateNights();
  const roomsSubtotal = selectedRooms.reduce((sum, room) => sum + getRoomPrice(room) * nights, 0);
  const roomAddons  = (() => { try { return JSON.parse(sessionStorage.getItem('roomAddons') || '{}'); } catch { return {}; } })();
  const addonsTotal = Object.values(roomAddons).reduce((sum, addons) =>
    sum + (Array.isArray(addons) ? addons.reduce((s, addon) => s + getAddonLineTotal(addon), 0) : 0), 0
  );
  const promoResult  = (() => { try { return JSON.parse(sessionStorage.getItem('promoResult') || 'null'); } catch { return null; } })();
  const promoCode    = sessionStorage.getItem('promoCode') || '';
  const discount     = promoResult?.valid ? (promoResult.discount_amount || 0) : 0;
  const pricing = calculateBookingEstimate({
    roomsSubtotal,
    addonsTotal,
    discount,
    taxRate,
  });
  const total = pricing.total;
  const downpaymentAmount = Math.round(total * downpaymentRate * 100) / 100;
  const remainingBalance = Math.round(Math.max(0, total - downpaymentAmount) * 100) / 100;
  const downpaymentPercentage = new Intl.NumberFormat('en-US', {
    maximumFractionDigits: 2,
  }).format(downpaymentRate * 100);

  const bookingData = JSON.parse(sessionStorage.getItem('bookingData') || '{}');
  const adultsCount = Math.max(1, Number(bookingData.adults || 1));
  const childrenCount = Math.max(0, Number(bookingData.children || 0));
  const eligibleFreeChildren = childrenAges.filter((age) => age >= 1 && age <= 7).length;
  const freeChildrenCount = Math.min(2, eligibleFreeChildren);
  const chargedChildrenCount = Math.max(0, childrenCount - freeChildrenCount);

  const handleChildAgeChange = (index, value) => {
    const nextValue = Math.max(1, Math.min(17, Number(value) || 1));
    const nextAges = [...childrenAges];
    nextAges[index] = nextValue;
    setChildrenAges(nextAges);

    const latestBooking = JSON.parse(sessionStorage.getItem('bookingData') || '{}');
    const updatedBookingData = {
      ...latestBooking,
      childrenAges: nextAges,
    };
    sessionStorage.setItem('bookingData', JSON.stringify(updatedBookingData));
    persistBookingCart({ bookingData: updatedBookingData, selectedRooms, roomAddons });
  };

  const checkInDate = bookingData.checkIn
    ? new Date(bookingData.checkIn).toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })
    : '';
  const checkOutDate = bookingData.checkOut
    ? new Date(bookingData.checkOut).toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })
    : '';
  const privacyTermsContent = resolvePolicyContent(
    get('policy_privacy_terms', ''),
    DEFAULT_PRIVACY_TERMS
  );
  const bookingConditionsContent = resolvePolicyContent(
    get('policy_booking_conditions', ''),
    DEFAULT_BOOKING_CONDITIONS
  );
  const fullPolicyContent = [
    `Check-in: ${resolvePolicyContent(get('policy_checkin', ''), 'Check-in is after 3:00 PM.')}`,
    `Check-out: ${resolvePolicyContent(get('policy_checkout', ''), 'Check-out is before 12:00 PM.')}`,
    resolvePolicyContent(get('policy_downpayment', ''), `${downpaymentPercentage}% downpayment is required to confirm a booking. The remaining balance is payable at check-in.`),
    resolvePolicyContent(get('policy_cancellation', ''), 'Cancellations requested within 24 hours of check-in are non-refundable.'),
    resolvePolicyContent(get('policy_rebooking', ''), 'Rebooking is allowed once, subject to room availability and the active hotel rebooking conditions.'),
    resolvePolicyContent(get('policy_requests', ''), 'Special requests are subject to availability and are not guaranteed.'),
    resolvePolicyContent(get('policy_id', ''), 'Guests must present a valid government-issued ID at check-in.'),
    bookingConditionsContent,
  ].join('\n\n');
  const policyContentByType = {
    privacy: privacyTermsContent,
    booking: bookingConditionsContent,
    full: fullPolicyContent,
  };
  const policyTitleByType = {
    privacy: 'Privacy Terms',
    booking: 'Booking Conditions',
    full: 'Hotel Booking Policy',
  };
  const activePolicyContent = policyContentByType[activePolicyModal] || '';
  const activePolicyTitle = policyTitleByType[activePolicyModal] || 'Hotel Policy';
  const activePolicyParagraphs = toPolicyParagraphs(activePolicyContent);

  const handlePolicyLinkClick = (event, type) => {
    event.preventDefault();
    event.stopPropagation();
    setActivePolicyModal(type);
  };

  // ── Main submit handler ─────────────────────────────────────────────────
  const handleContinueToPayment = async () => {
    if (submissionInFlightRef.current) {
      return;
    }

    const requiredFields = ['prefix', 'firstName', 'lastName', 'mobilePhone', 'email', 'country', 'address1', 'city', 'zipCode'];
    const newErrors = {};
    let hasErrors = false;
    requiredFields.forEach(field => {
      const error = validateField(field, formData[field]);
      if (error) { newErrors[field] = error; hasErrors = true; }
    });
    setTouched(Object.fromEntries(requiredFields.map(f => [f, true])));
    setErrors(newErrors);

    if (hasErrors) {
      document.querySelector('.form-input.error, .form-select.error')
        ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      showToast('Please fix the errors in the form before continuing.', 'warning');
      return;
    }

    if (!agreedToPrivacy || !agreedToBooking) {
      showToast('Please agree to the Privacy Terms and Booking Conditions', 'warning');
      return;
    }

    submissionInFlightRef.current = true;
    setBookingStep('creating-booking');

    let captchaToken = null;
    try {
      captchaToken = await fetchCaptchaToken();
    } catch (error) {
      submissionInFlightRef.current = false;
      setBookingStep('form');
      showToast(error.message || 'CAPTCHA initialization failed. Please refresh and try again.', 'error');
      return;
    }

    if (!getBookingCart()) {
      submissionInFlightRef.current = false;
      showToast('Your saved booking progress has expired. Please start a new booking.', 'warning');
      navigate('/', { replace: true });
      return;
    }

    const bd = JSON.parse(sessionStorage.getItem('bookingData') || '{}');
    const payloadAdultsCount = Math.max(1, Number(bd.adults || 1));
    const payloadChildrenCount = Math.max(0, Number(bd.children || 0));
    const payloadChildrenAges = Array.isArray(childrenAges)
      ? childrenAges.map((age) => Math.max(1, Math.min(17, Number(age) || 1))).slice(0, payloadChildrenCount)
      : [];

    if (payloadChildrenCount > 0 && payloadChildrenAges.length !== payloadChildrenCount) {
      submissionInFlightRef.current = false;
      setBookingStep('form');
      showToast('Please enter the age of each child before continuing to payment.', 'warning');
      return;
    }

    const totalGuestsCount = payloadAdultsCount + payloadChildrenCount;
    const roomAddonsPayload = selectedRooms.reduce((acc, room, roomIndex) => {
      const roomSelectionKey = String(room?.roomId ?? room?.id ?? roomIndex);
      const addonsForRoom = Array.isArray(roomAddons?.[roomSelectionKey]) ? roomAddons[roomSelectionKey] : [];

      if (addonsForRoom.length === 0) {
        return acc;
      }

      acc[roomSelectionKey] = addonsForRoom
        .filter((addon) => addon && typeof addon === 'object')
        .map((addon) => ({
          id: addon.id || addon.addon_id,
          quantity: Math.max(1, Number.parseInt(addon.quantity ?? 1, 10) || 1),
          room_index: roomIndex,
        }));

      return acc;
    }, {});

    const normalizedGuestDetails = {
      ...formData,
      prefix: formData.prefix.trim(),
      firstName: formData.firstName.trim().replace(/\s+/gu, ' '),
      lastName: formData.lastName.trim().replace(/\s+/gu, ' '),
    };
    const bookingPayload = {
      guest_name:       `${normalizedGuestDetails.prefix} ${normalizedGuestDetails.firstName} ${normalizedGuestDetails.lastName}`,
      guest_email:      formData.email,
      guest_phone:      formatPhilippineMobile(formData.mobilePhone),
      guest_country:    formData.country,
      guest_address_line_1: formData.address1.trim(),
      guest_address_line_2: formData.address2.trim() || null,
      guest_city:       formData.city.trim(),
      guest_postal_code: formData.zipCode.trim(),
      room_ids:         selectedRooms
        .map((room) => room.sourceRoomId || room.id || room.roomId)
        .filter((id) => Number.isFinite(Number(id)))
        .map((id) => Number(id)),
      room_types: selectedRooms
        .map((room) => room.requested_room_type || room.room_type || null)
        .filter(Boolean),
      room_addons:      roomAddonsPayload,
      check_in:         formatDateForAPI(bd.checkIn),
      check_out:        formatDateForAPI(bd.checkOut),
      number_of_guests: totalGuestsCount,
      adults_count:     payloadAdultsCount,
      children_count:   payloadChildrenCount,
      children_ages:    payloadChildrenAges,
      special_requests: formData.specialRequests || null,
      payment_method:   'gcash',
      captcha_token:    captchaToken,
      promo_code:       promoCode || null,
    };

    const requestFingerprint = JSON.stringify({
      ...bookingPayload,
      captcha_token: null,
    });
    if (idempotencyRef.current.fingerprint !== requestFingerprint) {
      idempotencyRef.current = {
        fingerprint: requestFingerprint,
        key: createRequestKey(),
      };
    }
    const idempotencyKey = idempotencyRef.current.key;

    const resumeExpiration = pauseBookingCartExpiration();
    try {
      const bookingResponse = await clientBookingService.createBooking(bookingPayload, { idempotencyKey });

      if (!bookingResponse.success) {
        setBookingStep('form');
        showToast('Booking failed: ' + (bookingResponse.message || 'Unknown error'), 'error');
        return;
      }

      const newBookingId          = bookingResponse.data.booking.id;
      const newReference          = bookingResponse.data.booking.reference_number;
      const normalizedEmail = formData.email.trim().toLowerCase();

      sessionStorage.setItem('bookingReference', newReference);
      sessionStorage.setItem('guestDetails',     JSON.stringify(normalizedGuestDetails));
      sessionStorage.setItem('bookingId',         String(newBookingId));
      sessionStorage.setItem('bookingEmail',      normalizedEmail);
      rememberGuestBookingSession({
        email: normalizedEmail,
        referenceNumber: newReference,
        bookingId: newBookingId,
      });

      completeBookingCart();

      setBookingStep('creating-payment');
      navigate(bookingResponse.data.next_url || `/payment/${newBookingId}`, { replace: true });

    } catch (error) {
      console.error('Booking error:', error);
      setBookingStep('form');

      if (error.response?.data?.errors) {
        const errs = Object.values(error.response.data.errors).flat();
        showToast(errs[0] || 'Validation error', 'error');
      } else if (error.response?.data?.message) {
        showToast('Error: ' + error.response.data.message, 'error');
      } else {
        showToast('Failed to create booking. Please try again.', 'error');
      }
    } finally {
      submissionInFlightRef.current = false;
      resumeExpiration();
    }
  };

  // ── Loading screens ─────────────────────────────────────────────────────
  if (bookingStep === 'creating-booking') {
    return (
      <div className="checkout-page">
        <div className="payment-loading-screen">
          <Loader size={48} className="spin" />
          <h2>Preparing your payment...</h2>
          <p>Your booking will be confirmed after hotel staff verify your payment.</p>
        </div>
      </div>
    );
  }

  if (bookingStep === 'creating-payment') {
    return (
      <div className="checkout-page">
        <div className="payment-loading-screen">
          <Loader size={48} className="spin" />
          <h2>Preparing your GCash payment...</h2>
          <p>Please wait while we set up your payment.</p>
        </div>
      </div>
    );
  }

  // ── Normal form ─────────────────────────────────────────────────────────
  return (
    <div className="checkout-page">
      {activePolicyModal && (
        <div className="policy-modal-overlay" onClick={() => setActivePolicyModal(null)}>
          <div className="policy-modal" onClick={(e) => e.stopPropagation()}>
            <div className="policy-modal-header">
              <h3>{activePolicyTitle}</h3>
              <button
                type="button"
                className="policy-modal-close"
                onClick={() => setActivePolicyModal(null)}
              >
                Close
              </button>
            </div>
            <div className="policy-modal-body">
              {activePolicyParagraphs.length > 0 ? (
                activePolicyParagraphs.map((paragraph, index) => (
                  <p key={`${activePolicyModal}-paragraph-${index}`}>{paragraph}</p>
                ))
              ) : (
                <p>No policy content available at the moment.</p>
              )}
            </div>
          </div>
        </div>
      )}

      <BookingProgress currentStep={4} />

      <section className="page-header-checkout">
        <div className="container-checkout">
          <button type="button" className="back-btn" onClick={() => navigate(-1)} aria-label="Return to the previous booking step">
            <ChevronLeft size={20} /> Checkout
          </button>
        </div>
      </section>

      <section className="checkout-content">
        <div className="checkout-layout">
          <div className="checkout-form">

            {/* Contact Info */}
            <div className="form-card">
              <div className="form-card-header">
                <User size={20} />
                <h2>Contact Info</h2>
                <span className="required-note">* Required</span>
              </div>
              <div className="form-card-body">
                <div className="form-row form-row-name">
                  <div className="form-group">
                    <label className="form-label">Prefix *</label>
                    <select
                      className={`form-select ${errors.prefix && touched.prefix ? 'error' : ''}`}
                      value={formData.prefix}
                      onChange={(e) => handleInputChange('prefix', e.target.value)}
                      onBlur={() => handleBlur('prefix')}
                      aria-label="Prefix"
                    >
                      <option value="">Select</option>
                      <option value="Mr">Mr</option>
                      <option value="Ms">Ms</option>
                      <option value="Mrs">Mrs</option>
                      <option value="Dr">Dr</option>
                    </select>
                    {errors.prefix && touched.prefix && (
                      <span className="error-message"><AlertCircle size={14} /> {errors.prefix}</span>
                    )}
                  </div>
                  <div className="form-group">
                    <label className="form-label">First Name *</label>
                    <input
                      type="text"
                      className={`form-input ${errors.firstName && touched.firstName ? 'error' : ''}`}
                      value={formData.firstName}
                      onChange={(e) => handleInputChange('firstName', e.target.value)}
                      onBlur={() => handleBlur('firstName')}
                      placeholder="First Name"
                      aria-label="First Name"
                      maxLength={100}
                    />
                    {errors.firstName && touched.firstName && (
                      <span className="error-message"><AlertCircle size={14} /> {errors.firstName}</span>
                    )}
                  </div>
                  <div className="form-group">
                    <label className="form-label">Last Name *</label>
                    <input
                      type="text"
                      className={`form-input ${errors.lastName && touched.lastName ? 'error' : ''}`}
                      value={formData.lastName}
                      onChange={(e) => handleInputChange('lastName', e.target.value)}
                      onBlur={() => handleBlur('lastName')}
                      placeholder="Last Name"
                      aria-label="Last Name"
                      maxLength={100}
                    />
                    {errors.lastName && touched.lastName && (
                      <span className="error-message"><AlertCircle size={14} /> {errors.lastName}</span>
                    )}
                  </div>
                </div>

                <div className="form-group">
                  <label className="form-label" htmlFor="guest-mobile-phone">Philippine mobile number *</label>
                  <div className="guest-ph-phone">
                    <span className="guest-ph-phone-prefix" aria-hidden="true">+63</span>
                    <input
                      id="guest-mobile-phone"
                      type="tel"
                      inputMode="numeric"
                      autoComplete="tel-national"
                      maxLength={24}
                      pattern="9[0-9]{9}"
                      required
                      aria-invalid={Boolean(errors.mobilePhone && touched.mobilePhone)}
                      aria-describedby={`guest-mobile-phone-help${errors.mobilePhone && touched.mobilePhone ? ' guest-mobile-phone-error' : ''}`}
                      className={`form-input ${errors.mobilePhone && touched.mobilePhone ? 'error' : ''}`}
                      value={formData.mobilePhone}
                      onChange={(event) => handleInputChange('mobilePhone', event.target.value)}
                      onBlur={() => handleBlur('mobilePhone')}
                      placeholder="9171234567"
                    />
                  </div>
                  <p className="form-helper" id="guest-mobile-phone-help">Philippines (+63) only. Enter 10 digits starting with 9, or paste your 09 number.</p>
                  {errors.mobilePhone && touched.mobilePhone && (
                    <span className="error-message" id="guest-mobile-phone-error" role="alert"><AlertCircle size={14} /> {errors.mobilePhone}</span>
                  )}
                </div>

                <div className="form-group">
                  <label className="form-label">Email Address *</label>
                  <input
                    type="email"
                    className={`form-input ${errors.email && touched.email ? 'error' : ''}`}
                    value={formData.email}
                    onChange={(e) => handleInputChange('email', e.target.value)}
                    onBlur={() => handleBlur('email')}
                    placeholder="your.email@example.com"
                    maxLength={255}
                  />
                  {errors.email && touched.email && (
                    <span className="error-message"><AlertCircle size={14} /> {errors.email}</span>
                  )}
                  <p className="form-helper">We will use this email for booking updates and contact details.</p>
                </div>
              </div>
            </div>

            {/* Address */}
            <div className="form-card">
              <div className="form-card-header">
                <MapPin size={20} />
                <h2>ADDRESS</h2>
              </div>
              <div className="form-card-body">
                <div className="form-group">
                  <label className="form-label">Country *</label>
                  <select
                    className={`form-select ${errors.country && touched.country ? 'error' : ''}`}
                    value={formData.country}
                    onChange={(e) => handleInputChange('country', e.target.value)}
                    onBlur={() => handleBlur('country')}
                    autoComplete="country"
                  >
                    <option value="">Select Country</option>
                    <option value="PH">Philippines</option>
                    <option value="US">United States</option>
                    <option value="UK">United Kingdom</option>
                    <option value="JP">Japan</option>
                    <option value="CN">China</option>
                    <option value="KR">South Korea</option>
                  </select>
                  {errors.country && touched.country && (
                    <span className="error-message"><AlertCircle size={14} /> {errors.country}</span>
                  )}
                </div>
                <div className="form-row">
                  <div className="form-group">
                    <label className="form-label">Address 1 *</label>
                    <input
                      type="text"
                      autoComplete="address-line1"
                      maxLength={255}
                      className={`form-input ${errors.address1 && touched.address1 ? 'error' : ''}`}
                      value={formData.address1}
                      onChange={(e) => handleInputChange('address1', e.target.value)}
                      onBlur={() => handleBlur('address1')}
                      placeholder="House number and street"
                    />
                    {errors.address1 && touched.address1 && (
                      <span className="error-message"><AlertCircle size={14} /> {errors.address1}</span>
                    )}
                  </div>
                  <div className="form-group">
                    <label className="form-label">Address 2 <span className="optional-label">Optional</span></label>
                    <input
                      type="text"
                      autoComplete="address-line2"
                      maxLength={255}
                      className="form-input"
                      value={formData.address2}
                      onChange={(e) => handleInputChange('address2', e.target.value)}
                      placeholder="Apartment, suite, or unit"
                    />
                  </div>
                </div>
                <div className="form-row">
                  <div className="form-group">
                    <label className="form-label">City *</label>
                    <input
                      type="text"
                      autoComplete="address-level2"
                      maxLength={120}
                      className={`form-input ${errors.city && touched.city ? 'error' : ''}`}
                      value={formData.city}
                      onChange={(e) => handleInputChange('city', e.target.value)}
                      onBlur={() => handleBlur('city')}
                    />
                    {errors.city && touched.city && (
                      <span className="error-message"><AlertCircle size={14} /> {errors.city}</span>
                    )}
                  </div>
                  <div className="form-group form-group-sm">
                    <label className="form-label">Zip / Postal Code *</label>
                    <input
                      type="text"
                      autoComplete="postal-code"
                      maxLength={20}
                      className={`form-input ${errors.zipCode && touched.zipCode ? 'error' : ''}`}
                      value={formData.zipCode}
                      onChange={(e) => handleInputChange('zipCode', e.target.value)}
                      onBlur={() => handleBlur('zipCode')}
                    />
                    {errors.zipCode && touched.zipCode && (
                      <span className="error-message"><AlertCircle size={14} /> {errors.zipCode}</span>
                    )}
                  </div>
                </div>
              </div>
            </div>

            {/* Reservation Details */}
            <div className="form-card">
              <div className="form-card-header">
                <FileText size={20} />
                <h2>Reservation Details</h2>
              </div>
              <div className="form-card-body">
                {childrenCount > 0 && (
                  <div className="children-policy-card">
                    <p className="children-policy-note">
                      Children aged 1–7 stay free (maximum 2). Children aged 8 and above are counted as adults for room occupancy purposes.
                    </p>
                    <div className="children-age-grid">
                      {Array.from({ length: childrenCount }).map((_, index) => (
                        <div key={`guest-child-age-${index}`} className="children-age-field">
                          <label className="form-label">Child {index + 1} Age</label>
                          <input
                            type="number"
                            min={1}
                            max={17}
                            required
                            className="form-input"
                            value={childrenAges[index] ?? 7}
                            onChange={(e) => handleChildAgeChange(index, e.target.value)}
                          />
                        </div>
                      ))}
                    </div>
                    <p className="children-policy-breakdown">
                      Free children: {freeChildrenCount} | Charged as adults: {chargedChildrenCount}
                    </p>
                  </div>
                )}

                <div className="expandable-section">
                  <button className="expandable-header" onClick={() => setShowSpecialRequests(!showSpecialRequests)}>
                    <span>Special Requests</span>
                    <ChevronLeft size={18} className={`chevron ${showSpecialRequests ? 'expanded' : ''}`} />
                  </button>
                  {showSpecialRequests && (
                    <div className="expandable-body">
                      <textarea
                        className="form-textarea"
                        rows="4"
                        maxLength={1000}
                        aria-label="Special Requests"
                        aria-describedby="special-requests-count"
                        placeholder="Any special requests or preferences..."
                        value={formData.specialRequests}
                        onChange={(e) => handleInputChange('specialRequests', e.target.value)}
                      />
                      <p id="special-requests-count" className="form-helper">
                        {formData.specialRequests.length} / 1000 characters
                      </p>
                    </div>
                  )}
                </div>
              </div>
            </div>

            {/* Policies */}
            <div className="form-card">
              <div className="form-card-header"><h2>Policies:</h2></div>
              <div className="form-card-body">
                <div className="policy-box">
                  <div className="policy-row">
                    <div className="policy-label">Check-in</div>
                    <div className="policy-value">after 3:00 pm</div>
                  </div>
                  <div className="policy-row">
                    <div className="policy-label">Check-out</div>
                    <div className="policy-value">before 12:00 pm</div>
                  </div>
                </div>
                {selectedRooms.map((room, index) => (
                  <div key={room.roomId} className="room-policy">
                    <h4>ROOM {index + 1} {(room.name || 'ROOM').toUpperCase()}</h4>
                    <div className="policy-text">
                      <strong>Guarantee Policy</strong>
                      <p>{downpaymentPercentage}% downpayment is required to confirm booking. Remaining balance is payable at check-in.</p>
                    </div>
                    <div className="policy-text">
                      <strong>Cancel Policy</strong>
                      <p>If cancellation is requested within 24 hours of check-in time, it is non-refundable. Rebooking is only allowed once and must be requested within 24 hours of your original booking, subject to room availability. {formatCurrency(getRoomPrice(room) * nights)}</p>
                    </div>
                  </div>
                ))}
                <button
                  type="button"
                  className="view-policy-link"
                  onClick={(event) => handlePolicyLinkClick(event, 'full')}
                >
                  View Full Policy
                </button>
              </div>
            </div>

            {/* Acknowledgement */}
            <div className="form-card">
              <div className="form-card-header"><h2>Acknowledgement</h2></div>
              <div className="form-card-body">
                <div className="checkbox-group">
                  <input
                    type="checkbox"
                    id="privacy"
                    required
                    checked={agreedToPrivacy}
                    onChange={(e) => setAgreedToPrivacy(e.target.checked)}
                  />
                  <label htmlFor="privacy">
                    * I agree with the{' '}
                    <button
                      type="button"
                      className="policy-link"
                      onClick={(event) => handlePolicyLinkClick(event, 'privacy')}
                    >
                      Privacy Terms.
                    </button>
                    <br />
                    <button
                      type="button"
                      className="link-text link-text-btn"
                      onClick={(event) => handlePolicyLinkClick(event, 'privacy')}
                    >
                      Click here
                    </button>
                  </label>
                </div>
                <div className="checkbox-group">
                  <input
                    type="checkbox"
                    id="booking"
                    required
                    checked={agreedToBooking}
                    onChange={(e) => setAgreedToBooking(e.target.checked)}
                  />
                  <label htmlFor="booking">
                    * I agree with the{' '}
                    <button
                      type="button"
                      className="policy-link"
                      onClick={(event) => handlePolicyLinkClick(event, 'booking')}
                    >
                      Booking Conditions.
                    </button>
                    <br />
                    <button
                      type="button"
                      className="link-text link-text-btn"
                      onClick={(event) => handlePolicyLinkClick(event, 'booking')}
                    >
                      Click here
                    </button>
                  </label>
                </div>
              </div>
            </div>

            {/* Mobile submit */}
            <div className="mobile-submit">
              <Button variant="primary" size="lg" fullWidth onClick={handleContinueToPayment}>
                CONTINUE TO PAYMENT
              </Button>
            </div>
          </div>

          {/* Sidebar */}
          <aside className="price-sidebar">
            <div className="price-card">
              <h2 className="cart-title price-card-title">
                <span className="price-title-desktop">
                  Your Cart: {selectedRooms.length} Item{selectedRooms.length > 1 ? 's' : ''}
                </span>
                <span className="price-title-mobile">Price Details</span>
              </h2>
              <div className="cart-items">
                {selectedRooms.map((room, index) => {
                  const lastRoomId = selectedRooms[selectedRooms.length - 1]?.roomId;
                  const roomAddonList = roomAddons[room.roomId] || [];
                  return (
                    <div key={room.roomId} className={`cart-item ${room.roomId === lastRoomId ? 'highlight' : ''}`}>
                      <div className="item-label">ROOM {index + 1}</div>
                      <h4 className="item-name">{room.name || 'Room'}</h4>
                      <p className="item-description">{room.description || 'Comfortable room'}</p>
                      <p className="item-price">{formatCurrency(getRoomPrice(room))}</p>
                      <p className="item-duration">{nights} Night stay</p>

                      {roomAddonList.length > 0 && (
                        <div className="room-addons-list">
                          <div className="addons-label">Add-ons:</div>
                          {roomAddonList.map(addon => (
                            <div key={addon.id} className="addon-item">
                              <div className="addon-item-info">
                                <span className="addon-item-name">{addon.name}</span>
                                <span className="addon-item-meta">
                                  Qty {getAddonQuantity(addon)} x {formatCurrency(parseFloat(addon?.price || 0) || 0)}
                                </span>
                                <span className="addon-item-price">{formatCurrency(getAddonLineTotal(addon))}</span>
                              </div>
                            </div>
                          ))}
                        </div>
                      )}
                    </div>
                  );
                })}
              </div>
              <div className="cart-summary">
                <div className="summary-row total">
                  <span>Total</span>
                  <span>{formatCurrency(total)}</span>
                </div>
                <div className="summary-row">
                  <span>Required Down Payment (DP)</span>
                  <span>{formatCurrency(downpaymentAmount)}</span>
                </div>
                <div className="summary-row">
                  <span>Balance at Check-in</span>
                  <span>{formatCurrency(remainingBalance)}</span>
                </div>
              </div>
              <div className="booking-info">
                <div className="booking-dates">{checkInDate} - {checkOutDate}</div>
                <div className="booking-guests">
                  {adultsCount} Adult{adultsCount > 1 ? 's' : ''}
                  {childrenCount > 0 ? `, ${childrenCount} Child${childrenCount > 1 ? 'ren' : ''}` : ''}
                </div>
              </div>
              <div className="desktop-submit">
                <Button variant="primary" fullWidth onClick={handleContinueToPayment}>
                  CONTINUE TO PAYMENT
                </Button>
                <Button variant="outline" fullWidth onClick={() => { suppressNextRecoveryPrompt(); navigate('/select-room'); }}>ADD A ROOM</Button>

              </div>
            </div>
          </aside>
        </div>
      </section>
    </div>
  );
};

export default GuestDetails;

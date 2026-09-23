const SESSION_KEY = 'guest_booking_lookup';

const LEGACY_KEYS = [
  'booking_check_email',
  'booking_check_ref',
  'bookingEmail',
  'bookingReference',
  'bookingId',
];

const normalizeEmail = (value) => String(value || '').trim().toLowerCase();
const normalizeReference = (value) => String(value || '').trim().toUpperCase();

export const readGuestBookingSession = () => {
  try {
    const parsed = JSON.parse(sessionStorage.getItem(SESSION_KEY) || '{}');
    return {
      email: normalizeEmail(parsed.email),
      referenceNumber: normalizeReference(parsed.referenceNumber),
      bookingId: parsed.bookingId ? Number(parsed.bookingId) : null,
    };
  } catch {
    return { email: '', referenceNumber: '', bookingId: null };
  }
};

export const rememberGuestBookingSession = ({ email, referenceNumber, bookingId } = {}) => {
  const current = readGuestBookingSession();
  const next = {
    email: normalizeEmail(email ?? current.email),
    referenceNumber: normalizeReference(referenceNumber ?? current.referenceNumber),
    bookingId: bookingId ? Number(bookingId) : current.bookingId,
  };

  sessionStorage.setItem(SESSION_KEY, JSON.stringify(next));
  LEGACY_KEYS.forEach((key) => localStorage.removeItem(key));

  return next;
};

export const clearGuestBookingSession = () => {
  sessionStorage.removeItem(SESSION_KEY);
  sessionStorage.removeItem('booking_last_success');
  LEGACY_KEYS.forEach((key) => {
    sessionStorage.removeItem(key);
    localStorage.removeItem(key);
  });
};


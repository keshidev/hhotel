export const BOOKING_CART_STORAGE_KEY = 'hhotel_booking_cart_v1';
export const BOOKING_CART_EVENT = 'hhotel:booking-cart-updated';
export const BOOKING_CART_EXPIRED_EVENT = 'hhotel:booking-cart-expired';
export const BOOKING_CART_DURATION_MS = 30 * 60 * 1000;
export const BOOKING_CART_EXPIRATION_KEY = 'bookingCartExpiresAt';
let pendingSubmissions = 0;

const BOOKING_WORKFLOW_PATHS = new Set([
  '/rooms',
  '/booking',
  '/select-room',
  '/add-ons',
  '/guest-details',
  '/cart',
]);

const BOOKING_RECOVERY_PATHS = new Set([
  '/rooms',
  '/booking',
  '/select-room',
]);

const COMPLETED_KEY = 'bookingCartCompleted';
const SKIP_RECOVERY_KEY = 'bookingCartSkipRecoveryOnce';
const RECOVERY_ACKNOWLEDGED_KEY = 'bookingCartRecoveryAcknowledged';

const hasWindow = () => typeof window !== 'undefined';

export const isBookingWorkflowPath = (pathname = '') => (
  BOOKING_WORKFLOW_PATHS.has(pathname) || pathname.startsWith('/room/')
);

export const isBookingRecoveryPath = (pathname = '') => (
  BOOKING_RECOVERY_PATHS.has(pathname) || pathname.startsWith('/room/')
);

const parseJson = (value, fallback) => {
  if (!value) return fallback;
  try {
    return JSON.parse(value);
  } catch {
    return fallback;
  }
};

export const normalizeBookingDate = (value) => {
  if (value === null || value === undefined || value === '') return '';

  if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
    const [year, month, day] = value.split('-').map(Number);
    const candidate = new Date(year, month - 1, day);
    if (
      candidate.getFullYear() === year
      && candidate.getMonth() === month - 1
      && candidate.getDate() === day
    ) return value;
  }

  const numericValue = typeof value === 'string' && /^\d+$/.test(value)
    ? Number(value)
    : value;
  const date = value instanceof Date ? value : new Date(numericValue);
  if (Number.isNaN(date.getTime())) return '';

  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
};

export const normalizeBookingData = (bookingData) => {
  if (!bookingData || typeof bookingData !== 'object') return null;
  return {
    ...bookingData,
    checkIn: normalizeBookingDate(bookingData.checkIn),
    checkOut: normalizeBookingDate(bookingData.checkOut),
  };
};

const normalizeSnapshot = (snapshot) => ({
  ...snapshot,
  bookingData: normalizeBookingData(snapshot.bookingData),
  selectedRooms: Array.isArray(snapshot.selectedRooms) ? snapshot.selectedRooms : [],
  roomAddons: snapshot.roomAddons && typeof snapshot.roomAddons === 'object' ? snapshot.roomAddons : {},
});

const snapshotDeadline = (snapshot) => {
  if (!snapshot || typeof snapshot !== 'object') return NaN;
  // Older saved carts already have updatedAt; never give an old draft a new lifetime.
  return snapshot.expiresAt !== undefined
    ? Date.parse(snapshot.expiresAt)
    : Date.parse(snapshot.updatedAt) + BOOKING_CART_DURATION_MS;
};

const validDeadline = (deadline) => Number.isFinite(deadline)
  && deadline > Date.now() && deadline <= Date.now() + BOOKING_CART_DURATION_MS;

export const expireBookingCart = () => {
  if (!hasWindow() || pendingSubmissions > 0) return false;
  const saved = parseJson(localStorage.getItem(BOOKING_CART_STORAGE_KEY), null);
  const localExpired = localStorage.getItem(BOOKING_CART_STORAGE_KEY) !== null
    && !validDeadline(snapshotDeadline(saved));
  const completed = sessionStorage.getItem(COMPLETED_KEY) === '1';
  const hasDraft = !completed && ['bookingData', 'selectedRooms', 'roomAddons', 'guestDetails', 'promoCode', 'promoResult']
    .some((key) => sessionStorage.getItem(key) !== null);
  const storedDeadline = sessionStorage.getItem(BOOKING_CART_EXPIRATION_KEY);
  const deadline = storedDeadline !== null ? Date.parse(storedDeadline) : snapshotDeadline(saved);
  const sessionExpired = hasDraft && !validDeadline(deadline);
  if (localExpired) localStorage.removeItem(BOOKING_CART_STORAGE_KEY);
  if (sessionExpired) {
    [
      'bookingData', 'selectedRooms', 'roomAddons', 'promoCode', 'promoResult',
      'activeAddonRoomId', 'guestDetails', SKIP_RECOVERY_KEY, RECOVERY_ACKNOWLEDGED_KEY,
    ].forEach((key) => sessionStorage.removeItem(key));
    // Keep an expired marker so a late async callback cannot resurrect the draft.
    sessionStorage.setItem(BOOKING_CART_EXPIRATION_KEY, new Date(0).toISOString());
  } else if (hasDraft && storedDeadline === null) {
    sessionStorage.setItem(BOOKING_CART_EXPIRATION_KEY, new Date(deadline).toISOString());
  }
  if (localExpired || sessionExpired) {
    queueMicrotask(() => {
      announceCartUpdate();
      window.dispatchEvent(new CustomEvent(BOOKING_CART_EXPIRED_EVENT, {
        detail: { sessionExpired, completed },
      }));
    });
  }
  return sessionExpired || (localExpired && !hasDraft && !completed);
};

export const getBookingCartDeadline = () => {
  if (!hasWindow() || pendingSubmissions > 0 || sessionStorage.getItem(COMPLETED_KEY) === '1') return null;
  const stored = sessionStorage.getItem(BOOKING_CART_EXPIRATION_KEY);
  const deadline = stored !== null ? Date.parse(stored) : snapshotDeadline(parseJson(localStorage.getItem(BOOKING_CART_STORAGE_KEY), null));
  return validDeadline(deadline) ? deadline : null;
};

export const pauseBookingCartExpiration = () => {
  pendingSubmissions += 1;
  announceCartUpdate();
  let resumed = false;
  return () => {
    if (resumed) return;
    resumed = true;
    pendingSubmissions -= 1;
    expireBookingCart();
    announceCartUpdate();
  };
};

const readSessionSnapshot = () => {
  expireBookingCart();
  if (!hasWindow() || sessionStorage.getItem(COMPLETED_KEY) === '1') return null;

  const selectedRooms = parseJson(sessionStorage.getItem('selectedRooms'), []);
  if (!Array.isArray(selectedRooms) || selectedRooms.length === 0) return null;

  return normalizeSnapshot({
    version: 1,
    bookingData: parseJson(sessionStorage.getItem('bookingData'), null),
    selectedRooms,
    roomAddons: parseJson(sessionStorage.getItem('roomAddons'), {}),
    promoCode: sessionStorage.getItem('promoCode') || '',
    promoResult: parseJson(sessionStorage.getItem('promoResult'), null),
    expiresAt: sessionStorage.getItem(BOOKING_CART_EXPIRATION_KEY),
  });
};

const readLocalSnapshot = () => {
  expireBookingCart();
  if (!hasWindow() || sessionStorage.getItem(COMPLETED_KEY) === '1') return null;

  const snapshot = parseJson(localStorage.getItem(BOOKING_CART_STORAGE_KEY), null);
  if (!snapshot || !Array.isArray(snapshot.selectedRooms) || snapshot.selectedRooms.length === 0) {
    return null;
  }
  return normalizeSnapshot({ ...snapshot, expiresAt: new Date(snapshotDeadline(snapshot)).toISOString() });
};

const announceCartUpdate = (snapshot = null) => {
  if (!hasWindow()) return;
  window.dispatchEvent(new CustomEvent(BOOKING_CART_EVENT, {
    detail: { count: snapshot?.selectedRooms?.length || 0, snapshot },
  }));
};

const writeSessionSnapshot = (snapshot) => {
  if (!hasWindow() || !snapshot) return;
  sessionStorage.setItem(BOOKING_CART_EXPIRATION_KEY, snapshot.expiresAt);
  if (snapshot.bookingData) sessionStorage.setItem('bookingData', JSON.stringify(snapshot.bookingData));
  sessionStorage.setItem('selectedRooms', JSON.stringify(snapshot.selectedRooms || []));
  sessionStorage.setItem('roomAddons', JSON.stringify(snapshot.roomAddons || {}));

  if (snapshot.promoCode) sessionStorage.setItem('promoCode', snapshot.promoCode);
  else sessionStorage.removeItem('promoCode');

  if (snapshot.promoResult) sessionStorage.setItem('promoResult', JSON.stringify(snapshot.promoResult));
  else sessionStorage.removeItem('promoResult');
};

export const getBookingCart = () => readSessionSnapshot() || readLocalSnapshot();

export const restoreBookingCartToSession = () => {
  const sessionSnapshot = readSessionSnapshot();
  if (sessionSnapshot) {
    writeSessionSnapshot(sessionSnapshot);
    localStorage.setItem(BOOKING_CART_STORAGE_KEY, JSON.stringify(sessionSnapshot));
    return sessionSnapshot;
  }

  const localSnapshot = readLocalSnapshot();
  if (!localSnapshot) return null;

  writeSessionSnapshot(localSnapshot);
  announceCartUpdate(localSnapshot);
  return localSnapshot;
};

export const persistBookingCart = (overrides = {}) => {
  if (!hasWindow()) return null;
  if (expireBookingCart()) return null;
  const storedDeadline = sessionStorage.getItem(BOOKING_CART_EXPIRATION_KEY);
  if (storedDeadline !== null && !validDeadline(Date.parse(storedDeadline))) return null;

  const existing = readSessionSnapshot() || readLocalSnapshot() || {};
  const sessionBookingData = parseJson(sessionStorage.getItem('bookingData'), null);
  const sessionRooms = parseJson(sessionStorage.getItem('selectedRooms'), []);
  const sessionAddons = parseJson(sessionStorage.getItem('roomAddons'), {});
  const has = (key) => Object.prototype.hasOwnProperty.call(overrides, key);

  const snapshot = {
    version: 1,
    bookingData: normalizeBookingData(has('bookingData') ? overrides.bookingData : (sessionBookingData || existing.bookingData || null)),
    selectedRooms: has('selectedRooms') ? overrides.selectedRooms : (Array.isArray(sessionRooms) ? sessionRooms : existing.selectedRooms || []),
    roomAddons: has('roomAddons') ? overrides.roomAddons : (sessionAddons || existing.roomAddons || {}),
    promoCode: has('promoCode') ? overrides.promoCode : (sessionStorage.getItem('promoCode') || existing.promoCode || ''),
    promoResult: has('promoResult') ? overrides.promoResult : (parseJson(sessionStorage.getItem('promoResult'), null) || existing.promoResult || null),
    updatedAt: new Date().toISOString(),
    expiresAt: storedDeadline || existing.expiresAt || new Date(Date.now() + BOOKING_CART_DURATION_MS).toISOString(),
  };

  snapshot.selectedRooms = Array.isArray(snapshot.selectedRooms) ? snapshot.selectedRooms : [];
  snapshot.roomAddons = snapshot.roomAddons && typeof snapshot.roomAddons === 'object' ? snapshot.roomAddons : {};
  sessionStorage.removeItem(COMPLETED_KEY);
  writeSessionSnapshot(snapshot);

  if (snapshot.selectedRooms.length > 0) {
    localStorage.setItem(BOOKING_CART_STORAGE_KEY, JSON.stringify(snapshot));
    announceCartUpdate(snapshot);
    return snapshot;
  }

  localStorage.removeItem(BOOKING_CART_STORAGE_KEY);
  announceCartUpdate(null);
  return null;
};

export const beginNewBooking = (bookingData) => {
  clearBookingCart();
  sessionStorage.setItem('bookingData', JSON.stringify(normalizeBookingData(bookingData)));
  sessionStorage.setItem(BOOKING_CART_EXPIRATION_KEY, new Date(Date.now() + BOOKING_CART_DURATION_MS).toISOString());
  announceCartUpdate();
};

export const clearBookingCart = () => {
  if (!hasWindow()) return;
  [
    'bookingData', 'selectedRooms', 'roomAddons', 'promoCode', 'promoResult',
    'activeAddonRoomId', 'guestDetails', COMPLETED_KEY, SKIP_RECOVERY_KEY,
    RECOVERY_ACKNOWLEDGED_KEY, BOOKING_CART_EXPIRATION_KEY,
  ].forEach((key) => sessionStorage.removeItem(key));
  localStorage.removeItem(BOOKING_CART_STORAGE_KEY);
  announceCartUpdate(null);
};

export const completeBookingCart = () => {
  if (!hasWindow()) return;
  localStorage.removeItem(BOOKING_CART_STORAGE_KEY);
  sessionStorage.removeItem(RECOVERY_ACKNOWLEDGED_KEY);
  sessionStorage.setItem(COMPLETED_KEY, '1');
  sessionStorage.removeItem(BOOKING_CART_EXPIRATION_KEY);
  announceCartUpdate(null);
};

export const acknowledgeBookingRecovery = () => {
  if (hasWindow()) sessionStorage.setItem(RECOVERY_ACKNOWLEDGED_KEY, '1');
};

export const resetBookingRecoveryAcknowledgement = () => {
  if (hasWindow()) sessionStorage.removeItem(RECOVERY_ACKNOWLEDGED_KEY);
};

export const hasAcknowledgedBookingRecovery = () => (
  hasWindow() && sessionStorage.getItem(RECOVERY_ACKNOWLEDGED_KEY) === '1'
);

export const suppressNextRecoveryPrompt = () => {
  if (hasWindow()) sessionStorage.setItem(SKIP_RECOVERY_KEY, '1');
};

export const consumeRecoverySuppression = () => {
  if (!hasWindow() || sessionStorage.getItem(SKIP_RECOVERY_KEY) !== '1') return false;
  sessionStorage.removeItem(SKIP_RECOVERY_KEY);
  return true;
};

export const getRoomImage = (room) => (
  room?.image
  || room?.image_url
  || (Array.isArray(room?.image_urls) ? room.image_urls[0] : '')
  || '/images/Executive%20Suite/executive_2.jpg'
);

export const getBookingNights = (bookingData) => {
  const checkIn = normalizeBookingDate(bookingData?.checkIn);
  const checkOut = normalizeBookingDate(bookingData?.checkOut);
  if (!checkIn || !checkOut) return 0;

  const difference = new Date(`${checkOut}T12:00:00`) - new Date(`${checkIn}T12:00:00`);
  if (!Number.isFinite(difference) || difference <= 0) return 0;
  return Math.max(1, Math.round(difference / 86400000));
};

export const getAddonLineTotal = (addon) => {
  const explicit = Number(addon?.line_total);
  if (Number.isFinite(explicit)) return explicit;
  return (Number(addon?.price) || 0) * Math.max(1, Number(addon?.quantity) || 1);
};

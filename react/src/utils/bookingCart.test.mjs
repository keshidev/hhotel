import assert from 'node:assert/strict';

class StorageMock {
  constructor() {
    this.values = new Map();
  }

  getItem(key) {
    return this.values.has(key) ? this.values.get(key) : null;
  }

  setItem(key, value) {
    this.values.set(key, String(value));
  }

  removeItem(key) {
    this.values.delete(key);
  }
}

globalThis.sessionStorage = new StorageMock();
globalThis.localStorage = new StorageMock();
globalThis.CustomEvent = class CustomEvent {
  constructor(type, options = {}) {
    this.type = type;
    this.detail = options.detail;
  }
};
globalThis.window = { dispatchEvent() {} };

const {
  acknowledgeBookingRecovery,
  beginNewBooking,
  getBookingCart,
  getBookingNights,
  hasAcknowledgedBookingRecovery,
  isBookingRecoveryPath,
  isBookingWorkflowPath,
  persistBookingCart,
  resetBookingRecoveryAcknowledgement,
  restoreBookingCartToSession,
} = await import('./bookingCart.js');

const checkIn = new Date(2026, 8, 20).getTime();
const checkOut = new Date(2026, 8, 23).getTime();

beginNewBooking({ checkIn, checkOut, adults: 2, children: 1 });
persistBookingCart({
  selectedRooms: [{ roomId: 'room-1', name: 'Superior Twin', price: 1000 }],
});

const cart = getBookingCart();
assert.equal(cart.bookingData.checkIn, '2026-09-20');
assert.equal(cart.bookingData.checkOut, '2026-09-23');
assert.equal(getBookingNights(cart.bookingData), 3);
assert.equal(Number(cart.selectedRooms[0].price) * getBookingNights(cart.bookingData), 3000);

sessionStorage.setItem('bookingData', JSON.stringify({ checkIn, checkOut, adults: 2, children: 1 }));
restoreBookingCartToSession();
const migratedBooking = JSON.parse(sessionStorage.getItem('bookingData'));
assert.equal(migratedBooking.checkIn, '2026-09-20');
assert.equal(migratedBooking.checkOut, '2026-09-23');

assert.equal(getBookingNights({ checkIn: 'invalid', checkOut: 'invalid' }), 0);

for (const path of ['/rooms', '/booking', '/select-room', '/room/superior-twin']) {
  assert.equal(isBookingWorkflowPath(path), true);
  assert.equal(isBookingRecoveryPath(path), true);
}
for (const path of ['/add-ons', '/guest-details', '/cart']) {
  assert.equal(isBookingWorkflowPath(path), true);
  assert.equal(isBookingRecoveryPath(path), false);
}
for (const path of ['/', '/faq', '/privacy', '/terms', '/cookies', '/policies']) {
  assert.equal(isBookingWorkflowPath(path), false);
  assert.equal(isBookingRecoveryPath(path), false);
}

assert.equal(hasAcknowledgedBookingRecovery(), false);
acknowledgeBookingRecovery();
assert.equal(hasAcknowledgedBookingRecovery(), true);
resetBookingRecoveryAcknowledgement();
assert.equal(hasAcknowledgedBookingRecovery(), false);

console.log('bookingCart recovery and date normalization tests passed');

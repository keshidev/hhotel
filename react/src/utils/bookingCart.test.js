import test, { beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import {
  BOOKING_CART_DURATION_MS, BOOKING_CART_EXPIRATION_KEY, BOOKING_CART_STORAGE_KEY,
  beginNewBooking, completeBookingCart, expireBookingCart, getBookingCart,
  getBookingCartDeadline, pauseBookingCartExpiration, persistBookingCart, restoreBookingCartToSession,
} from './bookingCart.js';

class MemoryStorage {
  data = new Map();
  getItem(key) { return this.data.get(key) ?? null; }
  setItem(key, value) { this.data.set(key, String(value)); }
  removeItem(key) { this.data.delete(key); }
}
const originalNow = Date.now;
let now;
const booking = { checkIn: '2026-10-01', checkOut: '2026-10-02', adults: 2 };
const rooms = [{ roomId: 101, name: 'Superior Twin', price: 1000 }];
const save = () => {
  beginNewBooking(booking);
  return persistBookingCart({ selectedRooms: rooms, roomAddons: { 101: [{ price: 100 }] }, promoCode: 'TEST' });
};
beforeEach(() => {
  now = originalNow();
  Date.now = () => now;
  globalThis.window = new EventTarget();
  globalThis.sessionStorage = new MemoryStorage();
  globalThis.localStorage = new MemoryStorage();
});
afterEach(async () => {
  await Promise.resolve();
  Date.now = originalNow;
});

test('saved progress has a fixed 30-minute deadline; reads, restore, and edits do not extend it', () => {
  const saved = save();
  const deadline = now + BOOKING_CART_DURATION_MS;
  assert.equal(Date.parse(saved.expiresAt), deadline);
  now += 10 * 60 * 1000;
  assert.equal(Date.parse(getBookingCart().expiresAt), deadline);
  restoreBookingCartToSession();
  persistBookingCart({ promoCode: 'NEW' });
  assert.equal(getBookingCartDeadline(), deadline);
  assert.equal(Date.parse(JSON.parse(localStorage.getItem(BOOKING_CART_STORAGE_KEY)).expiresAt), deadline);
});

test('expires at the exact deadline and cannot be resurrected by stale callbacks', () => {
  save();
  sessionStorage.setItem('guestDetails', '{"firstName":"Guest"}');
  sessionStorage.setItem('bookingCartRecoveryAcknowledged', '1');
  now += BOOKING_CART_DURATION_MS - 1;
  assert.ok(getBookingCart());
  now++;
  assert.equal(expireBookingCart(), true);
  for (const key of ['bookingData', 'selectedRooms', 'roomAddons', 'promoCode', 'guestDetails', 'bookingCartRecoveryAcknowledged']) {
    assert.equal(sessionStorage.getItem(key), null, key);
  }
  assert.equal(localStorage.getItem(BOOKING_CART_STORAGE_KEY), null);
  assert.equal(getBookingCart(), null);
  assert.equal(persistBookingCart({ bookingData: booking, selectedRooms: rooms }), null);
  beginNewBooking(booking);
  assert.ok(persistBookingCart({ selectedRooms: rooms }));
});

test('reopening a tab restores an unexpired draft with its original deadline', () => {
  const saved = save();
  globalThis.sessionStorage = new MemoryStorage();
  now += 29 * 60 * 1000;
  assert.equal(restoreBookingCartToSession().expiresAt, saved.expiresAt);
  now += 60 * 1000;
  assert.equal(restoreBookingCartToSession(), null);
});

test('old local and untimed session drafts expire rather than receiving a fresh timestamp', () => {
  localStorage.setItem(BOOKING_CART_STORAGE_KEY, JSON.stringify({ bookingData: booking, selectedRooms: rooms, updatedAt: new Date(now - BOOKING_CART_DURATION_MS).toISOString() }));
  sessionStorage.setItem('selectedRooms', JSON.stringify(rooms));
  sessionStorage.setItem('bookingData', JSON.stringify(booking));
  assert.equal(restoreBookingCartToSession(), null);
  assert.equal(sessionStorage.getItem('selectedRooms'), null);
  assert.equal(localStorage.getItem(BOOKING_CART_STORAGE_KEY), null);
});

test('recent legacy drafts keep only the remaining portion of their 30-minute window', () => {
  localStorage.setItem(BOOKING_CART_STORAGE_KEY, JSON.stringify({ bookingData: booking, selectedRooms: rooms, updatedAt: new Date(now - 20 * 60 * 1000).toISOString() }));
  assert.equal(Date.parse(restoreBookingCartToSession().expiresAt), now + 10 * 60 * 1000);
});

test('malformed timestamps and unversioned session data cannot persist indefinitely', () => {
  localStorage.setItem(BOOKING_CART_STORAGE_KEY, JSON.stringify({ selectedRooms: rooms, expiresAt: 'not-a-date' }));
  sessionStorage.setItem('bookingData', JSON.stringify(booking));
  assert.equal(getBookingCart(), null);
  assert.equal(sessionStorage.getItem('bookingData'), null);
});

test('confirmed booking and payment access data are preserved', () => {
  save();
  for (const key of ['bookingId', 'bookingReference', 'bookingEmail', 'paymentAccessToken', 'guest_booking_session', 'admin_token']) sessionStorage.setItem(key, `keep-${key}`);
  completeBookingCart();
  now += 2 * BOOKING_CART_DURATION_MS;
  assert.equal(expireBookingCart(), false);
  assert.equal(getBookingCart(), null);
  assert.equal(getBookingCartDeadline(), null);
  assert.notEqual(sessionStorage.getItem('bookingData'), null);
  for (const key of ['bookingId', 'bookingReference', 'bookingEmail', 'paymentAccessToken', 'guest_booking_session', 'admin_token']) assert.equal(sessionStorage.getItem(key), `keep-${key}`);
});

test('draft cleanup does not delete access to an earlier reservation', () => {
  save();
  sessionStorage.setItem('bookingId', 'confirmed-123');
  sessionStorage.setItem('paymentAccessToken', 'keep-token');
  now += BOOKING_CART_DURATION_MS;
  expireBookingCart();
  assert.equal(sessionStorage.getItem('bookingId'), 'confirmed-123');
  assert.equal(sessionStorage.getItem('paymentAccessToken'), 'keep-token');
});

test('in-flight creation can finish past the draft deadline without losing payment handoff data', () => {
  save();
  const resume = pauseBookingCartExpiration();
  now += BOOKING_CART_DURATION_MS;
  assert.equal(expireBookingCart(), false);
  assert.notEqual(sessionStorage.getItem('selectedRooms'), null);
  completeBookingCart();
  resume();
  assert.notEqual(sessionStorage.getItem('bookingData'), null);
  assert.equal(getBookingCart(), null);
});

test('failed in-flight creation resumes expiration instead of extending the draft', () => {
  save();
  const resume = pauseBookingCartExpiration();
  now += BOOKING_CART_DURATION_MS;
  resume();
  assert.equal(getBookingCart(), null);
  assert.equal(sessionStorage.getItem('selectedRooms'), null);
  assert.equal(getBookingCartDeadline(), null);
});

test('expiry of another tab’s local cart does not clear this tab’s newer draft', () => {
  save();
  localStorage.setItem(BOOKING_CART_STORAGE_KEY, JSON.stringify({ selectedRooms: rooms, expiresAt: new Date(now - 1).toISOString() }));
  assert.equal(expireBookingCart(), false);
  assert.ok(getBookingCart());
  assert.notEqual(sessionStorage.getItem(BOOKING_CART_EXPIRATION_KEY), null);
});

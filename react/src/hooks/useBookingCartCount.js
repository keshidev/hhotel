import { useEffect, useState } from 'react';
import {
  BOOKING_CART_EVENT,
  BOOKING_CART_STORAGE_KEY,
  getBookingCart,
  restoreBookingCartToSession,
} from '../utils/bookingCart';

const readCount = () => getBookingCart()?.selectedRooms?.length || 0;

export default function useBookingCartCount() {
  const [count, setCount] = useState(readCount);

  useEffect(() => {
    restoreBookingCartToSession();
    setCount(readCount());

    const handleCartUpdate = () => setCount(readCount());
    const handleStorage = (event) => {
      if (event.key === BOOKING_CART_STORAGE_KEY) handleCartUpdate();
    };

    window.addEventListener(BOOKING_CART_EVENT, handleCartUpdate);
    window.addEventListener('storage', handleStorage);
    return () => {
      window.removeEventListener(BOOKING_CART_EVENT, handleCartUpdate);
      window.removeEventListener('storage', handleStorage);
    };
  }, []);

  return count;
}

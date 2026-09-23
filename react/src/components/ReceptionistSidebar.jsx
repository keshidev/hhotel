import { useEffect, useMemo, useState } from 'react';
import {
  ArrowRightLeft,
  CalendarCheck,
  LayoutDashboard,
  LogIn,
  LogOut as CheckOutIcon,
  MessageSquare,
  RefreshCw,
  Settings,
  UserPlus,
  WalletCards,
  XCircle,
} from 'lucide-react';
import api from '../services/receptionistApi';
import StaffSidebar from './StaffSidebar';

const ReceptionistSidebar = () => {
  const [counts, setCounts] = useState({
    checkIn: 0,
    checkOut: 0,
    cancellation: 0,
    transfer: 0,
    rebooking: 0,
  });

  useEffect(() => {
    const fetchCounts = async () => {
      try {
        const today = new Date().toLocaleDateString('en-CA');
        const now = new Date().toLocaleTimeString('en-GB', { hour12: false });
        const checkInResponse = await api.get('/receptionist/bookings', {
          params: { status: 'confirmed', date_field: 'check_in', start_date: today, end_date: today, per_page: 100 },
        });
        const checkOutResponse = await api.get('/receptionist/bookings', {
          params: { status: 'checked_in', date_field: 'check_out', start_date: today, end_date: today, per_page: 100 },
        });
        const checkOutBookings = checkOutResponse.data?.data?.data ?? [];
        const checkOutTotal = checkOutResponse.data?.data?.total ?? 0;
        const expiredDayTours = checkOutBookings.filter(
          (booking) => booking.is_day_tour && booking.day_tour_end_time && now >= booking.day_tour_end_time,
        );
        const checkOutIds = new Set([
          ...checkOutBookings.map((booking) => booking.id),
          ...expiredDayTours.map((booking) => booking.id),
        ]);
        const [cancellationResponse, transferResponse, rebookingResponse] = await Promise.all([
          api.get('/receptionist/cancellation-requests', { params: { status: 'pending_approval', per_page: 1 } }),
          api.get('/receptionist/transfer-requests', { params: { status: 'pending_approval', per_page: 1 } }),
          api.get('/receptionist/rebookings', { params: { open: 1, per_page: 1 } }),
        ]);

        setCounts((current) => ({
          ...current,
          checkIn: checkInResponse.data?.data?.total ?? 0,
          checkOut: Math.max(checkOutTotal, checkOutIds.size),
          cancellation: cancellationResponse.data?.total ?? 0,
          transfer: transferResponse.data?.total ?? 0,
          rebooking: rebookingResponse.data?.total ?? 0,
        }));
      } catch {
        return;
      }
    };

    fetchCounts();
    const interval = setInterval(fetchCounts, 60000);
    return () => clearInterval(interval);
  }, []);

  useEffect(() => {
    const handleCheckInDelta = (event) => {
      const delta = Number(event?.detail?.delta || 0);
      if (!Number.isFinite(delta) || delta === 0) return;
      setCounts((current) => ({ ...current, checkIn: Math.max(0, current.checkIn + delta) }));
    };

    window.addEventListener('receptionist:checkin-count-delta', handleCheckInDelta);
    return () => window.removeEventListener('receptionist:checkin-count-delta', handleCheckInDelta);
  }, []);

  const navigation = useMemo(() => [
    {
      label: 'Main',
      items: [
        { path: '/receptionist/dashboard', label: 'Dashboard', icon: LayoutDashboard },
      ],
    },
    {
      label: 'Reservations',
      items: [
        { path: '/receptionist/reservation', label: 'Reservation', icon: CalendarCheck },
        { path: '/receptionist/manual-gcash-reviews', label: 'Payment Operations', icon: WalletCards },
        { path: '/receptionist/cancellation', label: 'Cancellation', icon: XCircle, badge: counts.cancellation },
        { path: '/receptionist/transfer-requests', label: 'Transfer Requests', icon: ArrowRightLeft, badge: counts.transfer },
        { path: '/receptionist/rebooking', label: 'Rebooking', icon: RefreshCw, badge: counts.rebooking },
        { path: '/receptionist/contact-inquiries', label: 'Guest Inquiries', icon: MessageSquare },
      ],
    },
    {
      label: 'Front Desk',
      items: [
        { path: '/receptionist/walk-in', label: 'Walk-In', icon: UserPlus },
        { path: '/receptionist/check-in', label: 'Check-In', icon: LogIn, badge: counts.checkIn },
        { path: '/receptionist/check-out', label: 'Check-Out', icon: CheckOutIcon, badge: counts.checkOut },
      ],
    },
    {
      label: 'Account',
      items: [
        { path: '/receptionist/settings', label: 'Settings', icon: Settings },
      ],
    },
  ], [counts]);

  return (
    <StaffSidebar navigation={navigation} panelLabel="Receptionist Panel" role="receptionist" />
  );
};

export default ReceptionistSidebar;

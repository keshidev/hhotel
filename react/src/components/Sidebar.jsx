import {
  ArrowRightLeft,
  BedDouble,
  ClipboardCheck,
  Clock3,
  FileText,
  LayoutDashboard,
  LayoutTemplate,
  MessageSquare,
  Settings,
  ShieldAlert,
  Tag,
  Users,
  WalletCards,
  RefreshCw,
} from 'lucide-react';
import StaffSidebar from './StaffSidebar';

const navigation = [
  {
    label: 'Main',
    items: [
      { path: '/admin/dashboard', label: 'Dashboard', icon: LayoutDashboard },
    ],
  },
  {
    label: 'Management',
    items: [
      { path: '/admin/users', label: 'User Management', icon: Users },
      { path: '/admin/rooms', label: 'Room Management', icon: BedDouble },
      { path: '/admin/promo-codes', label: 'Promo Codes', icon: Tag },
      { path: '/admin/cms', label: 'Content Management', icon: LayoutTemplate },
    ],
  },
  {
    label: 'Operations',
    items: [
      { path: '/admin/cancellation-approvals', label: 'Cancellation Approvals', icon: ClipboardCheck },
      { path: '/admin/rebooking-approvals', label: 'Rebooking Approvals', icon: RefreshCw },
      { path: '/admin/transfer-approvals', label: 'Transfer Approvals', icon: ArrowRightLeft },
      { path: '/admin/manual-gcash-reviews', label: 'Payment Operations', icon: WalletCards },
      { path: '/admin/early-check-in-approvals', label: 'Early Check-In', icon: Clock3 },
      { path: '/admin/contact-inquiries', label: 'Guest Inquiries', icon: MessageSquare },
    ],
  },
  {
    label: 'Reports & Logs',
    items: [
      {
        key: 'reports',
        label: 'Report Management',
        icon: FileText,
        children: [
          { path: '/admin/reports/revenue', label: 'Revenue Report' },
          { path: '/admin/reports/occupancy', label: 'Occupancy Report' },
          { path: '/admin/reports/reservation', label: 'Reservation Report' },
          { path: '/admin/reports/reservation-modified', label: 'Modified Reservations' },
          { path: '/admin/reports/feedback', label: 'Feedback Report' },
        ],
      },
      { path: '/admin/audit-trail', label: 'Audit Trail', icon: ShieldAlert },
    ],
  },
  {
    label: 'Account',
    items: [
      { path: '/admin/settings', label: 'Settings', icon: Settings },
    ],
  },
];

const AdminSidebar = () => (
  <StaffSidebar navigation={navigation} panelLabel="Admin Panel" role="admin" />
);

export default AdminSidebar;

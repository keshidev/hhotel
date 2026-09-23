import { notificationDestination, notificationMessage } from '../utils/notificationDestination';
import { useCallback, useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { useNavigate } from "react-router-dom";
import {
  Bell, X, CheckCheck, CalendarPlus, CalendarX, CalendarClock,
  CreditCard, AlertCircle, LogIn, LogOut, RefreshCw, Wrench,
  ShieldAlert, BarChart3, ClipboardList, UserCog, Hash,
  ChevronRight, Clock, User, MapPin, DollarSign, ArrowRight,
  Wallet, Star,
  MessageSquare,
} from "lucide-react";
import adminApi from "../services/adminApi";
import receptionistApi from "../services/receptionistApi";
import { NOTIFICATION_TYPES } from "../constants/notificationTypes";

const TYPE_CONFIG = {
  // Booking
  [NOTIFICATION_TYPES.BOOKING_CREATED]:    { icon: CalendarPlus,   color: "#3b82f6", bg: "#eff6ff", label: "New Booking",       route_admin: "/admin/dashboard",        route_receptionist: "/receptionist/reservation",  cta_admin: "View Reservations",  cta_receptionist: "Open Reservation"   },
  [NOTIFICATION_TYPES.BOOKING_CONFIRMED]:  { icon: CalendarClock,  color: "#10b981", bg: "#ecfdf5", label: "Confirmed",         route_admin: "/admin/dashboard",        route_receptionist: "/receptionist/reservation",  cta_admin: "View Reservation",   cta_receptionist: "Open Reservation"   },
  [NOTIFICATION_TYPES.BOOKING_CANCELLED]:  { icon: CalendarX,      color: "#ef4444", bg: "#fef2f2", label: "Cancelled",         route_admin: "/admin/dashboard",        route_receptionist: "/receptionist/cancellation", cta_admin: "View Details",       cta_receptionist: "View Cancellation"  },
  [NOTIFICATION_TYPES.BOOKING_MODIFIED]:   { icon: CalendarClock,  color: "#8b5cf6", bg: "#f5f3ff", label: "Modified",          route_admin: "/admin/dashboard",        route_receptionist: "/receptionist/reservation",  cta_admin: "View Reservation",   cta_receptionist: "Open Reservation"   },
  // Payment
  payment_received:         { icon: CreditCard,      color: "#10b981", bg: "#ecfdf5", label: "Payment Accepted",   route_admin: "/admin/dashboard",        route_receptionist: "/receptionist/payment",      cta_admin: "View Payment",       cta_receptionist: "Review Payment"     },
  payment_rejected:         { icon: AlertCircle,     color: "#ef4444", bg: "#fef2f2", label: "Payment Rejected",   route_admin: "/admin/dashboard",        route_receptionist: "/receptionist/payment",      cta_admin: "View Payment",       cta_receptionist: "Review Payment"     },
  payment_pending_summary:  { icon: Wallet,          color: "#f59e0b", bg: "#fffbeb", label: "Pending Summary",    route_admin: "/admin/dashboard",        route_receptionist: "/receptionist/payment",      cta_admin: "View Payments",      cta_receptionist: "View Pending"       },
  payment_unpaid_checkout:  { icon: AlertCircle,     color: "#ef4444", bg: "#fef2f2", label: "Unpaid Checkout",    route_admin: "/admin/dashboard",        route_receptionist: "/receptionist/payment",      cta_admin: "Review",             cta_receptionist: "Review Payment"     },
  payment_submitted:        { icon: CreditCard,      color: "#10b981", bg: "#ecfdf5", label: "Payment Submitted",  route_admin: null,                      route_receptionist: "/receptionist/payment",      cta_admin: null,                 cta_receptionist: "Review Payment"     },
  payment_partial:          { icon: AlertCircle,     color: "#f59e0b", bg: "#fffbeb", label: "Partial Payment",    route_admin: null,                      route_receptionist: "/receptionist/payment",      cta_admin: null,                 cta_receptionist: "Review Payment"     },
  payment_pending_reminder: { icon: Clock,           color: "#ef4444", bg: "#fef2f2", label: "Payment Reminder",   route_admin: null,                      route_receptionist: "/receptionist/payment",      cta_admin: null,                 cta_receptionist: "View Pending"       },
  // Room
  room_maintenance:         { icon: Wrench,          color: "#6366f1", bg: "#eef2ff", label: "Maintenance",        route_admin: "/admin/rooms",            route_receptionist: "/receptionist/reservation",  cta_admin: "View Rooms",         cta_receptionist: "View Reservation"   },
  room_conflict_blocked:    { icon: ShieldAlert,     color: "#ef4444", bg: "#fef2f2", label: "Conflict Blocked",   route_admin: "/admin/rooms",            route_receptionist: null,                         cta_admin: "Review Rooms",       cta_receptionist: null                 },
  room_overbooking_blocked: { icon: ShieldAlert,     color: "#dc2626", bg: "#fef2f2", label: "Overbooking",        route_admin: "/admin/rooms",            route_receptionist: null,                         cta_admin: "Review Rooms",       cta_receptionist: null                 },
  room_ready:               { icon: Star,            color: "#10b981", bg: "#ecfdf5", label: "Room Ready",         route_admin: null,                      route_receptionist: "/receptionist/reservation",  cta_admin: null,                 cta_receptionist: "View Reservation"   },
  // Check-in / Check-out
  guest_arriving_today:     { icon: LogIn,           color: "#8b5cf6", bg: "#f5f3ff", label: "Arriving Today",     route_admin: null,                      route_receptionist: "/receptionist/reservation",  cta_admin: null,                 cta_receptionist: "Open Reservation"   },
  guest_late_checkin:       { icon: Clock,           color: "#f59e0b", bg: "#fffbeb", label: "Late Check-in",      route_admin: null,                      route_receptionist: "/receptionist/reservation",  cta_admin: null,                 cta_receptionist: "Open Reservation"   },
  guest_checking_out_today: { icon: LogOut,          color: "#ec4899", bg: "#fdf2f8", label: "Checking Out",       route_admin: null,                      route_receptionist: "/receptionist/reservation",  cta_admin: null,                 cta_receptionist: "Open Reservation"   },
  guest_checkout_with_balance: { icon: AlertCircle,  color: "#ef4444", bg: "#fef2f2", label: "Balance Due",        route_admin: null,                      route_receptionist: "/receptionist/payment",      cta_admin: null,                 cta_receptionist: "Review Payment"     },
  [NOTIFICATION_TYPES.UPCOMING_CHECKIN]:   { icon: LogIn,           color: "#8b5cf6", bg: "#f5f3ff", label: "Upcoming Check-in", route_admin: null,                      route_receptionist: "/receptionist/check-in",     cta_admin: null,                 cta_receptionist: "Open Check-in"      },
  [NOTIFICATION_TYPES.UPCOMING_CHECKOUT]:  { icon: LogOut,          color: "#ec4899", bg: "#fdf2f8", label: "Upcoming Check-out",route_admin: null,                      route_receptionist: "/receptionist/check-out",    cta_admin: null,                 cta_receptionist: "Open Check-out"     },
  [NOTIFICATION_TYPES.EARLY_CHECKIN_REQUESTED]: { icon: Clock, color: "#f59e0b", bg: "#fffbeb", label: "Early Check-In", route_admin: "/admin/early-check-in-approvals", route_receptionist: null, cta_admin: "Review Request", cta_receptionist: null },
  [NOTIFICATION_TYPES.EARLY_CHECKIN_APPROVED]:  { icon: CheckCheck, color: "#10b981", bg: "#ecfdf5", label: "Early Check-In Approved", route_admin: "/admin/early-check-in-approvals", route_receptionist: "/receptionist/check-in", cta_admin: "View Request", cta_receptionist: "Complete Check-In" },
  [NOTIFICATION_TYPES.EARLY_CHECKIN_REJECTED]:  { icon: AlertCircle, color: "#ef4444", bg: "#fef2f2", label: "Early Check-In Rejected", route_admin: "/admin/early-check-in-approvals", route_receptionist: "/receptionist/check-in", cta_admin: "View Request", cta_receptionist: "Open Check-In" },
  // Staff Activity (Admin only)
  staff_booking_deleted:    { icon: UserCog,         color: "#dc2626", bg: "#fef2f2", label: "Staff Action",       route_admin: "/admin/dashboard",        route_receptionist: null,                         cta_admin: "View Activity",      cta_receptionist: null                 },
  staff_billing_edited:     { icon: UserCog,         color: "#f59e0b", bg: "#fffbeb", label: "Billing Edited",     route_admin: "/admin/dashboard",        route_receptionist: null,                         cta_admin: "View Details",       cta_receptionist: null                 },
  staff_room_override:      { icon: UserCog,         color: "#8b5cf6", bg: "#f5f3ff", label: "Room Override",      route_admin: "/admin/rooms",            route_receptionist: null,                         cta_admin: "Review Rooms",       cta_receptionist: null                 },
  // Rebooking & Walk-in
  rebooking_requested:      { icon: RefreshCw,       color: "#3b82f6", bg: "#eff6ff", label: "Rebooking",          route_admin: "/admin/rebooking-approvals", route_receptionist: "/receptionist/rebooking", cta_admin: "View Rebooking", cta_receptionist: "View Rebooking" },
  rebooking_approved:       { icon: CheckCheck,      color: "#10b981", bg: "#ecfdf5", label: "Rebooking Approved", route_admin: "/admin/rebooking-approvals", route_receptionist: "/receptionist/rebooking", cta_admin: "View Rebooking", cta_receptionist: "View Rebooking" },
  rebooking_rejected:       { icon: AlertCircle,     color: "#ef4444", bg: "#fef2f2", label: "Rebooking Rejected", route_admin: "/admin/rebooking-approvals", route_receptionist: "/receptionist/rebooking", cta_admin: "View Rebooking", cta_receptionist: "View Rebooking" },
  cancellation_requested:   { icon: CalendarX,       color: "#f59e0b", bg: "#fffbeb", label: "Cancellation",       route_admin: "/admin/cancellation-approvals", route_receptionist: "/receptionist/cancellation", cta_admin: "Review Request", cta_receptionist: "View Request" },
  cancellation_approved:    { icon: CheckCheck,      color: "#10b981", bg: "#ecfdf5", label: "Cancellation Approved", route_admin: "/admin/cancellation-approvals", route_receptionist: "/receptionist/cancellation", cta_admin: "View Decision", cta_receptionist: "View Request" },
  cancellation_rejected:    { icon: AlertCircle,     color: "#ef4444", bg: "#fef2f2", label: "Cancellation Rejected", route_admin: "/admin/cancellation-approvals", route_receptionist: "/receptionist/cancellation", cta_admin: "View Decision", cta_receptionist: "View Request" },
  walkin_created:           { icon: ClipboardList,   color: "#10b981", bg: "#ecfdf5", label: "Walk-in",            route_admin: "/admin/dashboard",        route_receptionist: "/receptionist/reservation",  cta_admin: "View Details",       cta_receptionist: "Open Reservation"   },
  [NOTIFICATION_TYPES.ROOM_TRANSFER_REQUESTED]: { icon: RefreshCw, color: "#6366f1", bg: "#eef2ff", label: "Room Transfer", route_admin: "/admin/transfer-approvals", route_receptionist: "/receptionist/transfer-requests", cta_admin: "Review Transfer", cta_receptionist: "View Transfer" },
  [NOTIFICATION_TYPES.ROOM_TRANSFER_APPROVED]: { icon: CheckCheck, color: "#10b981", bg: "#ecfdf5", label: "Transfer Approved", route_admin: "/admin/transfer-approvals", route_receptionist: "/receptionist/transfer-requests", cta_admin: "View Transfer", cta_receptionist: "Complete Transfer" },
  [NOTIFICATION_TYPES.ROOM_TRANSFER_REJECTED]: { icon: AlertCircle, color: "#ef4444", bg: "#fef2f2", label: "Transfer Rejected", route_admin: "/admin/transfer-approvals", route_receptionist: "/receptionist/transfer-requests", cta_admin: "View Transfer", cta_receptionist: "View Decision" },
  [NOTIFICATION_TYPES.ROOM_TRANSFER_COMPLETED]: { icon: CheckCheck, color: "#10b981", bg: "#ecfdf5", label: "Transfer Complete", route_admin: "/admin/transfer-approvals", route_receptionist: "/receptionist/transfer-requests", cta_admin: "View Transfer", cta_receptionist: "View Transfer" },
  [NOTIFICATION_TYPES.CONTACT_INQUIRY]: { icon: MessageSquare, color: "#1A4BCC", bg: "#eff6ff", label: "Guest Inquiry", route_admin: "/admin/contact-inquiries", route_receptionist: "/receptionist/contact-inquiries", cta_admin: "Open Inquiry", cta_receptionist: "Open Inquiry" },
  // Daily Summary (Admin only)
  [NOTIFICATION_TYPES.DAILY_SUMMARY]: { icon: BarChart3, color: "#1A4BCC", bg: "#eff6ff", label: "Daily Summary", route_admin: "/admin/reports/revenue", route_receptionist: null, cta_admin: "View Reports", cta_receptionist: null },
};

const DEFAULT_CFG = { icon: Bell, color: "#1A4BCC", bg: "#eff6ff", label: "Alert", route_admin: null, route_receptionist: null };

function formatTime(dateStr) {
  const diff = Math.floor((Date.now() - new Date(dateStr)) / 1000);
  if (diff < 60) return "Just now";
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
  return `${Math.floor(diff / 86400)}d ago`;
}

function DR({ icon, label, value, highlight }) {
  return (
    <div style={{ display: "flex", alignItems: "flex-start", gap: 9 }}>
      <div style={{ width: 18, display: "flex", justifyContent: "center", flexShrink: 0, marginTop: 1 }}>{icon}</div>
      <span style={{ fontSize: "0.775rem", color: "#94a3b8", minWidth: 90, flexShrink: 0 }}>{label}</span>
      <span style={{ fontSize: "0.8rem", fontWeight: highlight ? 700 : 500, color: highlight ? "#1A4BCC" : "#1e293b", textAlign: "right", flex: 1 }}>{value}</span>
    </div>
  );
}

function DetailDrawer({ notification, role, onClose, onNavigate }) {
  const closeButtonRef = useRef(null);

  useEffect(() => {
    if (!notification) return undefined;

    const previousOverflow = document.body.style.overflow;
    const handleKeyDown = (event) => {
      if (event.key === "Escape") onClose();
    };

    document.body.style.overflow = "hidden";
    document.addEventListener("keydown", handleKeyDown);
    closeButtonRef.current?.focus();

    return () => {
      document.body.style.overflow = previousOverflow;
      document.removeEventListener("keydown", handleKeyDown);
    };
  }, [notification, onClose]);

  if (!notification) return null;
  const cfg      = TYPE_CONFIG[notification.type] || DEFAULT_CFG;
  const Icon     = cfg.icon;
  const meta     = notification.meta || {};
  const { route, label: destinationLabel } = notificationDestination(notification, role, cfg);
  const ctaLabel = destinationLabel;
  const isDaily  = notification.type === NOTIFICATION_TYPES.DAILY_SUMMARY;
  const drawerTitleId = `notification-drawer-title-${notification.id}`;

  return createPortal(
    <div style={{ position: "fixed", inset: 0, zIndex: 99999, display: "flex", justifyContent: "flex-end" }}>
      <button type="button" aria-label="Close notification details" onClick={onClose} style={{ position: "absolute", inset: 0, width: "100%", height: "100%", border: 0, padding: 0, background: "rgba(15,23,42,0.25)", backdropFilter: "blur(3px)", animation: "ndFadeIn 0.2s ease", cursor: "default" }} />
      <div role="dialog" aria-modal="true" aria-labelledby={drawerTitleId} style={{ position: "relative", width: "min(390px, 100vw)", height: "100%", background: "#fff", boxShadow: "-12px 0 48px rgba(0,0,0,0.12)", display: "flex", flexDirection: "column", animation: "ndSlideIn 0.28s cubic-bezier(0.22,1,0.36,1)", fontFamily: "'Inter', sans-serif" }}>

        {/* Header */}
        <div style={{ padding: "22px 24px 16px", borderBottom: "1px solid #f1f5f9", display: "flex", alignItems: "flex-start", gap: 12 }}>
          <div style={{ width: 46, height: 46, borderRadius: 13, background: cfg.bg, display: "flex", alignItems: "center", justifyContent: "center", flexShrink: 0 }}>
            <Icon size={20} color={cfg.color} strokeWidth={1.8} />
          </div>
          <div style={{ flex: 1, minWidth: 0, overflowWrap: 'anywhere' }}>
            <div style={{ fontSize: "0.66rem", fontWeight: 700, letterSpacing: "0.08em", textTransform: "uppercase", color: cfg.color, marginBottom: 3 }}>{cfg.label}</div>
            <div id={drawerTitleId} style={{ fontSize: "0.9375rem", fontWeight: 700, color: "#0f172a", lineHeight: 1.3 }}>{notification.title}</div>
          </div>
          <button ref={closeButtonRef} type="button" aria-label="Close notification details" onClick={onClose} style={{ background: "#f8fafc", border: "1px solid #e2e8f0", borderRadius: 8, width: 32, height: 32, cursor: "pointer", display: "flex", alignItems: "center", justifyContent: "center", color: "#64748b", flexShrink: 0 }}>
            <X size={14} strokeWidth={2.2} />
          </button>
        </div>

        {/* Body */}
        <div style={{ padding: "20px 24px", flex: 1, overflowY: "auto" }}>
          <p style={{ fontSize: "0.875rem", color: "#475569", lineHeight: 1.65, margin: "0 0 20px" }}>{notificationMessage(notification)}</p>
          <p style={{ fontSize: 12, color: "#64748b", lineHeight: 1.6 }}>Sent {new Date(notification.created_at).toLocaleString()}. This is a snapshot; the destination shows the current records. Reading this notification does not resolve the task.</p>

          {/* Daily Summary grid */}
          {isDaily && (
            <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 10, marginBottom: 20 }}>
              {[
                { label: "Check-ins",        value: meta.checkins        ?? "—", color: "#8b5cf6", bg: "#f5f3ff" },
                { label: "Check-outs",       value: meta.checkouts       ?? "—", color: "#ec4899", bg: "#fdf2f8" },
                { label: "In-House",         value: meta.in_house        ?? "—", color: "#3b82f6", bg: "#eff6ff" },
                { label: "Revenue",          value: meta.revenue         ?? "—", color: "#10b981", bg: "#ecfdf5" },
                { label: "Pending Payments", value: meta.pending_payments ?? "—", color: "#f59e0b", bg: "#fffbeb" },
              ].map(item => (
                <div key={item.label} style={{ background: item.bg, borderRadius: 10, padding: "12px 14px" }}>
                  <div style={{ fontSize: "0.68rem", color: item.color, fontWeight: 700, textTransform: "uppercase", letterSpacing: "0.06em", marginBottom: 4 }}>{item.label}</div>
                  <div style={{ fontSize: "1.25rem", fontWeight: 800, color: "#0f172a" }}>{item.value}</div>
                </div>
              ))}
            </div>
          )}

          {/* Standard meta rows */}
          {!isDaily && Object.values(meta).some(v => v != null) && (
            <>
              <div style={{ fontSize: "0.66rem", fontWeight: 700, letterSpacing: "0.08em", textTransform: "uppercase", color: "#94a3b8", marginBottom: 10 }}>Details</div>
              <div style={{ background: "#f8fafc", borderRadius: 12, padding: "14px 16px", display: "flex", flexDirection: "column", gap: 11, marginBottom: 20 }}>
                {meta.booking_id  && <DR icon={<Hash size={13} color="#94a3b8"/>}          label="Booking ID"  value={meta.booking_id}  highlight />}
                {meta.payment_id  && <DR icon={<Hash size={13} color="#94a3b8"/>}          label="Payment ID"  value={meta.payment_id}  highlight />}
                {meta.guest_name  && <DR icon={<User size={13} color="#94a3b8"/>}          label="Guest"       value={meta.guest_name} />}
                {meta.room        && <DR icon={<MapPin size={13} color="#94a3b8"/>}        label="Room"        value={`Room ${meta.room}`} />}
                {meta.check_in    && <DR icon={<LogIn size={13} color="#94a3b8"/>}         label="Check-in"    value={meta.check_in} />}
                {meta.check_out   && <DR icon={<LogOut size={13} color="#94a3b8"/>}        label="Check-out"   value={meta.check_out} />}
                {meta.amount      && <DR icon={<DollarSign size={13} color="#94a3b8"/>}    label="Amount"      value={meta.amount} />}
                {meta.method      && <DR icon={<CreditCard size={13} color="#94a3b8"/>}    label="Method"      value={meta.method} />}
                {meta.reason      && <DR icon={<AlertCircle size={13} color="#94a3b8"/>}   label="Reason"      value={meta.reason} />}
                {meta.staff_name  && <DR icon={<UserCog size={13} color="#94a3b8"/>}       label="Staff"       value={meta.staff_name} />}
                {meta.action      && <DR icon={<ClipboardList size={13} color="#94a3b8"/>} label="Action"      value={meta.action} />}
                <DR icon={<Clock size={13} color="#94a3b8"/>} label="Received" value={formatTime(notification.created_at)} />
              </div>
            </>
          )}

          {notification.type === 'payment_pending_summary' && route && (
            <button type="button" onClick={() => onNavigate(notification, true)} style={{ width: '100%', marginBottom: 10, padding: 12, border: '1px solid #c7d7fe', borderRadius: 11, background: '#eff6ff', color: '#1a4bcc', fontWeight: 700, cursor: 'pointer' }}>Review submitted payment proofs</button>
          )}
          {!route && <p style={{ fontSize: 13, color: '#64748b' }}>This notification is informational. There is no linked action available for your role.</p>}
          {/* CTA */}
          {route && ctaLabel && (
            <button
              onClick={() => onNavigate(notification)}
              style={{ width: "100%", padding: "13px 16px", background: "#1a4bcc", border: "none", borderRadius: 11, color: "#fff", fontSize: "0.875rem", fontWeight: 700, cursor: "pointer", display: "flex", alignItems: "center", justifyContent: "center", gap: 8, fontFamily: "'Inter', sans-serif" }}
            >
              {ctaLabel} <ArrowRight size={15} strokeWidth={2.5} />
            </button>
          )}
        </div>
      </div>
    </div>,
    document.body,
  );
}

const NotificationDropdown = ({ role = "receptionist" }) => {
  const navigate = useNavigate();
  const api = role === "admin" ? adminApi : receptionistApi;
  const [isOpen, setIsOpen]               = useState(false);
  const [notifications, setNotifications] = useState([]);
  const [unreadCount, setUnreadCount]     = useState(0);
  const [loading, setLoading]             = useState(false);
  const [filter, setFilter]               = useState("all");
  const [activeNotif, setActiveNotif]     = useState(null);
  const [hoveredId, setHoveredId]         = useState(null);
  const dropdownRef = useRef(null);
  const listRef = useRef(null);
  const rowsRef = useRef([]);
  const cursorRef = useRef(null);
  const requestRef = useRef(null);
  const generationRef = useRef(0);
  const mutationRef = useRef(false);
  const [nextCursor, setNextCursor] = useState(null);
  const [totalCount, setTotalCount] = useState(0);
  const [loadingMore, setLoadingMore] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [mutating, setMutating] = useState(false);
  const [fetchError, setFetchError] = useState('');
  const [actionError, setActionError] = useState('');

  const cancelFetch = useCallback(() => {
    generationRef.current += 1;
    requestRef.current?.abort();
    requestRef.current = null;
  }, []);

  const fetchNotifications = useCallback(async (showSpinner = false, append = false) => {
    if (requestRef.current || mutationRef.current || (append && !cursorRef.current)) return;
    const controller = new AbortController();
    requestRef.current = controller;
    const generation = generationRef.current;
    const list = listRef.current;
    const oldScroll = list?.scrollTop || 0;
    const anchor = oldScroll > 0 ? [...(list?.querySelectorAll('[data-notification-id]') || [])]
      .find(el => el.getBoundingClientRect().bottom > list.getBoundingClientRect().top) : null;
    const anchorId = anchor?.dataset.notificationId;
    const anchorOffset = anchor ? anchor.getBoundingClientRect().top - list.getBoundingClientRect().top : 0;
    const desiredCount = Math.max(20, rowsRef.current.length);
    if (showSpinner && rowsRef.current.length === 0) setLoading(true);
    if (append) setLoadingMore(true); else setRefreshing(true);
    setFetchError('');
    try {
      let cursor = append ? cursorRef.current : null;
      const collected = [];
      const visited = new Set();
      let response;
      do {
        const res = await api.get('/notifications', {
          params: { limit: 20, filter, ...(cursor ? { cursor } : {}) }, signal: controller.signal,
        });
        if (generation !== generationRef.current) return;
        response = res.data;
        collected.push(...(response?.data || []).map(n => ({ ...n, meta: n.data })));
        cursor = response?.next_cursor || null;
        if (cursor && visited.has(cursor)) throw new Error('Repeated notification cursor');
        if (cursor) visited.add(cursor);
        // Refresh the already-loaded window, including the visible anchor, before replacing it.
      } while (!append && cursor && (collected.length < desiredCount || (anchorId && !collected.some(n => String(n.id) === anchorId))));
      const rows = [...new Map((append ? [...rowsRef.current, ...collected] : collected).map(n => [n.id, n])).values()];
      rowsRef.current = rows;
      cursorRef.current = cursor;
      setNotifications(rows);
      setNextCursor(cursor);
      setUnreadCount(Number(response?.unread_count ?? 0));
      setTotalCount(Number(response?.total_count ?? rows.length));
      requestAnimationFrame(() => {
        if (generation !== generationRef.current || !listRef.current || listRef.current !== list) return;
        const restored = anchorId ? [...list.querySelectorAll('[data-notification-id]')].find(el => el.dataset.notificationId === anchorId) : null;
        list.scrollTop = restored ? list.scrollTop + restored.getBoundingClientRect().top - list.getBoundingClientRect().top - anchorOffset : oldScroll;
      });
    } catch (err) {
      if (!controller.signal.aborted) {
        setFetchError(append ? 'Could not load more notifications. Please retry.' : 'Could not refresh notifications. Please retry.');
        console.error('Failed to fetch notifications:', err);
      }
    } finally {
      if (generation === generationRef.current) {
        requestRef.current = null;
        setLoading(false); setLoadingMore(false); setRefreshing(false);
      }
    }
  }, [api, filter]);

  useEffect(() => {
    cancelFetch();
    rowsRef.current = [];
    cursorRef.current = null;
    setNotifications([]); setNextCursor(null); setTotalCount(0);
    setLoadingMore(false); setRefreshing(false); setFetchError('');
    if (listRef.current) listRef.current.scrollTop = 0;
    fetchNotifications(true);
    const iv = setInterval(() => fetchNotifications(false), 15000);
    const onFocus = () => fetchNotifications(false);
    const onVisible = () => {
      if (document.visibilityState === 'visible') {
        fetchNotifications(false);
      }
    };
    window.addEventListener('focus', onFocus);
    document.addEventListener('visibilitychange', onVisible);
    return () => {
      cancelFetch();
      clearInterval(iv);
      window.removeEventListener('focus', onFocus);
      document.removeEventListener('visibilitychange', onVisible);
    };
  }, [fetchNotifications, cancelFetch]);

  useEffect(() => {
    const h = (e) => { if (dropdownRef.current && !dropdownRef.current.contains(e.target)) setIsOpen(false); };
    document.addEventListener("mousedown", h);
    return () => document.removeEventListener("mousedown", h);
  }, []);

  const markAsRead = async (id, e) => {
    e?.stopPropagation();
    if (mutationRef.current) return;
    const wasUnread = notifications.some(n => n.id === id && !n.read_at);
    if (!wasUnread) return;

    mutationRef.current = true; setMutating(true); setActionError('');
    cancelFetch(); setLoading(false); setLoadingMore(false); setRefreshing(false);
    try {
      await api.patch(`/notifications/${id}/read`);
      const rows = rowsRef.current.map(n => n.id === id ? { ...n, read_at: new Date().toISOString() } : n);
      rowsRef.current = filter === 'unread' ? rows.filter(n => !n.read_at) : rows;
      setNotifications(rowsRef.current);
      setUnreadCount(count => Math.max(0, count - 1));
      if (filter === 'unread') setTotalCount(count => Math.max(0, count - 1));
    } catch (err) { setActionError('Could not mark the notification as read. Please retry.'); console.error(err); }
    finally { mutationRef.current = false; setMutating(false); void fetchNotifications(false); }
  };

  const markAllAsRead = async () => {
    if (mutationRef.current) return;
    mutationRef.current = true; setMutating(true); setActionError('');
    cancelFetch(); setLoading(false); setLoadingMore(false); setRefreshing(false);
    try {
      await api.patch("/notifications/read-all");
      rowsRef.current = filter === 'unread' ? [] : rowsRef.current.map(n => ({ ...n, read_at: new Date().toISOString() }));
      setNotifications(rowsRef.current);
      setUnreadCount(0);
    } catch (err) { setActionError('Could not mark all notifications as read. Please retry.'); console.error(err); }
    finally { mutationRef.current = false; setMutating(false); void fetchNotifications(false); }
  };

  const handleNavigate = (notif, proofReview = false) => {
    const cfg = TYPE_CONFIG[notif.type] || DEFAULT_CFG;
    const destination = notificationDestination(notif, role, cfg);
    if (!destination.route) return;
    setActiveNotif(null);
    if (proofReview) {
      navigate('/' + role + '/manual-gcash-reviews?section=reviews', {
        state: { notificationTarget: { ...destination.state.notificationTarget, search: '', status: '' } },
      });
    } else navigate(destination.route, { state: destination.state });
  };

  const handleNotifClick = (n) => {
    void markAsRead(n.id);
    setActiveNotif(n);
    setIsOpen(false);
  };

  const filtered    = filter === "unread" ? notifications.filter(n => !n.read_at) : notifications;

  return (
    <>
      <style>{`
        @import url('https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&display=swap');
        @keyframes ndFadeIn  { from{opacity:0}to{opacity:1} }
        @keyframes ndSlideIn { from{transform:translateX(100%)}to{transform:translateX(0)} }
        @keyframes ndDropIn  { from{opacity:0;transform:translateY(-8px) scale(0.98)}to{opacity:1;transform:translateY(0) scale(1)} }
        @keyframes ndSpin    { to{transform:rotate(360deg)} }
        .nd-bell:hover        { background:#f1f5f9 !important; color:#0f172a !important; }
        .nd-item:hover        { background:#f8fafc !important; }
        .nd-item.unread:hover { background:#eff6ff !important; }
        .nd-tab:hover         { color:#0f172a !important; }
        .nd-markall:hover     { text-decoration:underline; }
        .nd-read-dot:hover    { transform:scale(1.5) !important; }
        @media (max-width: 640px) {
          .nd-panel { position: fixed !important; top: 72px !important; left: 12px; right: 12px !important; width: auto !important; max-height: calc(100dvh - 84px); display: flex; flex-direction: column; }
          .nd-panel > :first-child, .nd-panel > :last-child { flex-shrink: 0; }
          .nd-list { min-height: 0; }
        }
      `}</style>

      <div ref={dropdownRef} style={{ position: "relative", fontFamily: "'Inter', sans-serif" }}>
        {/* Bell */}
        <button className="nd-bell" onClick={() => { setIsOpen(p => !p); if (!isOpen) fetchNotifications(false); }} style={{ position: "relative", width: 40, height: 40, background: isOpen ? "#f1f5f9" : "transparent", border: `1px solid ${isOpen ? "#e2e8f0" : "transparent"}`, borderRadius: 10, cursor: "pointer", display: "flex", alignItems: "center", justifyContent: "center", color: isOpen ? "#0f172a" : "#64748b", transition: "all 0.15s ease" }} aria-label="Notifications">
          <Bell size={18} strokeWidth={isOpen ? 2.2 : 1.8} />
          {unreadCount > 0 && (
            <span style={{ position: "absolute", top: 5, right: 5, minWidth: 17, height: 17, background: "#ef4444", color: "#fff", fontSize: "0.6rem", fontWeight: 700, borderRadius: 999, display: "flex", alignItems: "center", justifyContent: "center", padding: "0 4px", boxShadow: "0 0 0 2px #fff", pointerEvents: "none" }}>
              {unreadCount > 9 ? "9+" : unreadCount}
            </span>
          )}
        </button>

        {/* Dropdown */}
        {isOpen && (
          <div className="nd-panel" style={{ position: "absolute", top: "calc(100% + 10px)", right: 0, width: "min(376px, calc(100vw - 24px))", background: "#fff", border: "1px solid #e2e8f0", borderRadius: 16, boxShadow: "0 4px 6px -2px rgba(0,0,0,0.05), 0 20px 48px -8px rgba(0,0,0,0.15)", overflow: "hidden", animation: "ndDropIn 0.22s cubic-bezier(0.22,1,0.36,1)", zIndex: 9999 }}>
            <div style={{ padding: "16px 18px 0" }}>
              <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 12 }}>
                <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
                  <span style={{ fontSize: "0.9375rem", fontWeight: 700, color: "#0f172a" }}>Notifications</span>
                  {unreadCount > 0 && <span style={{ background: "#fef3c7", color: "#92400e", fontSize: "0.67rem", fontWeight: 700, padding: "2px 8px", borderRadius: 999 }}>{unreadCount} unread</span>}
                </div>
                {unreadCount > 0 && (
                  <button className="nd-markall" disabled={mutating} onClick={markAllAsRead} style={{ background: "none", border: "none", cursor: "pointer", display: "flex", alignItems: "center", gap: 4, fontSize: "0.74rem", color: "#1A4BCC", fontWeight: 600, fontFamily: "'Inter', sans-serif", padding: 0 }}>
                    <CheckCheck size={13} strokeWidth={2.2} /> Mark all read
                  </button>
                )}
              </div>
              <div style={{ display: "flex", borderBottom: "1px solid #f1f5f9" }}>
                {["all", "unread"].map(tab => (
                  <button key={tab} className="nd-tab" disabled={mutating} onClick={() => setFilter(tab)} style={{ background: "none", border: "none", borderBottom: `2px solid ${filter === tab ? "#1A4BCC" : "transparent"}`, marginBottom: -1, cursor: "pointer", padding: "6px 14px 10px", fontSize: "0.78rem", fontWeight: filter === tab ? 700 : 500, color: filter === tab ? "#0f172a" : "#94a3b8", fontFamily: "'Inter', sans-serif", transition: "all 0.15s", textTransform: "capitalize" }}>
                    {tab}{tab === "unread" && unreadCount > 0 ? ` (${unreadCount})` : ""}
                  </button>
                ))}
              </div>
            </div>

            <div ref={listRef} className="nd-list" aria-label="Notification list" onScroll={(event) => {
              const list = event.currentTarget;
              if (!fetchError && list.scrollHeight - list.scrollTop - list.clientHeight < 60) void fetchNotifications(false, true);
            }} style={{ maxHeight: 400, overflowY: "auto", scrollbarWidth: "thin", scrollbarColor: "#e2e8f0 transparent" }}>
              {loading && (
                <div style={{ padding: "32px", display: "flex", flexDirection: "column", alignItems: "center", gap: 8 }}>
                  <div style={{ width: 20, height: 20, border: "2px solid #e2e8f0", borderTopColor: "#1A4BCC", borderRadius: "50%", animation: "ndSpin 0.7s linear infinite" }} />
                  <span style={{ fontSize: "0.8rem", color: "#94a3b8" }}>Loading…</span>
                </div>
              )}
              {!loading && !fetchError && filtered.length === 0 && (
                <div style={{ padding: "44px 24px", display: "flex", flexDirection: "column", alignItems: "center", gap: 8 }}>
                  <Bell size={28} strokeWidth={1.2} color="#cbd5e1" />
                  <p style={{ margin: 0, fontSize: "0.85rem", color: "#94a3b8" }}>{filter === "unread" ? "All caught up!" : "No notifications yet"}</p>
                </div>
              )}
              {!loading && filtered.map((n, i) => {
                const cfg = TYPE_CONFIG[n.type] || DEFAULT_CFG;
                const Icon = cfg.icon;
                const isUnread = !n.read_at;
                const isHov = hoveredId === n.id;
                const meta = n.meta || {};
                return (
                  <div key={n.id} data-notification-id={n.id} className={`nd-item${isUnread ? " unread" : ""}`} onClick={() => handleNotifClick(n)} onMouseEnter={() => setHoveredId(n.id)} onMouseLeave={() => setHoveredId(null)}
                    style={{ display: "flex", alignItems: "flex-start", gap: 11, padding: "12px 16px 12px 18px", cursor: "pointer", background: isUnread ? "#f7f9ff" : "#fff", borderBottom: i < filtered.length - 1 ? "1px solid #f8fafc" : "none", borderLeft: isUnread ? "3px solid #1A4BCC" : "3px solid transparent", transition: "background 0.12s" }}>
                    <div style={{ width: 36, height: 36, borderRadius: 10, background: cfg.bg, flexShrink: 0, display: "flex", alignItems: "center", justifyContent: "center", marginTop: 1 }}>
                      <Icon size={16} color={cfg.color} strokeWidth={1.8} />
                    </div>
                    <div style={{ flex: 1, minWidth: 0 }}>
                      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 2 }}>
                        <span style={{ fontSize: "0.8125rem", fontWeight: isUnread ? 700 : 600, color: "#0f172a", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis", maxWidth: 175 }}>{n.title}</span>
                        <span style={{ fontSize: "0.68rem", color: "#94a3b8", flexShrink: 0, marginLeft: 8 }}>{formatTime(n.created_at)}</span>
                      </div>
                      <p style={{ margin: 0, fontSize: "0.775rem", color: "#64748b", lineHeight: 1.45, display: "-webkit-box", WebkitLineClamp: 2, WebkitBoxOrient: "vertical", overflow: "hidden" }}>{notificationMessage(n)}</p>
                      {(meta.booking_id || meta.payment_id) && (
                        <span style={{ display: "inline-flex", alignItems: "center", gap: 3, marginTop: 5, fontSize: "0.67rem", fontWeight: 700, color: cfg.color, background: cfg.bg, padding: "2px 7px", borderRadius: 5, letterSpacing: "0.04em" }}>
                          <Hash size={9} strokeWidth={2.5} />{meta.booking_id || meta.payment_id}
                        </span>
                      )}
                    </div>
                    <div style={{ display: "flex", flexDirection: "column", alignItems: "center", gap: 8, flexShrink: 0, marginTop: 2 }}>
                      <ChevronRight size={14} strokeWidth={2} color="#cbd5e1" style={{ opacity: isHov ? 1 : 0, transition: "opacity 0.15s, transform 0.15s", transform: isHov ? "translateX(0)" : "translateX(-4px)" }} />
                      {isUnread && <button className="nd-read-dot" disabled={mutating} onClick={e => markAsRead(n.id, e)} title="Mark as read" style={{ width: 7, height: 7, borderRadius: "50%", background: "#1A4BCC", border: "none", cursor: "pointer", padding: 0, transition: "transform 0.15s" }} />}
                    </div>
                  </div>
                );
              })}
            </div>

            <div style={{ padding: "10px 18px", borderTop: "1px solid #f1f5f9", textAlign: "center" }}>
              {actionError && <p role="alert" style={{ color: '#b91c1c', fontSize: '0.75rem' }}>{actionError}</p>}
              {fetchError && <p role="alert" style={{ color: '#b91c1c', fontSize: '0.75rem' }}>{fetchError}</p>}
              <p aria-live="polite" style={{ color: '#64748b', fontSize: '0.73rem', margin: '0 0 8px' }}>
                {loading ? 'Loading notifications…' : `${filtered.length}${filter === 'unread' ? ' unread' : ''} loaded · ${totalCount} total`}
                {refreshing && !loading ? ' · Refreshing…' : ''}
              </p>
              {nextCursor && <button type="button" disabled={loadingMore || refreshing || mutating} onClick={() => fetchNotifications(false, true)} style={{ display: 'block', margin: '0 auto 8px', color: '#1a4bcc', background: 'none', border: 0, cursor: 'pointer', fontSize: '0.78rem' }}>
                {loadingMore ? 'Loading more…' : 'Load more'}
              </button>}
              {!loading && !refreshing && !fetchError && !nextCursor && filtered.length > 0 && <p style={{ color: '#64748b', fontSize: '0.73rem', margin: '0 0 8px' }}>No more notifications.</p>}
              <button disabled={refreshing || loadingMore || mutating} onClick={() => fetchNotifications(true)} style={{ background: "none", border: "none", cursor: "pointer", fontSize: "0.775rem", color: "#64748b", fontWeight: 500, fontFamily: "'Inter', sans-serif" }}>
                Refresh notifications
              </button>
            </div>
          </div>
        )}
      </div>

      {activeNotif && (
        <DetailDrawer notification={activeNotif} role={role} onClose={() => setActiveNotif(null)} onNavigate={handleNavigate} />
      )}
    </>
  );
};

export default NotificationDropdown;

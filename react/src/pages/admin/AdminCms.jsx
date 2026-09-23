import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  Save,
  Upload,
  Loader2,
  CheckCircle,
  LayoutGrid,
  BarChart2,
  Star,
  Shield,
  MapPin,
  Images,
  BedDouble,
  Package,
  Palette,
  ArrowUp,
  ArrowDown,
  Plus,
  Trash2,
  Info,
} from 'lucide-react';
import api from '../../services/adminApi';
import './AdminCms.css';

const COLORS = {
  border: '#dde3f0',
  bg: '#eef0f7',
  surface: '#ffffff',
  text: '#0d1b3e',
  textSecondary: '#4a5568',
  muted: '#6b7280',
  blue: '#1a4bcc',
  success: '#16a34a',
  successDark: '#15803d',
};

const ROOM_TYPES = [
  { key: 'executive_suite', label: 'Executive Suite' },
  { key: 'family', label: 'Family Room' },
  { key: 'deluxe', label: 'Deluxe' },
  { key: 'superior_twin', label: 'Superior Twin' },
  { key: 'superior_queen', label: 'Superior Queen' },
  { key: 'premier', label: 'Premier' },
];

const ADDON_CATALOG = [
  { id: 'rollaway_bed', name: 'Rollaway Bed' },
  { id: 'extra_pillows_and_blankets', name: 'Extra Pillows and Blankets' },
  { id: 'breakfast_package', name: 'Breakfast Package' },
  { id: 'early_check_in', name: 'Early Check-In' },
  { id: 'late_check_out', name: 'Late Check-Out' },
  { id: 'extra_toiletries_kit', name: 'Extra Toiletries Kit' },
  { id: 'laundry_service', name: 'Laundry Service' },
];

const DEFAULT_HIGHLIGHTS = [
  { title: 'FAST BOOKING', description: 'Reserve your room in under 2 minutes with our streamlined booking system.' },
  { title: 'CLEAR BILLING', description: 'Transparent pricing with no hidden fees and secure payments.' },
  { title: 'COMFORTABLE ROOMS', description: 'Every room is equipped for a restful stay with premium essentials.' },
  { title: 'PRIME LOCATION', description: 'Minutes from key destinations in Quezon City.' },
];

const DEFAULT_STATS = [
  { value: '2min', label: 'AVERAGE BOOKING TIME' },
  { value: '24/7', label: 'FRONT DESK SUPPORT' },
  { value: '100%', label: 'TRANSPARENT BILLING' },
  { value: '6+', label: 'ROOM TYPES AVAILABLE' },
];

const DEFAULT_NEARBY = [
  { name: 'SM North EDSA', category: 'Shopping & Dining', description: 'A major Quezon City destination for shopping, dining, and entertainment.', map_url: 'https://www.google.com/maps/search/?api=1&query=SM+North+EDSA', image: '' },
  { name: 'TriNoma', category: 'Shopping & Transit', description: 'Shopping and dining with convenient access to nearby transport connections.', map_url: 'https://www.google.com/maps/search/?api=1&query=TriNoma+Quezon+City', image: '' },
  { name: 'Solaire Resort North', category: 'Entertainment', description: 'A Quezon City destination for dining, events, and entertainment.', map_url: 'https://www.google.com/maps/search/?api=1&query=Solaire+Resort+North', image: '' },
  { name: 'Quezon Memorial Circle', category: 'City Landmark', description: 'A landmark park with green spaces, museums, and recreational attractions.', map_url: 'https://www.google.com/maps/search/?api=1&query=Quezon+Memorial+Circle', image: '' },
];

const DEFAULT_GALLERY = [
  { image: '/images/Executive%20Suite/executive_room.jpg', title: 'Executive Suite', alt: 'Executive Suite sleeping area' },
  { image: '/images/Deluxe%20Room/deluxe_room.jpg', title: 'Deluxe Room', alt: 'Deluxe Room interior' },
  { image: '/images/Superior%20Twin/superior_twin_room.jpg', title: 'Superior Twin', alt: 'Superior Twin room interior' },
  { image: '/images/Superior%20Queen/superior_queen_2.jpg', title: 'Superior Queen', alt: 'Superior Queen room interior' },
  { image: '/images/Premier%20Room/premier_room.jpg', title: 'Premier Room', alt: 'Premier Room interior' },
];

const DEFAULT_TESTIMONIALS = [];

const DEFAULT_POLICIES = [
  { title: 'Check-In Time', body: 'After 3:00 PM' },
  { title: 'Check-Out Time', body: 'Before 12:00 PM' },
  { title: 'Down Payment Policy', body: 'Down payment is required to confirm the reservation.' },
  { title: 'Cancellation Policy', body: 'If cancellation is requested within 24 hours of check-in time, it is non-refundable.' },
  { title: 'Request Limit', body: 'Guests may submit only one cancellation request and one rebooking request.' },
  { title: 'Rebooking Policy', body: 'Rebooking requests may have rate differences and require staff approval.' },
  { title: 'ID Requirement', body: 'Please present a valid government-issued ID during check-in.' },
];

const DEFAULT_PRIVACY_TERMS =
  'We collect guest information such as your name, email, contact number, and booking details to process reservations, payments, and support requests. We use this data only for reservation management, guest communication, compliance, and service improvements. Your information is protected with appropriate administrative and technical safeguards, and we do not sell personal data. If you have questions or concerns about your data, please contact our support team through the hotel contact details provided on this website.';

const DEFAULT_BOOKING_CONDITIONS =
  'Reservations are confirmed only after required payment and verification steps are completed. Published rates, inclusions, and availability are subject to validation at the time of booking. Cancellation, rebooking, and no-show handling follow the active hotel policy shown during booking and in confirmation communications. Check-in and check-out schedules must be observed, and guests are required to present a valid government-issued ID upon arrival.';

const DEFAULT_BRAND = {
  primary: '#1a4bcc',
  accent: '#0d1b3e',
  button: '#1a4bcc',
  buttonHover: '#1340b8',
};

const DEFAULT_ABOUT = {
  eyebrow: 'ABOUT H+ HOTEL',
  title: 'A STAY BUILT AROUND COMFORT.',
  description: 'H+ Hotel QC offers clean, cozy, and thoughtfully prepared rooms for guests who value comfort, convenience, and straightforward service.',
  secondaryText: 'Located near key shopping, dining, and entertainment destinations in Quezon City, we make it easy to settle in, recharge, and enjoy the city at your own pace.',
  image: '/images/Executive%20Suite/executive_2.jpg',
};

const showToast = (message, type = 'success') => {
  const existing = document.getElementById('cms-toast');
  if (existing) existing.remove();

  const color = type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#1a4bcc';
  const toast = document.createElement('div');
  toast.id = 'cms-toast';
  toast.style.cssText = `position:fixed;top:20px;right:20px;padding:12px 18px;border-radius:8px;color:#fff;font-size:14px;font-weight:600;z-index:9999;box-shadow:0 8px 30px rgba(0,0,0,0.18);background:${color};transition:opacity 0.3s;`;
  toast.textContent = message;
  document.body.appendChild(toast);

  setTimeout(() => {
    toast.style.opacity = '0';
    setTimeout(() => toast.remove(), 300);
  }, 2800);
};

const parseJsonArray = (raw, fallback = []) => {
  if (Array.isArray(raw)) return raw;
  if (typeof raw !== 'string' || raw.trim() === '') return fallback;

  try {
    const parsed = JSON.parse(raw);
    return Array.isArray(parsed) ? parsed : fallback;
  } catch {
    return fallback;
  }
};

const normalizeHighlights = (items) => {
  if (!Array.isArray(items)) return DEFAULT_HIGHLIGHTS;
  return items
    .filter((item) => item && typeof item === 'object')
    .map((item) => ({
      title: String(item.title ?? ''),
      description: String(item.description ?? ''),
    }));
};

const Input = ({ label, value, onChange, placeholder = '', hint = '', type = 'text', disabled = false }) => (
  <div style={{ marginBottom: '0.95rem' }}>
    <label style={{ display: 'block', fontSize: '0.69rem', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.6px', color: COLORS.muted, marginBottom: 5 }}>
      {label}
    </label>
    <input
      type={type}
      value={value ?? ''}
      onChange={(e) => onChange(e.target.value)}
      placeholder={placeholder}
      disabled={disabled}
      style={{ width: '100%', border: `1px solid ${COLORS.border}`, borderRadius: 6, padding: '9px 12px', fontFamily: 'inherit', fontSize: '0.9rem', color: COLORS.text, background: disabled ? '#f1f5f9' : COLORS.surface, cursor: disabled ? 'not-allowed' : 'text' }}
    />
    {hint && <p style={{ fontSize: '0.74rem', color: COLORS.muted, marginTop: 4 }}>{hint}</p>}
  </div>
);

const TextArea = ({ label, value, onChange, placeholder = '', hint = '', disabled = false }) => (
  <div style={{ marginBottom: '0.95rem' }}>
    <label style={{ display: 'block', fontSize: '0.69rem', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.6px', color: COLORS.muted, marginBottom: 5 }}>
      {label}
    </label>
    <textarea
      value={value ?? ''}
      onChange={(e) => onChange(e.target.value)}
      placeholder={placeholder}
      rows={3}
      disabled={disabled}
      style={{ width: '100%', border: `1px solid ${COLORS.border}`, borderRadius: 6, padding: '9px 12px', fontFamily: 'inherit', fontSize: '0.9rem', color: COLORS.text, background: disabled ? '#f1f5f9' : COLORS.surface, resize: disabled ? 'none' : 'vertical', cursor: disabled ? 'not-allowed' : 'text' }}
    />
    {hint && <p style={{ fontSize: '0.74rem', color: COLORS.muted, marginTop: 4 }}>{hint}</p>}
  </div>
);

const SectionCard = ({ title, children, right = null }) => (
  <div style={{ background: COLORS.surface, border: `1px solid ${COLORS.border}`, borderRadius: 8, padding: '1.15rem', marginBottom: '1rem' }}>
    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '0.75rem', marginBottom: '0.8rem' }}>
      <div style={{ fontSize: '0.72rem', color: COLORS.muted, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.8px' }}>{title}</div>
      {right}
    </div>
    {children}
  </div>
);

const SaveButton = ({ saving, saved, onClick }) => (
  <button
    onClick={onClick}
    disabled={saving}
    style={{
      display: 'inline-flex',
      alignItems: 'center',
      gap: 8,
      background: saved ? COLORS.successDark : COLORS.success,
      color: '#fff',
      border: 'none',
      borderRadius: 8,
      padding: '10px 20px',
      fontWeight: 700,
      fontSize: '0.88rem',
      cursor: saving ? 'not-allowed' : 'pointer',
      boxShadow: '0 2px 8px rgba(22,163,74,0.25)',
      fontFamily: 'inherit',
    }}
  >
    {saving ? <Loader2 size={14} style={{ animation: 'spin 1s linear infinite' }} /> : saved ? <CheckCircle size={14} /> : <Save size={14} />}
    {saving ? 'Saving...' : saved ? 'Saved' : 'Save Changes'}
  </button>
);

const RowActions = ({ onMoveUp, onMoveDown, onDelete, disableUp, disableDown }) => (
  <div style={{ display: 'flex', gap: 6 }}>
    <button type="button" onClick={onMoveUp} disabled={disableUp} style={{ border: `1px solid ${COLORS.border}`, background: COLORS.surface, color: COLORS.textSecondary, borderRadius: 6, padding: '6px 8px', cursor: disableUp ? 'not-allowed' : 'pointer' }}><ArrowUp size={13} /></button>
    <button type="button" onClick={onMoveDown} disabled={disableDown} style={{ border: `1px solid ${COLORS.border}`, background: COLORS.surface, color: COLORS.textSecondary, borderRadius: 6, padding: '6px 8px', cursor: disableDown ? 'not-allowed' : 'pointer' }}><ArrowDown size={13} /></button>
    <button type="button" onClick={onDelete} style={{ border: '1px solid #fecaca', background: '#fff1f2', color: '#dc2626', borderRadius: 6, padding: '6px 8px', cursor: 'pointer' }}><Trash2 size={13} /></button>
  </div>
);

const moveItem = (items, index, direction) => {
  const next = [...items];
  const target = index + direction;
  if (target < 0 || target >= items.length) return items;
  const temp = next[index];
  next[index] = next[target];
  next[target] = temp;
  return next;
};

const normalizeAddonItems = (items) => {
  const byId = new Map((items || []).map((item) => [String(item.id), item]));
  return ADDON_CATALOG.map((addon) => {
    const existing = byId.get(addon.id) || {};
    return {
      id: addon.id,
      name: addon.name,
      display_description: existing.display_description ?? '',
      image: existing.image ?? '',
    };
  });
};

const createContentSignature = ({ settings, highlights, stats, nearby, gallery, testimonials, policies, addons }) => JSON.stringify({
  settings,
  highlights,
  stats,
  nearby,
  gallery,
  testimonials,
  policies,
  addons,
});

export default function AdminCms() {
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [tab, setTab] = useState('hero');
  const [activeRoomType, setActiveRoomType] = useState('executive_suite');
  const [availableFeedbackCount, setAvailableFeedbackCount] = useState(0);
  const [revision, setRevision] = useState('');
  const [savedContentSignature, setSavedContentSignature] = useState('');

  const [settings, setSettings] = useState({});
  const [highlights, setHighlights] = useState(DEFAULT_HIGHLIGHTS);
  const [stats, setStats] = useState(DEFAULT_STATS);
  const [nearby, setNearby] = useState(DEFAULT_NEARBY);
  const [gallery, setGallery] = useState(DEFAULT_GALLERY);
  const [testimonials, setTestimonials] = useState(DEFAULT_TESTIMONIALS);
  const [policies, setPolicies] = useState(DEFAULT_POLICIES);
  const [addons, setAddons] = useState(normalizeAddonItems([]));
  const [pendingTestimonialScrollIndex, setPendingTestimonialScrollIndex] = useState(null);

  const heroImageRef = useRef(null);
  const aboutImageRef = useRef(null);
  const statsImageRef = useRef(null);

  const loadSettings = useCallback(async () => {
    try {
      const res = await api.get('/admin/cms');
      const rows = res.data?.data ?? [];
      const availableCount = Number(res.data?.meta?.available_feedback_testimonials ?? 0);
      const serverRevision = String(res.data?.meta?.revision ?? '');
      const mapped = {};
      rows.forEach((item) => {
        mapped[item.key] = item.value ?? '';
      });
      setSettings(mapped);
      setAvailableFeedbackCount(Number.isFinite(availableCount) ? availableCount : 0);
      setRevision(serverRevision);

      const hasSetting = (key) => Object.prototype.hasOwnProperty.call(mapped, key);

      const loadedHighlights = hasSetting('highlights_items')
        ? normalizeHighlights(parseJsonArray(mapped.highlights_items, []))
        : DEFAULT_HIGHLIGHTS;
      const loadedStats = hasSetting('stats_items') ? parseJsonArray(mapped.stats_items, []) : DEFAULT_STATS;
      const loadedNearby = hasSetting('nearby_items') ? parseJsonArray(mapped.nearby_items, []) : DEFAULT_NEARBY;
      const loadedGallery = hasSetting('gallery_items') ? parseJsonArray(mapped.gallery_items, []) : DEFAULT_GALLERY;
      const loadedTestimonials = hasSetting('testimonials_items')
        ? parseJsonArray(mapped.testimonials_items, [])
        : DEFAULT_TESTIMONIALS;
      const loadedPolicies = hasSetting('policies_items') ? parseJsonArray(mapped.policies_items, []) : DEFAULT_POLICIES;
      const loadedAddons = normalizeAddonItems(parseJsonArray(mapped.addons_items, []));

      setHighlights(loadedHighlights);
      setStats(loadedStats);
      setNearby(loadedNearby);
      setGallery(loadedGallery);
      setTestimonials(loadedTestimonials);
      setPolicies(loadedPolicies);
      setAddons(loadedAddons);
      setSavedContentSignature(createContentSignature({
        settings: mapped,
        highlights: loadedHighlights,
        stats: loadedStats,
        nearby: loadedNearby,
        gallery: loadedGallery,
        testimonials: loadedTestimonials,
        policies: loadedPolicies,
        addons: loadedAddons,
      }));
    } catch {
      showToast('Failed to load CMS settings.', 'error');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadSettings();
  }, [loadSettings]);

  const setField = (key, value) => {
    setSettings((prev) => ({ ...prev, [key]: value }));
  };

  useEffect(() => {
    if (pendingTestimonialScrollIndex === null || tab !== 'testimonials') return;

    const target = document.getElementById(`testimonial-card-${pendingTestimonialScrollIndex}`);
    if (target) {
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      setPendingTestimonialScrollIndex(null);
    }
  }, [pendingTestimonialScrollIndex, testimonials, tab]);

  const addTestimonial = () => {
    const nextIndex = testimonials.length;
    setTestimonials((prev) => [
      ...prev,
      { guest_name: '', review_text: '', star_rating: 5, date: '', is_active: true, source: 'manual', feedback_id: null },
    ]);
    setPendingTestimonialScrollIndex(nextIndex);
  };

  const uploadImage = async ({ file, onSuccess }) => {
    if (!file) return;

    setUploading(true);
    try {
      const fd = new FormData();
      fd.append('image', file);

      const res = await api.post('/admin/cms/upload-image', fd, { headers: { 'Content-Type': 'multipart/form-data' } });

      const url = res.data?.url;
      if (url) {
        if (onSuccess) onSuccess(url);
        showToast('Image uploaded.', 'success');
      }
    } catch (error) {
      const status = error?.response?.status;
      const message =
        error?.response?.data?.message ||
        error?.response?.data?.errors?.image?.[0] ||
        error?.message ||
        'Image upload failed.';
      showToast(`Image upload failed${status ? ` (${status})` : ''}: ${message}`, 'error');
    } finally {
      setUploading(false);
    }
  };

  const saveAll = async () => {
    if (!revision) {
      showToast('CMS data is not synchronized. Reload the page before saving.', 'error');
      return;
    }

    setSaving(true);
    try {
      const getPolicyValue = (needle, fallback = '') => {
        const match = policies.find((policy) => String(policy.title || '').toLowerCase().includes(needle));
        return match?.body || fallback;
      };

      const payloadSettings = {
        ...settings,
        highlights_items: JSON.stringify(highlights.map((item) => ({
          title: String(item.title ?? ''),
          description: String(item.description ?? ''),
        }))),
        stats_items: JSON.stringify(stats),
        nearby_items: JSON.stringify(nearby),
        gallery_items: JSON.stringify(gallery),
        testimonials_items: JSON.stringify(testimonials),
        policies_items: JSON.stringify(policies),
        addons_items: JSON.stringify(addons),
        policy_checkin: getPolicyValue('check-in', settings.policy_checkin || ''),
        policy_checkout: getPolicyValue('check-out', settings.policy_checkout || ''),
        policy_downpayment: getPolicyValue('down payment', settings.policy_downpayment || ''),
        policy_cancellation: getPolicyValue('cancellation', settings.policy_cancellation || ''),
        policy_requests: getPolicyValue('request', settings.policy_requests || ''),
        policy_rebooking: getPolicyValue('rebooking', settings.policy_rebooking || ''),
        policy_id: getPolicyValue('id requirement', settings.policy_id || ''),
        policy_privacy_terms: (settings.policy_privacy_terms || DEFAULT_PRIVACY_TERMS),
        policy_booking_conditions: (settings.policy_booking_conditions || DEFAULT_BOOKING_CONDITIONS),
      };

      const payload = Object.entries(payloadSettings)
        .filter(([key]) => !!key)
        .map(([key, value]) => ({ key, value: value == null ? '' : String(value) }));

      await api.put('/admin/cms', { settings: payload, revision });
      await loadSettings();
      setSaved(true);
      showToast('CMS changes saved.', 'success');
      setTimeout(() => setSaved(false), 2200);
    } catch (error) {
      if (error?.response?.status === 409 && error?.response?.data?.error_code === 'CMS_REVISION_CONFLICT') {
        showToast('Content changed in another session. Reload this page before saving again.', 'error');
      } else {
        const validationErrors = error?.response?.data?.errors;
        const firstValidationMessage = validationErrors
          ? Object.values(validationErrors).flat().find(Boolean)
          : null;
        showToast(firstValidationMessage || error?.response?.data?.message || 'Failed to save CMS settings.', 'error');
      }
    } finally {
      setSaving(false);
    }
  };

  const tabGroups = [
    {
      label: 'Homepage',
      items: [
        { key: 'hero', label: 'Hero', icon: LayoutGrid },
        { key: 'about', label: 'About Us', icon: Info },
        { key: 'highlights', label: 'Highlights', icon: LayoutGrid },
        { key: 'stats', label: 'Statistics', icon: BarChart2 },
        { key: 'gallery', label: 'Gallery', icon: Images },
        { key: 'nearby', label: 'Nearby Places', icon: MapPin },
        { key: 'testimonials', label: 'Testimonials', icon: Star },
      ],
    },
    {
      label: 'Hotel Content',
      items: [
        { key: 'rooms', label: 'Rooms', icon: BedDouble },
        { key: 'addons', label: 'Add-Ons', icon: Package },
      ],
    },
    {
      label: 'Site Information',
      items: [
        { key: 'policies', label: 'Policies', icon: Shield },
        { key: 'location', label: 'Location', icon: MapPin },
        { key: 'brand', label: 'Brand', icon: Palette },
      ],
    },
  ];

  const tabs = tabGroups.flatMap((group) => group.items);
  const activeTab = tabs.find((item) => item.key === tab) || tabs[0];

  const brandPreview = useMemo(
    () => ({
      primary: settings.brand_primary_color || DEFAULT_BRAND.primary,
      accent: settings.brand_accent_color || DEFAULT_BRAND.accent,
      button: settings.brand_button_color || DEFAULT_BRAND.button,
      buttonHover: settings.brand_button_hover_color || DEFAULT_BRAND.buttonHover,
    }),
    [settings]
  );

  const currentContentSignature = useMemo(() => createContentSignature({
    settings,
    highlights,
    stats,
    nearby,
    gallery,
    testimonials,
    policies,
    addons,
  }), [settings, highlights, stats, nearby, gallery, testimonials, policies, addons]);
  const hasUnsavedChanges = Boolean(savedContentSignature) && currentContentSignature !== savedContentSignature;

  useEffect(() => {
    if (!hasUnsavedChanges) return undefined;

    const warnBeforeLeaving = (event) => {
      event.preventDefault();
      event.returnValue = '';
    };

    window.addEventListener('beforeunload', warnBeforeLeaving);
    return () => window.removeEventListener('beforeunload', warnBeforeLeaving);
  }, [hasUnsavedChanges]);

  if (loading) {
    return (
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 10, padding: '4rem', color: COLORS.muted }}>
        <Loader2 size={18} style={{ animation: 'spin 1s linear infinite', color: COLORS.blue }} />
        <span>Loading content settings...</span>
        <style>{'@keyframes spin{to{transform:rotate(360deg)}}'}</style>
      </div>
    );
  }

  return (
    <div className="cms-page">
      <style>{'@keyframes spin{to{transform:rotate(360deg)}}'}</style>

      <div className="cms-page-header">
        <span style={{ fontSize: '0.68rem', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '1px', color: COLORS.blue, background: 'rgba(26,75,204,0.08)', padding: '3px 10px', borderRadius: 4, border: '1px solid rgba(26,75,204,0.25)' }}>
          Web Content Management
        </span>
        <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: '1rem', flexWrap: 'wrap', marginTop: '0.65rem' }}>
          <div>
            <h1 style={{ margin: 0, color: COLORS.text, fontSize: '1.5rem', fontWeight: 700 }}>Content Management</h1>
            <p style={{ margin: '0.35rem 0 0', color: COLORS.textSecondary, fontSize: '0.88rem' }}>
              Manage all guest-facing sections from one CMS screen.
            </p>
          </div>
          <div className="cms-header-save">
            <span className={`cms-save-status${hasUnsavedChanges ? ' has-changes' : ''}`}>
              {hasUnsavedChanges ? 'Unsaved changes' : 'All changes saved'}
            </span>
            <SaveButton saving={saving} saved={saved} onClick={saveAll} />
          </div>
        </div>
      </div>

      <div className="cms-workspace">
        <aside className="cms-section-navigation" aria-label="Content management sections">
          <div className="cms-mobile-navigation">
            <label htmlFor="cms-section-select">Content section</label>
            <select id="cms-section-select" value={tab} onChange={(event) => setTab(event.target.value)}>
              {tabGroups.map((group) => (
                <optgroup key={group.label} label={group.label}>
                  {group.items.map((item) => (
                    <option key={item.key} value={item.key}>{item.label}</option>
                  ))}
                </optgroup>
              ))}
            </select>
          </div>

          <div className="cms-desktop-navigation">
            <div className="cms-navigation-title">Content Sections</div>
            {tabGroups.map((group) => (
              <div className="cms-navigation-group" key={group.label}>
                <div className="cms-navigation-group-label">{group.label}</div>
                {group.items.map(({ key, label, icon: Icon }) => {
                  const active = tab === key;
                  const showFeedbackBadge = key === 'testimonials' && availableFeedbackCount > 0;

                  return (
                    <button
                      className={`cms-navigation-item${active ? ' is-active' : ''}`}
                      key={key}
                      type="button"
                      onClick={() => setTab(key)}
                      aria-current={active ? 'page' : undefined}
                    >
                      <Icon size={16} aria-hidden="true" />
                      <span>{label}</span>
                      {showFeedbackBadge && (
                        <span className="cms-navigation-badge" aria-label={`${availableFeedbackCount} testimonials available`}>
                          {availableFeedbackCount}
                        </span>
                      )}
                    </button>
                  );
                })}
              </div>
            ))}
          </div>
        </aside>

        <main className="cms-section-content">
          <div className="cms-section-heading">
            <div>
              <span>Editing Section</span>
              <h2>{activeTab.label}</h2>
            </div>
            {tab === 'testimonials' && availableFeedbackCount > 0 && (
              <div className="cms-available-feedback">{availableFeedbackCount} guest feedback available</div>
            )}
          </div>

      {tab === 'hero' && (
        <>
          <SectionCard title="Hero Content">
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 1rem' }}>
              <Input label="Title Line 1" value={settings.hero_title_line1 || ''} onChange={(v) => setField('hero_title_line1', v)} placeholder="YOUR COMFORT," />
              <Input label="Title Line 2 (accent)" value={settings.hero_title_line2 || ''} onChange={(v) => setField('hero_title_line2', v)} placeholder="OUR PRIORITY." />
            </div>
            <TextArea label="Subtitle" value={settings.hero_subtitle || ''} onChange={(v) => setField('hero_subtitle', v)} placeholder="Redefining hotel stays through seamless booking..." />
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 1rem' }}>
              <Input label="Primary Button Text" value={settings.hero_primary_button_text || ''} onChange={(v) => setField('hero_primary_button_text', v)} placeholder="BOOK YOUR STAY" />
              <Input label="Primary Button Link" value={settings.hero_primary_button_link || ''} onChange={(v) => setField('hero_primary_button_link', v)} placeholder="/select-room" />
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 1rem' }}>
              <Input label="Secondary Button Text" value={settings.hero_secondary_button_text || ''} onChange={(v) => setField('hero_secondary_button_text', v)} placeholder="EXPLORE ROOMS" />
              <Input label="Secondary Button Link" value={settings.hero_secondary_button_link || ''} onChange={(v) => setField('hero_secondary_button_link', v)} placeholder="/rooms" />
            </div>
          </SectionCard>

          <SectionCard title="Hero Image">
            <Input label="Image URL" value={settings.hero_image || ''} onChange={(v) => setField('hero_image', v)} placeholder="https://..." />
            <input ref={heroImageRef} type="file" accept="image/*" style={{ display: 'none' }} onChange={(e) => uploadImage({ file: e.target.files?.[0], onSuccess: (url) => setField('hero_image', url) })} />
            <button type="button" onClick={() => heroImageRef.current?.click()} disabled={uploading} style={{ border: `1px solid ${COLORS.border}`, background: COLORS.bg, color: COLORS.text, borderRadius: 6, padding: '8px 12px', cursor: uploading ? 'not-allowed' : 'pointer', display: 'inline-flex', alignItems: 'center', gap: 6, fontFamily: 'inherit' }}>
              {uploading ? <Loader2 size={13} style={{ animation: 'spin 1s linear infinite' }} /> : <Upload size={13} />}
              {uploading ? 'Uploading...' : 'Upload Image'}
            </button>
            {settings.hero_image && (
              <div style={{ marginTop: 10, border: `1px solid ${COLORS.border}`, borderRadius: 8, overflow: 'hidden', maxWidth: 560 }}>
                <img src={settings.hero_image} alt="Hero Preview" style={{ width: '100%', height: 220, objectFit: 'cover' }} />
              </div>
            )}
          </SectionCard>
        </>
      )}

      {tab === 'about' && (
        <>
          <SectionCard title="About Us Content">
            <Input label="Section Label" value={settings.about_eyebrow ?? DEFAULT_ABOUT.eyebrow} onChange={(value) => setField('about_eyebrow', value)} placeholder="ABOUT H+ HOTEL" />
            <Input label="Heading" value={settings.about_title ?? DEFAULT_ABOUT.title} onChange={(value) => setField('about_title', value)} placeholder="A STAY BUILT AROUND COMFORT." />
            <TextArea label="Introduction" value={settings.about_description ?? DEFAULT_ABOUT.description} onChange={(value) => setField('about_description', value)} placeholder="Introduce H+ Hotel to your guests." />
            <TextArea label="Supporting Description" value={settings.about_secondary_text ?? DEFAULT_ABOUT.secondaryText} onChange={(value) => setField('about_secondary_text', value)} placeholder="Describe the location and guest experience." />
          </SectionCard>

          <SectionCard title="About Us Image">
            <Input label="Image URL" value={settings.about_image ?? DEFAULT_ABOUT.image} onChange={(value) => setField('about_image', value)} placeholder="https://..." />
            <input ref={aboutImageRef} type="file" accept="image/*" style={{ display: 'none' }} onChange={(event) => uploadImage({ file: event.target.files?.[0], onSuccess: (url) => setField('about_image', url) })} />
            <button type="button" onClick={() => aboutImageRef.current?.click()} disabled={uploading} style={{ border: `1px solid ${COLORS.border}`, background: COLORS.bg, color: COLORS.text, borderRadius: 6, padding: '8px 12px', cursor: uploading ? 'not-allowed' : 'pointer', display: 'inline-flex', alignItems: 'center', gap: 6, fontFamily: 'inherit' }}>
              {uploading ? <Loader2 size={13} style={{ animation: 'spin 1s linear infinite' }} /> : <Upload size={13} />}
              {uploading ? 'Uploading...' : 'Upload Image'}
            </button>
            {(settings.about_image ?? DEFAULT_ABOUT.image) && (
              <div style={{ marginTop: 10, border: `1px solid ${COLORS.border}`, borderRadius: 8, overflow: 'hidden', maxWidth: 560 }}>
                <img src={settings.about_image ?? DEFAULT_ABOUT.image} alt="About section preview" style={{ width: '100%', height: 260, objectFit: 'cover' }} />
              </div>
            )}
          </SectionCard>
        </>
      )}

      {tab === 'highlights' && (
        <SectionCard
          title="Highlights List"
          right={
            <button type="button" onClick={() => setHighlights((prev) => [...prev, { title: '', description: '' }])} style={{ border: `1px solid ${COLORS.blue}`, background: '#eef2ff', color: COLORS.blue, borderRadius: 6, padding: '6px 10px', cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 6, fontFamily: 'inherit' }}>
              <Plus size={13} /> Add Highlight
            </button>
          }
        >
          {highlights.map((item, index) => (
            <div key={`highlight-${index}`} style={{ border: `1px solid ${COLORS.border}`, borderRadius: 8, padding: '0.9rem', marginBottom: '0.8rem' }}>
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '0.5rem' }}>
                <strong style={{ color: COLORS.text, fontSize: '0.85rem' }}>Highlight #{index + 1}</strong>
                <RowActions
                  onMoveUp={() => setHighlights((prev) => moveItem(prev, index, -1))}
                  onMoveDown={() => setHighlights((prev) => moveItem(prev, index, 1))}
                  onDelete={() => setHighlights((prev) => prev.filter((_, i) => i !== index))}
                  disableUp={index === 0}
                  disableDown={index === highlights.length - 1}
                />
              </div>
              <Input label="Title" value={item.title || ''} onChange={(v) => setHighlights((prev) => prev.map((row, i) => (i === index ? { ...row, title: v } : row)))} placeholder="FAST BOOKING" />
              <TextArea label="Description" value={item.description || ''} onChange={(v) => setHighlights((prev) => prev.map((row, i) => (i === index ? { ...row, description: v } : row)))} placeholder="Describe this highlight..." />
            </div>
          ))}
        </SectionCard>
      )}
      {tab === 'stats' && (
        <SectionCard
          title="Stats List"
          right={
            <button type="button" onClick={() => setStats((prev) => [...prev, { value: '', label: '' }])} style={{ border: `1px solid ${COLORS.blue}`, background: '#eef2ff', color: COLORS.blue, borderRadius: 6, padding: '6px 10px', cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 6, fontFamily: 'inherit' }}>
              <Plus size={13} /> Add Stat
            </button>
          }
        >
          <Input label="Stats Heading" value={settings.stats_heading || ''} onChange={(v) => setField('stats_heading', v)} placeholder="BUILT FOR EFFICIENCY" />
          <Input label="Stats Image URL" value={settings.stats_image || ''} onChange={(v) => setField('stats_image', v)} placeholder="https://..." />
          <input ref={statsImageRef} type="file" accept="image/*" style={{ display: 'none' }} onChange={(e) => uploadImage({ file: e.target.files?.[0], onSuccess: (url) => setField('stats_image', url) })} />
          <button type="button" onClick={() => statsImageRef.current?.click()} disabled={uploading} style={{ border: `1px solid ${COLORS.border}`, background: COLORS.bg, color: COLORS.text, borderRadius: 6, padding: '8px 12px', cursor: uploading ? 'not-allowed' : 'pointer', display: 'inline-flex', alignItems: 'center', gap: 6, fontFamily: 'inherit', marginBottom: '0.8rem' }}>
            {uploading ? <Loader2 size={13} style={{ animation: 'spin 1s linear infinite' }} /> : <Upload size={13} />}
            {uploading ? 'Uploading...' : 'Upload Stats Image'}
          </button>

          {stats.map((item, index) => (
            <div key={`stat-${index}`} style={{ border: `1px solid ${COLORS.border}`, borderRadius: 8, padding: '0.9rem', marginBottom: '0.8rem' }}>
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '0.5rem' }}>
                <strong style={{ color: COLORS.text, fontSize: '0.85rem' }}>Stat #{index + 1}</strong>
                <RowActions
                  onMoveUp={() => setStats((prev) => moveItem(prev, index, -1))}
                  onMoveDown={() => setStats((prev) => moveItem(prev, index, 1))}
                  onDelete={() => setStats((prev) => prev.filter((_, i) => i !== index))}
                  disableUp={index === 0}
                  disableDown={index === stats.length - 1}
                />
              </div>
              <Input label="Value" value={item.value || ''} onChange={(v) => setStats((prev) => prev.map((row, i) => (i === index ? { ...row, value: v } : row)))} placeholder="24/7" />
              <Input label="Label" value={item.label || ''} onChange={(v) => setStats((prev) => prev.map((row, i) => (i === index ? { ...row, label: v } : row)))} placeholder="FRONT DESK SUPPORT" />
            </div>
          ))}
        </SectionCard>
      )}

      {tab === 'gallery' && (
        <SectionCard
          title="Homepage Gallery"
          right={
            <button type="button" onClick={() => setGallery((prev) => [...prev, { image: '', title: '', alt: '' }])} style={{ border: `1px solid ${COLORS.blue}`, background: '#eef2ff', color: COLORS.blue, borderRadius: 6, padding: '6px 10px', cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 6, fontFamily: 'inherit' }}>
              <Plus size={13} /> Add Photo
            </button>
          }
        >
          <Input label="Section Title" value={settings.gallery_title || ''} onChange={(value) => setField('gallery_title', value)} placeholder="A CLOSER LOOK" />
          <TextArea label="Description" value={settings.gallery_description || ''} onChange={(value) => setField('gallery_description', value)} placeholder="Introduce the hotel gallery..." />
          <p style={{ margin: '-0.25rem 0 1rem', color: COLORS.muted, fontSize: '0.76rem' }}>The first five photos appear on the homepage. The first photo receives the largest position.</p>

          {gallery.map((item, index) => (
            <div key={`gallery-${index}`} style={{ border: `1px solid ${COLORS.border}`, borderRadius: 8, padding: '0.9rem', marginBottom: '0.8rem' }}>
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '0.5rem' }}>
                <strong style={{ color: COLORS.text, fontSize: '0.85rem' }}>Photo #{index + 1}</strong>
                <RowActions
                  onMoveUp={() => setGallery((prev) => moveItem(prev, index, -1))}
                  onMoveDown={() => setGallery((prev) => moveItem(prev, index, 1))}
                  onDelete={() => setGallery((prev) => prev.filter((_, itemIndex) => itemIndex !== index))}
                  disableUp={index === 0}
                  disableDown={index === gallery.length - 1}
                />
              </div>
              <div style={{ display: 'grid', gridTemplateColumns: 'minmax(180px, 260px) 1fr', gap: '1rem', alignItems: 'start' }}>
                <div>
                  <div style={{ height: 150, border: `1px solid ${COLORS.border}`, background: COLORS.bg, overflow: 'hidden', marginBottom: '0.65rem' }}>
                    {item.image ? <img src={item.image} alt={item.alt || item.title || `Gallery ${index + 1}`} style={{ width: '100%', height: '100%', objectFit: 'cover' }} /> : null}
                  </div>
                  <button
                    type="button"
                    disabled={uploading}
                    onClick={() => {
                      const input = document.createElement('input');
                      input.type = 'file';
                      input.accept = 'image/*';
                      input.onchange = (event) => uploadImage({
                        file: event.target.files?.[0],
                        onSuccess: (url) => setGallery((prev) => prev.map((row, itemIndex) => (itemIndex === index ? { ...row, image: url } : row))),
                      });
                      input.click();
                    }}
                    style={{ width: '100%', border: `1px solid ${COLORS.border}`, background: COLORS.bg, color: COLORS.text, borderRadius: 6, padding: '8px 12px', cursor: uploading ? 'not-allowed' : 'pointer', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 6, fontFamily: 'inherit' }}
                  >
                    {uploading ? <Loader2 size={13} style={{ animation: 'spin 1s linear infinite' }} /> : <Upload size={13} />}
                    {uploading ? 'Uploading...' : 'Upload Photo'}
                  </button>
                </div>
                <div>
                  <Input label="Image URL" value={item.image || ''} onChange={(value) => setGallery((prev) => prev.map((row, itemIndex) => (itemIndex === index ? { ...row, image: value } : row)))} placeholder="/storage/cms/... or https://..." />
                  <Input label="Photo Title" value={item.title || ''} onChange={(value) => setGallery((prev) => prev.map((row, itemIndex) => (itemIndex === index ? { ...row, title: value } : row)))} placeholder="Executive Suite" />
                  <Input label="Alternative Text" value={item.alt || ''} onChange={(value) => setGallery((prev) => prev.map((row, itemIndex) => (itemIndex === index ? { ...row, alt: value } : row)))} placeholder="Describe the photo for accessibility" />
                </div>
              </div>
            </div>
          ))}
        </SectionCard>
      )}

      {tab === 'nearby' && (
        <SectionCard
          title="Nearby Places and Landmarks"
          right={
            <button type="button" onClick={() => setNearby((prev) => [...prev, { name: '', category: '', description: '', map_url: '', image: '' }])} style={{ border: `1px solid ${COLORS.blue}`, background: '#eef2ff', color: COLORS.blue, borderRadius: 6, padding: '6px 10px', cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 6, fontFamily: 'inherit' }}>
              <Plus size={13} /> Add Place
            </button>
          }
        >
          <Input label="Section Title" value={settings.nearby_title || ''} onChange={(value) => setField('nearby_title', value)} placeholder="EXPLORE THE NEIGHBORHOOD" />
          <TextArea label="Description" value={settings.nearby_description || ''} onChange={(value) => setField('nearby_description', value)} placeholder="Introduce nearby destinations..." />

          {nearby.map((item, index) => (
            <div key={`nearby-${index}`} style={{ border: `1px solid ${COLORS.border}`, borderRadius: 8, padding: '0.9rem', marginBottom: '0.8rem' }}>
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '0.5rem' }}>
                <strong style={{ color: COLORS.text, fontSize: '0.85rem' }}>Place #{index + 1}</strong>
                <RowActions
                  onMoveUp={() => setNearby((prev) => moveItem(prev, index, -1))}
                  onMoveDown={() => setNearby((prev) => moveItem(prev, index, 1))}
                  onDelete={() => setNearby((prev) => prev.filter((_, itemIndex) => itemIndex !== index))}
                  disableUp={index === 0}
                  disableDown={index === nearby.length - 1}
                />
              </div>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0 1rem' }}>
                <Input label="Place Name" value={item.name || ''} onChange={(value) => setNearby((prev) => prev.map((row, itemIndex) => (itemIndex === index ? { ...row, name: value } : row)))} placeholder="SM North EDSA" />
                <Input label="Category" value={item.category || ''} onChange={(value) => setNearby((prev) => prev.map((row, itemIndex) => (itemIndex === index ? { ...row, category: value } : row)))} placeholder="Shopping & Dining" />
              </div>
              <TextArea label="Description" value={item.description || ''} onChange={(value) => setNearby((prev) => prev.map((row, itemIndex) => (itemIndex === index ? { ...row, description: value } : row)))} placeholder="Short guest-facing description..." />
              <div style={{ display: 'grid', gridTemplateColumns: 'minmax(180px, 260px) 1fr', gap: '1rem', alignItems: 'end' }}>
                <div style={{ height: 130, border: `1px solid ${COLORS.border}`, background: COLORS.bg, overflow: 'hidden', marginBottom: '0.95rem' }}>
                  {item.image ? <img src={item.image} alt={item.name || `Nearby place ${index + 1}`} style={{ width: '100%', height: '100%', objectFit: 'cover' }} /> : null}
                </div>
                <div>
                  <Input label="Card Image URL" value={item.image || ''} onChange={(value) => setNearby((prev) => prev.map((row, itemIndex) => (itemIndex === index ? { ...row, image: value } : row)))} placeholder="/storage/cms/... or https://..." />
                  <button
                    type="button"
                    disabled={uploading}
                    onClick={() => {
                      const input = document.createElement('input');
                      input.type = 'file';
                      input.accept = 'image/*';
                      input.onchange = (event) => uploadImage({
                        file: event.target.files?.[0],
                        onSuccess: (url) => setNearby((prev) => prev.map((row, itemIndex) => (itemIndex === index ? { ...row, image: url } : row))),
                      });
                      input.click();
                    }}
                    style={{ border: `1px solid ${COLORS.border}`, background: COLORS.bg, color: COLORS.text, borderRadius: 6, padding: '8px 12px', cursor: uploading ? 'not-allowed' : 'pointer', display: 'inline-flex', alignItems: 'center', gap: 6, fontFamily: 'inherit', marginBottom: '0.95rem' }}
                  >
                    {uploading ? <Loader2 size={13} style={{ animation: 'spin 1s linear infinite' }} /> : <Upload size={13} />}
                    {uploading ? 'Uploading...' : 'Upload Card Image'}
                  </button>
                </div>
              </div>
              <Input label="Google Maps Link" value={item.map_url || ''} onChange={(value) => setNearby((prev) => prev.map((row, itemIndex) => (itemIndex === index ? { ...row, map_url: value } : row)))} placeholder="https://www.google.com/maps/..." />
            </div>
          ))}
        </SectionCard>
      )}

      {tab === 'testimonials' && (
        <SectionCard
          title="Testimonials"
          right={
            <button type="button" onClick={addTestimonial} style={{ border: `1px solid ${COLORS.blue}`, background: '#eef2ff', color: COLORS.blue, borderRadius: 6, padding: '6px 10px', cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 6, fontFamily: 'inherit' }}>
              <Plus size={13} /> Add Testimonial
            </button>
          }
        >
          <Input label="Section Title" value={settings.testimonials_title || ''} onChange={(v) => setField('testimonials_title', v)} placeholder="TRUSTED BY OUR GUESTS." />

          {testimonials.map((item, index) => {
            const isFeedbackSourced = item?.source === 'guest_feedback' || Number(item?.feedback_id) > 0;
            const isPendingFeedback = isFeedbackSourced && item?.is_active === false;

            return (
            <div
              id={`testimonial-card-${index}`}
              key={`testimonial-${index}`}
              style={{
                border: `1px solid ${COLORS.border}`,
                borderRadius: 8,
                padding: '0.9rem',
                marginBottom: '0.8rem',
                background: isPendingFeedback ? '#f8fafc' : COLORS.surface,
                opacity: isPendingFeedback ? 0.82 : 1,
              }}
            >
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '0.5rem' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  <strong style={{ color: COLORS.text, fontSize: '0.85rem' }}>Testimonial #{index + 1}</strong>
                  {isFeedbackSourced && (
                    <span
                      style={{
                        fontSize: '0.68rem',
                        fontWeight: 700,
                        color: '#475569',
                        background: '#f1f5f9',
                        border: '1px solid #cbd5e1',
                        borderRadius: 999,
                        padding: '2px 8px',
                      }}
                    >
                      From Guest Feedback
                    </span>
                  )}
                  {isPendingFeedback && (
                    <span
                      style={{
                        fontSize: '0.68rem',
                        fontWeight: 700,
                        color: '#64748b',
                        background: '#f1f5f9',
                        border: '1px solid #cbd5e1',
                        borderRadius: 999,
                        padding: '2px 8px',
                      }}
                    >
                      Pending - not visible on site
                    </span>
                  )}
                </div>
                <RowActions
                  onMoveUp={() => setTestimonials((prev) => moveItem(prev, index, -1))}
                  onMoveDown={() => setTestimonials((prev) => moveItem(prev, index, 1))}
                  onDelete={() => setTestimonials((prev) => prev.filter((_, i) => i !== index))}
                  disableUp={index === 0}
                  disableDown={index === testimonials.length - 1}
                />
              </div>

              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 160px', gap: '0 0.8rem' }}>
                <Input label="Guest Name" value={item.guest_name || ''} onChange={(v) => setTestimonials((prev) => prev.map((row, i) => (i === index ? { ...row, guest_name: v } : row)))} placeholder="Guest Name" disabled={isFeedbackSourced} />
                <Input label="Date" type="date" value={item.date || ''} onChange={(v) => setTestimonials((prev) => prev.map((row, i) => (i === index ? { ...row, date: v } : row)))} disabled={isFeedbackSourced} />
                <Input label="Star Rating" type="number" value={item.star_rating ?? 5} onChange={(v) => {
                  const rating = Math.max(1, Math.min(5, Number(v) || 1));
                  setTestimonials((prev) => prev.map((row, i) => (i === index ? { ...row, star_rating: rating } : row)));
                }} hint={isFeedbackSourced ? 'Verified guest rating - cannot be edited' : '1 to 5'} disabled={isFeedbackSourced} />
              </div>

              <TextArea label="Review Text" value={item.review_text || ''} onChange={(v) => setTestimonials((prev) => prev.map((row, i) => (i === index ? { ...row, review_text: v } : row)))} placeholder="Guest review..." hint={isFeedbackSourced ? 'Verified guest review - only publication status can be changed' : ''} disabled={isFeedbackSourced} />

              <label style={{ display: 'inline-flex', alignItems: 'center', gap: 8, fontSize: '0.82rem', color: COLORS.textSecondary }}>
                <input
                  type="checkbox"
                  checked={item.is_active !== false}
                  onChange={(e) => setTestimonials((prev) => prev.map((row, i) => (i === index ? { ...row, is_active: e.target.checked } : row)))}
                />
                Publish to site
              </label>
            </div>
          )})}
        </SectionCard>
      )}

      {tab === 'policies' && (
        <SectionCard
          title="Policies"
          right={
            <button type="button" onClick={() => setPolicies((prev) => [...prev, { title: '', body: '' }])} style={{ border: `1px solid ${COLORS.blue}`, background: '#eef2ff', color: COLORS.blue, borderRadius: 6, padding: '6px 10px', cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 6, fontFamily: 'inherit' }}>
              <Plus size={13} /> Add Policy
            </button>
          }
        >
          <TextArea
            label="Privacy Terms"
            value={settings.policy_privacy_terms || DEFAULT_PRIVACY_TERMS}
            onChange={(v) => setField('policy_privacy_terms', v)}
            placeholder="Privacy Terms content shown in Guest Details acknowledgement."
          />

          <TextArea
            label="Booking Conditions"
            value={settings.policy_booking_conditions || DEFAULT_BOOKING_CONDITIONS}
            onChange={(v) => setField('policy_booking_conditions', v)}
            placeholder="Booking Conditions content shown in Guest Details acknowledgement."
          />

          {policies.map((item, index) => (
            <div key={`policy-${index}`} style={{ border: `1px solid ${COLORS.border}`, borderRadius: 8, padding: '0.9rem', marginBottom: '0.8rem' }}>
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '0.5rem' }}>
                <strong style={{ color: COLORS.text, fontSize: '0.85rem' }}>Policy #{index + 1}</strong>
                <RowActions
                  onMoveUp={() => setPolicies((prev) => moveItem(prev, index, -1))}
                  onMoveDown={() => setPolicies((prev) => moveItem(prev, index, 1))}
                  onDelete={() => setPolicies((prev) => prev.filter((_, i) => i !== index))}
                  disableUp={index === 0}
                  disableDown={index === policies.length - 1}
                />
              </div>
              <Input label="Title" value={item.title || ''} onChange={(v) => setPolicies((prev) => prev.map((row, i) => (i === index ? { ...row, title: v } : row)))} placeholder="Policy title" />
              <TextArea label="Body" value={item.body || ''} onChange={(v) => setPolicies((prev) => prev.map((row, i) => (i === index ? { ...row, body: v } : row)))} placeholder="Policy details..." />
            </div>
          ))}
        </SectionCard>
      )}

      {tab === 'location' && (
        <SectionCard title="Location Section">
          <Input label="Section Title" value={settings.location_title || ''} onChange={(v) => setField('location_title', v)} placeholder="HOW TO GET HERE" />
          <TextArea label="Description" value={settings.location_description || ''} onChange={(v) => setField('location_description', v)} placeholder="Location intro text..." />
          <Input label="Address Line 1" value={settings.location_address1 || ''} onChange={(v) => setField('location_address1', v)} placeholder="Address line 1" />
          <Input label="Address Line 2" value={settings.location_address2 || ''} onChange={(v) => setField('location_address2', v)} placeholder="Address line 2" />
          <Input label="Address Line 3" value={settings.location_address3 || ''} onChange={(v) => setField('location_address3', v)} placeholder="Address line 3" />
          <Input label="Contact Number" value={settings.location_contact_number || ''} onChange={(v) => setField('location_contact_number', v)} placeholder="+63 ..." />
          <Input label="Contact Email" value={settings.location_contact_email || ''} onChange={(v) => setField('location_contact_email', v)} placeholder="hello@example.com" />
          <Input label="Google Maps Embed URL" value={settings.location_map_url || ''} onChange={(v) => setField('location_map_url', v)} placeholder="https://maps.google.com/..." />
        </SectionCard>
      )}
      {tab === 'rooms' && (
        <div style={{ display: 'grid', gridTemplateColumns: '200px 1fr', gap: '1rem', alignItems: 'start' }}>
          <div style={{ background: COLORS.surface, border: `1px solid ${COLORS.border}`, borderRadius: 8, overflow: 'hidden' }}>
            <div style={{ padding: '0.65rem 0.9rem', borderBottom: `1px solid ${COLORS.border}`, fontSize: '0.7rem', color: COLORS.muted, fontWeight: 700, textTransform: 'uppercase' }}>Room Types</div>
            {ROOM_TYPES.map((roomType) => {
              const active = activeRoomType === roomType.key;
              return (
                <button
                  key={roomType.key}
                  type="button"
                  onClick={() => setActiveRoomType(roomType.key)}
                  style={{ width: '100%', textAlign: 'left', border: 'none', borderBottom: `1px solid ${COLORS.bg}`, borderLeft: active ? `3px solid ${COLORS.blue}` : '3px solid transparent', background: active ? 'rgba(26,75,204,0.08)' : COLORS.surface, color: active ? COLORS.blue : COLORS.textSecondary, padding: '0.65rem 0.85rem', cursor: 'pointer', fontFamily: 'inherit', fontWeight: active ? 700 : 500 }}
                >
                  {roomType.label}
                </button>
              );
            })}
          </div>

          {ROOM_TYPES.filter((row) => row.key === activeRoomType).map((roomType) => (
            <SectionCard key={roomType.key} title={`${roomType.label} Website Copy`}>
              <Input label="Display Name" value={settings[`room_${roomType.key}_label`] || ''} onChange={(v) => setField(`room_${roomType.key}_label`, v)} placeholder={roomType.label} />
              <Input label="Tagline" value={settings[`room_${roomType.key}_tagline`] || ''} onChange={(v) => setField(`room_${roomType.key}_tagline`, v)} placeholder="Short room tagline" />
              <TextArea label="Website Description" value={settings[`room_${roomType.key}_description`] || ''} onChange={(v) => setField(`room_${roomType.key}_description`, v)} placeholder="Public-facing room description" />

              <Input label="Featured Image URL" value={settings[`room_${roomType.key}_image`] || ''} onChange={(v) => setField(`room_${roomType.key}_image`, v)} placeholder="https://..." />
              <button
                type="button"
                onClick={() => {
                  const input = document.createElement('input');
                  input.type = 'file';
                  input.accept = 'image/*';
                  input.onchange = (event) => {
                    const file = event.target.files?.[0];
                    uploadImage({
                      file,
                      onSuccess: (url) => setField(`room_${roomType.key}_image`, url),
                    });
                  };
                  input.click();
                }}
                disabled={uploading}
                style={{ border: `1px solid ${COLORS.border}`, background: COLORS.bg, color: COLORS.text, borderRadius: 6, padding: '8px 12px', cursor: uploading ? 'not-allowed' : 'pointer', display: 'inline-flex', alignItems: 'center', gap: 6, fontFamily: 'inherit' }}
              >
                {uploading ? <Loader2 size={13} style={{ animation: 'spin 1s linear infinite' }} /> : <Upload size={13} />}
                {uploading ? 'Uploading...' : 'Upload Room Image'}
              </button>
            </SectionCard>
          ))}
        </div>
      )}

      {tab === 'addons' && (
        <SectionCard title="Add-Ons Display Content">
          {addons.map((addon, index) => (
            <div key={addon.id} style={{ border: `1px solid ${COLORS.border}`, borderRadius: 8, padding: '0.9rem', marginBottom: '0.8rem' }}>
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '0.45rem' }}>
                <strong style={{ color: COLORS.text, fontSize: '0.88rem' }}>{addon.name}</strong>
                <span style={{ fontSize: '0.72rem', color: COLORS.muted }}>ID: {addon.id}</span>
              </div>

              <TextArea
                label="Display Description"
                value={addon.display_description || ''}
                onChange={(v) => setAddons((prev) => prev.map((row, i) => (i === index ? { ...row, display_description: v } : row)))}
                placeholder="Guest-facing description"
              />

              <Input
                label="Display Image URL"
                value={addon.image || ''}
                onChange={(v) => setAddons((prev) => prev.map((row, i) => (i === index ? { ...row, image: v } : row)))}
                placeholder="https://..."
              />

              <button
                type="button"
                onClick={() => {
                  const input = document.createElement('input');
                  input.type = 'file';
                  input.accept = 'image/*';
                  input.onchange = (event) => {
                    const file = event.target.files?.[0];
                    uploadImage({
                      file,
                      onSuccess: (url) => {
                        setAddons((prev) => prev.map((row, i) => (i === index ? { ...row, image: url } : row)));
                      },
                    });
                  };
                  input.click();
                }}
                disabled={uploading}
                style={{ border: `1px solid ${COLORS.border}`, background: COLORS.bg, color: COLORS.text, borderRadius: 6, padding: '8px 12px', cursor: uploading ? 'not-allowed' : 'pointer', display: 'inline-flex', alignItems: 'center', gap: 6, fontFamily: 'inherit' }}
              >
                {uploading ? <Loader2 size={13} style={{ animation: 'spin 1s linear infinite' }} /> : <Upload size={13} />}
                {uploading ? 'Uploading...' : 'Upload Add-On Image'}
              </button>
            </div>
          ))}
        </SectionCard>
      )}

      {tab === 'brand' && (
        <>
          <SectionCard title="Brand Color Settings">
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '0.75rem 1rem' }}>
              <div>
                <Input label="Primary Color" value={settings.brand_primary_color || DEFAULT_BRAND.primary} onChange={(v) => setField('brand_primary_color', v)} placeholder="#1a4bcc" />
                <input type="color" value={settings.brand_primary_color || DEFAULT_BRAND.primary} onChange={(e) => setField('brand_primary_color', e.target.value)} style={{ width: 54, height: 34, border: `1px solid ${COLORS.border}`, borderRadius: 6, cursor: 'pointer' }} />
              </div>
              <div>
                <Input label="Accent Color" value={settings.brand_accent_color || DEFAULT_BRAND.accent} onChange={(v) => setField('brand_accent_color', v)} placeholder="#0d1b3e" />
                <input type="color" value={settings.brand_accent_color || DEFAULT_BRAND.accent} onChange={(e) => setField('brand_accent_color', e.target.value)} style={{ width: 54, height: 34, border: `1px solid ${COLORS.border}`, borderRadius: 6, cursor: 'pointer' }} />
              </div>
              <div>
                <Input label="Button Color" value={settings.brand_button_color || DEFAULT_BRAND.button} onChange={(v) => setField('brand_button_color', v)} placeholder="#1a4bcc" />
                <input type="color" value={settings.brand_button_color || DEFAULT_BRAND.button} onChange={(e) => setField('brand_button_color', e.target.value)} style={{ width: 54, height: 34, border: `1px solid ${COLORS.border}`, borderRadius: 6, cursor: 'pointer' }} />
              </div>
              <div>
                <Input label="Button Hover Color" value={settings.brand_button_hover_color || DEFAULT_BRAND.buttonHover} onChange={(v) => setField('brand_button_hover_color', v)} placeholder="#1340b8" />
                <input type="color" value={settings.brand_button_hover_color || DEFAULT_BRAND.buttonHover} onChange={(e) => setField('brand_button_hover_color', e.target.value)} style={{ width: 54, height: 34, border: `1px solid ${COLORS.border}`, borderRadius: 6, cursor: 'pointer' }} />
              </div>
            </div>
          </SectionCard>

          <SectionCard title="Preview Swatches">
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, minmax(120px, 1fr))', gap: '0.9rem' }}>
              {[
                { label: 'Primary', value: brandPreview.primary },
                { label: 'Accent', value: brandPreview.accent },
                { label: 'Button', value: brandPreview.button },
                { label: 'Button Hover', value: brandPreview.buttonHover },
              ].map((swatch) => (
                <div key={swatch.label} style={{ border: `1px solid ${COLORS.border}`, borderRadius: 8, overflow: 'hidden' }}>
                  <div style={{ height: 52, background: swatch.value }} />
                  <div style={{ padding: '0.55rem 0.7rem' }}>
                    <div style={{ fontSize: '0.75rem', fontWeight: 700, color: COLORS.text }}>{swatch.label}</div>
                    <div style={{ fontSize: '0.73rem', color: COLORS.muted }}>{swatch.value}</div>
                  </div>
                </div>
              ))}
            </div>
          </SectionCard>
        </>
      )}

      <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: '0.5rem' }}>
        <SaveButton saving={saving} saved={saved} onClick={saveAll} />
      </div>
        </main>
      </div>
    </div>
  );
}

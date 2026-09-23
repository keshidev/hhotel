import React, { useState, useEffect, useMemo } from 'react';
import {
  Tag, Plus, Edit2, Trash2, ToggleLeft, ToggleRight,
  Search, ChevronDown, X, Check, AlertCircle, TrendingDown,
  Calendar, Clock, Users, Hash, Percent, DollarSign
} from 'lucide-react';
import api from '../../services/adminApi';
import StatusBadge from '../../components/StatusBadge';
import TableActionButton from '../../components/TableActionButton';
import './AdminShared.css';
import './PromoManagement.css';

// ─── Toast ────────────────────────────────────────────────────────────────────
const showToast = (message, type = 'success') => {
  const toast = document.createElement('div');
  toast.className = `simple-toast toast-${type}`;
  toast.textContent = message;
  document.body.appendChild(toast);
  setTimeout(() => toast.classList.add('show'), 10);
  setTimeout(() => {
    toast.classList.remove('show');
    setTimeout(() => toast.parentNode?.removeChild(toast), 300);
  }, 3000);
};

const StatCard = ({ label, value }) => (
  <div className="report-stat-card">
    <div className="report-stat-label">{label}</div>
    <div className="report-stat-value">{value}</div>
  </div>
);
// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Parse a date string safely without timezone shifting.
 * Splits on 'T' to get the date portion only (YYYY-MM-DD),
 * then constructs a local Date to avoid UTC offset issues.
 */
const parseDateSafe = (d) => {
  if (!d) return null;
  const [year, month, day] = String(d).split('T')[0].split('-').map(Number);
  return new Date(year, month - 1, day);
};

/**
 * Format a date string for display without timezone shifting.
 * Handles ISO strings like "2026-03-20T16:00:00.000000Z" correctly.
 */
const formatDate = (d) => {
  if (!d) return '—';
  const date = parseDateSafe(d);
  if (!date) return '—';
  return date.toLocaleDateString('en-PH', {
    month: 'short', day: 'numeric', year: 'numeric',
  });
};

/**
 * Extract just the YYYY-MM-DD portion from any date string.
 * Safe against ISO strings with timezone offsets.
 */
const toDateInput = (d) => {
  if (!d) return '';
  return String(d).split('T')[0];
};

const formatCurrency = (n) =>
  '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 });

/**
 * Get today's date as YYYY-MM-DD in local time (not UTC).
 * Using toLocaleDateString('en-CA') reliably gives YYYY-MM-DD.
 */
const getTodayLocal = () => new Date().toLocaleDateString('en-CA');

const getPromoStatus = (promo) => {
  const today = getTodayLocal();
  const start = toDateInput(promo.start_date);
  const end   = toDateInput(promo.end_date);

  if (!promo.is_active)  return { label: 'Inactive', cls: 'status-inactive' };
  if (today > end)       return { label: 'Expired',  cls: 'status-expired'  };
  if (today < start)     return { label: 'Upcoming', cls: 'status-upcoming' };
  return { label: 'Active', cls: 'status-active' };
};

// ─── Empty form ───────────────────────────────────────────────────────────────
const emptyForm = {
  code: '', name: '', description: '',
  discount_type: 'percentage', discount_value: '',
  max_discount_amount: '',
  start_date: '', end_date: '',
  booking_start_date: '', booking_end_date: '',
  min_nights: 1, max_nights: '',
  usage_limit: '', usage_per_user_limit: 1,
  online_only: false, walk_in_only: false,
  is_active: true,
};

// ─────────────────────────────────────────────────────────────────────────────
const PromoManagement = () => {
  const [promos, setPromos]             = useState([]);
  const [stats, setStats]               = useState(null);
  const [loading, setLoading]           = useState(true);
  const [search, setSearch]             = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [modalOpen, setModalOpen]       = useState(false);
  const [editing, setEditing]           = useState(null);
  const [form, setForm]                 = useState(emptyForm);
  const [formErrors, setFormErrors]     = useState({});
  const [saving, setSaving]             = useState(false);
  const [deleteTarget, setDeleteTarget] = useState(null);

  // Toast styles
  useEffect(() => {
    const id = 'promo-toast-styles';
    if (!document.getElementById(id)) {
      const s = document.createElement('style');
      s.id = id;
      s.textContent = `.simple-toast{position:fixed;top:20px;right:20px;padding:14px 22px;border-radius:8px;color:white;font-size:14px;font-weight:500;box-shadow:0 10px 40px rgba(0,0,0,.2);z-index:9999;transform:translateX(400px);opacity:0;transition:all .3s ease}.simple-toast.show{transform:translateX(0);opacity:1}.toast-success{background:#10b981}.toast-error{background:#ef4444}.toast-warning{background:#f59e0b}`;
      document.head.appendChild(s);
    }
  }, []);

  useEffect(() => { fetchPromos(); fetchStats(); }, [statusFilter]);

  const fetchPromos = async () => {
    try {
      setLoading(true);
      const params = statusFilter !== 'all' ? { status: statusFilter } : {};
      const res = await api.get('/admin/promo-codes', { params });
      setPromos(res.data.data?.data || res.data.data || []);
    } catch { showToast('Failed to load promo codes', 'error'); }
    finally   { setLoading(false); }
  };

  const fetchStats = async () => {
    try {
      const res = await api.get('/admin/promo-codes/stats');
      setStats(res.data.data);
    } catch {}
  };

  const filtered = useMemo(() =>
    promos.filter(p =>
      p.code.toLowerCase().includes(search.toLowerCase()) ||
      p.name.toLowerCase().includes(search.toLowerCase())
    ), [promos, search]);

  // ── Modal helpers ─────────────────────────────────────────────────────────

  const openCreate = () => {
    setEditing(null);
    setForm(emptyForm);
    setFormErrors({});
    setModalOpen(true);
  };

  const openEdit = (promo) => {
    setEditing(promo);
    setForm({
      code:                 promo.code,
      name:                 promo.name,
      description:          promo.description || '',
      discount_type:        promo.discount_type,
      discount_value:       promo.discount_value,
      max_discount_amount:  promo.max_discount_amount || '',
      // Use toDateInput to safely strip time/timezone from the stored value
      start_date:           toDateInput(promo.start_date),
      end_date:             toDateInput(promo.end_date),
      booking_start_date:   toDateInput(promo.booking_start_date),
      booking_end_date:     toDateInput(promo.booking_end_date),
      min_nights:           promo.min_nights || 1,
      max_nights:           promo.max_nights || '',
      usage_limit:          promo.usage_limit || '',
      usage_per_user_limit: promo.usage_per_user_limit || 1,
      online_only:          promo.online_only || false,
      walk_in_only:         promo.walk_in_only || false,
      is_active:            promo.is_active !== false,
    });
    setFormErrors({});
    setModalOpen(true);
  };

  const closeModal = () => {
    setModalOpen(false);
    setEditing(null);
    setFormErrors({});
  };

  const handleField = (key, value) => {
    setForm(f => ({ ...f, [key]: value }));
    setFormErrors(e => ({ ...e, [key]: undefined }));
  };

  const validateForm = () => {
    const errs = {};
    if (!form.code.trim())           errs.code = 'Code is required';
    if (!form.name.trim())           errs.name = 'Name is required';
    if (!form.discount_value)        errs.discount_value = 'Discount value is required';
    if (!form.start_date)            errs.start_date = 'Start date is required';
    if (!form.end_date)              errs.end_date = 'End date is required';
    if (form.end_date < form.start_date) errs.end_date = 'End date must be after start date';
    if (form.booking_start_date && !form.booking_end_date)
      errs.booking_end_date = 'Eligible check-in end date is required';
    if (!form.booking_start_date && form.booking_end_date)
      errs.booking_start_date = 'Eligible check-in start date is required';
    if (form.booking_start_date && form.booking_end_date && form.booking_end_date < form.booking_start_date)
      errs.booking_end_date = 'End date must be on or after the start date';
    if (form.discount_type === 'percentage' && Number(form.discount_value) > 100)
      errs.discount_value = 'Percentage cannot exceed 100';
    setFormErrors(errs);
    return Object.keys(errs).length === 0;
  };

  const handleSave = async () => {
    if (!validateForm()) return;
    setSaving(true);
    try {
      // Date fields from <input type="date"> are already YYYY-MM-DD strings —
      // send them as-is so the backend receives clean date-only values.
      const payload = {
        ...form,
        code:                 form.code.toUpperCase().trim(),
        discount_value:       Number(form.discount_value),
        max_discount_amount:  form.max_discount_amount ? Number(form.max_discount_amount) : null,
        min_nights:           Number(form.min_nights) || 1,
        max_nights:           form.max_nights ? Number(form.max_nights) : null,
        usage_limit:          form.usage_limit ? Number(form.usage_limit) : null,
        usage_per_user_limit: Number(form.usage_per_user_limit) || 1,
        booking_start_date:   form.booking_start_date || null,
        booking_end_date:     form.booking_end_date || null,
      };

      if (editing) {
        await api.put(`/admin/promo-codes/${editing.id}`, payload);
        showToast(`Promo code ${payload.code} updated.`);
      } else {
        await api.post('/admin/promo-codes', payload);
        showToast(`Promo code ${payload.code} created.`);
      }
      closeModal();
      fetchPromos();
      fetchStats();
    } catch (err) {
      const errors = err.response?.data?.errors;
      if (errors) {
        setFormErrors(Object.fromEntries(
          Object.entries(errors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v])
        ));
      } else {
        showToast(err.response?.data?.message || 'Failed to save', 'error');
      }
    } finally {
      setSaving(false);
    }
  };

  const handleToggle = async (promo) => {
    try {
      await api.patch(`/admin/promo-codes/${promo.id}/toggle`);
      showToast(`${promo.code} ${promo.is_active ? 'deactivated' : 'activated'}.`);
      fetchPromos();
    } catch { showToast('Failed to update status', 'error'); }
  };

  const handleDelete = async () => {
    if (!deleteTarget) return;
    try {
      await api.delete(`/admin/promo-codes/${deleteTarget.id}`);
      showToast(`${deleteTarget.code} deleted.`);
      setDeleteTarget(null);
      fetchPromos();
      fetchStats();
    } catch (err) {
      showToast(err.response?.data?.message || 'Failed to delete', 'error');
      setDeleteTarget(null);
    }
  };

  // ─────────────────────────────────────────────────────────────────────────
  return (
    <div className="promo-page">

      {/* Header */}
      <div className="promo-page-header">
        <div>
          <h1>Promo Codes</h1>
          <p className="page-subtitle">Manage discounts, seasonal offers, and special rates.</p>
        </div>
        <button className="promo-create-btn" onClick={openCreate}>
          <Plus size={16} /> New Promo Code
        </button>
      </div>

      {/* Stats row */}
      {stats && (
        <div className="report-stats-grid">
          <StatCard label="Total Codes"     value={stats.total_codes} />
          <StatCard label="Active"          value={stats.active_codes} />
          <StatCard label="Total Usages"    value={stats.total_usages} />
          <StatCard label="Total Discounts" value={formatCurrency(stats.total_discount_given)} />
        </div>
      )}

      {/* Toolbar */}
      <div className="promo-toolbar">
        <div className="promo-search">
          <Search size={15} />
          <input
            placeholder="Search by code or name..."
            value={search}
            onChange={e => setSearch(e.target.value)}
          />
        </div>
        <div className="promo-filter">
          <select value={statusFilter} onChange={e => setStatusFilter(e.target.value)}>
            <option value="all">All Status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="expired">Expired</option>
          </select>
          <ChevronDown size={14} />
        </div>
      </div>

      {/* Table */}
      <div className="promo-table-card">
        <div className="promo-table-wrap">
          <table className="promo-table">
            <thead>
              <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Discount</th>
                <th>Valid Period</th>
                <th>Usage</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr><td colSpan={7} className="promo-empty">Loading...</td></tr>
              ) : filtered.length === 0 ? (
                <tr><td colSpan={7} className="promo-empty">No promo codes found.</td></tr>
              ) : filtered.map(promo => {
                const s = getPromoStatus(promo);
                return (
                  <tr key={promo.id}>
                    <td>
                      <span className="promo-code-cell">{promo.code}</span>
                    </td>
                    <td>
                      <div className="promo-name-cell">{promo.name}</div>
                      {promo.description && (
                        <div className="promo-desc-cell">{promo.description}</div>
                      )}
                    </td>
                    <td>
                      <span className={`promo-discount-badge ${promo.discount_type === 'percentage' ? 'badge-percent' : 'badge-fixed'}`}>
                        {promo.discount_type === 'percentage'
                          ? `${promo.discount_value}%`
                          : formatCurrency(promo.discount_value)
                        } OFF
                      </span>
                      {promo.max_discount_amount && (
                        <div className="promo-cap-label">max {formatCurrency(promo.max_discount_amount)}</div>
                      )}
                    </td>
                    <td>
                      <div className="promo-dates">
                        <span>{formatDate(promo.start_date)}</span>
                        <span className="promo-dates-sep">→</span>
                        <span>{formatDate(promo.end_date)}</span>
                      </div>
                    </td>
                    <td>
                      <div className="promo-usage-cell">
                        <span>{promo.total_used}</span>
                        <span className="promo-usage-sep">/</span>
                        <span>{promo.usage_limit ?? '∞'}</span>
                      </div>
                    </td>
                    <td>
                      <StatusBadge status={s.label} />
                    </td>
                    <td>
                      <div className="promo-actions">
                        <TableActionButton
                          iconOnly
                          label="Edit promo code"
                          onClick={() => openEdit(promo)}
                        >
                          <Edit2 size={14} />
                        </TableActionButton>
                        <TableActionButton
                          iconOnly
                          tone={promo.is_active ? 'warning' : 'success'}
                          label={promo.is_active ? 'Deactivate promo code' : 'Activate promo code'}
                          onClick={() => handleToggle(promo)}
                        >
                          {promo.is_active ? <ToggleRight size={16} /> : <ToggleLeft size={16} />}
                        </TableActionButton>
                        <TableActionButton
                          iconOnly
                          tone="danger"
                          label="Delete promo code"
                          onClick={() => setDeleteTarget(promo)}
                          disabled={promo.total_used > 0}
                        >
                          <Trash2 size={14} />
                        </TableActionButton>
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>

      {/* ── Create / Edit Modal ──────────────────────────────────────────── */}
      {modalOpen && (
        <div className="promo-modal-overlay" onClick={closeModal}>
          <div className="promo-modal" onClick={e => e.stopPropagation()}>
            <div className="promo-modal-header">
              <h3>{editing ? `Edit — ${editing.code}` : 'New Promo Code'}</h3>
              <button className="promo-modal-close" onClick={closeModal}><X size={18} /></button>
            </div>

            <div className="promo-modal-body">
              <div className="promo-form-grid">

                {/* Code */}
                <div className="promo-field">
                  <label>Code *</label>
                  <input
                    value={form.code}
                    onChange={e => handleField('code', e.target.value.toUpperCase())}
                    placeholder="e.g. SUMMER20"
                    className={formErrors.code ? 'field-error' : ''}
                  />
                  {formErrors.code && <span className="field-err-msg">{formErrors.code}</span>}
                </div>

                {/* Name */}
                <div className="promo-field">
                  <label>Name *</label>
                  <input
                    value={form.name}
                    onChange={e => handleField('name', e.target.value)}
                    placeholder="e.g. Summer Sale 2026"
                    className={formErrors.name ? 'field-error' : ''}
                  />
                  {formErrors.name && <span className="field-err-msg">{formErrors.name}</span>}
                </div>

                {/* Description */}
                <div className="promo-field promo-field--full">
                  <label>Description</label>
                  <textarea
                    value={form.description}
                    onChange={e => handleField('description', e.target.value)}
                    placeholder="Optional description..."
                    rows={2}
                  />
                </div>

                {/* Discount Type */}
                <div className="promo-field">
                  <label>Discount Type *</label>
                  <select
                    value={form.discount_type}
                    onChange={e => handleField('discount_type', e.target.value)}
                  >
                    <option value="percentage">Percentage (%)</option>
                    <option value="fixed">Fixed Amount (₱)</option>
                  </select>
                </div>

                {/* Discount Value */}
                <div className="promo-field">
                  <label>Discount Value *</label>
                  <div className="promo-input-icon">
                    {form.discount_type === 'percentage' ? <Percent size={14} /> : <DollarSign size={14} />}
                    <input
                      type="number" min="0" step="0.01"
                      value={form.discount_value}
                      onChange={e => handleField('discount_value', e.target.value)}
                      placeholder={form.discount_type === 'percentage' ? '20' : '500'}
                      className={formErrors.discount_value ? 'field-error' : ''}
                    />
                  </div>
                  {formErrors.discount_value && (
                    <span className="field-err-msg">{formErrors.discount_value}</span>
                  )}
                </div>

                {/* Max Discount */}
                {form.discount_type === 'percentage' && (
                  <div className="promo-field">
                    <label>Max Discount Amount (₱) <span className="optional-tag">optional</span></label>
                    <input
                      type="number" min="0"
                      value={form.max_discount_amount}
                      onChange={e => handleField('max_discount_amount', e.target.value)}
                      placeholder="e.g. 1000"
                    />
                  </div>
                )}

                {/* Start Date */}
                <div className="promo-field">
                  <label>Valid From *</label>
                  <input
                    type="date"
                    value={form.start_date}
                    onChange={e => handleField('start_date', e.target.value)}
                    className={formErrors.start_date ? 'field-error' : ''}
                  />
                  {formErrors.start_date && (
                    <span className="field-err-msg">{formErrors.start_date}</span>
                  )}
                </div>

                {/* End Date */}
                <div className="promo-field">
                  <label>Valid Until *</label>
                  <input
                    type="date"
                    value={form.end_date}
                    onChange={e => handleField('end_date', e.target.value)}
                    className={formErrors.end_date ? 'field-error' : ''}
                  />
                  {formErrors.end_date && (
                    <span className="field-err-msg">{formErrors.end_date}</span>
                  )}
                </div>

                {/* Eligible check-in dates */}
                <div className="promo-field">
                  <label>Eligible Check-in Date — From <span className="optional-tag">optional</span></label>
                  <input
                    type="date"
                    value={form.booking_start_date}
                    onChange={e => handleField('booking_start_date', e.target.value)}
                    className={formErrors.booking_start_date ? 'field-error' : ''}
                  />
                  {formErrors.booking_start_date && (
                    <span className="field-err-msg">{formErrors.booking_start_date}</span>
                  )}
                </div>
                <div className="promo-field">
                  <label>Eligible Check-in Date — Until <span className="optional-tag">optional</span></label>
                  <input
                    type="date"
                    value={form.booking_end_date}
                    onChange={e => handleField('booking_end_date', e.target.value)}
                    className={formErrors.booking_end_date ? 'field-error' : ''}
                  />
                  {formErrors.booking_end_date && (
                    <span className="field-err-msg">{formErrors.booking_end_date}</span>
                  )}
                </div>

                {/* Min nights */}
                <div className="promo-field">
                  <label>Min Nights</label>
                  <input
                    type="number" min="1"
                    value={form.min_nights}
                    onChange={e => handleField('min_nights', e.target.value)}
                  />
                </div>

                {/* Max nights */}
                <div className="promo-field">
                  <label>Max Nights <span className="optional-tag">optional</span></label>
                  <input
                    type="number" min="1"
                    value={form.max_nights}
                    onChange={e => handleField('max_nights', e.target.value)}
                    placeholder="No limit"
                  />
                </div>

                {/* Usage limit */}
                <div className="promo-field">
                  <label>Global Usage Limit <span className="optional-tag">optional</span></label>
                  <input
                    type="number" min="1"
                    value={form.usage_limit}
                    onChange={e => handleField('usage_limit', e.target.value)}
                    placeholder="Unlimited"
                  />
                </div>

                {/* Per-user limit */}
                <div className="promo-field">
                  <label>Per-User Limit</label>
                  <input
                    type="number" min="1"
                    value={form.usage_per_user_limit}
                    onChange={e => handleField('usage_per_user_limit', e.target.value)}
                  />
                </div>

                {/* Toggles */}
                <div className="promo-field promo-field--full promo-toggles">
                  <label className="promo-toggle-label">
                    <input
                      type="checkbox"
                      checked={form.online_only}
                      onChange={e => {
                        handleField('online_only', e.target.checked);
                        if (e.target.checked) handleField('walk_in_only', false);
                      }}
                    />
                    Online bookings only
                  </label>
                  <label className="promo-toggle-label">
                    <input
                      type="checkbox"
                      checked={form.walk_in_only}
                      onChange={e => {
                        handleField('walk_in_only', e.target.checked);
                        if (e.target.checked) handleField('online_only', false);
                      }}
                    />
                    Walk-in bookings only
                  </label>
                  <label className="promo-toggle-label">
                    <input
                      type="checkbox"
                      checked={form.is_active}
                      onChange={e => handleField('is_active', e.target.checked)}
                    />
                    Active
                  </label>
                </div>

              </div>
            </div>

            <div className="promo-modal-footer">
              <button className="promo-btn-ghost" onClick={closeModal}>Cancel</button>
              <button className="promo-btn-primary" onClick={handleSave} disabled={saving}>
                {saving ? 'Saving...' : (editing ? 'Update Code' : 'Create Code')}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ── Delete Confirm ───────────────────────────────────────────────── */}
      {deleteTarget && (
        <div className="promo-modal-overlay" onClick={() => setDeleteTarget(null)}>
          <div className="promo-confirm-modal" onClick={e => e.stopPropagation()}>
            <AlertCircle size={32} className="promo-confirm-icon" />
            <h3>Delete {deleteTarget.code}?</h3>
            <p>This action cannot be undone.</p>
            <div className="promo-confirm-actions">
              <button className="promo-btn-ghost" onClick={() => setDeleteTarget(null)}>Cancel</button>
              <button className="promo-btn-danger" onClick={handleDelete}>Delete</button>
            </div>
          </div>
        </div>
      )}

    </div>
  );
};

export default PromoManagement;

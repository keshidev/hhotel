import { useNotificationTarget } from '../../hooks/useNotificationTarget';
import React, { useEffect, useMemo, useState } from 'react';
import { Search, Filter, ChevronDown, Eye, X, ClipboardCheck, User, BedDouble, AlertTriangle, Upload, ExternalLink } from 'lucide-react';
import approvalService from '../../services/admin/approvalService';
import StatusBadge from '../../components/StatusBadge';
import TableActionButton from '../../components/TableActionButton';
import { refundApiFields, validateCancellationRefund } from '../../utils/cancellationRefundValidation';
import './AdminShared.css';
import './AdminApprovalToolbar.css';

import useAutoRefresh from '../../hooks/useAutoRefresh';

const STATUS_OPTIONS = [
  { label: 'All', value: 'all' },
  { label: 'Pending Approval', value: 'pending_approval' },
  { label: 'Approved', value: 'approved' },
  { label: 'Refund Pending', value: 'refund_pending' },
  { label: 'Refunded', value: 'refunded' },
  { label: 'Rejected', value: 'rejected' },
  { label: 'Cancelled', value: 'cancelled' },
];

const RefundFieldError = ({ field, errors }) => errors[field]
  ? <small id={`refund-${field}-error`} className="cancellation-refund-field-error">{errors[field]}</small>
  : null;

const showToast = (message, type = 'success') => {
  const toast = document.createElement('div');
  toast.className = `simple-toast toast-${type}`;
  toast.textContent = message;
  document.body.appendChild(toast);

  setTimeout(() => toast.classList.add('show'), 10);
  setTimeout(() => {
    toast.classList.remove('show');
    setTimeout(() => {
      if (toast.parentNode) {
        document.body.removeChild(toast);
      }
    }, 300);
  }, 3000);
};

const AdminCancellationApprovals = () => {
  useNotificationTarget((target) => { setSearch(target.search); setStatusFilter('all'); setSelected(null); });
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [records, setRecords] = useState([]);
  const [selected, setSelected] = useState(null);
  const [openingDetails, setOpeningDetails] = useState(false);
  const [decisionNote, setDecisionNote] = useState('');
  const [finalizeNote, setFinalizeNote] = useState('');
  const [refundForm, setRefundForm] = useState({
    recipientName: '',
    recipientAccount: '',
    gcashReference: '',
    processedAt: '',
    refundReason: '',
    transferConfirmed: false,
  });
  const [refundProof, setRefundProof] = useState(null);
  const [refundErrors, setRefundErrors] = useState({});
  const [refundSubmitError, setRefundSubmitError] = useState('');
  const [viewingRefundProof, setViewingRefundProof] = useState(false);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);

  useEffect(() => {
    if (saving || !refundSubmitError) return undefined;
    const frame = requestAnimationFrame(() => {
      const firstField = Object.keys(refundErrors)[0];
      const target = document.getElementById(firstField ? `refund-${firstField}` : 'refund-submit-error');
      target?.focus();
      target?.scrollIntoView({ block: 'center', behavior: 'smooth' });
    });
    return () => cancelAnimationFrame(frame);
  }, [refundErrors, refundSubmitError, saving]);

  useEffect(() => {
    fetchRequests();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusFilter]);

  useEffect(() => {
    const styleId = 'simple-toast-styles';
    if (!document.getElementById(styleId)) {
      const style = document.createElement('style');
      style.id = styleId;
      style.textContent = `
        .simple-toast {
          position: fixed;
          top: 20px;
          right: 20px;
          padding: 16px 24px;
          border-radius: 8px;
          color: white;
          font-size: 14px;
          font-weight: 500;
          box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
          z-index: 9999;
          transform: translateX(400px);
          opacity: 0;
          transition: all 0.3s ease;
        }
        .simple-toast.show {
          transform: translateX(0);
          opacity: 1;
        }
        .toast-success { background: #10b981; }
        .toast-error { background: #ef4444; }
        .toast-warning { background: #f59e0b; }
      `;
      document.head.appendChild(style);
    }
  }, []);

  const fetchRequests = async ({ showLoading = true, showErrorToast = true } = {}) => {
    try {
      if (showLoading) {
        setLoading(true);
      }
      const response = await approvalService.getCancellationRequests({
        status: statusFilter,
      });
      setRecords(response.requests || []);
      setError(null);
    } catch (err) {
      setError('Failed to load cancellation approvals.');
      if (showErrorToast) {
        showToast(err?.response?.data?.message || 'Failed to load cancellation approvals', 'error');
      }
    } finally {
      if (showLoading) {
        setLoading(false);
      }
    }
  };

  useAutoRefresh(
    () => fetchRequests({ showLoading: false, showErrorToast: false }),
    { deps: [statusFilter], intervalMs: 15000 }
  );

  const filtered = useMemo(() => {
    const keyword = search.trim().toLowerCase();
    if (!keyword) return records;
    return records.filter((item) => (
      String(item.id || '').toLowerCase().includes(keyword)
      || String(item.bookingId || '').toLowerCase().includes(keyword)
      || String(item.guest || '').toLowerCase().includes(keyword)
      || String(item.room || '').toLowerCase().includes(keyword)
    ));
  }, [records, search]);

  const openDetails = async (row) => {
    if (openingDetails) return;
    setOpeningDetails(true);
    try {
    const detail = await approvalService.getCancellationRequest(row.requestId);
    setSelected(detail);
    setDecisionNote(detail.decisionNote || '');
    setFinalizeNote('');
    setRefundForm({
      recipientName: detail.refundRecipientSuggestion?.name || '',
      recipientAccount: detail.refundRecipientSuggestion?.account || '',
      gcashReference: '',
      processedAt: '',
      refundReason: detail.reason || '',
      transferConfirmed: false,
    });
    setRefundProof(null);
    setRefundErrors({});
    setRefundSubmitError('');
    } catch (err) {
      showToast(err?.response?.data?.message || 'Unable to load cancellation details. Please try again.', 'error');
    } finally {
      setOpeningDetails(false);
    }
  };

  const clearRefundError = (field) => {
    setRefundErrors((current) => {
      const next = { ...current };
      delete next[field];
      return next;
    });
    setRefundSubmitError('');
  };

  const updateRefundField = (field, value) => {
    setRefundForm((current) => ({ ...current, [field]: value, ...(['recipientName', 'recipientAccount'].includes(field) ? { transferConfirmed: false } : {}) }));
    clearRefundError(field);
  };

  const refundFieldProps = (field, label) => ({
    id: `refund-${field}`,
    'aria-label': label,
    'aria-invalid': Boolean(refundErrors[field]),
    'aria-describedby': [refundErrors[field] && `refund-${field}-error`, field === 'refundReason' && 'refund-reason-hint', field === 'processedAt' && 'refund-date-hint'].filter(Boolean).join(' ') || undefined,
  });

  const showRefundErrors = (errors, message) => {
    setRefundErrors(errors);
    setRefundSubmitError(message);
  };

  const updateSelectedAndList = (updated) => {
    setSelected(updated);
    setRecords((prev) => prev.map((item) => (item.id === updated.id ? updated : item)));
  };

  const handleApprove = async () => {
    if (!selected) return;
    try {
      setSaving(true);
      const response = await approvalService.approveCancellationRequest(selected.requestId, {
        decision_note: decisionNote.trim() || null,
      });
      updateSelectedAndList(response.request);
      showToast(response.message || 'Request approved.', 'success');
      await fetchRequests();
    } catch (err) {
      showToast(err?.response?.data?.message || 'Failed to approve request.', 'error');
    } finally {
      setSaving(false);
    }
  };

  const handleReject = async () => {
    if (!selected) return;
    if (!decisionNote.trim()) {
      showToast('Decision note is required for rejection.', 'warning');
      return;
    }
    try {
      setSaving(true);
      const response = await approvalService.rejectCancellationRequest(selected.requestId, {
        decision_note: decisionNote.trim(),
      });
      updateSelectedAndList(response.request);
      showToast(response.message || 'Request rejected.', 'success');
      await fetchRequests();
    } catch (err) {
      showToast(err?.response?.data?.message || 'Failed to reject request.', 'error');
    } finally {
      setSaving(false);
    }
  };

  const handleProcessRefund = async () => {
    if (!selected || saving) return;
    setRefundSubmitError('');
    let payload = { refund_note: finalizeNote.trim() || null };
    if (selected.requiresManualGcashEvidence) {
      const errors = validateCancellationRefund(refundForm, refundProof);
      if (Object.keys(errors).length) {
        showRefundErrors(errors, 'Review the highlighted refund fields, then submit again.');
        return;
      }
      setRefundErrors({});
      payload = new FormData();
      payload.append('recipient_name', refundForm.recipientName.trim());
      payload.append('recipient_account', refundForm.recipientAccount.trim());
      payload.append('gcash_reference', refundForm.gcashReference.trim());
      payload.append('processed_at', refundForm.processedAt);
      payload.append('refund_reason', refundForm.refundReason.trim());
      payload.append('manual_transfer_confirmed', '1');
      payload.append('proof', refundProof);
    }
    try {
      setSaving(true);
      const response = await approvalService.processRefundForCancellationRequest(selected.requestId, payload);
      updateSelectedAndList(response.request);
      showToast(response.message || 'Refund processed.', 'success');
      await fetchRequests();
    } catch (err) {
      const fieldErrors = {};
      for (const [key, value] of Object.entries(err?.response?.data?.errors || {})) {
        if (refundApiFields[key]) fieldErrors[refundApiFields[key]] = Array.isArray(value) ? value[0] : value;
      }
      showRefundErrors(fieldErrors, err?.response?.data?.message || 'Unable to confirm that the refund was recorded. Refresh the request to check its status before trying again.');
    } finally {
      setSaving(false);
    }
  };

  const handleViewRefundProof = async () => {
    if (!selected || viewingRefundProof) return;

    const proofWindow = window.open('about:blank', '_blank');
    if (proofWindow) {
      proofWindow.opener = null;
    }

    try {
      setViewingRefundProof(true);
      const proof = await approvalService.getRefundProofForCancellationRequest(selected.requestId);
      const proofUrl = URL.createObjectURL(proof);

      if (proofWindow) {
        proofWindow.location.replace(proofUrl);
      } else {
        const link = document.createElement('a');
        link.href = proofUrl;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.click();
      }

      window.setTimeout(() => URL.revokeObjectURL(proofUrl), 60000);
    } catch (err) {
      proofWindow?.close();
      showToast(err?.response?.status === 410
        ? 'This refund proof has already reached the end of its retention period.'
        : 'Unable to open the private refund proof.', 'error');
    } finally {
      setViewingRefundProof(false);
    }
  };

  if (loading) {
    return <div style={{ padding: '2rem' }}>Loading cancellation approvals...</div>;
  }

  if (error) {
    return (
      <div style={{ padding: '2rem' }}>
        <h1 style={{ marginBottom: '1rem' }}>Cancellation Approvals</h1>
        <div style={{ color: '#ef4444' }}>{error}</div>
      </div>
    );
  }

  return (
    <div className="cancellation-approval-page admin-approval-page">
      <div className="page-header">
        <div>
          <h1>Cancellation Approvals</h1>
          <p className="page-subtitle">Approve cancellations to release rooms, then record any outstanding refunds.</p>
        </div>
      </div>

      <div className="page-toolbar">
        <div className="search-wrap">
          <Search size={16} />
          <input
            type="text"
            aria-label="Search cancellation requests"
            placeholder="Search by request, booking, guest, room..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>

        <div className="filter-wrap">
          <Filter size={16} />
          <select aria-label="Filter cancellation requests by status" value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
            {STATUS_OPTIONS.map((item) => (
              <option key={item.value} value={item.value}>{item.label}</option>
            ))}
          </select>
          <ChevronDown size={14} className="select-chevron" />
        </div>
      </div>

      <div className="content-card">
        <div className="table-container">
          <table className="data-table">
            <thead>
              <tr>
                <th>Request</th>
                <th>Booking</th>
                <th>Guest</th>
                <th>Room</th>
                <th>Refund</th>
                <th>Status</th>
                <th>Requested At</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              {filtered.length === 0 ? (
                <tr>
                  <td colSpan={8} style={{ textAlign: 'center', padding: '2rem', color: 'var(--color-text-secondary)' }}>
                    No cancellation requests found.
                  </td>
                </tr>
              ) : (
                filtered.map((row) => (
                  <tr key={row.id}>
                    <td className="booking-id">{row.id}</td>
                    <td className="booking-id">{row.bookingId}</td>
                    <td>{row.guest}</td>
                    <td>{row.room}</td>
                    <td>₱{Number(row.refundAmount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}<div>{row.refundStatusLabel}</div></td>
                    <td><StatusBadge status={row.statusLabel} /></td>
                    <td>{row.requestedAt ? String(row.requestedAt).replace('T', ' ').slice(0, 16) : 'N/A'}</td>
                    <td>
                      <TableActionButton iconOnly label="View cancellation details" disabled={openingDetails} onClick={() => openDetails(row)}>
                        <Eye size={15} />
                      </TableActionButton>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {selected && (
        <div className="modal-overlay" onClick={() => !saving && setSelected(null)}>
          <div className="modal-content modal-large" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <div>
                <h2>Cancellation Request {selected.id}</h2>
                <div style={{ fontSize: '0.85rem', color: 'var(--color-text-secondary)' }}>{selected.bookingId}</div>
              </div>
              <button className="modal-close" onClick={() => setSelected(null)} disabled={saving}><X size={18} /></button>
            </div>

            <div style={{ padding: '1.5rem' }}>
              <div className="detail-grid" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '1.25rem' }}>
                <div>
                  <div style={{ fontWeight: 700, marginBottom: '0.6rem', display: 'flex', alignItems: 'center', gap: 6 }}>
                    <User size={15} /> Guest
                  </div>
                  <div style={{ color: 'var(--color-text-secondary)', lineHeight: 1.7 }}>
                    <div><strong style={{ color: 'var(--color-text-primary)' }}>{selected.guest}</strong></div>
                    <div>Booking Status: {selected.bookingStatus}</div>
                    <div>Requested By: {selected.requestedBy}</div>
                    <div>Requested At: {selected.requestedAt || 'N/A'}</div>
                  </div>
                </div>
                <div>
                  <div style={{ fontWeight: 700, marginBottom: '0.6rem', display: 'flex', alignItems: 'center', gap: 6 }}>
                    <BedDouble size={15} /> Reservation
                  </div>
                  <div style={{ color: 'var(--color-text-secondary)', lineHeight: 1.7 }}>
                    <div><strong style={{ color: 'var(--color-text-primary)' }}>{selected.room}</strong></div>
                    <div>Check-in: {selected.checkIn}</div>
                    <div>Check-out: {selected.checkOut}</div>
                    <div>Refund: ₱{Number(selected.refundAmount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</div>
                    <div>Method: {selected.refundMethod || 'N/A'}</div>
                  </div>
                </div>
              </div>

              <div style={{ marginTop: '1rem', padding: '0.9rem', border: '1px solid var(--color-border)', borderRadius: 8, background: 'var(--color-background)' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: '0.5rem', fontWeight: 700 }}>
                  <ClipboardCheck size={15} /> Workflow
                </div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: '0.5rem' }}>
                  <span>Status</span>
                  <StatusBadge status={selected.statusLabel} />
                </div>
                <div style={{ fontSize: '0.86rem', color: 'var(--color-text-secondary)', lineHeight: 1.7 }}>
                  <div><strong>Booking: {selected.bookingStatus}</strong></div>
                  <div><strong>Refund: {selected.refundStatusLabel}</strong></div>
                  {selected.canApprove && <p>Approving cancels this reservation and releases its room allocation immediately. Any refund remains pending until the completed transfer is recorded.</p>}
                  {selected.bookingStatus === 'cancelled' && selected.canProcessRefund && <p>The reservation is cancelled. Record the completed transfer below to finish the refund.</p>}
                  <div>Approved By: {selected.approvedBy || 'N/A'}</div>
                  <div>Approved At: {selected.approvedAt || 'N/A'}</div>
                  <div>Rejected At: {selected.rejectedAt || 'N/A'}</div>
                  <div>Cancelled By: {selected.finalizedBy || 'N/A'}</div>
                  <div>Cancelled At: {selected.finalizedAt || 'N/A'}</div>
                  <div>Refund Processed At: {selected.refundProcessedAt || 'N/A'}</div>
                </div>
              </div>

              <div style={{ marginTop: '1rem', padding: '0.9rem', border: '1px solid var(--color-border)', borderRadius: 8 }}>
                <div style={{ fontWeight: 700, marginBottom: 6 }}>Reason</div>
                <div style={{ color: 'var(--color-text-secondary)' }}>{selected.reason}</div>
              </div>

              {selected.requestNote && (
                <div style={{ marginTop: '0.75rem', padding: '0.9rem', border: '1px solid var(--color-border)', borderRadius: 8 }}>
                  <div style={{ fontWeight: 700, marginBottom: 6 }}>Request Note</div>
                  <div style={{ color: 'var(--color-text-secondary)' }}>{selected.requestNote}</div>
                </div>
              )}

              <div style={{ marginTop: '1rem' }}>
                <label htmlFor="cancellation-decision-note" style={{ fontWeight: 600, fontSize: '0.86rem', display: 'block', marginBottom: 6 }}>Decision Note</label>
                <textarea
                  id="cancellation-decision-note"
                  rows={3}
                  value={decisionNote}
                  onChange={(e) => setDecisionNote(e.target.value)}
                  placeholder={selected.canApprove || selected.canReject ? 'Add note for approve/reject' : 'No decision note provided'}
                  style={{ width: '100%', border: '1px solid var(--color-border)', borderRadius: 8, padding: '0.7rem', fontFamily: 'inherit', resize: 'vertical' }}
                  disabled={saving || (!selected.canApprove && !selected.canReject)}
                />
              </div>

              {(!selected.canProcessRefund || !selected.requiresManualGcashEvidence) && <div style={{ marginTop: '0.75rem' }}>
                <label htmlFor="cancellation-refund-note" style={{ fontWeight: 600, fontSize: '0.86rem', display: 'block', marginBottom: 6 }}>Refund Note</label>
                <textarea
                  id="cancellation-refund-note"
                  rows={2}
                  value={finalizeNote}
                  onChange={(e) => setFinalizeNote(e.target.value)}
                  placeholder={selected.canProcessRefund ? 'Optional note for refund processing' : 'No refund note provided'}
                  style={{ width: '100%', border: '1px solid var(--color-border)', borderRadius: 8, padding: '0.7rem', fontFamily: 'inherit', resize: 'vertical' }}
                  disabled={saving || !selected.canProcessRefund}
                />
              </div>}

              {refundSubmitError && (
                <div id="refund-submit-error" role="alert" tabIndex={-1} className="cancellation-refund-submit-error">
                  {refundSubmitError}
                </div>
              )}

              {selected.canProcessRefund && selected.requiresManualGcashEvidence && (
                <section className="manual-refund-form">
                  <div className="manual-refund-form__heading">
                    <div className="manual-refund-form__icon"><ClipboardCheck size={18} /></div>
                    <div>
                      <h3>Record completed GCash refund</h3>
                      <p>Enter details from the official hotel GCash transaction record.</p>
                    </div>
                  </div>

                  <div className="manual-refund-warning">
                    <AlertTriangle size={18} />
                    <span><strong>This system does not send money.</strong> Transfer the refund in the official GCash account first, then record the evidence here.</span>
                  </div>

                  <div className="manual-refund-grid">
                    <label>
                      <span>Approved refund amount</span>
                      <input aria-label="Approved refund amount" readOnly value={`PHP ${Number(selected.refundAmount || 0).toFixed(2)}`} />
                    </label>
                    <div className="cancellation-refund-hint">
                      {selected.refundRecipientSuggestion?.source === 'guest_confirmed'
                        ? 'Recipient details confirmed by the guest when requesting cancellation. Review against the original payment before sending.'
                        : selected.refundRecipientSuggestion?.source === 'approved_payment_sender'
                          ? 'Name suggested from the approved payment sender. Confirm the intended refund account before sending.'
                          : 'Name suggested from booking guest details. Confirm the intended refund account before sending.'}
                    </div>
                    <label>
                      <span>Recipient name *</span>
                      <input {...refundFieldProps('recipientName', 'Recipient name *')} value={refundForm.recipientName} onChange={(event) => updateRefundField('recipientName', event.target.value)} disabled={saving} />
                      <RefundFieldError field="recipientName" errors={refundErrors} />
                    </label>
                    <label>
                      <span>Recipient GCash number *</span>
                      <input {...refundFieldProps('recipientAccount', 'Recipient GCash number *')} inputMode="tel" placeholder="09XXXXXXXXX" value={refundForm.recipientAccount} onChange={(event) => updateRefundField('recipientAccount', event.target.value)} disabled={saving} />
                      {selected.refundRecipientSuggestion?.guestContactNumber && <button type="button" className="btn-secondary" disabled={saving} onClick={() => updateRefundField('recipientAccount', selected.refundRecipientSuggestion.guestContactNumber)}>Use guest contact number</button>}
                      <small className="cancellation-refund-hint">The booking contact number is a suggestion; confirm it is the intended GCash account.</small>
                      <RefundFieldError field="recipientAccount" errors={refundErrors} />
                    </label>
                    <label>
                      <span>GCash refund reference *</span>
                      <input {...refundFieldProps('gcashReference', 'GCash refund reference *')} value={refundForm.gcashReference} onChange={(event) => updateRefundField('gcashReference', event.target.value)} disabled={saving} />
                      <RefundFieldError field="gcashReference" errors={refundErrors} />
                    </label>
                    <label>
                      <span>Date and time sent *</span>
                      <input {...refundFieldProps('processedAt', 'Date and time sent *')} type="datetime-local" value={refundForm.processedAt} onChange={(event) => updateRefundField('processedAt', event.target.value)} disabled={saving} />
                      <small id="refund-date-hint" className="cancellation-refund-hint">Philippine time. Must be after the original payment and not in the future.</small>
                      <RefundFieldError field="processedAt" errors={refundErrors} />
                    </label>
                  </div>

                  <label className="manual-refund-field">
                    <span>Refund reason *</span>
                    <textarea {...refundFieldProps('refundReason', 'Refund reason *')} rows={3} value={refundForm.refundReason} onChange={(event) => updateRefundField('refundReason', event.target.value)} disabled={saving} />
                    <small id="refund-reason-hint" className="cancellation-refund-hint">10–1,000 characters. Explain why this refund was issued.</small>
                    <RefundFieldError field="refundReason" errors={refundErrors} />
                  </label>

                  <label className="manual-refund-upload">
                    <Upload size={20} />
                    <span>{refundProof ? refundProof.name : 'Choose official GCash refund proof'}</span>
                    <small>JPEG, PNG, WebP, or PDF — maximum 5 MB</small>
                    <input {...refundFieldProps('proof', 'Official GCash refund proof *')} type="file" accept="image/jpeg,image/png,image/webp,application/pdf" onChange={(event) => { setRefundProof(event.target.files?.[0] || null); clearRefundError('proof'); }} disabled={saving} />
                  </label>
                  <RefundFieldError field="proof" errors={refundErrors} />

                  <label className="manual-refund-confirmation">
                    <input {...refundFieldProps('transferConfirmed', 'Confirm the refund was already sent')} type="checkbox" checked={refundForm.transferConfirmed} onChange={(event) => updateRefundField('transferConfirmed', event.target.checked)} disabled={saving} />
                    <span>I have checked the recipient details and confirm the refund was already sent from the official hotel GCash account.</span>
                  </label>
                  <RefundFieldError field="transferConfirmed" errors={refundErrors} />
                </section>
              )}

              {selected.manualGcashRefund?.status === 'completed' && (
                <section className="manual-refund-record">
                  <div><span>GCash reference</span><strong>{selected.manualGcashRefund.gcashReference}</strong></div>
                  <div><span>Recipient</span><strong>{selected.manualGcashRefund.recipientName} · {selected.manualGcashRefund.recipientAccount}</strong></div>
                  <div><span>Processed</span><strong>{selected.manualGcashRefund.processedAt} by {selected.manualGcashRefund.processedBy}</strong></div>
                  {selected.manualGcashRefund.proofUrl && (
                    <button
                      type="button"
                      className="manual-refund-proof-link"
                      onClick={handleViewRefundProof}
                      disabled={viewingRefundProof}
                    >
                      {viewingRefundProof ? 'Opening private refund proof...' : 'View private refund proof'}
                      <ExternalLink size={14} />
                    </button>
                  )}
                  {selected.manualGcashRefund.proofDeletedAt && (
                    <div><span>Refund proof</span><strong>Securely retired {selected.manualGcashRefund.proofDeletedAt}</strong></div>
                  )}
                </section>
              )}
            </div>

            <div className="modal-footer">
              <button className="btn-secondary modal-close-compact" onClick={() => setSelected(null)} disabled={saving}>Close</button>
              {selected.canReject && <button className="btn-danger" onClick={handleReject} disabled={saving}>Reject</button>}
              {selected.canApprove && <button className="btn-primary" onClick={handleApprove} disabled={saving}>{selected.previouslyApproved ? 'Cancel Approved Reservation' : 'Approve Cancellation'}</button>}
              {selected.canProcessRefund && <button className="btn-primary" onClick={handleProcessRefund} disabled={saving}>{selected.requiresManualGcashEvidence ? 'Record Completed Refund' : 'Process Refund'}</button>}
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default AdminCancellationApprovals;

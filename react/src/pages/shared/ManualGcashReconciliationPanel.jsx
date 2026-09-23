import React, { useCallback, useEffect, useState } from 'react';
import {
  AlertTriangle,
  CheckCircle2,
  Clock3,
  FileWarning,
  Loader,
  RefreshCw,
  Search,
  ShieldAlert,
  X,
} from 'lucide-react';
import { showToast } from '../../utils/showToast';
import { formatCurrency } from '../../utils/currency';
import StatusBadge from '../../components/StatusBadge';
import TableActionButton from '../../components/TableActionButton';
import './ManualGcashReconciliationPanel.css';

const emptySummary = {
  pending_verification: 0,
  escalated_review: 0,
  pending_review: 0,
  overdue_review: 0,
  unreconciled: 0,
  overdue_unreconciled: 0,
  oldest_unreconciled_age_minutes: null,
  reconciliation_max_age_minutes: 1440,
  open_exceptions: 0,
  refund_required: 0,
};

const formatAge = (minutes) => {
  if (!Number.isFinite(Number(minutes))) return null;
  const totalMinutes = Math.max(0, Number(minutes));
  const days = Math.floor(totalMinutes / 1440);
  const hours = Math.floor((totalMinutes % 1440) / 60);
  if (days > 0) return `${days}d ${hours}h`;
  if (hours > 0) return `${hours}h`;
  return `${Math.floor(totalMinutes)}m`;
};

const timingLabel = (timing) => {
  if (!timing) return null;
  if (timing.status === 'overdue') return `Overdue by ${formatAge(timing.overdue_minutes)}`;
  if (timing.status === 'due_soon') return `Due in ${formatAge(timing.minutes_remaining)}`;
  return null;
};

const emptyForm = {
  statement_reference: '',
  statement_amount: '',
  statement_paid_at: '',
  merchant_record_confirmed: false,
  notes: '',
  exception_type: 'missing_transaction',
  resolution: 'matched',
  resolution_notes: '',
};

const exceptionLabels = {
  missing_transaction: 'Missing transaction',
  duplicate_reference: 'Duplicate reference',
  amount_mismatch: 'Amount mismatch',
  time_mismatch: 'Paid-time mismatch',
  reversed_transaction: 'Reversed transaction',
  other: 'Other discrepancy',
};

const ManualGcashReconciliationPanel = ({ api, role, refreshKey, queue, onQueueChange, onSummaryChange }) => {
  const [summary, setSummary] = useState(emptySummary);
  const [rows, setRows] = useState([]);
  const [historyStatus, setHistoryStatus] = useState('');
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [selected, setSelected] = useState(null);
  const [action, setAction] = useState('match');
  const [form, setForm] = useState(emptyForm);

  const selectedStatus = selected?.reconciliation?.status || 'unreconciled';
  const isOpenException = selectedStatus === 'exception_open';
  const canResolve = isOpenException && role === 'admin';

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [summaryResponse, rowsResponse] = await Promise.all([
        api.get('/manual-gcash-reconciliations/summary'),
        api.get('/manual-gcash-reconciliations', {
          params: {
            queue,
            status: queue === 'history' ? historyStatus || undefined : undefined,
            search: search || undefined,
          },
        }),
      ]);
      const nextSummary = { ...emptySummary, ...(summaryResponse.data || {}) };
      setSummary(nextSummary);
      onSummaryChange(nextSummary);
      setRows(rowsResponse.data?.data || []);
    } catch (error) {
      showToast(error.response?.data?.message || 'Unable to load GCash reconciliation records.', 'error');
    } finally {
      setLoading(false);
    }
  }, [api, historyStatus, onSummaryChange, queue, search]);

  useEffect(() => { load(); }, [load, refreshKey]);

  const queueDetails = {
    needs_reconciliation: {
      title: 'Payments ready to reconcile',
      description: 'Match approved payments against the official daily merchant statement.',
    },
    exceptions: {
      title: 'Open reconciliation exceptions',
      description: role === 'admin'
        ? 'Investigate and resolve statement discrepancies.'
        : 'These discrepancies are visible here while they await an administrator decision.',
    },
    history: {
      title: 'Reconciliation history',
      description: 'Look up matched payments and resolved exceptions.',
    },
  }[queue];

  const open = (row) => {
    const currentStatus = row.reconciliation?.status || 'unreconciled';
    setSelected(row);
    setAction(currentStatus === 'matched' ? 'exception' : currentStatus === 'exception_open' ? 'resolve' : 'match');
    setForm({
      ...emptyForm,
      statement_reference: row.reconciliation?.statement_reference || row.transaction_reference || '',
      statement_amount: String(row.reconciliation?.statement_amount ?? row.amount ?? ''),
      statement_paid_at: row.paid_at_local || '',
      notes: row.reconciliation?.notes || '',
      exception_type: row.reconciliation?.exception_type || 'missing_transaction',
    });
  };

  const close = () => {
    setSelected(null);
    setForm(emptyForm);
  };

  const submit = async () => {
    setSaving(true);
    try {
      if (action === 'match') {
        await api.post(`/manual-gcash-reconciliations/submissions/${selected.submission_id}/match`, {
          statement_reference: form.statement_reference,
          statement_amount: form.statement_amount,
          statement_paid_at: form.statement_paid_at,
          merchant_record_confirmed: form.merchant_record_confirmed,
          notes: form.notes,
        });
        showToast('Payment reconciled with the merchant statement.', 'success');
      } else if (action === 'exception') {
        await api.post(`/manual-gcash-reconciliations/submissions/${selected.submission_id}/exception`, {
          exception_type: form.exception_type,
          notes: form.notes,
          statement_reference: form.statement_reference || null,
          statement_amount: form.statement_amount || null,
          statement_paid_at: form.statement_paid_at || null,
        });
        showToast('Reconciliation exception sent to administrators.', 'success');
      } else {
        const payload = {
          resolution: form.resolution,
          resolution_notes: form.resolution_notes,
        };
        if (form.resolution === 'matched') {
          Object.assign(payload, {
            statement_reference: form.statement_reference,
            statement_amount: form.statement_amount,
            statement_paid_at: form.statement_paid_at,
            merchant_record_confirmed: form.merchant_record_confirmed,
          });
        }
        await api.post(`/manual-gcash-reconciliations/${selected.reconciliation.id}/resolve`, payload);
        showToast('Reconciliation exception resolved.', 'success');
      }
      close();
      await load();
    } catch (error) {
      const validation = error.response?.data?.errors;
      showToast(validation ? Object.values(validation).flat()[0] : error.response?.data?.message || 'Reconciliation action failed.', 'error');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="gcash-reconciliation">
      <div className="gcash-reconciliation-queue-tabs" role="tablist" aria-label="Reconciliation queues">
        <button type="button" role="tab" aria-selected={queue === 'needs_reconciliation'} className={queue === 'needs_reconciliation' ? 'active' : ''} onClick={() => onQueueChange('needs_reconciliation')}>Needs Reconciliation <span>{summary.unreconciled}</span></button>
        <button type="button" role="tab" aria-selected={queue === 'exceptions'} className={queue === 'exceptions' ? 'active attention' : ''} onClick={() => onQueueChange('exceptions')}>Exceptions <span>{summary.open_exceptions}</span></button>
        <button type="button" role="tab" aria-selected={queue === 'history'} className={queue === 'history' ? 'active' : ''} onClick={() => onQueueChange('history')}>Reconciliation History</button>
      </div>

      {queue === 'needs_reconciliation' && summary.overdue_unreconciled > 0 && <div className="gcash-reconciliation-overdue"><AlertTriangle size={16} /><span><strong>{summary.overdue_unreconciled} overdue</strong> · Oldest waiting {formatAge(summary.oldest_unreconciled_age_minutes)}</span></div>}

      <div className="gcash-reconciliation-toolbar">
        <div className="gcash-reconciliation-search">
          <Search size={16} />
          <input value={search} onChange={(event) => setSearch(event.target.value)} onKeyDown={(event) => event.key === 'Enter' && load()} placeholder="Search booking, guest, or merchant reference" />
        </div>
        {queue === 'history' && <select value={historyStatus} onChange={(event) => setHistoryStatus(event.target.value)}>
          <option value="">All Completed Records</option>
          <option value="matched">Matched</option>
          <option value="exception_resolved">Resolved Exceptions</option>
        </select>}
        <button type="button" onClick={load}><RefreshCw size={15} /> Refresh</button>
      </div>

      <div className="gcash-reconciliation-table-card">
        <div className="gcash-reconciliation-table-title">
          <div><Clock3 size={17} /><span><strong>{queueDetails.title}</strong><small>{queueDetails.description}</small></span></div>
          <span>{rows.length} {rows.length === 1 ? 'record' : 'records'}</span>
        </div>
        {loading ? (
          <div className="gcash-reconciliation-empty"><Loader className="spin" size={22} /><strong>Loading reconciliation queue...</strong></div>
        ) : rows.length === 0 ? (
          <div className="gcash-reconciliation-empty"><CheckCircle2 size={28} /><strong>No records in this queue</strong><small>Try another status or search.</small></div>
        ) : (
          <div className="gcash-reconciliation-table-wrap">
            <table>
              <thead><tr><th>Paid</th><th>Booking</th><th>Guest</th><th>Merchant Reference</th><th>Amount</th><th>Reviewed By</th><th>Status</th><th aria-label="Actions" /></tr></thead>
              <tbody>
                {rows.map((row) => {
                  const rowStatus = row.reconciliation?.status || 'unreconciled';
                  const timing = row.reconciliation_timing;
                  const attentionLabel = timingLabel(timing);
                  return (
                    <tr className={timing?.status === 'overdue' ? 'is-overdue' : ''} key={row.submission_id}>
                      <td>{new Date(row.paid_at).toLocaleString()}</td>
                      <td className="gcash-reconciliation-code">{row.booking_reference}</td>
                      <td><strong>{row.guest_name}</strong></td>
                      <td className="gcash-reconciliation-code">{row.transaction_reference}</td>
                      <td className="gcash-reconciliation-amount">{formatCurrency(row.amount)}</td>
                      <td>{row.reviewed_by || '—'}</td>
                      <td>
                        <div className="gcash-reconciliation-status-stack">
                          <StatusBadge status={rowStatus} />
                          {attentionLabel && <StatusBadge status={timing.status} label={attentionLabel} />}
                        </div>
                      </td>
                      <td><TableActionButton label="Open reconciliation" onClick={() => open(row)}>Open</TableActionButton></td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {selected && (
        <div className="gcash-reconciliation-modal" role="dialog" aria-modal="true" aria-labelledby="gcash-reconciliation-title" onMouseDown={(event) => event.target === event.currentTarget && close()}>
          <div className="gcash-reconciliation-dialog">
            <div className="gcash-reconciliation-dialog-header">
              <div><span>Daily reconciliation</span><h2 id="gcash-reconciliation-title">{selected.booking_reference}</h2></div>
              <button type="button" onClick={close} aria-label="Close reconciliation"><X size={18} /></button>
            </div>
            <div className="gcash-reconciliation-dialog-body">
              <div className="gcash-reconciliation-system-record">
                <h3>System payment record</h3>
                <dl>
                  <div><dt>Guest</dt><dd>{selected.guest_name}</dd></div>
                  <div><dt>Merchant reference</dt><dd>{selected.transaction_reference}</dd></div>
                  <div><dt>Amount</dt><dd>{formatCurrency(selected.amount)}</dd></div>
                  <div><dt>Paid time</dt><dd>{new Date(selected.paid_at).toLocaleString()}</dd></div>
                  <div><dt>Proof reviewer</dt><dd>{selected.reviewed_by || '—'}</dd></div>
                  {selected.reconciliation_timing && <div><dt>Reconcile by</dt><dd className={selected.reconciliation_timing.status}>{new Date(selected.reconciliation_timing.due_at).toLocaleString()} {timingLabel(selected.reconciliation_timing) && `(${timingLabel(selected.reconciliation_timing)})`}</dd></div>}
                </dl>
                {isOpenException && <div className="gcash-reconciliation-warning"><AlertTriangle size={17} /><div><strong>{exceptionLabels[selected.reconciliation.exception_type]}</strong><p>{selected.reconciliation.notes}</p></div></div>}
              </div>

              <div className="gcash-reconciliation-action">
                {!isOpenException && (
                  <div className="gcash-reconciliation-action-tabs">
                    {selectedStatus !== 'matched' && <button type="button" className={action === 'match' ? 'active' : ''} onClick={() => setAction('match')}><CheckCircle2 size={15} /> Exact Match</button>}
                    <button type="button" className={action === 'exception' ? 'active danger' : ''} onClick={() => setAction('exception')}><AlertTriangle size={15} /> Flag Exception</button>
                  </div>
                )}

                {isOpenException && !canResolve ? (
                  <div className="gcash-reconciliation-admin-only"><ShieldAlert size={21} /><div><strong>Administrator action required</strong><p>This exception cannot be closed by a receptionist.</p></div></div>
                ) : action === 'resolve' ? (
                  <div className="gcash-reconciliation-form">
                    <label><span>Administrator resolution</span><select value={form.resolution} onChange={(event) => setForm({ ...form, resolution: event.target.value })}><option value="matched">Exact statement match found</option><option value="payment_review_required">Keep payment under review</option><option value="refund_required">Refund workflow required</option></select></label>
                    {form.resolution === 'matched' && <StatementFields form={form} setForm={setForm} />}
                    <label><span>Resolution notes</span><textarea value={form.resolution_notes} onChange={(event) => setForm({ ...form, resolution_notes: event.target.value })} placeholder="Explain the evidence and final decision." /></label>
                  </div>
                ) : action === 'match' ? (
                  <div className="gcash-reconciliation-form"><StatementFields form={form} setForm={setForm} /><label><span>Optional note</span><textarea value={form.notes} onChange={(event) => setForm({ ...form, notes: event.target.value })} placeholder="Daily statement or reconciliation note." /></label></div>
                ) : (
                  <div className="gcash-reconciliation-form">
                    <label><span>Exception type</span><select value={form.exception_type} onChange={(event) => setForm({ ...form, exception_type: event.target.value })}>{Object.entries(exceptionLabels).map(([value, label]) => <option value={value} key={value}>{label}</option>)}</select></label>
                    <StatementFields form={form} setForm={setForm} confirmation={false} />
                    <label><span>Required discrepancy details</span><textarea value={form.notes} onChange={(event) => setForm({ ...form, notes: event.target.value })} placeholder="Describe what differs from the official merchant statement." /></label>
                  </div>
                )}

                {(!isOpenException || canResolve) && <button type="button" className={`gcash-reconciliation-submit ${action === 'exception' ? 'danger' : ''}`} onClick={submit} disabled={saving}>{saving ? <><Loader className="spin" size={15} /> Saving...</> : action === 'exception' ? 'Send to Exception Queue' : action === 'resolve' ? 'Save Administrator Resolution' : 'Mark as Reconciled'}</button>}
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

const StatementFields = ({ form, setForm, confirmation = true }) => (
  <>
    <label><span>Statement reference</span><input value={form.statement_reference} onChange={(event) => setForm({ ...form, statement_reference: event.target.value })} /></label>
    <div className="gcash-reconciliation-form-row">
      <label><span>Statement amount</span><input type="number" step="0.01" value={form.statement_amount} onChange={(event) => setForm({ ...form, statement_amount: event.target.value })} /></label>
      <label><span>Statement paid time</span><input type="datetime-local" value={form.statement_paid_at} onChange={(event) => setForm({ ...form, statement_paid_at: event.target.value })} /></label>
    </div>
    {confirmation && <label className="gcash-reconciliation-check"><input type="checkbox" checked={form.merchant_record_confirmed} onChange={(event) => setForm({ ...form, merchant_record_confirmed: event.target.checked })} /><span>I matched these details against the official merchant GCash statement.</span></label>}
  </>
);

export default ManualGcashReconciliationPanel;

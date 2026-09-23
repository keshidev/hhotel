import { useNotificationTarget } from '../../hooks/useNotificationTarget';
import React, { useState, useEffect, useCallback, useMemo } from 'react';
import {
  Search, Eye, Download, X,
  ShieldAlert, RefreshCw, User,
  Layers, FileText
} from 'lucide-react';
import jsPDF from 'jspdf';
import autoTable from 'jspdf-autotable';
import './AuditTrail.css';
import AuditReadableDetails from './AuditReadableDetails';
import { activityLabel, auditArea, auditSummary } from '../../utils/auditPresentation';
import auditService from '../../services/auditService';
import { showToast } from '../../utils/showToast';
import Pagination from '../../components/Pagination';
import TableActionButton from '../../components/TableActionButton';
import { StaffDatePicker } from '../../components/StaffDatePicker';
import ReportChartCard, { createReportChartOptions, formatChartDate } from '../../components/reports/ReportChartCard';

// ── helpers ───────────────────────────────────────────────────
const debounce = (fn, ms) => {
  let t;
  return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
};

const ITEMS_PER_PAGE = 20;

// ── component ─────────────────────────────────────────────────
const AuditTrail = () => {
  const [logs,     setLogs]     = useState([]);
  const [modules,  setModules]  = useState([]);
  const [total,    setTotal]    = useState(0);
  const [lastPage, setLastPage] = useState(1);
  const [activitySummary, setActivitySummary] = useState([]);

  useNotificationTarget((target) => { setSearch(target.search); setModule(''); setAction(''); setStaffUser(''); setDateFrom(''); setDateTo(''); setPage(1); setSelected(null); });
  const [search,    setSearch]    = useState('');
  const [module,    setModule]    = useState('');
  const [action,    setAction]    = useState('');
  const [staffUser, setStaffUser] = useState('');
  const [dateFrom,  setDateFrom]  = useState('');
  const [dateTo,    setDateTo]    = useState('');
  const [page,      setPage]      = useState(1);

  const [loading,       setLoading]       = useState(true);
  const [exporting,     setExporting]     = useState(false);
  const [error,         setError]         = useState(null);
  const [selected,      setSelected]      = useState(null);
  const [detailLog,     setDetailLog]     = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);

  const activityChartOptions = useMemo(() => {
    const dates = [...new Set(activitySummary.map((row) => row.date))].sort();
    const actions = [...new Set(activitySummary.map((row) => row.action || 'other'))].sort();
    const counts = new Map(activitySummary.map((row) => [`${row.date}:${row.action || 'other'}`, Number(row.count ?? 0)]));
    const colors = {
      created: '#10b981',
      updated: '#1a4bcc',
      deleted: '#ef4444',
      viewed: '#7c3aed',
      exported: '#f59e0b',
      other: '#64748b',
    };

    return createReportChartOptions({
      chart: { type: 'column', height: 330, zooming: { type: 'x' } },
      xAxis: {
        categories: dates.map(formatChartDate),
        crosshair: true,
        labels: {
          step: Math.max(1, Math.ceil(dates.length / 10)),
          style: { color: '#64748b', fontSize: '11px' },
        },
      },
      yAxis: {
        min: 0,
        allowDecimals: false,
        title: { text: 'Recorded actions' },
      },
      tooltip: { valueSuffix: ' action(s)' },
      plotOptions: { column: { stacking: 'normal' } },
      series: actions.map((actionName, index) => ({
        name: actionName.replace(/_/g, ' ').replace(/\b\w/g, (character) => character.toUpperCase()),
        color: colors[actionName] ?? ['#0891b2', '#db2777', '#64748b'][index % 3],
        data: dates.map((date) => counts.get(`${date}:${actionName}`) ?? 0),
      })),
    });
  }, [activitySummary]);

  // ── fetch ──────────────────────────────────────────────────
  const fetchLogs = useCallback(async (params = {}) => {
    setLoading(true);
    setError(null);
    try {
      const res = await auditService.getLogs({
        search:    params.search    ?? search,
        module:    params.module    ?? module,
        action:    params.action    ?? action,
        user:      params.staffUser ?? staffUser,
        date_from: params.dateFrom  ?? dateFrom,
        date_to:   params.dateTo    ?? dateTo,
        page:      params.page      ?? page,
        per_page:  ITEMS_PER_PAGE,
      });
      setLogs(res.data?.data       ?? []);
      setTotal(res.data?.total     ?? 0);
      setLastPage(res.data?.last_page ?? 1);
      setActivitySummary(res.activity_summary ?? []);
      if (res.modules?.length) setModules(res.modules);
    } catch {
      setError('Failed to load audit logs. Please try again.');
    } finally {
      setLoading(false);
    }
  }, [search, module, action, staffUser, dateFrom, dateTo, page]);

  useEffect(() => { fetchLogs(); }, []);

  const debouncedFetch = useCallback(debounce(fetchLogs, 400), [fetchLogs]);

  useEffect(() => {
    setPage(1);
    debouncedFetch({ page: 1 });
  }, [search, module, action, staffUser, dateFrom, dateTo]);

  useEffect(() => { fetchLogs({ page }); }, [page]);

  // ── detail modal ───────────────────────────────────────────
  const openDetail = async (id) => {
    setSelected(id);
    setDetailLog(null);
    setDetailLoading(true);
    try {
      const res = await auditService.getLog(id);
      setDetailLog(res.data);
    } catch {
      setDetailLog(null);
    } finally {
      setDetailLoading(false);
    }
  };

  const closeDetail = () => { setSelected(null); setDetailLog(null); };

  // ── clear filters ──────────────────────────────────────────
  const clearFilters = () => {
    setSearch(''); setModule(''); setAction('');
    setStaffUser(''); setDateFrom(''); setDateTo('');
    setPage(1);
  };

  const hasActiveFilters = search || module || action || staffUser || dateFrom || dateTo;

  // ── PDF Export ─────────────────────────────────────────────
  const handleExport = async () => {
    if (!logs.length) { showToast('No data to export', 'error'); return; }
    setExporting(true);
    try {
      const doc        = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
      const brandColor = [26, 26, 26];
      const accentGray = [100, 116, 139];
      const lightGray  = [248, 248, 248];
      const midGray    = [200, 200, 200];
      const pageW      = doc.internal.pageSize.getWidth();

      // ── Header bar ──
      doc.setFillColor(...brandColor);
      doc.rect(0, 0, pageW, 60, 'F');
      doc.setTextColor(255, 255, 255);
      doc.setFontSize(20);
      doc.setFont('helvetica', 'bold');
      doc.text('H+ HOTEL', 40, 38);
      doc.setFontSize(11);
      doc.setFont('helvetica', 'normal');
      doc.setTextColor(...accentGray);
      doc.text('Audit Trail Report', 40, 52);
      doc.setTextColor(200, 200, 200);
      doc.setFontSize(9);
      doc.text(
        `Generated: ${new Date().toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' })}`,
        pageW - 40, 38, { align: 'right' }
      );
      doc.text(`Total Records: ${total}`, pageW - 40, 52, { align: 'right' });

      let y = 80;

      // ── Filter summary boxes ──
      const activeFilters = [
        dateFrom && `From: ${dateFrom}`,
        dateTo   && `To: ${dateTo}`,
        module   && `Module: ${module}`,
        staffUser && `Staff: ${staffUser}`,
        search   && `Search: "${search}"`,
      ].filter(Boolean);

      if (activeFilters.length > 0) {
        const cols  = Math.min(activeFilters.length, 4);
        const boxW  = (pageW - 80) / cols;
        activeFilters.forEach((f, i) => {
          const x = 40 + i * boxW;
          doc.setFillColor(...lightGray);
          doc.roundedRect(x, y, boxW - 8, 32, 3, 3, 'F');
          doc.setDrawColor(...midGray);
          doc.roundedRect(x, y, boxW - 8, 32, 3, 3, 'S');
          doc.setFontSize(8);
          doc.setFont('helvetica', 'normal');
          doc.setTextColor(100, 100, 100);
          doc.text('FILTER', x + 8, y + 11);
          doc.setFontSize(9);
          doc.setFont('helvetica', 'bold');
          doc.setTextColor(...brandColor);
          doc.text(f, x + 8, y + 24);
        });
        y += 48;
      }

      // ── Section label ──
      doc.setFontSize(10);
      doc.setFont('helvetica', 'bold');
      doc.setTextColor(...brandColor);
      doc.text('AUDIT LOG ENTRIES', 40, y);
      doc.setDrawColor(...accentGray);
      doc.setLineWidth(1);
      doc.line(40, y + 4, pageW - 40, y + 4);
      y += 18;

      // ── Audit log table ──
      autoTable(doc, {
        startY: y,
        head: [['#', 'Staff', 'Activity', 'Area', 'Summary', 'Date & Time']],
        body: logs.map((log, i) => [
          String(i + 1),
          log.user_staff_name ?? '-',
          activityLabel(log),
          auditArea(log),
          auditSummary(log),
          log.date_time ?? '-',
        ]),
        styles: {
          fontSize: 7.5,
          cellPadding: 5,
          textColor: [50, 50, 50],
          lineColor: [226, 232, 240],
          lineWidth: 0.5,
          overflow: 'linebreak',
        },
        headStyles: {
          fillColor: brandColor,
          textColor: [255, 255, 255],
          fontStyle: 'bold',
          fontSize: 7.5,
        },
        alternateRowStyles: {
          fillColor: [248, 250, 252],
        },
        columnStyles: { 0: { cellWidth: 22 }, 4: { cellWidth: 240 } },
        didDrawPage: () => {
          const pageCount   = doc.internal.getNumberOfPages();
          const currentPage = doc.internal.getCurrentPageInfo().pageNumber;
          doc.setFillColor(...brandColor);
          doc.rect(0, doc.internal.pageSize.getHeight() - 24, pageW, 24, 'F');
          doc.setFontSize(8);
          doc.setTextColor(...accentGray);
          doc.text('H+ HOTEL - Confidential', 40, doc.internal.pageSize.getHeight() - 9);
          doc.setTextColor(180, 180, 180);
          doc.text(
            `Page ${currentPage} of ${pageCount}  |  Audit Trail Report  |  Generated ${new Date().toLocaleDateString('en-PH')}`,
            pageW / 2,
            doc.internal.pageSize.getHeight() - 9,
            { align: 'center' }
          );
        },
      });

      doc.save(`audit-trail-${new Date().toISOString().slice(0, 10)}.pdf`);
      showToast('PDF exported successfully!', 'success');
    } catch (err) {
      console.error('PDF Export Error:', err);
      showToast(`Export failed: ${err.message}`, 'error');
    } finally {
      setExporting(false);
    }
  };

  // ── render ─────────────────────────────────────────────────
  return (
    <div className="audit-page">

      {/* Page header */}
      <div className="audit-page-header">
        <div>
          <h1>Audit Trail</h1>
          <p>Track all staff actions - who did what, when, and where.</p>
        </div>
        <button
          className="audit-export-btn"
          onClick={handleExport}
          disabled={exporting || loading}
        >
          <Download size={15} />
          {exporting ? 'Exporting...' : 'Export PDF'}
        </button>
      </div>

      {/* Filters */}
      <div className="audit-filter-row">
        <div className="audit-search-wrap">
          <Search size={15} />
          <input
            type="text"
            placeholder="Search staff, action, record..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>

        <div className="audit-filter-select">
          <Layers size={15} />
          <select value={module} onChange={(e) => setModule(e.target.value)}>
            <option value="">All Modules</option>
            {modules.map((m) => <option key={m} value={m}>{m}</option>)}
          </select>
        </div>

        <div className="audit-search-wrap" style={{ minWidth: 160, flex: 'unset' }}>
          <User size={15} />
          <input
            type="text"
            placeholder="Filter by staff..."
            value={staffUser}
            onChange={(e) => setStaffUser(e.target.value)}
          />
        </div>

        <div className="audit-date-picker">
          <StaffDatePicker
            value={dateFrom}
            onChange={setDateFrom}
            emptyLabel="Start date"
            ariaLabel="Select audit start date"
            clearable
          />
        </div>

        <div className="audit-date-picker">
          <StaffDatePicker
            value={dateTo}
            onChange={setDateTo}
            emptyLabel="End date"
            ariaLabel="Select audit end date"
            clearable
          />
        </div>

        {hasActiveFilters && (
          <button className="audit-clear-btn" onClick={clearFilters}>
            <X size={14} /> Clear
          </button>
        )}

        <button className="audit-clear-btn" onClick={() => fetchLogs()} disabled={loading}>
          <RefreshCw size={14} className={loading ? 'spin' : ''} />
        </button>
      </div>

      <ReportChartCard
        title="Audit Activity Trend"
        subtitle={dateFrom || dateTo ? 'Actions matching the selected filters' : 'Recorded actions during the last 30 days'}
        badge="Daily activity"
        options={activityChartOptions}
        empty={activitySummary.length === 0}
      />

      {/* Error */}
      {error && (
        <div className="audit-error-banner">
          <ShieldAlert size={16} /> {error}
        </div>
      )}

      {/* Table */}
      <div className="audit-table-card">
        <div className="audit-table-wrap">
          <table className="audit-table">
            <thead>
              <tr>
                <th>Staff</th>
                <th>Activity</th>
                <th>Area</th>
                <th>Summary</th>
                <th>Date &amp; Time</th>
                <th>Details</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                Array.from({ length: 6 }).map((_, i) => (
                  <tr key={i} className="audit-skeleton-row">
                    {Array.from({ length: 6 }).map((__, j) => (
                      <td key={j}>
                        <div className="audit-skeleton-cell" style={{ width: j === 0 ? '120px' : j === 6 ? '140px' : '80px' }} />
                      </td>
                    ))}
                  </tr>
                ))
              ) : logs.length === 0 ? (
                <tr>
                  <td colSpan={6} className="audit-empty">
                    <div className="audit-empty-icon"><ShieldAlert size={36} /></div>
                    <div>No audit logs found.</div>
                    {hasActiveFilters && <div style={{ fontSize: '0.82rem', marginTop: 4 }}>Try clearing your filters.</div>}
                  </td>
                </tr>
              ) : (
                logs.map((log) => (
                  <tr key={log.id}>
                    <td><span className="audit-staff-name">{log.user_staff_name}</span></td>
                    <td><span className="audit-action">{activityLabel(log)}</span></td>
                    <td><span className="audit-module"><Layers size={11} />{auditArea(log)}</span></td>
                    <td><span className="audit-summary">{auditSummary(log)}</span></td><td><span className="audit-datetime">{log.date_time}</span></td>
                    <td>
                      <TableActionButton iconOnly label="View audit details" onClick={() => openDetail(log.id)}>
                        <Eye size={14} />
                      </TableActionButton>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {/* Shared Pagination component */}
        {!loading && logs.length > 0 && (
          <Pagination
            currentPage={page}
            totalPages={lastPage}
            totalItems={total}
            itemsPerPage={ITEMS_PER_PAGE}
            onPageChange={(newPage) => setPage(newPage)}
            onItemsPerPageChange={() => {}}
            pageSizeOptions={[20]}
          />
        )}
      </div>

      {/* Detail Modal */}
      {selected && (
        <div className="audit-modal-overlay" onClick={closeDetail}>
          <div className="audit-modal" onClick={(e) => e.stopPropagation()}>
            <div className="audit-modal-header">
              <h3>Activity Details #{selected}</h3>
              <button className="audit-modal-close" onClick={closeDetail}><X size={18} /></button>
            </div>

            <div className="audit-modal-body">
              {detailLoading ? (
                <div style={{ textAlign: 'center', padding: '2rem', color: 'var(--color-text-secondary)' }}>
                  Loading...
                </div>
              ) : detailLog ? (
                <>
                  <div className="audit-modal-section">
                    <div className="audit-modal-section-title"><User size={13} /> Who &amp; When</div>
                    <div className="audit-modal-row"><span>Staff</span><strong>{detailLog.user_staff_name}</strong></div>
                    <div className="audit-modal-row"><span>Date &amp; Time</span><strong>{detailLog.date_time}</strong></div>
                  </div>

                  <div className="audit-modal-section">
                    <div className="audit-modal-section-title"><FileText size={13} /> What &amp; Where</div>
                    <div className="audit-modal-row"><span>Action</span><strong>{activityLabel(detailLog)}</strong></div>
                    <div className="audit-modal-row"><span>Module</span><strong>{auditArea(detailLog)}</strong></div>
                    <div className="audit-modal-row"><span>Summary</span><strong>{auditSummary(detailLog)}</strong></div>
                  </div>

                  <AuditReadableDetails key={detailLog.id || selected} log={detailLog} />
                </>
              ) : (
                <div style={{ textAlign: 'center', padding: '2rem', color: 'var(--color-text-secondary)' }}>
                  Could not load log details.
                </div>
              )}
            </div>
          </div>
        </div>
      )}

      <style>{`.spin { animation: auditSpin 1s linear infinite; } @keyframes auditSpin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }`}</style>
    </div>
  );
};

export default AuditTrail;


import React, { useState, useEffect, useMemo } from 'react';
import { Search, Star, ThumbsUp, ThumbsDown, AlertCircle, ChevronDown, X, MessageSquare, Filter, Download } from 'lucide-react';
import jsPDF from 'jspdf';
import autoTable from 'jspdf-autotable';
import api from '../../../services/adminApi';
import Pagination from '../../../components/Pagination';
import { usePagination } from '../../../hooks/usePagination';
import { PageSkeletonLoader, usePageCache } from '../../../components/ProtectedRoute';
import ReportChartCard, { createReportChartOptions } from '../../../components/reports/ReportChartCard';
import StatusBadge from '../../../components/StatusBadge';
import TableActionButton from '../../../components/TableActionButton';
import {
  BASE_TABLE_STYLES,
  drawPageFooter,
  drawPageHeader,
  drawSectionDivider,
  drawSummaryCards,
  formatRoomType,
  sanitizePdfText,
} from '../../../utils/pdfStyles';
import './Reports.css';

const showToast = (message, type = 'success') => {
  const toast = document.createElement('div');
  toast.className = `simple-toast toast-${type}`;
  toast.textContent = message;
  document.body.appendChild(toast);
  setTimeout(() => toast.classList.add('show'), 10);
  setTimeout(() => {
    toast.classList.remove('show');
    setTimeout(() => { if (toast.parentNode) document.body.removeChild(toast); }, 300);
  }, 3000);
};

const Stars = ({ value, size = 14 }) => (
  <span style={{ display: 'inline-flex', gap: 1 }}>
    {[1,2,3,4,5].map((s) => (
      <Star
        key={s}
        size={size}
        fill={s <= Math.round(value) ? '#F5A623 ' : 'none'}
        strokeWidth={1.5}
        style={{ color: '#F5A623' }}
      />
    ))}
  </span>
);

const StatCard = ({ label, value, sub }) => (
  <div className="report-stat-card">
    <div className="report-stat-label">{label}</div>
    <div className="report-stat-value">{value}</div>
    {sub && <div className="report-stat-sub">{sub}</div>}
  </div>
);

export default function AdminFeedbackReport() {
  const { shouldShowSkeleton, markPageAsLoaded } = usePageCache('admin-feedback');
  const [loading,  setLoading]  = useState(shouldShowSkeleton);
  const [records,  setRecords]  = useState([]);
  const [stats,    setStats]    = useState(null);
  const [selected, setSelected] = useState(null);
  const [reply,    setReply]    = useState('');
  const [replying, setReplying] = useState(false);
  const [publishingId, setPublishingId] = useState(null);
  const [search,   setSearch]   = useState('');
  const [filters,  setFilters]  = useState({ rating: '', date_from: '', date_to: '', has_issue: '', would_recommend: '' });
  const [sortKey, setSortKey] = useState(null);
  const [sortDir, setSortDir] = useState('asc');

  const feedbackChartOptions = useMemo(() => {
    const ratings = [
      ['Cleanliness', Number(stats?.avg_cleanliness ?? 0)],
      ['Comfort', Number(stats?.avg_comfort ?? 0)],
      ['Staff', Number(stats?.avg_staff ?? 0)],
      ['Facilities', Number(stats?.avg_facilities ?? 0)],
      ['Overall', Number(stats?.avg_overall ?? 0)],
    ];

    return createReportChartOptions({
      chart: { type: 'bar', height: 320 },
      legend: { enabled: false },
      xAxis: { categories: ratings.map(([label]) => label), title: { text: null } },
      yAxis: {
        min: 0,
        max: 5,
        tickInterval: 1,
        title: { text: 'Average rating' },
        labels: { format: '{value}★' },
      },
      tooltip: { valueSuffix: ' / 5', valueDecimals: 2 },
      plotOptions: { bar: { colorByPoint: true } },
      colors: ['#1a4bcc', '#0891b2', '#10b981', '#7c3aed', '#f59e0b'],
      series: [{ name: 'Average rating', data: ratings.map(([, value]) => value) }],
    });
  }, [stats]);

  useEffect(() => { fetchFeedbacks(); }, []);

  const fetchFeedbacks = async () => {
    try {
      setLoading(true);
      const params = {};
      Object.entries(filters).forEach(([k, v]) => { if (v !== '') params[k] = v; });
      const res = await api.get('/admin/feedbacks', { params: { ...params, per_page: 9999 } });
      setRecords(res.data?.data?.data ?? []);
      setStats(res.data?.stats ?? null);
      markPageAsLoaded();
    } catch {
      showToast('Failed to load feedbacks', 'error');
    } finally {
      setLoading(false);
    }
  };

  const filtered = useMemo(() => {
    if (!search.trim()) return records;
    const q = search.toLowerCase();
    return records.filter((r) =>
      r.guest_name?.toLowerCase().includes(q) ||
      r.reference_number?.toLowerCase().includes(q) ||
      r.room?.toLowerCase().includes(q) ||
      r.review?.toLowerCase().includes(q)
    );
  }, [records, search]);

  const compareSortValues = (valueA, valueB) => {
    const isEmptyA = valueA === null || valueA === undefined || valueA === '';
    const isEmptyB = valueB === null || valueB === undefined || valueB === '';
    if (isEmptyA && isEmptyB) return 0;
    if (isEmptyA) return 1;
    if (isEmptyB) return -1;

    if (typeof valueA === 'number' && typeof valueB === 'number') {
      return valueA - valueB;
    }

    return String(valueA).localeCompare(String(valueB), undefined, {
      numeric: true,
      sensitivity: 'base',
    });
  };

  const resolveSortValue = (record, key) => {
    switch (key) {
      case 'guest':
        return record.guest_name ?? '';
      case 'booking':
        return record.reference_number ?? '';
      case 'room':
        return record.room ?? '';
      case 'date': {
        const ts = record.submitted_at ? new Date(record.submitted_at).getTime() : null;
        return Number.isFinite(ts) ? ts : null;
      }
      case 'cleanliness':
        return Number(record.rating_cleanliness ?? 0);
      case 'comfort':
        return Number(record.rating_comfort ?? 0);
      case 'staff':
        return Number(record.rating_staff ?? 0);
      case 'facilities':
        return Number(record.rating_facilities ?? 0);
      case 'overall':
        return Number(record.rating_overall ?? 0);
      case 'recommend':
        return record.would_recommend === null ? null : (record.would_recommend ? 1 : 0);
      case 'issue':
        return record.has_issue ? (record.issue_type ?? 'Yes') : null;
      case 'actions':
        return record.admin_reply ? 1 : 0;
      default:
        return null;
    }
  };

  const sortedFiltered = useMemo(() => {
    if (!sortKey) return filtered;

    return [...filtered].sort((a, b) => {
      const result = compareSortValues(resolveSortValue(a, sortKey), resolveSortValue(b, sortKey));
      return sortDir === 'asc' ? result : -result;
    });
  }, [filtered, sortKey, sortDir]);

  const { currentPage, totalPages, itemsPerPage, paginatedData, totalItems, handlePageChange, handleItemsPerPageChange, resetPage } = usePagination(sortedFiltered, 10);

  const sortBy = (key) => {
    if (sortKey === key) {
      setSortDir((prev) => (prev === 'asc' ? 'desc' : 'asc'));
    } else {
      setSortKey(key);
      setSortDir('asc');
    }
    resetPage();
  };

  const sortIndicator = (key) => {
    if (sortKey !== key) return '\u21C5';
    return sortDir === 'asc' ? '\u25B2' : '\u25BC';
  };

  useEffect(() => { resetPage(); }, [search]);

  const handleReply = async () => {
    if (!reply.trim() || !selected) return;
    setReplying(true);
    try {
      await api.post(`/admin/feedbacks/${selected.id}/reply`, { reply: reply.trim() });
      showToast('Reply saved successfully', 'success');
      setSelected((prev) => ({ ...prev, admin_reply: reply.trim(), replied_at: new Date().toISOString() }));
      setRecords((prev) => prev.map((r) => r.id === selected.id ? { ...r, admin_reply: reply.trim() } : r));
      setReply('');
    } catch {
      showToast('Failed to save reply', 'error');
    } finally {
      setReplying(false);
    }
  };

  const handleTogglePublication = async (feedback) => {
    if (!feedback || publishingId) return;

    setPublishingId(feedback.id);
    try {
      const res = await api.patch(`/admin/feedbacks/${feedback.id}/feature`);
      const isFeatured = Boolean(res.data?.is_featured);
      setRecords((prev) => prev.map((record) => (
        record.id === feedback.id ? { ...record, is_featured: isFeatured } : record
      )));
      setSelected((prev) => (
        prev?.id === feedback.id ? { ...prev, is_featured: isFeatured } : prev
      ));
      showToast(isFeatured ? 'Review published on the website' : 'Review removed from the website', 'success');
    } catch (error) {
      showToast(error.response?.data?.message || 'Failed to update publication status', 'error');
    } finally {
      setPublishingId(null);
    }
  };

  const fmtDate = (str) => {
    if (!str) return '—';
    return new Date(str).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  };

  const handleExportPDF = () => {
    const exportRows = sortedFiltered;
    if (!exportRows.length) { showToast('No data to export', 'error'); return; }

    const exportParams = { per_page: 1, audit_event: 'export_pdf' };
    Object.entries(filters).forEach(([key, value]) => {
      if (value !== '') exportParams[key] = value;
    });
    if (sortKey) {
      exportParams.sort_by = sortKey;
      exportParams.sort_direction = sortDir;
    }
    api.get('/admin/feedbacks', { params: exportParams }).catch(() => {});

    try {
      const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
      const today = new Date().toISOString().split('T')[0];
      drawPageHeader(doc, {
        title: 'Guest Feedback Report',
        subtitle: 'Guest satisfaction ratings and reviews',
        dateRange: {
          start: filters.date_from || 'All Dates',
          end: filters.date_to || today,
        },
        totalRecords: exportRows.length,
      });

      let y = 84;
      if (stats) {
        y = drawSummaryCards(doc, [
          { label: 'Total Reviews', value: String(stats.total ?? 0) },
          { label: 'Overall Rating', value: stats.avg_overall ? `${stats.avg_overall} / 5` : 'N/A' },
          { label: 'Cleanliness Avg', value: stats.avg_cleanliness ? `${stats.avg_cleanliness} / 5` : 'N/A' },
          { label: 'Comfort Avg', value: stats.avg_comfort ? `${stats.avg_comfort} / 5` : 'N/A' },
          { label: 'Staff Avg', value: stats.avg_staff ? `${stats.avg_staff} / 5` : 'N/A' },
          { label: 'Facilities Avg', value: stats.avg_facilities ? `${stats.avg_facilities} / 5` : 'N/A' },
          { label: 'Would Recommend', value: `${stats.would_recommend_count ?? 0} / ${stats.total ?? 0}` },
          { label: 'Issues Reported', value: String(stats.has_issue_count ?? 0) },
        ], y);
      }

      y = drawSectionDivider(doc, 'Feedback Records', y);

      autoTable(doc, {
        ...BASE_TABLE_STYLES,
        startY: y,
        rowPageBreak: 'avoid',
        showHead: 'everyPage',
        head: [['Guest', 'Reference', 'Room', 'Date', 'Clean.', 'Comfort', 'Staff', 'Facil.', 'Overall', 'Recommend', 'Issue', 'Review']],
        body: exportRows.map((r) => [
          sanitizePdfText(r.guest_name || 'N/A'),
          sanitizePdfText(r.reference_number || 'N/A'),
          sanitizePdfText(formatRoomType(r.room || 'N/A')),
          r.submitted_at ? new Date(r.submitted_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'N/A',
          r.rating_cleanliness ? `${r.rating_cleanliness}/5` : 'N/A',
          r.rating_comfort ? `${r.rating_comfort}/5` : 'N/A',
          r.rating_staff ? `${r.rating_staff}/5` : 'N/A',
          r.rating_facilities ? `${r.rating_facilities}/5` : 'N/A',
          r.rating_overall ? `${r.rating_overall}/5` : 'N/A',
          r.would_recommend === true ? 'Yes' : r.would_recommend === false ? 'No' : 'N/A',
          r.has_issue
            ? sanitizePdfText(String(r.issue_type || 'Yes').replace(/^\w/, (c) => c.toUpperCase()))
            : 'N/A',
          (sanitizePdfText(r.review || '').replace(/[^\x20-\x7E]/g, '') || 'N/A'),
        ]),
        columnStyles: {
          0: { cellWidth: 70 },
          1: { cellWidth: 130, overflow: 'linebreak' },
          2: { cellWidth: 75, overflow: 'linebreak' },
          3: { cellWidth: 68 },
          4: { cellWidth: 26 },
          5: { cellWidth: 32 },
          6: { cellWidth: 26 },
          7: { cellWidth: 26 },
          8: { cellWidth: 32 },
          9: { cellWidth: 44 },
          10: { cellWidth: 52 },
          11: { cellWidth: 'auto' },
        },
        didDrawPage: () => {
          const currentPage = doc.internal.getCurrentPageInfo().pageNumber;
          if (currentPage > 1) {
            drawPageHeader(doc, {
              title: 'Guest Feedback Report',
              subtitle: 'Guest satisfaction ratings and reviews',
              dateRange: {
                start: filters.date_from || 'All Dates',
                end: filters.date_to || today,
              },
              totalRecords: exportRows.length,
            });
          }
          drawPageFooter(doc, { reportTitle: 'Guest Feedback Report' });
        },
      });

      doc.save(`feedback-report-${new Date().toISOString().split('T')[0]}.pdf`);
      showToast('PDF report exported!', 'success');
    } catch (err) {
      console.error('PDF Export Error:', err);
      showToast(`Export failed: ${err.message}`, 'error');
    }
  };

  if (loading) return <PageSkeletonLoader title="Feedback Report" />;

  return (
    <div className="report-page feedback-report-page">

      {/* PAGE HEADER */}
      <div className="report-page-header">
        <div className="report-page-title">
          <h1>Feedback Report</h1>
          <p className="report-page-subtitle">Guest satisfaction ratings and reviews</p>
        </div>
        <div className="report-header-actions">
          <button className="btn-export" onClick={handleExportPDF}>
            <Download size={16} /> Export PDF
          </button>
        </div>
      </div>

      {/* STAT CARDS */}
      {stats && (
        <div className="report-stats-grid">
          <StatCard label="Total Reviews"   value={stats.total} />
          <StatCard label="Overall Rating"  value={stats.avg_overall ? `${stats.avg_overall}★` : '—'} sub="average" />
          <StatCard label="Cleanliness"     value={stats.avg_cleanliness ?? '—'} sub="average" />
          <StatCard label="Comfort"         value={stats.avg_comfort ?? '—'} sub="average" />
          <StatCard label="Staff"           value={stats.avg_staff ?? '—'} sub="average" />
          <StatCard label="Facilities"      value={stats.avg_facilities ?? '—'} sub="average" />
          <StatCard label="Would Recommend" value={stats.would_recommend_count} sub={`of ${stats.total}`} />
          <StatCard label="Issues Reported" value={stats.has_issue_count} />
        </div>
      )}

      <ReportChartCard
        title="Guest Satisfaction by Category"
        subtitle="Average ratings for feedback matching the active filters"
        badge="5-point scale"
        options={feedbackChartOptions}
        empty={!stats || Number(stats.total ?? 0) === 0}
      />

      {/* TOOLBAR */}
      <div className="report-toolbar">
        <div className="report-search-box">
          <Search size={15} style={{ color: '#6b7280', flexShrink: 0 }} />
          <input
            placeholder="Search guest, booking ID, room, review…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
        <div className="report-select">
          <select value={filters.rating} onChange={(e) => setFilters((f) => ({ ...f, rating: e.target.value }))}>
            <option value="">All Ratings</option>
            {[5,4,3,2,1].map((r) => <option key={r} value={r}>{r} Star{r !== 1 ? 's' : ''}</option>)}
          </select>
          <ChevronDown size={14} className="report-select-icon" />
        </div>
        <div className="report-select">
          <select value={filters.has_issue} onChange={(e) => setFilters((f) => ({ ...f, has_issue: e.target.value }))}>
            <option value="">All</option>
            <option value="1">Has Issue</option>
            <option value="0">No Issue</option>
          </select>
          <ChevronDown size={14} className="report-select-icon" />
        </div>
        <button className="btn-action" onClick={fetchFeedbacks}>
          <Filter size={14} /> Apply
        </button>
      </div>

      {/* TABLE */}
      <div className="table-card">
        <div style={{ overflowX: 'auto' }}>
          <table className="data-table">
            <thead>
              <tr>
                <th className="sortable-th" onClick={() => sortBy('guest')}>Guest <span className={`sort-indicator ${sortKey === 'guest' ? 'active' : ''}`}>{sortIndicator('guest')}</span></th>
                <th className="sortable-th" onClick={() => sortBy('booking')}>Booking <span className={`sort-indicator ${sortKey === 'booking' ? 'active' : ''}`}>{sortIndicator('booking')}</span></th>
                <th className="sortable-th" onClick={() => sortBy('room')}>Room <span className={`sort-indicator ${sortKey === 'room' ? 'active' : ''}`}>{sortIndicator('room')}</span></th>
                <th className="sortable-th" onClick={() => sortBy('date')}>Date <span className={`sort-indicator ${sortKey === 'date' ? 'active' : ''}`}>{sortIndicator('date')}</span></th>
                <th className="sortable-th" onClick={() => sortBy('cleanliness')}>Cleanliness <span className={`sort-indicator ${sortKey === 'cleanliness' ? 'active' : ''}`}>{sortIndicator('cleanliness')}</span></th>
                <th className="sortable-th" onClick={() => sortBy('comfort')}>Comfort <span className={`sort-indicator ${sortKey === 'comfort' ? 'active' : ''}`}>{sortIndicator('comfort')}</span></th>
                <th className="sortable-th" onClick={() => sortBy('staff')}>Staff <span className={`sort-indicator ${sortKey === 'staff' ? 'active' : ''}`}>{sortIndicator('staff')}</span></th>
                <th className="sortable-th" onClick={() => sortBy('facilities')}>Facilities <span className={`sort-indicator ${sortKey === 'facilities' ? 'active' : ''}`}>{sortIndicator('facilities')}</span></th>
                <th className="sortable-th" onClick={() => sortBy('overall')}>Overall <span className={`sort-indicator ${sortKey === 'overall' ? 'active' : ''}`}>{sortIndicator('overall')}</span></th>
                <th className="sortable-th" onClick={() => sortBy('recommend')}>Recommend <span className={`sort-indicator ${sortKey === 'recommend' ? 'active' : ''}`}>{sortIndicator('recommend')}</span></th>
                <th className="sortable-th" onClick={() => sortBy('issue')}>Issue <span className={`sort-indicator ${sortKey === 'issue' ? 'active' : ''}`}>{sortIndicator('issue')}</span></th>
                <th>Website</th>
                <th className="sortable-th" onClick={() => sortBy('actions')}>Actions <span className={`sort-indicator ${sortKey === 'actions' ? 'active' : ''}`}>{sortIndicator('actions')}</span></th>
              </tr>
            </thead>
            <tbody>
              {paginatedData.length === 0 ? (
                <tr>
                  <td colSpan={13} style={{ textAlign: 'center', padding: '3rem', color: '#6b7280', fontSize: '0.9rem' }}>
                    No feedback found.
                  </td>
                </tr>
              ) : paginatedData.map((r) => (
                <tr key={r.id}>
                  <td className="cell-bold" style={{ whiteSpace: 'nowrap' }}>{r.guest_name}</td>
                  <td style={{ fontFamily: 'monospace', fontSize: '0.78rem' }}>{r.reference_number}</td>
                  <td style={{ whiteSpace: 'nowrap' }}>{r.room}</td>
                  <td style={{ whiteSpace: 'nowrap' }}>{fmtDate(r.submitted_at)}</td>
                  <td><Stars value={r.rating_cleanliness} /></td>
                  <td><Stars value={r.rating_comfort} /></td>
                  <td><Stars value={r.rating_staff} /></td>
                  <td><Stars value={r.rating_facilities} /></td>
                  <td>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
                      <Stars value={r.rating_overall} size={15} />
                      <span style={{ fontSize: '0.78rem', color: '#6b7280' }}>{r.rating_overall}</span>
                    </div>
                  </td>
                  <td>
                    {r.would_recommend === true  && <ThumbsUp size={15} style={{ color: '#16a34a' }} />}
                    {r.would_recommend === false && <ThumbsDown size={15} style={{ color: '#ef4444' }} />}
                    {r.would_recommend === null  && <span style={{ color: '#6b7280', fontSize: '0.75rem' }}>—</span>}
                  </td>
                  <td>
                    {r.has_issue
                      ? <StatusBadge status="issue_reported" label={r.issue_type || 'Yes'} tone="danger" />
                      : <span style={{ color: '#6b7280', fontSize: '0.75rem' }}>—</span>
                    }
                  </td>
                  <td>
                    <StatusBadge status={r.is_featured ? 'published' : 'private'} />
                  </td>
                  <td>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' }}>
                        <TableActionButton label="View feedback" onClick={() => { setSelected(r); setReply(r.admin_reply || ''); }}>
                          <MessageSquare size={13} /> View
                        </TableActionButton>
                      </div>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {paginatedData.length > 0 && (
          <Pagination
            currentPage={currentPage} totalPages={totalPages} totalItems={totalItems}
            itemsPerPage={itemsPerPage} onPageChange={handlePageChange}
            onItemsPerPageChange={handleItemsPerPageChange} pageSizeOptions={[10, 25, 50]}
          />
        )}
      </div>

      {/* DETAIL MODAL */}
      {selected && (
        <div
          style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.45)', backdropFilter: 'blur(4px)', zIndex: 9999, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '1rem' }}
          onClick={() => setSelected(null)}
        >
          <div
            style={{ background: '#fff', border: '1px solid #dde3f0', borderRadius: 12, maxWidth: 560, width: '100%', maxHeight: '90vh', overflowY: 'auto', padding: '2rem', boxShadow: '0 20px 60px rgba(0,0,0,0.15)' }}
            onClick={(e) => e.stopPropagation()}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '1.5rem' }}>
              <div>
                <h3 style={{ fontFamily: "'Inter', sans-serif", fontSize: '1.25rem', fontWeight: 500, color: '#0d1b3e', margin: 0 }}>Feedback Detail</h3>
                <p style={{ fontSize: '0.78rem', color: '#6b7280', marginTop: 3 }}>{selected.reference_number} · {fmtDate(selected.submitted_at)}</p>
              </div>
              <button onClick={() => setSelected(null)} style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#6b7280', padding: 4 }}>
                <X size={20} />
              </button>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: '0 1rem', marginBottom: '1.5rem', padding: '1rem', background: '#f7f9ff', border: '1px solid #dde3f0', borderRadius: 8 }}>
              {[['Guest', selected.guest_name], ['Room', selected.room], ['Stay', `${fmtDate(selected.check_in)} – ${fmtDate(selected.check_out)}`]].map(([l, v]) => (
                <div key={l}>
                  <div className="report-stat-label" style={{ marginBottom: 3 }}>{l}</div>
                  <div style={{ fontSize: '0.82rem', fontWeight: 600, color: '#0d1b3e' }}>{v}</div>
                </div>
              ))}
            </div>

            <div style={{ marginBottom: '1.5rem' }}>
              <div className="report-stat-label" style={{ marginBottom: '0.75rem' }}>Ratings</div>
              {[
                ['Cleanliness',   selected.rating_cleanliness],
                ['Comfort',       selected.rating_comfort],
                ['Staff Service', selected.rating_staff],
                ['Facilities',    selected.rating_facilities],
                ['Overall',       selected.rating_overall],
              ].map(([label, val]) => (
                <div key={label} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '6px 0', borderBottom: '1px solid #eef0f7' }}>
                  <span style={{ fontSize: '0.88rem', color: '#0d1b3e', fontWeight: label === 'Overall' ? 700 : 400 }}>{label}</span>
                  <Stars value={val} size={label === 'Overall' ? 16 : 14} />
                </div>
              ))}
            </div>

            {selected.review && (
              <div style={{ marginBottom: '1.5rem', padding: '1rem', background: '#f7f9ff', border: '1px solid #dde3f0', borderLeft: '3px solid #1a4bcc', borderRadius: '0 8px 8px 0' }}>
                <div className="report-stat-label" style={{ marginBottom: 6 }}>Review</div>
                <p style={{ fontSize: '0.9rem', color: '#4a5568', lineHeight: 1.65, margin: 0 }}>"{selected.review}"</p>
              </div>
            )}

            <div style={{ marginBottom: '1.5rem', padding: '0.85rem 1rem', background: '#f8fafc', border: '1px solid #dde3f0', borderRadius: 8, display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12 }}>
              <div>
                <div className="report-stat-label" style={{ marginBottom: 3 }}>Website Publication</div>
                <div style={{ fontSize: '0.82rem', color: '#4a5568' }}>
                  {selected.is_featured ? 'Visible publicly using the guest initials.' : 'Private and visible only to staff.'}
                </div>
              </div>
              <button
                type="button"
                className={selected.is_featured ? 'btn-export' : 'btn-action'}
                onClick={() => handleTogglePublication(selected)}
                disabled={publishingId === selected.id || (!selected.is_featured && (Number(selected.rating_overall) < 4 || !selected.review?.trim()))}
                style={{ minWidth: 120, justifyContent: 'center' }}
              >
                {publishingId === selected.id ? 'Saving...' : selected.is_featured ? 'Make Private' : 'Publish Review'}
              </button>
            </div>

            {selected.has_issue && (
              <div style={{ marginBottom: '1.5rem', padding: '0.75rem 1rem', background: '#fef2f2', border: '1px solid #fca5a5', borderRadius: 8, display: 'flex', alignItems: 'center', gap: 8 }}>
                <AlertCircle size={15} style={{ color: '#ef4444', flexShrink: 0 }} />
                <span style={{ fontSize: '0.88rem', color: '#991b1b' }}>Issue reported: <strong>{selected.issue_type}</strong></span>
              </div>
            )}

            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: '1.5rem', fontSize: '0.88rem', color: '#0d1b3e' }}>
              {selected.would_recommend === true  && <><ThumbsUp size={16} style={{ color: '#16a34a' }} /> <span>Would recommend</span></>}
              {selected.would_recommend === false && <><ThumbsDown size={16} style={{ color: '#ef4444' }} /> <span>Would not recommend</span></>}
              {selected.would_recommend === null  && <span style={{ color: '#6b7280' }}>No recommendation preference provided</span>}
            </div>

            <div>
              <div className="report-stat-label" style={{ marginBottom: 8 }}>Admin Reply</div>
              {selected.admin_reply && (
                <div style={{ padding: '0.75rem 1rem', background: '#f0fdf4', border: '1px solid #86efac', borderRadius: 8, marginBottom: 10, fontSize: '0.88rem', color: '#166534', lineHeight: 1.6 }}>
                  {selected.admin_reply}
                </div>
              )}
              <textarea
                style={{ width: '100%', border: '1px solid #dde3f0', padding: '10px 12px', fontFamily: 'inherit', fontSize: '0.88rem', color: '#0d1b3e', resize: 'vertical', outline: 'none', borderRadius: 8, minHeight: 80 }}
                placeholder="Write a reply to this guest's feedback…"
                value={reply}
                onChange={(e) => setReply(e.target.value)}
                rows={3}
              />
              <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 8 }}>
                <button onClick={() => setSelected(null)} className="btn-export" style={{ minWidth: 108, justifyContent: 'center' }}>Close</button>
                <button
                  onClick={handleReply}
                  disabled={replying || !reply.trim()}
                  className="btn-action"
                  style={{ opacity: replying || !reply.trim() ? 0.6 : 1, cursor: replying || !reply.trim() ? 'not-allowed' : 'pointer' }}
                >
                  {replying ? 'Saving…' : 'Save Reply'}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}



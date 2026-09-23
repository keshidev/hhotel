import { useNotificationTarget } from '../../../hooks/useNotificationTarget';
import React, { useState, useEffect, useMemo, useRef } from 'react';
import { CalendarCheck, FileText, Download, Filter, CheckCircle, Clock, XCircle, TrendingUp, TrendingDown } from 'lucide-react';
import jsPDF from 'jspdf';
import autoTable from 'jspdf-autotable';
import reservationReportService from '../../../services/reservationReportService';
import { StaffDatePicker } from '../../../components/StaffDatePicker';
import Pagination from '../../../components/Pagination';
import { usePagination } from '../../../hooks/usePagination';
import { formatCurrency } from '../../../utils/currency';
import ReportChartCard, { createReportChartOptions, formatChartDate } from '../../../components/reports/ReportChartCard';
import StatusBadge from '../../../components/StatusBadge';
import {
  BASE_TABLE_STYLES,
  PDF_COLORS,
  drawPageFooter,
  drawPageHeader,
  drawSectionDivider,
  drawSummaryCards,
  formatCurrencyPDF,
  formatRoomType,
  formatStatus,
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

const StatCard = ({ label, value, sub }) => (
  <div className="report-stat-card">
    <div className="report-stat-label">{label}</div>
    <div className="report-stat-value">{value}</div>
    {sub && <div className="report-stat-sub">{sub}</div>}
  </div>
);

const ReservationReport = () => {
  const [loading, setLoading]     = useState(false);
  const [notificationSearch, setNotificationSearch] = useState('');
  const [notificationRevision, setNotificationRevision] = useState(0);
  useNotificationTarget((target) => {
    setNotificationSearch(target.search); setStatusFilter('all');
    if (/^\d{4}-\d{2}-\d{2}$/.test(target.checkIn) && /^\d{4}-\d{2}-\d{2}$/.test(target.checkOut)) {
      setDateRange({ startDate: target.checkIn, endDate: target.checkOut });
    }
    setNotificationRevision((value) => value + 1);
  });
  const [dateRange, setDateRange] = useState({
    startDate: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0],
    endDate: new Date().toISOString().split('T')[0],
  });
  const [statusFilter, setStatusFilter] = useState('all');
  const [stats, setStats] = useState({
    total_reservations: 0, confirmed_reservations: 0, pending_reservations: 0,
    cancelled_reservations: 0, checked_in_reservations: 0, checked_out_reservations: 0, total_change: null,
  });
  const [reservations,    setReservations]    = useState([]);
  const [statusBreakdown, setStatusBreakdown] = useState([]);
  const [reservationTimeline, setReservationTimeline] = useState([]);
  const [sortKey, setSortKey] = useState(null);
  const [sortDir, setSortDir] = useState('asc');

  const reservationChartOptions = useMemo(() => {
    const statuses = [
      ['confirmed', 'Confirmed', '#1a4bcc'],
      ['pending', 'Pending', '#f59e0b'],
      ['checked_in', 'Checked In', '#10b981'],
      ['checked_out', 'Checked Out', '#0891b2'],
      ['no_show', 'No Show', '#7c3aed'],
      ['cancelled', 'Cancelled', '#ef4444'],
      ['expired', 'Expired', '#64748b'],
    ];

    return createReportChartOptions({
      chart: { type: 'column', zooming: { type: 'x' } },
      xAxis: {
        categories: reservationTimeline.map((row) => formatChartDate(row.date)),
        crosshair: true,
        labels: {
          step: Math.max(1, Math.ceil(reservationTimeline.length / 9)),
          style: { color: '#64748b', fontSize: '11px' },
        },
      },
      yAxis: {
        min: 0,
        allowDecimals: false,
        title: { text: 'Reservations' },
        stackLabels: { enabled: true, style: { color: '#475569', fontSize: '10px', textOutline: 'none' } },
      },
      tooltip: { valueSuffix: ' reservation(s)' },
      plotOptions: { column: { stacking: 'normal' } },
      series: statuses
        .map(([key, name, color]) => ({
          name,
          color,
          data: reservationTimeline.map((row) => Number(row[key] ?? 0)),
        }))
        .filter((series) => series.data.some((value) => value > 0)),
    });
  }, [reservationTimeline]);

  const bookingLifecycleChartOptions = useMemo(() => createReportChartOptions({
    chart: { type: 'pie', height: 340 },
    legend: {
      enabled: true,
      align: 'right',
      verticalAlign: 'middle',
      layout: 'vertical',
      labelFormatter() { return `${this.name} (${this.y})`; },
    },
    tooltip: {
      shared: false,
      pointFormat: '<b>{point.y}</b> reservation(s)<br/><b>{point.percentage:.1f}%</b> of total',
    },
    plotOptions: {
      pie: {
        innerSize: '64%',
        size: '78%',
        center: ['34%', '50%'],
        borderColor: '#ffffff',
        borderWidth: 3,
        dataLabels: { enabled: false },
        showInLegend: true,
      },
    },
    responsive: {
      rules: [{
        condition: { maxWidth: 430 },
        chartOptions: {
          legend: { align: 'center', verticalAlign: 'bottom', layout: 'horizontal' },
          plotOptions: { pie: { center: ['50%', '40%'], size: '62%' } },
        },
      }],
    },
    series: [{
      name: 'Reservations',
      colorByPoint: true,
      data: statusBreakdown
        .filter((row) => Number(row.count ?? 0) > 0)
        .map((row) => ({ name: row.status, y: Number(row.count), color: row.color })),
    }],
  }), [statusBreakdown]);

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

  const resolveSortValue = (reservation, key) => {
    switch (key) {
      case 'reference':
        return reservation.reference ?? '';
      case 'guest':
        return reservation.guest_name ?? '';
      case 'room':
        return reservation.room_numbers ?? '';
      case 'type':
        return reservation.room_type ?? '';
      case 'status':
        return reservation.status ?? '';
      case 'check_in': {
        const ts = reservation.check_in ? new Date(reservation.check_in).getTime() : null;
        return Number.isFinite(ts) ? ts : null;
      }
      case 'check_out': {
        const ts = reservation.check_out ? new Date(reservation.check_out).getTime() : null;
        return Number.isFinite(ts) ? ts : null;
      }
      case 'nights':
        return Number(reservation.nights ?? 0);
      case 'amount':
        return Number(reservation.total_amount ?? 0);
      default:
        return null;
    }
  };

  const sortedReservations = useMemo(() => {
    if (!sortKey) return reservations;

    return [...reservations].sort((a, b) => {
      const result = compareSortValues(resolveSortValue(a, sortKey), resolveSortValue(b, sortKey));
      return sortDir === 'asc' ? result : -result;
    });
  }, [reservations, sortKey, sortDir]);

  const { currentPage, totalPages, itemsPerPage, paginatedData: paginatedReservations, totalItems, handlePageChange, handleItemsPerPageChange, resetPage } = usePagination(sortedReservations, 10);

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

  useEffect(() => { fetchData(); }, [statusFilter, notificationRevision]);

  const latestRequest = useRef(0);
  const fetchData = async () => {
    const request = ++latestRequest.current;
    setLoading(true);
    try {
      const params = { ...(statusFilter !== 'all' ? { status: statusFilter } : {}), ...(notificationSearch ? { search: notificationSearch } : {}) };
      const res = await reservationReportService.getReport(dateRange.startDate, dateRange.endDate, params);
      if (request !== latestRequest.current) return;
      const { stats: s, reservations: r, status_breakdown: sb, reservation_timeline: rt } = res.data;
      setStats(s); setReservations(r); setStatusBreakdown(sb); setReservationTimeline(rt ?? []); resetPage();
    } catch (err) {
      if (request !== latestRequest.current) return;
      showToast(err?.response?.data?.message || 'Failed to load reservation data', 'error');
    } finally {
      if (request === latestRequest.current) setLoading(false);
    }
  };

  const handleExport = () => {
    const exportRows = sortedReservations;
    if (!exportRows.length) { showToast('No data to export', 'error'); return; }
    const exportParams = {
      ...(notificationSearch ? { search: notificationSearch } : {}),
      ...(statusFilter !== 'all' ? { status: statusFilter } : {}),
      audit_event: 'export_pdf',
      ...(sortKey ? { sort_by: sortKey, sort_direction: sortDir } : {}),
    };
    reservationReportService
      .getReport(dateRange.startDate, dateRange.endDate, exportParams)
      .catch(() => {});

    try {
      const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
      drawPageHeader(doc, {
        title: 'Reservation Report',
        subtitle: 'View all reservations, statuses and stay details',
        dateRange: { start: dateRange.startDate, end: dateRange.endDate },
        totalRecords: exportRows.length,
      });

      let y = 84;
      y = drawSummaryCards(doc, [
        { label: 'Total Reservations', value: String(stats.total_reservations ?? 0) },
        { label: 'Confirmed', value: String(stats.confirmed_reservations ?? 0) },
        { label: 'Pending', value: String(stats.pending_reservations ?? 0) },
        { label: 'Cancelled', value: String(stats.cancelled_reservations ?? 0) },
      ], y);

      y = drawSectionDivider(doc, 'Reservation Records', y);

      autoTable(doc, {
        ...BASE_TABLE_STYLES,
        startY: y,
        rowPageBreak: 'avoid',
        showHead: 'everyPage',
        margin: { ...BASE_TABLE_STYLES.margin, top: 88 }, 
        head: [['Reference', 'Guest', 'Room', 'Type', 'Status', 'Check-In', 'Check-Out', 'Nights', 'Total']],
        body: exportRows.map((r) => [
          String(r.reference ?? ''),
          String(r.guest_name ?? ''),
          String(r.room_numbers ?? ''),
          formatRoomType(r.room_type),
          formatStatus(r.status),
          r.check_in ? new Date(r.check_in).toLocaleDateString('en-PH') : 'N/A',
          r.check_out ? new Date(r.check_out).toLocaleDateString('en-PH') : 'N/A',
          String(r.nights ?? ''),
          formatCurrencyPDF(r.total_amount),
        ]),
        columnStyles: {
          0: { cellWidth: 140, overflow: 'linebreak' },  
          1: { cellWidth: 95 },
          2: { cellWidth: 40 },
          3: { cellWidth: 80, overflow: 'linebreak' },
          4: { cellWidth: 62 },
          5: { cellWidth: 58 },
          6: { cellWidth: 58 },
          7: { cellWidth: 28 },
          8: { cellWidth: 'auto' },
        },
        didParseCell: (data) => {
          if (data.section !== 'body' || data.column.index !== 4) return;
          const rawStatus = String(data.cell.raw ?? '').toLowerCase();
          if (rawStatus.includes('checked out')) data.cell.styles.textColor = PDF_COLORS.SUCCESS;
          else if (rawStatus.includes('cancelled') || rawStatus.includes('no show') || rawStatus.includes('expired')) data.cell.styles.textColor = PDF_COLORS.DANGER;
          else if (rawStatus.includes('confirmed') || rawStatus.includes('checked in')) data.cell.styles.textColor = PDF_COLORS.BLUE;
        },
        didDrawPage: () => {
          const currentPage = doc.internal.getCurrentPageInfo().pageNumber;
          if (currentPage > 1) {
            drawPageHeader(doc, {
              title: 'Reservation Report',
              subtitle: 'View all reservations, statuses and stay details',
              dateRange: { start: dateRange.startDate, end: dateRange.endDate },
              totalRecords: exportRows.length,
            });
          }
          drawPageFooter(doc, { reportTitle: 'Reservation Report' });
        },
      });
      doc.save(`reservation-report-${dateRange.startDate}-to-${dateRange.endDate}.pdf`);
      showToast('PDF report exported!', 'success');
    } catch (err) {
      showToast(`Export failed: ${err.message}`, 'error');
    }
  };

  const ChangeBadge = ({ value }) => {
    if (value === null || value === undefined) return null;
    const positive = value >= 0;
    return (
      <span className={`report-badge ${positive ? 'positive' : 'negative'}`}>
        {positive ? <TrendingUp size={12} /> : <TrendingDown size={12} />}
        {positive ? '+' : ''}{value}% vs last period
      </span>
    );
  };

  const safePercent = (part, total) => total > 0 ? Math.round((part / total) * 100) : 0;

  return (
    <div className="report-page reservation-report-page">
      {/* HEADER */}
      <div className="report-page-header">
        <div className="report-page-title">
          <h1>Reservation Report</h1>
          <p className="report-page-subtitle">View all reservations, statuses and stay details</p>
        </div>
        <div className="report-header-actions">
          <button className="btn-export" onClick={handleExport}>
            <Download size={16} /> Export PDF
          </button>
        </div>
      </div>

      {/* FILTER */}
      <div className="report-filter-bar">
        <div className="filter-field">
          <label>Start Date</label>
          <StaffDatePicker
            value={dateRange.startDate}
            onChange={(value) => setDateRange((current) => ({ ...current, startDate: value }))}
            ariaLabel="Select reservation report start date"
          />
        </div>
        <div className="filter-field">
          <label>End Date</label>
          <StaffDatePicker
            value={dateRange.endDate}
            onChange={(value) => setDateRange((current) => ({ ...current, endDate: value }))}
            ariaLabel="Select reservation report end date"
          />
        </div>
        <div className="filter-field">
          <label>Status</label>
          {notificationSearch && <button type="button" className="btn-apply-filter" onClick={() => { setNotificationSearch(''); setNotificationRevision((value) => value + 1); }}>Clear reference: {notificationSearch}</button>}
          <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
            <option value="all">All</option>
            <option value="pending">Pending</option>
            <option value="confirmed">Confirmed</option>
            <option value="checked_in">Checked-In</option>
            <option value="checked_out">Checked-Out</option>
            <option value="cancelled">Cancelled</option>
            <option value="no_show">No-Show</option>
          </select>
        </div>
        <button className="btn-apply-filter" onClick={fetchData}>
          <Filter size={15} /> Apply Filter
        </button>
      </div>

      {loading ? (
        <div className="report-loading">
          <div className="report-spinner" />
          <p>Loading reservation data…</p>
        </div>
      ) : (
        <>
          {/* STAT CARDS */}
          <div className="report-stats-grid">
            <StatCard label="Total Reservations" value={stats.total_reservations} sub={<ChangeBadge value={stats.total_change} />} />
            <StatCard label="Confirmed"  value={stats.confirmed_reservations}  sub={`${safePercent(stats.confirmed_reservations, stats.total_reservations)}% of total`} />
            <StatCard label="Pending"    value={stats.pending_reservations}    sub="Awaiting confirmation" />
            <StatCard label="Cancelled"  value={stats.cancelled_reservations}  sub={`${safePercent(stats.cancelled_reservations, stats.total_reservations)}% cancellation`} />
          </div>

          <div className="report-chart-pair">
          <ReportChartCard
            title="Reservations by Arrival Date"
            subtitle="Daily arrival volume grouped by current booking status"
            badge="Stacked status"
            options={reservationChartOptions}
            empty={reservationTimeline.length === 0}
          />

          <ReportChartCard
            title="Booking Lifecycle Mix"
            subtitle="Current distribution across reservation stages"
            badge={`${stats.total_reservations} total`}
            options={bookingLifecycleChartOptions}
            empty={statusBreakdown.every((row) => Number(row.count ?? 0) === 0)}
          />
          </div>

          {/* RESERVATION RECORDS */}
          <div className="table-card">
            <div className="table-card-header">
              <h3>Reservation Records</h3>
              <span className="table-card-count">{reservations.length} records</span>
            </div>
            {reservations.length === 0 ? (
              <div className="report-loading" style={{ padding: '3rem' }}>
                <p>No reservations found for this period.</p>
              </div>
            ) : (
              <>
                <div style={{ overflowX: 'auto' }}>
                  <table className="data-table">
                    <thead>
                      <tr>
                        <th className="sortable-th" onClick={() => sortBy('reference')}>Reference <span className={`sort-indicator ${sortKey === 'reference' ? 'active' : ''}`}>{sortIndicator('reference')}</span></th>
                        <th className="sortable-th" onClick={() => sortBy('guest')}>Guest Name <span className={`sort-indicator ${sortKey === 'guest' ? 'active' : ''}`}>{sortIndicator('guest')}</span></th>
                        <th className="sortable-th" onClick={() => sortBy('room')}>Room <span className={`sort-indicator ${sortKey === 'room' ? 'active' : ''}`}>{sortIndicator('room')}</span></th>
                        <th className="sortable-th" onClick={() => sortBy('type')}>Type <span className={`sort-indicator ${sortKey === 'type' ? 'active' : ''}`}>{sortIndicator('type')}</span></th>
                        <th className="sortable-th" onClick={() => sortBy('status')}>Status <span className={`sort-indicator ${sortKey === 'status' ? 'active' : ''}`}>{sortIndicator('status')}</span></th>
                        <th className="sortable-th" onClick={() => sortBy('check_in')}>Check-in <span className={`sort-indicator ${sortKey === 'check_in' ? 'active' : ''}`}>{sortIndicator('check_in')}</span></th>
                        <th className="sortable-th" onClick={() => sortBy('check_out')}>Check-out <span className={`sort-indicator ${sortKey === 'check_out' ? 'active' : ''}`}>{sortIndicator('check_out')}</span></th>
                        <th className="sortable-th" onClick={() => sortBy('nights')}>Nights <span className={`sort-indicator ${sortKey === 'nights' ? 'active' : ''}`}>{sortIndicator('nights')}</span></th>
                        <th className="sortable-th" onClick={() => sortBy('amount')}>Total Amount <span className={`sort-indicator ${sortKey === 'amount' ? 'active' : ''}`}>{sortIndicator('amount')}</span></th>
                      </tr>
                    </thead>
                    <tbody>
                      {paginatedReservations.map(r => (
                        <tr key={r.id}>
                          <td className="cell-bold">{r.reference}</td>
                          <td>{r.guest_name}</td>
                          <td>{r.room_numbers}</td>
                          <td style={{ textTransform: 'capitalize' }}>{r.room_type}</td>
                          <td><StatusBadge status={r.status} /></td>
                          <td>{r.check_in ? new Date(r.check_in).toLocaleDateString() : '—'}</td>
                          <td>{r.check_out ? new Date(r.check_out).toLocaleDateString() : '—'}</td>
                          <td>{r.nights}</td>
                          <td className="price-cell">{formatCurrency(r.total_amount)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                <Pagination currentPage={currentPage} totalPages={totalPages} totalItems={totalItems} itemsPerPage={itemsPerPage} onPageChange={handlePageChange} onItemsPerPageChange={handleItemsPerPageChange} pageSizeOptions={[10, 25, 50, 100]} />
              </>
            )}
          </div>
        </>
      )}
    </div>
  );
};

export default ReservationReport;

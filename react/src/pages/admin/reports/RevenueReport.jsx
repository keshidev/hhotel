import { useNotificationTarget } from '../../../hooks/useNotificationTarget';
import React, { useState, useEffect, useMemo, useRef } from 'react';
import { PhilippinePeso, TrendingUp, TrendingDown, FileText, Download, Filter } from 'lucide-react';
import jsPDF from 'jspdf';
import autoTable from 'jspdf-autotable';
import revenueReportService from '../../../services/revenueReportService';
import Pagination from '../../../components/Pagination';
import { StaffDatePicker } from '../../../components/StaffDatePicker';
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
  formatPaymentMethod,
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

const StatCard = ({ label, value, sub, color = '#000000' }) => (
  <div className="report-stat-card">
    <div className="report-stat-label">{label}</div>
    <div className="report-stat-value" style={{ color }}>{value}</div>
    {sub && <div className="report-stat-sub">{sub}</div>}
  </div>
);

const toPaymentMethodLabel = (value) => {
  const raw = String(value ?? '').trim().toLowerCase();
  if (raw.includes('gcash')) return 'GCash';
  if (raw.includes('bank')) return 'Bank Transfer';
  if (raw.includes('cash')) return 'Cash';
  return raw.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()) || 'Cash';
};

const formatTransactionPaymentChannels = (transaction) => {
  const channels = transaction?.payment_channels ?? transaction?.payment_methods ?? [];
  const labels = channels
    .map((channel) => toPaymentMethodLabel(channel))
    .filter(Boolean);

  return [...new Set(labels)].join(', ');
};

const RevenueReport = () => {
  const [loading, setLoading] = useState(false);
  const [notificationRevision, setNotificationRevision] = useState(0);
  useNotificationTarget((target) => { if (target.date) { setDateRange({ startDate: target.date, endDate: target.date }); setNotificationRevision((value) => value + 1); } });
  const [dateRange, setDateRange] = useState({
    startDate: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0],
    endDate: new Date().toISOString().split('T')[0],
  });
  const [stats, setStats] = useState({
    total_revenue: 0,
    gross_revenue: 0,
    total_refunds: 0,
    total_bookings: 0,
    average_booking_value: 0,
    revenue_change_percent: null,
    bookings_change_percent: null,
  });
  const [transactions, setTransactions]       = useState([]);
  const [byPaymentMethod, setByPaymentMethod] = useState([]);
  const [dailyRevenue, setDailyRevenue] = useState([]);
  const [dailyRefunds, setDailyRefunds] = useState([]);
  const [sortKey, setSortKey] = useState(null);
  const [sortDir, setSortDir] = useState('asc');

  const revenueChartOptions = useMemo(() => {
    const dates = [...new Set([
      ...dailyRevenue.map((row) => row.date),
      ...dailyRefunds.map((row) => row.date),
    ])].sort();
    const revenueByDate = new Map(dailyRevenue.map((row) => [row.date, Number(row.total ?? 0)]));
    const refundsByDate = new Map(dailyRefunds.map((row) => [row.date, Number(row.total ?? 0)]));

    return createReportChartOptions({
      chart: { type: 'column', zooming: { type: 'x' } },
      xAxis: {
        categories: dates.map(formatChartDate),
        crosshair: true,
        labels: { step: Math.max(1, Math.ceil(dates.length / 9)), style: { color: '#64748b', fontSize: '11px' } },
      },
      yAxis: {
        min: 0,
        title: { text: 'Amount (PHP)' },
        labels: { formatter() { return `₱${Number(this.value).toLocaleString('en-PH', { maximumFractionDigits: 0 })}`; } },
      },
      tooltip: {
        formatter() {
          const rows = (this.points ?? []).map((point) => (
            `<span style="color:${point.color}">●</span> ${point.series.name}: <b>₱${Number(point.y).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</b>`
          )).join('<br/>');
          return `<b>${this.x}</b><br/>${rows}`;
        },
      },
      series: [
        {
          name: 'Gross Collected',
          type: 'column',
          color: '#1a4bcc',
          data: dates.map((date) => revenueByDate.get(date) ?? 0),
        },
        {
          name: 'Refunds',
          type: 'column',
          color: '#ef4444',
          data: dates.map((date) => refundsByDate.get(date) ?? 0),
        },
        {
          name: 'Net Revenue',
          type: 'spline',
          color: '#0f172a',
          lineWidth: 3,
          marker: { radius: 3, lineColor: '#ffffff', lineWidth: 2 },
          data: dates.map((date) => (revenueByDate.get(date) ?? 0) - (refundsByDate.get(date) ?? 0)),
        },
      ],
    });
  }, [dailyRevenue, dailyRefunds]);

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

  const resolveSortValue = (transaction, key) => {
    switch (key) {
      case 'reference':
        return transaction.reference ?? '';
      case 'guest_name':
        return transaction.guest_name ?? '';
      case 'room':
        return transaction.room_numbers ?? '';
      case 'type':
        return transaction.room_type ?? '';
      case 'nights':
        return Number(transaction.nights ?? 0);
      case 'status':
        return transaction.booking_status ?? '';
      case 'amount_collected':
        return Number(transaction.amount_collected ?? 0);
      case 'amount_refunded':
        return Number(transaction.amount_refunded ?? 0);
      case 'net_amount':
        return Number(transaction.net_amount ?? 0);
      case 'payment_method':
        return formatTransactionPaymentChannels(transaction) || '';
      case 'paid_at': {
        const ts = transaction.paid_at ? new Date(transaction.paid_at).getTime() : null;
        return Number.isFinite(ts) ? ts : null;
      }
      default:
        return null;
    }
  };

  const sortedTransactions = useMemo(() => {
    if (!sortKey) return transactions;

    return [...transactions].sort((a, b) => {
      const result = compareSortValues(
        resolveSortValue(a, sortKey),
        resolveSortValue(b, sortKey)
      );
      return sortDir === 'asc' ? result : -result;
    });
  }, [transactions, sortKey, sortDir]);

  const { currentPage, totalPages, itemsPerPage, paginatedData: paginatedTransactions, totalItems, handlePageChange, handleItemsPerPageChange, resetPage } = usePagination(sortedTransactions, 10);

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

  useEffect(() => { fetchData(); }, [notificationRevision]);

  const latestRequest = useRef(0);
  const fetchData = async () => {
    const request = ++latestRequest.current;
    setLoading(true);
    try {
      const res = await revenueReportService.getReport(dateRange.startDate, dateRange.endDate);
      if (request !== latestRequest.current) return;
      const { stats: s, transactions: t, by_payment_method: bpm, daily_revenue: dr, daily_refunds: df } = res.data;
      setStats(s);
      setTransactions(t);
      setByPaymentMethod(bpm);
      setDailyRevenue(dr ?? []);
      setDailyRefunds(df ?? []);
      resetPage();
    } catch (err) {
      if (request !== latestRequest.current) return;
      showToast(err?.response?.data?.message || 'Failed to load revenue data', 'error');
    } finally {
      if (request === latestRequest.current) setLoading(false);
    }
  };

  const handleExport = () => {
    const exportRows = sortedTransactions;
    if (!exportRows.length) { showToast('No data to export', 'error'); return; }
    revenueReportService
      .getReport(dateRange.startDate, dateRange.endDate, {
        audit_event: 'export_pdf',
        ...(sortKey ? { sort_by: sortKey, sort_direction: sortDir } : {}),
      })
      .catch(() => {});

    const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });

    drawPageHeader(doc, {
      title: 'Revenue Report',
      subtitle: 'Track income, bookings and average values',
      dateRange: { start: dateRange.startDate, end: dateRange.endDate },
      totalRecords: exportRows.length,
    });

    let y = 84;
    y = drawSummaryCards(doc, [
      { label: 'Gross Collected', value: formatCurrencyPDF(stats.gross_revenue) },
      { label: 'Refunds', value: formatCurrencyPDF(stats.total_refunds), valueColor: PDF_COLORS.DANGER },
      { label: 'Net Revenue', value: formatCurrencyPDF(stats.total_revenue) },
      { label: 'Bookings With Activity', value: String(stats.total_bookings ?? exportRows.length) },
    ], y);

    y = drawSectionDivider(doc, 'Booking Transactions', y);

    autoTable(doc, {
      ...BASE_TABLE_STYLES,
      startY: y,
      rowPageBreak: 'avoid',
      showHead: 'everyPage',
      head: [['Reference', 'Guest', 'Room', 'Status', 'Collected', 'Refunded', 'Net', 'Method', 'Activity Date']],
      body: exportRows.map((t) => [
        String(t.reference ?? ''),
        String(t.guest_name ?? ''),
        String(t.room_numbers ?? ''),
        formatStatus(t.booking_status),
        formatCurrencyPDF(t.amount_collected),
        formatCurrencyPDF(t.amount_refunded),
        formatCurrencyPDF(t.net_amount),
        formatPaymentMethod(t.payment_channels ?? t.payment_methods ?? t.payment_method),
        t.paid_at ? new Date(t.paid_at).toLocaleDateString('en-PH') : 'N/A',
      ]),
      columnStyles: {
        0: { cellWidth: 128, overflow: 'linebreak' },
        1: { cellWidth: 90 },
        2: { cellWidth: 50 },
        3: { cellWidth: 62 },
        4: { cellWidth: 72 },
        5: { cellWidth: 72 },
        6: { cellWidth: 72 },
        7: { cellWidth: 58 },
        8: { cellWidth: 'auto' },
      },
      didParseCell: (data) => {
        if (data.section !== 'body' || data.column.index !== 3) return;
        const rawStatus = String(data.cell.raw ?? '').toLowerCase();
        if (rawStatus.includes('checked out')) data.cell.styles.textColor = PDF_COLORS.SUCCESS;
        else if (rawStatus.includes('cancelled') || rawStatus.includes('no show') || rawStatus.includes('expired')) data.cell.styles.textColor = PDF_COLORS.DANGER;
        else if (rawStatus.includes('confirmed') || rawStatus.includes('checked in')) data.cell.styles.textColor = PDF_COLORS.BLUE;
      },
      didDrawPage: () => {
        const currentPage = doc.internal.getCurrentPageInfo().pageNumber;
        if (currentPage > 1) {
          drawPageHeader(doc, {
            title: 'Revenue Report',
            subtitle: 'Track income, bookings and average values',
            dateRange: { start: dateRange.startDate, end: dateRange.endDate },
            totalRecords: exportRows.length,
          });
        }
        drawPageFooter(doc, { reportTitle: 'Revenue Report' });
      },
    });
    doc.save(`revenue-report-${dateRange.startDate}-to-${dateRange.endDate}.pdf`);
    showToast('PDF report exported!', 'success');
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

  return (
    <div className="report-page">
      {/* HEADER */}
      <div className="report-page-header">
        <div className="report-page-title">
          <h1>Revenue Report</h1>
          <p className="report-page-subtitle">Track income, bookings and average values</p>
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
            onChange={(value) => setDateRange((previous) => ({ ...previous, startDate: value }))}
            ariaLabel="Select revenue report start date"
          />
        </div>
        <div className="filter-field">
          <label>End Date</label>
          <StaffDatePicker
            value={dateRange.endDate}
            onChange={(value) => setDateRange((previous) => ({ ...previous, endDate: value }))}
            ariaLabel="Select revenue report end date"
          />
        </div>
        <button className="btn-apply-filter" onClick={fetchData}>
          <Filter size={15} /> Apply Filter
        </button>
      </div>

      {loading ? (
        <div className="report-loading">
          <div className="report-spinner" />
          <p>Loading revenue data…</p>
        </div>
      ) : (
        <>
          {/* STAT CARDS */}
          <div className="report-stats-grid">
            <StatCard label="Gross Collected" value={formatCurrency(stats.gross_revenue)} sub="completed payments" />
            <StatCard label="Refunds" value={formatCurrency(stats.total_refunds)} sub="processed refunds" color="#dc2626" />
            <StatCard label="Net Revenue" value={formatCurrency(stats.total_revenue)} sub={<ChangeBadge value={stats.revenue_change_percent} />} color={stats.total_revenue < 0 ? '#dc2626' : '#000000'} />
            <StatCard label="Bookings With Activity" value={stats.total_bookings} sub={<ChangeBadge value={stats.bookings_change_percent} />} />
            <StatCard label="Avg. Net Per Booking" value={formatCurrency(stats.average_booking_value)} sub="for this period" color={stats.average_booking_value < 0 ? '#dc2626' : '#000000'} />
          </div>

          <ReportChartCard
            title="Revenue Performance"
            subtitle="Daily gross collections, refunds, and resulting net revenue"
            badge="Daily trend"
            options={revenueChartOptions}
            empty={dailyRevenue.length === 0 && dailyRefunds.length === 0}
          />

          {/* PAYMENT METHOD BREAKDOWN */}
          {byPaymentMethod.length > 0 && (
            <div className="table-card" style={{ marginBottom: '1.5rem' }}>
              <div className="table-card-header">
                <h3>Collections by Payment Method</h3>
                <span className="table-card-count">{byPaymentMethod.length} methods</span>
              </div>
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Payment Method</th>
                    <th>Transactions</th>
                    <th>Total Collected</th>
                  </tr>
                </thead>
                <tbody>
                  {byPaymentMethod.map((m, i) => (
                    <tr key={i}>
                      <td className="cell-bold">{m.label ?? m.method?.replace(/_/g, ' ') ?? 'Unknown'}</td>
                      <td>{m.count}</td>
                      <td className="price-cell">{formatCurrency(m.total)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {/* TRANSACTIONS TABLE */}
          <div className="table-card">
            <div className="table-card-header">
              <h3>Booking Transactions</h3>
              <span className="table-card-count">{transactions.length} records</span>
            </div>
            {transactions.length === 0 ? (
              <div className="report-loading" style={{ padding: '3rem' }}>
                <p>No transactions found for this period.</p>
              </div>
            ) : (
              <>
                <div style={{ overflowX: 'auto' }}>
                  <table className="data-table">
                    <thead>
                      <tr>
                        <th className="sortable-th" onClick={() => sortBy('reference')}>Reference <span className={`sort-indicator ${sortKey === 'reference' ? 'active' : ''}`}>{sortIndicator('reference')}</span></th>
                        <th className="sortable-th" onClick={() => sortBy('guest_name')}>Guest Name <span className={`sort-indicator ${sortKey === 'guest_name' ? 'active' : ''}`}>{sortIndicator('guest_name')}</span></th>
                        <th className="sortable-th" onClick={() => sortBy('room')}>Room <span className={`sort-indicator ${sortKey === 'room' ? 'active' : ''}`}>{sortIndicator('room')}</span></th>
                        <th className="sortable-th" onClick={() => sortBy('type')}>Type <span className={`sort-indicator ${sortKey === 'type' ? 'active' : ''}`}>{sortIndicator('type')}</span></th>
                        <th className="sortable-th" onClick={() => sortBy('nights')}>Nights <span className={`sort-indicator ${sortKey === 'nights' ? 'active' : ''}`}>{sortIndicator('nights')}</span></th>
                        <th className="sortable-th" onClick={() => sortBy('status')}>Status <span className={`sort-indicator ${sortKey === 'status' ? 'active' : ''}`}>{sortIndicator('status')}</span></th>
                        <th className="sortable-th" onClick={() => sortBy('amount_collected')}>Collected <span className={`sort-indicator ${sortKey === 'amount_collected' ? 'active' : ''}`}>{sortIndicator('amount_collected')}</span></th>
                        <th className="sortable-th" onClick={() => sortBy('amount_refunded')}>Refunded <span className={`sort-indicator ${sortKey === 'amount_refunded' ? 'active' : ''}`}>{sortIndicator('amount_refunded')}</span></th>
                        <th className="sortable-th" onClick={() => sortBy('net_amount')}>Net <span className={`sort-indicator ${sortKey === 'net_amount' ? 'active' : ''}`}>{sortIndicator('net_amount')}</span></th>
                        <th className="sortable-th" onClick={() => sortBy('payment_method')}>Payment Method <span className={`sort-indicator ${sortKey === 'payment_method' ? 'active' : ''}`}>{sortIndicator('payment_method')}</span></th>
                        <th className="sortable-th" onClick={() => sortBy('paid_at')}>Paid At <span className={`sort-indicator ${sortKey === 'paid_at' ? 'active' : ''}`}>{sortIndicator('paid_at')}</span></th>
                      </tr>
                    </thead>
                    <tbody>
                      {paginatedTransactions.map(t => (
                        <tr key={t.id}>
                          <td className="cell-bold">{t.reference}</td>
                          <td>{t.guest_name}</td>
                          <td>{t.room_numbers}</td>
                          <td style={{ textTransform: 'capitalize' }}>{t.room_type}</td>
                          <td>{t.nights}</td>
                          <td><StatusBadge status={t.booking_status} /></td>
                          <td className="price-cell">{formatCurrency(t.amount_collected)}</td>
                          <td className="price-cell" style={{ color: Number(t.amount_refunded) > 0 ? '#dc2626' : undefined }}>{formatCurrency(t.amount_refunded)}</td>
                          <td className="price-cell" style={{ color: Number(t.net_amount) < 0 ? '#dc2626' : undefined }}>{formatCurrency(t.net_amount)}</td>
                          <td>{formatTransactionPaymentChannels(t) || '—'}</td>
                          <td>{t.paid_at ? new Date(t.paid_at).toLocaleDateString() : '—'}</td>
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

export default RevenueReport;

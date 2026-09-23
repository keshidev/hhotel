import React, { useState, useEffect, useMemo } from 'react';
import { BedDouble, Users, TrendingUp, TrendingDown, Download, Filter, Sparkles } from 'lucide-react';
import jsPDF from 'jspdf';
import autoTable from 'jspdf-autotable';
import occupancyReportService from '../../../services/occupancyReportService';
import { StaffDatePicker } from '../../../components/StaffDatePicker';
import ReportChartCard, { createReportChartOptions, formatChartDate } from '../../../components/reports/ReportChartCard';
import {
  BASE_TABLE_STYLES,
  PDF_COLORS,
  drawPageFooter,
  drawPageHeader,
  drawSectionDivider,
  drawSummaryCards,
  formatRoomType,
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

const OccupancyReport = () => {
  const [loading, setLoading]     = useState(false);
  const [dateRange, setDateRange] = useState({
    startDate: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0],
    endDate: new Date().toISOString().split('T')[0],
  });
  const [stats, setStats] = useState({
    total_rooms: 0, occupied_rooms: 0, available_rooms: 0,
    cleaning_rooms: 0, maintenance_rooms: 0, occupancy_rate: 0, occupancy_change: null,
  });
  const [roomsByType,          setRoomsByType]          = useState([]);
  const [statusBreakdown,      setStatusBreakdown]      = useState([]);
  const [roomsForPreparation,  setRoomsForPreparation]  = useState([]);
  const [dailyOccupancy,       setDailyOccupancy]       = useState([]);
  const [sortKey, setSortKey] = useState(null);
  const [sortDir, setSortDir] = useState('asc');

  const occupancyChartOptions = useMemo(() => createReportChartOptions({
    chart: { type: 'areaspline', zooming: { type: 'x' } },
    xAxis: {
      categories: dailyOccupancy.map((row) => formatChartDate(row.date)),
      crosshair: true,
      labels: {
        step: Math.max(1, Math.ceil(dailyOccupancy.length / 9)),
        style: { color: '#64748b', fontSize: '11px' },
      },
    },
    yAxis: {
      min: 0,
      max: 100,
      tickInterval: 20,
      title: { text: 'Occupancy rate' },
      labels: { format: '{value}%' },
    },
    tooltip: { valueSuffix: '%', valueDecimals: 1 },
    plotOptions: {
      areaspline: {
        lineWidth: 3,
        marker: { enabled: dailyOccupancy.length <= 31, radius: 3 },
        fillColor: {
          linearGradient: { x1: 0, y1: 0, x2: 0, y2: 1 },
          stops: [[0, 'rgba(26, 75, 204, 0.35)'], [1, 'rgba(26, 75, 204, 0.03)']],
        },
      },
    },
    series: [{
      name: 'Occupancy',
      color: '#1a4bcc',
      data: dailyOccupancy.map((row) => Number(row.rate ?? 0)),
    }],
  }), [dailyOccupancy]);

  const roomStatusChartOptions = useMemo(() => createReportChartOptions({
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
      pointFormat: '<b>{point.y}</b> room(s)<br/><b>{point.percentage:.1f}%</b> of inventory',
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
          plotOptions: { pie: { center: ['50%', '42%'], size: '66%' } },
        },
      }],
    },
    series: [{
      name: 'Rooms',
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

  const resolveSortValue = (room, key) => {
    switch (key) {
      case 'room_type':
        return room.type ?? '';
      case 'total_rooms':
        return Number(room.total ?? 0);
      case 'occupied':
        return Number(room.occupied ?? 0);
      case 'available':
        return Number(room.available ?? 0);
      case 'occupancy_rate':
        return Number(room.rate ?? 0);
      default:
        return null;
    }
  };

  const sortedRoomsByType = useMemo(() => {
    if (!sortKey) return roomsByType;

    return [...roomsByType].sort((a, b) => {
      const result = compareSortValues(resolveSortValue(a, sortKey), resolveSortValue(b, sortKey));
      return sortDir === 'asc' ? result : -result;
    });
  }, [roomsByType, sortKey, sortDir]);

  const sortBy = (key) => {
    if (sortKey === key) {
      setSortDir((prev) => (prev === 'asc' ? 'desc' : 'asc'));
    } else {
      setSortKey(key);
      setSortDir('asc');
    }
  };

  const sortIndicator = (key) => {
    if (sortKey !== key) return '\u21C5';
    return sortDir === 'asc' ? '\u25B2' : '\u25BC';
  };

  useEffect(() => { fetchData(); }, []);

  const fetchData = async () => {
    setLoading(true);
    try {
      const res = await occupancyReportService.getReport(dateRange.startDate, dateRange.endDate);
      const { stats: s, rooms_by_type, daily_occupancy, status_breakdown, rooms_for_preparation } = res.data;
      setStats(s);
      setRoomsByType(rooms_by_type);
      setStatusBreakdown(status_breakdown);
      setRoomsForPreparation(rooms_for_preparation || []);
      setDailyOccupancy(daily_occupancy ?? []);
    } catch (err) {
      showToast(err?.response?.data?.message || 'Failed to load occupancy data', 'error');
    } finally {
      setLoading(false);
    }
  };

  const handleExport = () => {
    if (!roomsByType.length) { showToast('No data to export', 'error'); return; }
    occupancyReportService
      .getReport(dateRange.startDate, dateRange.endDate, { audit_event: 'export_pdf' })
      .catch(() => {});

    try {
      const doc = new jsPDF({ orientation: 'portrait', unit: 'pt', format: 'a4' });
      drawPageHeader(doc, {
        title: 'Occupancy Report',
        subtitle: 'Monitor room availability and occupancy rates',
        dateRange: { start: dateRange.startDate, end: dateRange.endDate },
        totalRecords: roomsByType.length,
      });

      let y = 84;
      y = drawSummaryCards(doc, [
        { label: 'Total Rooms', value: String(stats.total_rooms ?? 0) },
        { label: 'Occupied', value: String(stats.occupied_rooms ?? 0) },
        { label: 'Available', value: String(stats.available_rooms ?? 0) },
        { label: 'Occupancy Rate', value: `${Number(stats.occupancy_rate ?? 0)}%` },
      ], y);

      y = drawSectionDivider(doc, 'Occupancy by Room Type', y);

      autoTable(doc, {
        ...BASE_TABLE_STYLES,
        startY: y,
        head: [['Room Type', 'Total Rooms', 'Occupied', 'Available', 'Occupancy Rate']],
        body: roomsByType.map((r) => {
          const total = Number(r.total ?? 0);
          const occupied = Number(r.occupied ?? 0);
          const available = Number(r.available ?? 0);
          const rate = Number(r.rate ?? 0);
          const safeRate = Number.isFinite(rate) ? rate : 0;
          return [
            formatRoomType(r.type),
            String(total),
            String(occupied),
            String(available),
            `${safeRate.toFixed(1)}%`,
          ];
        }),
        columnStyles: {
          0: { cellWidth: 120, overflow: 'linebreak' },
          1: { cellWidth: 60 },
          2: { cellWidth: 60 },
          3: { cellWidth: 60 },
          4: { cellWidth: 'auto' },
        },
        didParseCell: (data) => {
          if (data.section !== 'body' || data.column.index !== 4) return;
          const rate = Number.parseFloat(String(data.cell.raw ?? '0').replace('%', ''));
          if (Number.isNaN(rate)) return;
          if (rate > 70) data.cell.styles.textColor = PDF_COLORS.SUCCESS;
          else if (rate >= 40) data.cell.styles.textColor = PDF_COLORS.WARN;
          else data.cell.styles.textColor = PDF_COLORS.DANGER;
        },
        didDrawPage: () => {
          const currentPage = doc.internal.getCurrentPageInfo().pageNumber;
          if (currentPage > 1) {
            drawPageHeader(doc, {
              title: 'Occupancy Report',
              subtitle: 'Monitor room availability and occupancy rates',
              dateRange: { start: dateRange.startDate, end: dateRange.endDate },
              totalRecords: roomsByType.length,
            });
          }
          drawPageFooter(doc, { reportTitle: 'Occupancy Report' });
        },
      });
      doc.save(`occupancy-report-${dateRange.startDate}-to-${dateRange.endDate}.pdf`);
      showToast('PDF report exported!', 'success');
    } catch (err) {
      showToast(`Export failed: ${err.message}`, 'error');
    }
  };

  const rateColor = (rate) => {
    if (rate >= 80) return 'linear-gradient(90deg, #10b981, #059669)';
    if (rate >= 60) return 'linear-gradient(90deg, #1A4BCC, #1340B8)';
    return 'linear-gradient(90deg, #ef4444, #dc2626)';
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
          <h1>Occupancy Report</h1>
          <p className="report-page-subtitle">Monitor room availability and occupancy rates</p>
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
            ariaLabel="Select occupancy report start date"
          />
        </div>
        <div className="filter-field">
          <label>End Date</label>
          <StaffDatePicker
            value={dateRange.endDate}
            onChange={(value) => setDateRange((current) => ({ ...current, endDate: value }))}
            ariaLabel="Select occupancy report end date"
          />
        </div>
        <button className="btn-apply-filter" onClick={fetchData}>
          <Filter size={15} /> Apply Filter
        </button>
      </div>

      {loading ? (
        <div className="report-loading">
          <div className="report-spinner" />
          <p>Loading occupancy data…</p>
        </div>
      ) : (
        <>
          {/* STAT CARDS */}
          <div className="report-stats-grid">
            <StatCard label="Total Rooms"       value={stats.total_rooms}      sub="All room types" />
            <StatCard label="Occupied Rooms"    value={stats.occupied_rooms}   sub={`${stats.total_rooms - stats.occupied_rooms} available`} />
            <StatCard label="Occupancy Rate"    value={`${stats.occupancy_rate}%`} sub={<ChangeBadge value={stats.occupancy_change} />} />
            <StatCard label="Needs Preparation" value={(stats.cleaning_rooms || 0) + (stats.maintenance_rooms || 0)}
              sub={`${stats.cleaning_rooms || 0} cleaning, ${stats.maintenance_rooms || 0} maintenance`}
              color={(stats.cleaning_rooms || 0) + (stats.maintenance_rooms || 0) > 0 ? '#f59e0b' : '#1A4BCC'}
            />
          </div>

          <div className="report-chart-pair">
          <ReportChartCard
            title="Occupancy Trend"
            subtitle="Daily occupied room share across sellable inventory"
            badge="0–100%"
            options={occupancyChartOptions}
            empty={dailyOccupancy.length === 0}
          />

          <ReportChartCard
            title="Room Operational Status"
            subtitle="Current room readiness and service state"
            badge={`${stats.total_rooms} rooms`}
            options={roomStatusChartOptions}
            empty={statusBreakdown.every((row) => Number(row.count ?? 0) === 0)}
          />
          </div>

          {/* ROOMS FOR PREPARATION */}
          <div className="table-card" style={{ marginBottom: '1.5rem' }}>
            <div className="table-card-header">
              <h3>Rooms for Cleaning / Preparation</h3>
              <span className="table-card-count">{roomsForPreparation.length} rooms</span>
            </div>
            {roomsForPreparation.length === 0 ? (
              <div className="report-loading" style={{ padding: '2rem' }}>
                <p>No rooms currently marked for preparation.</p>
              </div>
            ) : (
              <table className="data-table">
                <thead>
                  <tr><th>Room Number</th><th>Type</th><th>Floor</th><th>Status</th><th>Preparation Tag</th><th>Last Checkout</th></tr>
                </thead>
                <tbody>
                  {roomsForPreparation.map((r) => (
                    <tr key={r.room_id}>
                      <td className="cell-bold">{r.room_number}</td>
                      <td style={{ textTransform: 'capitalize' }}>{r.room_type}</td>
                      <td>{r.floor ?? '—'}</td>
                      <td style={{ textTransform: 'capitalize' }}>{r.status}</td>
                      <td>{r.preparation_tag}</td>
                      <td>{r.last_checkout ? new Date(r.last_checkout).toLocaleDateString() : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>

          {/* OCCUPANCY BY ROOM TYPE */}
          <div className="table-card">
            <div className="table-card-header">
              <h3>Occupancy by Room Type</h3>
              <span className="table-card-count">{roomsByType.length} types</span>
            </div>
            {roomsByType.length === 0 ? (
              <div className="report-loading" style={{ padding: '3rem' }}>
                <p>No room data found for this period.</p>
              </div>
            ) : (
              <table className="data-table">
                <thead>
                  <tr>
                    <th className="sortable-th" onClick={() => sortBy('room_type')}>Room Type <span className={`sort-indicator ${sortKey === 'room_type' ? 'active' : ''}`}>{sortIndicator('room_type')}</span></th>
                    <th className="sortable-th" onClick={() => sortBy('total_rooms')}>Total Rooms <span className={`sort-indicator ${sortKey === 'total_rooms' ? 'active' : ''}`}>{sortIndicator('total_rooms')}</span></th>
                    <th className="sortable-th" onClick={() => sortBy('occupied')}>Occupied <span className={`sort-indicator ${sortKey === 'occupied' ? 'active' : ''}`}>{sortIndicator('occupied')}</span></th>
                    <th className="sortable-th" onClick={() => sortBy('available')}>Available <span className={`sort-indicator ${sortKey === 'available' ? 'active' : ''}`}>{sortIndicator('available')}</span></th>
                    <th className="sortable-th" onClick={() => sortBy('occupancy_rate')}>Occupancy Rate <span className={`sort-indicator ${sortKey === 'occupancy_rate' ? 'active' : ''}`}>{sortIndicator('occupancy_rate')}</span></th>
                  </tr>
                </thead>
                <tbody>
                  {sortedRoomsByType.map((room, i) => (
                    <tr key={i}>
                      <td className="cell-bold" style={{ textTransform: 'capitalize' }}>{room.type}</td>
                      <td>{room.total}</td>
                      <td>{room.occupied}</td>
                      <td>{room.available}</td>
                      <td>
                        <div className="progress-cell">
                          <div className="progress-bar">
                            <div className="progress-fill" style={{ width: `${room.rate}%`, background: rateColor(room.rate) }} />
                          </div>
                          <span className="progress-label">{room.rate}%</span>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </>
      )}
    </div>
  );
};

export default OccupancyReport;

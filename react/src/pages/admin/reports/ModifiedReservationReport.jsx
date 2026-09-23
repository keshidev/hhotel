import React, { useEffect, useMemo, useState } from 'react';
import { Filter, Download } from 'lucide-react';
import jsPDF from 'jspdf';
import autoTable from 'jspdf-autotable';
import reservationReportService from '../../../services/reservationReportService';
import { StaffDatePicker } from '../../../components/StaffDatePicker';
import Pagination from '../../../components/Pagination';
import { usePagination } from '../../../hooks/usePagination';
import ReportChartCard, { createReportChartOptions } from '../../../components/reports/ReportChartCard';
import {
  BASE_TABLE_STYLES,
  drawPageFooter,
  drawPageHeader,
  drawSectionDivider,
  drawSummaryCards,
  formatChangedFields,
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

const StatCard = ({ label, value, sub, color = '#000000', valueFontSize = '1.8rem' }) => (
  <div className="report-stat-card">
    <div className="report-stat-label">{label}</div>
    <div className="report-stat-value" style={{ color, fontSize: valueFontSize }}>{value}</div>
    {sub && <div className="report-stat-sub">{sub}</div>}
  </div>
);

const ModifiedReservationReport = () => {
  const [loading, setLoading] = useState(false);
  const [dateRange, setDateRange] = useState({
    startDate: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0],
    endDate: new Date().toISOString().split('T')[0],
  });
  const [stats, setStats] = useState({ total_modifications: 0, unique_reservations_modified: 0 });
  const [topModifiers, setTopModifiers] = useState([]);
  const [topChangedFields, setTopChangedFields] = useState([]);
  const [changes, setChanges] = useState([]);
  const [sortKey, setSortKey] = useState(null);
  const [sortDir, setSortDir] = useState('asc');

  const changedFieldsChartOptions = useMemo(() => createReportChartOptions({
    chart: { type: 'bar', height: Math.max(300, topChangedFields.length * 42 + 90) },
    legend: { enabled: false },
    xAxis: {
      categories: topChangedFields.map((row) => String(row.field ?? '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase())),
      title: { text: null },
    },
    yAxis: {
      min: 0,
      allowDecimals: false,
      title: { text: 'Number of changes' },
    },
    tooltip: { valueSuffix: ' change(s)' },
    series: [{
      name: 'Changes',
      color: '#1a4bcc',
      data: topChangedFields.map((row) => Number(row.count ?? 0)),
    }],
  }), [topChangedFields]);

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

  const resolveSortValue = (change, key) => {
    switch (key) {
      case 'modified_at': {
        const ts = change.modified_at ? new Date(change.modified_at).getTime() : null;
        return Number.isFinite(ts) ? ts : null;
      }
      case 'reference':
        return change.reference_number ?? `#${change.reservation_id ?? ''}`;
      case 'modified_by':
        return change.modified_by ?? 'System';
      case 'action':
        return change.action_activity ?? 'Updated Reservation';
      case 'changed_fields':
        return (change.changed_fields ?? []).join(', ');
      default:
        return null;
    }
  };

  const sortedChanges = useMemo(() => {
    if (!sortKey) return changes;

    return [...changes].sort((a, b) => {
      const result = compareSortValues(resolveSortValue(a, sortKey), resolveSortValue(b, sortKey));
      return sortDir === 'asc' ? result : -result;
    });
  }, [changes, sortKey, sortDir]);

  const { currentPage, totalPages, itemsPerPage, paginatedData, totalItems, handlePageChange, handleItemsPerPageChange, resetPage } = usePagination(sortedChanges, 10);

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

  useEffect(() => { fetchData(); }, []);

  const fetchData = async () => {
    setLoading(true);
    try {
      const res = await reservationReportService.getModifiedReport(dateRange.startDate, dateRange.endDate);
      setStats(res.data?.stats ?? {});
      setChanges(res.data?.changes ?? []);
      setTopModifiers(res.data?.top_modifiers ?? []);
      setTopChangedFields(res.data?.top_changed_fields ?? []);
      resetPage();
    } catch (err) {
      showToast(err?.response?.data?.message || 'Failed to load modified reservation report', 'error');
    } finally {
      setLoading(false);
    }
  };

  const handleExportPDF = () => {
    const exportRows = sortedChanges;
    if (!exportRows.length) { showToast('No data to export', 'error'); return; }
    reservationReportService
      .getModifiedReport(dateRange.startDate, dateRange.endDate, {
        audit_event: 'export_pdf',
        ...(sortKey ? { sort_by: sortKey, sort_direction: sortDir } : {}),
      })
      .catch(() => {});

    try {
      const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
      drawPageHeader(doc, {
        title: 'Modified Reservations Report',
        subtitle: 'Track reservation edits and changed fields',
        dateRange: { start: dateRange.startDate, end: dateRange.endDate },
        totalRecords: exportRows.length,
      });

      let y = 84;
      y = drawSummaryCards(doc, [
        { label: 'Total Modifications', value: String(stats.total_modifications ?? 0) },
        { label: 'Unique Reservations', value: String(stats.unique_reservations_modified ?? 0) },
        { label: 'Top Modifier', value: String(topModifiers[0]?.name ?? 'N/A') },
        { label: 'Top Modifier Updates', value: `${Number(topModifiers[0]?.count ?? 0)} updates` },
      ], y);
      y = drawSectionDivider(doc, 'Modification Log', y);

      autoTable(doc, {
        ...BASE_TABLE_STYLES,
        startY: y,
        rowPageBreak: 'avoid',
        showHead: 'everyPage',
        margin: { ...BASE_TABLE_STYLES.margin, top: 88 }, 
        head: [['Modified At', 'Reference', 'Modified By', 'Action', 'Changed Fields']],
        body: exportRows.map((c) => [
          c.modified_at ? new Date(c.modified_at).toLocaleString('en-PH') : 'N/A',
          c.reference_number ?? `#${c.reservation_id}`,
          c.modified_by ?? 'System',
          c.action_activity ?? 'Updated Reservation',
          formatChangedFields(c.changed_fields),
        ]),
        columnStyles: {
          0: { cellWidth: 95 },
          1: { cellWidth: 150, overflow: 'linebreak' },  // full reference fits
          2: { cellWidth: 110 },
          3: { cellWidth: 130 },
          4: { cellWidth: 'auto' },
        },
        didDrawPage: () => {
          const currentPageNumber = doc.internal.getCurrentPageInfo().pageNumber;
          if (currentPageNumber > 1) {
            drawPageHeader(doc, {
              title: 'Modified Reservations Report',
              subtitle: 'Track reservation edits and changed fields',
              dateRange: { start: dateRange.startDate, end: dateRange.endDate },
              totalRecords: exportRows.length,
            });
          }
          drawPageFooter(doc, { reportTitle: 'Modified Reservations Report' });
        },
      });

      doc.save(`modified-reservations-${dateRange.startDate}-to-${dateRange.endDate}.pdf`);
      showToast('PDF report exported!', 'success');
    } catch (err) {
      console.error('PDF Export Error:', err);
      showToast(`Export failed: ${err.message}`, 'error');
    }
  };

  return (
    <div className="report-page">
      <div className="report-page-header">
        <div className="report-page-title">
          <h1>Modified Reservations Report</h1>
          <p className="report-page-subtitle">Track reservation edits, who changed them, and what fields changed</p>
        </div>
        <div className="report-header-actions">
          <button className="btn-export" onClick={handleExportPDF}>
            <Download size={16} /> Export PDF
          </button>
        </div>
      </div>

      <div className="report-filter-bar">
        <div className="filter-field">
          <label>Start Date</label>
          <StaffDatePicker
            value={dateRange.startDate}
            onChange={(value) => setDateRange((current) => ({ ...current, startDate: value }))}
            ariaLabel="Select modified reservations start date"
          />
        </div>
        <div className="filter-field">
          <label>End Date</label>
          <StaffDatePicker
            value={dateRange.endDate}
            onChange={(value) => setDateRange((current) => ({ ...current, endDate: value }))}
            ariaLabel="Select modified reservations end date"
          />
        </div>
        <button className="btn-apply-filter" onClick={fetchData}>
          <Filter size={15} /> Apply Filter
        </button>
      </div>

      {loading ? (
        <div className="report-loading">
          <div className="report-spinner" />
          <p>Loading modified reservation data...</p>
        </div>
      ) : (
        <>
          <div className="report-stats-grid">
            <StatCard label="Total Modifications" value={stats.total_modifications ?? 0} sub="Within selected period" />
            <StatCard label="Unique Reservations" value={stats.unique_reservations_modified ?? 0} sub="Reservations touched" />
            <StatCard
              label="Top Modifier"
              value={topModifiers[0]?.name ?? 'N/A'}
              sub={`${topModifiers[0]?.count ?? 0} updates`}
              valueFontSize="1.1rem"
            />
          </div>

          <ReportChartCard
            title="Most Frequently Changed Fields"
            subtitle="Reservation fields most often edited in the selected period"
            badge="Top 8"
            options={changedFieldsChartOptions}
            empty={topChangedFields.length === 0}
          />

          <div className="table-card">
            <div className="table-card-header">
              <h3>Modification Log</h3>
              <span className="table-card-count">{changes.length} entries</span>
            </div>
            {changes.length === 0 ? (
              <div className="report-loading" style={{ padding: '3rem' }}>
                <p>No modified reservations found for this period.</p>
              </div>
            ) : (
              <>
                <div style={{ overflowX: 'auto' }}>
                  <table className="data-table">
                    <thead>
                      <tr>
                        <th className="sortable-th" onClick={() => sortBy('modified_at')}>Modified At <span className={`sort-indicator ${sortKey === 'modified_at' ? 'active' : ''}`}>{sortIndicator('modified_at')}</span></th>
                        <th className="sortable-th" onClick={() => sortBy('reference')}>Reference <span className={`sort-indicator ${sortKey === 'reference' ? 'active' : ''}`}>{sortIndicator('reference')}</span></th>
                        <th className="sortable-th" onClick={() => sortBy('modified_by')}>Modified By <span className={`sort-indicator ${sortKey === 'modified_by' ? 'active' : ''}`}>{sortIndicator('modified_by')}</span></th>
                        <th className="sortable-th" onClick={() => sortBy('action')}>Action <span className={`sort-indicator ${sortKey === 'action' ? 'active' : ''}`}>{sortIndicator('action')}</span></th>
                        <th className="sortable-th" onClick={() => sortBy('changed_fields')}>Changed Fields <span className={`sort-indicator ${sortKey === 'changed_fields' ? 'active' : ''}`}>{sortIndicator('changed_fields')}</span></th>
                      </tr>
                    </thead>
                    <tbody>
                      {paginatedData.map((c) => (
                        <tr key={c.id}>
                          <td>{c.modified_at ? new Date(c.modified_at).toLocaleString('en-PH') : 'N/A'}</td>
                          <td className="cell-bold">{c.reference_number ?? `#${c.reservation_id}`}</td>
                          <td>{c.modified_by ?? 'System'}</td>
                          <td>{c.action_activity ?? 'Updated Reservation'}</td>
                          <td>{(c.changed_fields ?? []).join(', ') || 'N/A'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                <Pagination
                  currentPage={currentPage}
                  totalPages={totalPages}
                  totalItems={totalItems}
                  itemsPerPage={itemsPerPage}
                  onPageChange={handlePageChange}
                  onItemsPerPageChange={handleItemsPerPageChange}
                  pageSizeOptions={[10, 25, 50, 100]}
                />
              </>
            )}
          </div>
        </>
      )}
    </div>
  );
};

export default ModifiedReservationReport;

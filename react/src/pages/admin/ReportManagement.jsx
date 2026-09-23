import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  DollarSign,
  BarChart2,
  CalendarCheck,
  FilePenLine,
  ArrowRight,
} from 'lucide-react';
import revenueReportService from '../../services/revenueReportService';
import occupancyReportService from '../../services/occupancyReportService';
import reservationReportService from '../../services/reservationReportService';
import './ReportManagement.css';

const toISODate = (date) => date.toISOString().split('T')[0];

const getDefaultDateRange = () => {
  const now = new Date();
  const startOfMonth = new Date(now.getFullYear(), now.getMonth(), 1);

  return {
    startDate: toISODate(startOfMonth),
    endDate: toISODate(now),
  };
};

const ReportManagement = () => {
  const navigate = useNavigate();
  const [dateRange] = useState(getDefaultDateRange);
  const [summary, setSummary] = useState({
    totalRevenue: 0,
    occupancyRate: 0,
    totalReservations: 0,
    pendingReservations: 0,
    modifiedReservations: 0,
  });
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState('');

  useEffect(() => {
    let isMounted = true;

    const load = async () => {
      setLoading(true);
      setLoadError('');

      try {
        const [
          revenueResponse,
          occupancyResponse,
          reservationResponse,
          modifiedReservationResponse,
        ] = await Promise.all([
          revenueReportService.getReport(dateRange.startDate, dateRange.endDate),
          occupancyReportService.getReport(dateRange.startDate, dateRange.endDate),
          reservationReportService.getReport(dateRange.startDate, dateRange.endDate),
          reservationReportService.getModifiedReport(dateRange.startDate, dateRange.endDate),
        ]);

        if (!isMounted) return;

        setSummary({
          totalRevenue: Number(revenueResponse?.data?.stats?.total_revenue ?? 0),
          occupancyRate: Number(occupancyResponse?.data?.stats?.occupancy_rate ?? 0),
          totalReservations: Number(reservationResponse?.data?.stats?.total_reservations ?? 0),
          pendingReservations: Number(reservationResponse?.data?.stats?.pending_reservations ?? 0),
          modifiedReservations: Number(modifiedReservationResponse?.data?.stats?.total_modifications ?? 0),
        });
      } catch (error) {
        if (!isMounted) return;
        setLoadError(error?.response?.data?.message || 'Failed to load report overview summary.');
      } finally {
        if (isMounted) setLoading(false);
      }
    };

    load();

    return () => {
      isMounted = false;
    };
  }, [dateRange.endDate, dateRange.startDate]);

  const reports = [
    {
      key: 'revenue',
      path: '/admin/reports/revenue',
      title: 'Revenue Report',
      description: 'Analyse total income, booking values and payment trends over any date range.',
      icon: DollarSign,
      colorClass: 'card-revenue',
      stat: `₱${summary.totalRevenue.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
      statLabel: 'Total Revenue',
    },
    {
      key: 'occupancy',
      path: '/admin/reports/occupancy',
      title: 'Occupancy Report',
      description: 'Track which rooms are occupied, available and how each type is performing.',
      icon: BarChart2,
      colorClass: 'card-occupancy',
      stat: `${summary.occupancyRate}%`,
      statLabel: 'Current Occupancy',
    },
    {
      key: 'reservation',
      path: '/admin/reports/reservation',
      title: 'Reservation Report',
      description: 'Full breakdown of confirmed, pending and cancelled reservations.',
      icon: CalendarCheck,
      colorClass: 'card-reservation',
      stat: summary.totalReservations,
      statLabel: 'Total Reservations',
    },
    {
      key: 'reservation-modified',
      path: '/admin/reports/reservation-modified',
      title: 'Modified Reservations',
      description: 'Audit-focused report of reservation updates, changed fields and staff activity.',
      icon: FilePenLine,
      colorClass: 'card-modified',
      stat: summary.modifiedReservations,
      statLabel: 'Updated Records',
    },
  ];

  return (
    <div className="report-overview-page">
      <div className="page-header">
        <div>
          <h1>Report Management</h1>
          <p className="page-subtitle">Select a report type to view detailed analytics</p>
          {!!loadError && (
            <p className="page-subtitle" style={{ color: '#b91c1c', marginTop: '0.35rem' }}>
              {loadError}
            </p>
          )}
        </div>
      </div>

      {!loading && (
        <div className="overview-strip">
          <div className="strip-item">
            <span className="strip-label">Total Revenue</span>
            <span className="strip-value green">₱{summary.totalRevenue.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
          </div>
          <div className="strip-divider" />
          <div className="strip-item">
            <span className="strip-label">Occupancy Rate</span>
            <span className="strip-value blue">{summary.occupancyRate}%</span>
          </div>
          <div className="strip-divider" />
          <div className="strip-item">
            <span className="strip-label">Total Reservations</span>
            <span className="strip-value purple">{summary.totalReservations}</span>
          </div>
          <div className="strip-divider" />
          <div className="strip-item">
            <span className="strip-label">Pending</span>
            <span className="strip-value amber">{summary.pendingReservations}</span>
          </div>
        </div>
      )}

      <div className="overview-cards">
        {reports.map((report) => {
          const Icon = report.icon;
          return (
            <button
              key={report.key}
              className={`overview-card ${report.colorClass}`}
              onClick={() => navigate(report.path)}
            >
              <div className="overview-card-top">
                <div className="overview-card-icon">
                  <Icon size={28} />
                </div>
                <div className="overview-card-stat">
                  <div className="card-stat-value">{report.stat}</div>
                  <div className="card-stat-label">{report.statLabel}</div>
                </div>
              </div>

              <div className="overview-card-body">
                <h3>{report.title}</h3>
                <p>{report.description}</p>
              </div>

              <div className="overview-card-footer">
                <span>View Full Report</span>
                <ArrowRight size={18} />
              </div>
            </button>
          );
        })}
      </div>
    </div>
  );
};

export default ReportManagement;

import React, { useEffect, useMemo, useRef, useState } from 'react';
import { ArrowRight, Clock } from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import ReportChartCard, { createReportChartOptions } from '../../components/reports/ReportChartCard';
import dashboardService from '../../services/dashboardService';
import { formatCurrency } from '../../utils/currency';
import './AdminShared.css';
import './Dashboard.css';

const StatCard = ({ label, value, sub }) => (
  <div className="report-stat-card">
    <div className="report-stat-label">{label}</div>
    <div className="report-stat-value">{value}</div>
    {sub && <div className="report-stat-sub">{sub}</div>}
  </div>
);

const formatDate = (value) => {
  if (!value) return 'Date unavailable';
  const date = new Date(`${String(value).slice(0, 10)}T00:00:00`);
  return Number.isNaN(date.getTime())
    ? String(value)
    : date.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
};

const Dashboard = () => {
  const navigate = useNavigate();
  const [dashData, setDashData] = useState(null);
  const [revenuePeriod, setRevenuePeriod] = useState('month');
  const [revenueData, setRevenueData] = useState([]);
  const [revenueLoading, setRevenueLoading] = useState(true);
  const [revenueError, setRevenueError] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const refreshIntervalRef = useRef(null);

  const fetchDashboard = async (silent = false) => {
    try {
      if (!silent) setLoading(true);
      const response = await dashboardService.getStats();
      setDashData(response);
      setError(null);
    } catch (err) {
      console.error('Failed to fetch dashboard:', err);
      if (!silent) setError('Failed to load dashboard data.');
    } finally {
      if (!silent) setLoading(false);
    }
  };

  useEffect(() => {
    fetchDashboard();
    refreshIntervalRef.current = setInterval(() => fetchDashboard(true), 30000);
    return () => clearInterval(refreshIntervalRef.current);
  }, []);

  useEffect(() => {
    let active = true;

    const fetchRevenue = async () => {
      setRevenueLoading(true);
      setRevenueError(false);

      try {
        const response = await dashboardService.getRevenueChart(revenuePeriod);
        if (active) setRevenueData(Array.isArray(response) ? response : []);
      } catch (err) {
        console.error('Failed to fetch dashboard revenue chart:', err);
        if (active) {
          setRevenueData([]);
          setRevenueError(true);
        }
      } finally {
        if (active) setRevenueLoading(false);
      }
    };

    fetchRevenue();
    return () => { active = false; };
  }, [revenuePeriod]);

  const s = dashData?.stats;
  const attention = dashData?.attention ?? {};
  const upcomingCheckIns = Array.isArray(dashData?.upcoming_checkins)
    ? dashData.upcoming_checkins
    : [];

  const revenueChartOptions = useMemo(() => {
    const formatPeriod = (row) => {
      if (revenuePeriod === 'week') {
        const value = String(row.week ?? '');
        return value.length >= 2 ? `Week ${Number(value.slice(-2))}` : value;
      }

      const value = revenuePeriod === 'day' ? row.date : row.month;
      if (!value) return '';
      const date = new Date(`${value}${revenuePeriod === 'month' ? '-01' : ''}T00:00:00`);
      if (Number.isNaN(date.getTime())) return String(value);

      return date.toLocaleDateString('en-PH', revenuePeriod === 'month'
        ? { month: 'short', year: 'numeric' }
        : { month: 'short', day: 'numeric' });
    };

    return createReportChartOptions({
      chart: {
        type: 'line',
        height: 340,
        plotBorderColor: '#e6eaf2',
        plotBorderWidth: 1,
        plotBorderRadius: 5,
      },
      legend: { enabled: false },
      xAxis: {
        categories: revenueData.map(formatPeriod),
        crosshair: true,
        lineWidth: 0,
        tickLength: 6,
        tickColor: '#dbe2ee',
        labels: {
          step: Math.max(1, Math.ceil(revenueData.length / 8)),
          style: { color: '#64748b', fontSize: '11px' },
        },
      },
      yAxis: {
        title: { text: 'Net revenue (PHP)' },
        labels: {
          formatter() {
            return `₱${Number(this.value).toLocaleString('en-PH', { notation: 'compact', maximumFractionDigits: 1 })}`;
          },
        },
      },
      tooltip: {
        formatter() {
          const point = this.points?.[0] ?? this;
          return `<b>${this.x}</b><br/><span style="color:#1a4bcc">●</span> Net Revenue: <b>₱${Number(point.y ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</b>`;
        },
      },
      plotOptions: {
        series: {
          lineWidth: 3,
          marker: {
            enabled: true,
            radius: 3,
            lineColor: '#ffffff',
            lineWidth: 2,
          },
        },
      },
      series: [{
        name: 'Net Revenue',
        color: '#1a4bcc',
        data: revenueData.map((row) => Number(row.total ?? 0)),
      }],
    });
  }, [revenueData, revenuePeriod]);

  const periodLabels = {
    day: 'Last 7 days',
    week: 'Last 12 weeks',
    month: 'Last 12 months',
  };

  const attentionItems = [
    { label: 'GCash reviews', count: attention.gcash_reviews ?? 0, path: '/admin/manual-gcash-reviews' },
    { label: 'Cancellations', count: attention.cancellations ?? 0, path: '/admin/cancellation-approvals' },
    { label: 'Transfer approvals', count: attention.transfers ?? 0, path: '/admin/transfer-approvals' },
    { label: 'Early check-in requests', count: attention.early_check_ins ?? 0, path: '/admin/early-check-in-approvals' },
  ];

  const roomStatuses = [
    { label: 'Available', count: s?.available_rooms ?? 0, tone: 'available' },
    { label: 'Occupied', count: s?.occupied_rooms ?? 0, tone: 'occupied' },
    { label: 'Cleaning', count: s?.cleaning_rooms ?? 0, tone: 'cleaning' },
    { label: 'Maintenance', count: s?.maintenance_rooms ?? 0, tone: 'maintenance' },
  ];

  if (loading) {
    return (
      <div className="dashboard-page">
        <div className="page-header">
          <div>
            <h1>Dashboard</h1>
            <p className="page-subtitle">Welcome! Here's today's overview.</p>
          </div>
          <div className="current-date">
            <Clock size={16} />
            {new Date().toLocaleDateString('en-PH', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}
          </div>
        </div>
        <div className="report-stats-grid">
          {[1, 2, 3, 4].map((item) => (
            <div key={item} className="report-stat-card dashboard-skeleton-card">
              <div className="report-stat-label">Loading...</div>
              <div className="report-stat-value">—</div>
            </div>
          ))}
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="dashboard-page">
        <div className="page-header">
          <div>
            <h1>Dashboard</h1>
            <p className="page-subtitle">Welcome! Here's today's overview.</p>
          </div>
          <div className="current-date">
            <Clock size={16} />
            {new Date().toLocaleDateString('en-PH', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}
          </div>
        </div>
        <div className="dashboard-error">
          <p>{error}</p>
          <button type="button" onClick={() => fetchDashboard()}>Retry</button>
        </div>
      </div>
    );
  }

  return (
    <div className="dashboard-page">
      <div className="page-header">
        <div>
          <h1>Dashboard</h1>
          <p className="page-subtitle">Welcome! Here's today's overview.</p>
        </div>
        <div className="current-date">
          <Clock size={16} />
          {new Date().toLocaleDateString('en-PH', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}
        </div>
      </div>

      <div className="report-stats-grid">
        <StatCard
          label="Monthly Net Revenue"
          value={formatCurrency(s?.monthly_revenue ?? 0)}
          sub="Completed collections less refunds"
        />
        <StatCard
          label="Current Occupancy"
          value={`${Number(s?.current_occupancy_rate ?? 0).toFixed(1)}%`}
          sub={`${s?.occupied_rooms ?? 0} of ${s?.operational_rooms ?? 0} operational rooms`}
        />
        <StatCard
          label="Confirmed Bookings"
          value={(s?.confirmed_bookings ?? 0).toLocaleString()}
          sub={`${s?.checked_in_bookings ?? 0} currently checked in`}
        />
        <StatCard
          label="Pending Actions"
          value={(s?.pending_actions ?? 0).toLocaleString()}
          sub={(s?.pending_actions ?? 0) > 0 ? 'Requires administrator review' : 'All caught up'}
        />
      </div>

      <div className="dashboard-grid">
        <div className="dashboard-main-chart">
          <ReportChartCard
            title="Net Revenue Trend"
            subtitle="Completed collections after processed refunds"
            actions={(
              <div className="dashboard-chart-periods" aria-label="Revenue chart period">
                {Object.entries(periodLabels).map(([period, label]) => (
                  <button
                    key={period}
                    type="button"
                    className={revenuePeriod === period ? 'active' : ''}
                    aria-pressed={revenuePeriod === period}
                    onClick={() => setRevenuePeriod(period)}
                  >
                    {label}
                  </button>
                ))}
              </div>
            )}
            options={revenueChartOptions}
            empty={revenueLoading || revenueError || revenueData.length === 0}
            emptyMessage={revenueLoading
              ? 'Loading revenue trend...'
              : revenueError
                ? 'Revenue trend could not be loaded.'
                : 'No completed revenue for this period.'}
          />
        </div>

        <section className="dashboard-card dashboard-attention" aria-labelledby="attention-title">
          <div className="card-header">
            <div>
              <h2 id="attention-title">Needs Attention</h2>
              <p>{attention.total ?? 0} open action{(attention.total ?? 0) === 1 ? '' : 's'}</p>
            </div>
          </div>
          <div className="attention-list">
            {attentionItems.map((item) => (
              <button key={item.label} type="button" onClick={() => navigate(item.path)}>
                <span>{item.label}</span>
                <span className={item.count > 0 ? 'attention-count active' : 'attention-count'}>
                  {item.count}
                </span>
                <ArrowRight size={15} aria-hidden="true" />
              </button>
            ))}
          </div>
        </section>
      </div>

      <div className="dashboard-secondary-grid">
        <section className="dashboard-card" aria-labelledby="today-title">
          <div className="card-header"><h2 id="today-title">Today&apos;s Operations</h2></div>
          <div className="today-metrics">
            <div>
              <span>Check-ins</span>
              <strong>{s?.today_check_ins ?? 0}</strong>
            </div>
            <div>
              <span>Check-outs</span>
              <strong>{s?.today_check_outs ?? 0}</strong>
            </div>
          </div>
        </section>

        <section className="dashboard-card" aria-labelledby="rooms-title">
          <div className="card-header">
            <div>
              <h2 id="rooms-title">Room Status</h2>
              <p>{s?.total_rooms ?? 0} rooms total</p>
            </div>
          </div>
          <div className="room-status-list">
            {roomStatuses.map((room) => (
              <div key={room.label}>
                <span className={`room-status-dot ${room.tone}`} aria-hidden="true" />
                <span>{room.label}</span>
                <strong>{room.count}</strong>
              </div>
            ))}
          </div>
        </section>

        <section className="dashboard-card dashboard-upcoming" aria-labelledby="upcoming-title">
          <div className="card-header">
            <div>
              <h2 id="upcoming-title">Upcoming Check-ins</h2>
              <p>Next five scheduled arrivals</p>
            </div>
            <button
              type="button"
              className="view-all-btn"
              onClick={() => navigate('/admin/reports/reservation')}
            >
              View all reservations <ArrowRight size={14} aria-hidden="true" />
            </button>
          </div>
          {upcomingCheckIns.length === 0 ? (
            <p className="dashboard-empty-state">No upcoming check-ins.</p>
          ) : (
            <div className="upcoming-list">
              {upcomingCheckIns.map((booking) => (
                <div key={booking.id} className="upcoming-item">
                  <div>
                    <strong>{booking.guest_name}</strong>
                    <span>{booking.reference_number}</span>
                  </div>
                  <div className="upcoming-details">
                    <strong>{formatDate(booking.check_in)}</strong>
                    <span>{booking.rooms ? `Room ${booking.rooms}` : 'Room unassigned'}</span>
                  </div>
                </div>
              ))}
            </div>
          )}
        </section>
      </div>
    </div>
  );
};

export default Dashboard;

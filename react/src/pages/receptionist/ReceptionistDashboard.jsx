import React, { useEffect, useState } from 'react';
import { Clock } from 'lucide-react';
import StatusBadge from '../../components/StatusBadge';
import './ReceptionistDashboard.css';
import receptionistDashboardService from '../../services/receptionist/receptionistDashboardService';
import { PageSkeletonLoader, usePageCache } from '../../components/ProtectedRoute';
import useAutoRefresh from '../../hooks/useAutoRefresh';

const StatCard = ({ label, value, sub, color = '#0d1b3e' }) => (
  <div className="report-stat-card">
    <div className="report-stat-label">{label}</div>
    <div className="report-stat-value" style={{ color }}>{value}</div>
    {sub && <div className="report-stat-sub">{sub}</div>}
  </div>
);

const formatRoomType = (roomType) => String(roomType || '')
  .replace(/_/g, ' ')
  .replace(/\b\w/g, (char) => char.toUpperCase())
  .trim();

const ReceptionistDashboard = () => {
  const [todayArrivals, setTodayArrivals] = useState([]);
  const [pendingPayments, setPendingPayments] = useState([]);
  const [pendingRoomAssignments, setPendingRoomAssignments] = useState([]);
  const [error, setError] = useState(null);
  const [stats, setStats] = useState({
    todayCheckIns: 0,
    todayCheckOuts: 0,
    pendingPayments: 0,
    pendingRoomAssignments: 0,
    guestsInHouse: 0,
  });

  const { shouldShowSkeleton, markPageAsLoaded } = usePageCache('dashboard');
  const [loading, setLoading] = useState(shouldShowSkeleton);

  useEffect(() => {
    fetchDashboardData();
  }, []);

  const fetchDashboardData = async ({ showSkeleton = shouldShowSkeleton } = {}) => {
    try {
      if (showSkeleton) setLoading(true);
      const response = await receptionistDashboardService.getStats();
      setStats({
        todayCheckIns: response.stats?.todayCheckIns || 0,
        todayCheckOuts: response.stats?.todayCheckOuts || 0,
        pendingPayments: response.stats?.pendingPayments || 0,
        pendingRoomAssignments: response.stats?.pendingRoomAssignments || 0,
        guestsInHouse: response.stats?.guestsInHouse || 0,
      });
      setTodayArrivals(response.todayArrivals || []);
      setPendingPayments(response.pendingPaymentsList || []);
      setPendingRoomAssignments(response.pendingRoomAssignmentsList || []);
      setError(null);
      markPageAsLoaded();
    } catch (err) {
      console.error('Error fetching dashboard data:', err);
      setError('Failed to load dashboard data. Please try again.');
    } finally {
      if (showSkeleton) setLoading(false);
    }
  };

  useAutoRefresh(
    () => fetchDashboardData({ showSkeleton: false }),
    { intervalMs: 15000 }
  );

  const statsConfig = [
    { label: "Today's Check-ins", value: stats.todayCheckIns?.toLocaleString?.() ?? '0' },
    { label: "Today's Check-outs", value: stats.todayCheckOuts?.toLocaleString?.() ?? '0' },
    { label: 'Pending Payments', value: stats.pendingPayments?.toLocaleString?.() ?? '0' },
    { label: 'Room Pending', value: stats.pendingRoomAssignments?.toLocaleString?.() ?? '0' },
    { label: 'Guests In-House', value: stats.guestsInHouse?.toLocaleString?.() ?? '0' },
  ];

  if (loading) return <PageSkeletonLoader title="Dashboard" showStats={true} />;

  if (error) {
    return (
      <div className="r-dashboard-page">
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
        <div style={{ textAlign: 'center', padding: '3rem', color: 'red' }}>
          {error}
          <br />
          <button onClick={fetchDashboardData} style={{ marginTop: '1rem' }}>Retry</button>
        </div>
      </div>
    );
  }

  return (
    <div className="r-dashboard-page">
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
        {statsConfig.map((stat) => (
          <StatCard key={stat.label} label={stat.label} value={stat.value} />
        ))}
      </div>

      <div className="r-dashboard-grid">
        <div className="dashboard-card">
          <div className="card-header">
            <h2>Today's Arrivals</h2>
            <button className="view-all-btn">View All</button>
          </div>
          <div className="table-container">
            {todayArrivals.length === 0 ? (
              <div style={{ padding: '2rem', textAlign: 'center', color: '#999' }}>
                No arrivals scheduled for today
              </div>
            ) : (
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Booking ID</th>
                    <th>Guest</th>
                    <th>Room</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {todayArrivals.map((row) => (
                    <tr key={row.id}>
                      <td className="booking-id">{row.id}</td>
                      <td>{row.guest}</td>
                      <td>{row.room}</td>
                      <td>
                        <StatusBadge status={row.status} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </div>

        <div className="dashboard-card">
          <div className="card-header">
            <h2>Pending Payments</h2>
            <span className="badge-count">{pendingPayments.length}</span>
          </div>
          <div className="pending-list">
            {pendingPayments.length === 0 ? (
              <div style={{ padding: '2rem', textAlign: 'center', color: '#999' }}>
                No pending payments
              </div>
            ) : (
              pendingPayments.map((p) => (
                <div key={p.id} className="pending-item">
                  <div className="pending-info">
                    <span className="pending-guest">{p.guest}</span>
                    <span className="pending-meta">{p.id} · {p.method} · {p.uploaded}</span>
                  </div>
                  <div className="pending-right">
                    <span className="pending-amount">{p.amount}</span>
                  </div>
                </div>
              ))
            )}
          </div>
        </div>

        <div className="dashboard-card">
          <div className="card-header">
            <h2>Pending Room Assignment</h2>
            <span className="badge-count">{pendingRoomAssignments.length}</span>
          </div>
          <div className="pending-list">
            {pendingRoomAssignments.length === 0 ? (
              <div style={{ padding: '2rem', textAlign: 'center', color: '#999' }}>
                No pending room assignments
              </div>
            ) : (
              pendingRoomAssignments.map((entry) => (
                <div key={entry.id} className="pending-item">
                  <div className="pending-info">
                    <span className="pending-guest">{entry.guest}</span>
                    <span className="pending-meta">{entry.id} · {formatRoomType(entry.room_type)} · {entry.check_in}</span>
                  </div>
                  <div className="pending-right">
                    <span className="pending-amount">Room Pending</span>
                  </div>
                </div>
              ))
            )}
          </div>
        </div>
      </div>
    </div>
  );
};

export default ReceptionistDashboard;

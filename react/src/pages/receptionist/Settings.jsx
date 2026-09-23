import React, { useEffect, useState } from 'react';
import { User, Lock, Bell, Save, Eye, EyeOff } from 'lucide-react';
import api from '../../services/receptionistApi';
import { clearUser } from '../../services/authService';
import { showToast } from '../../utils/showToast';
import './Settings.css';

const defaultNotifications = {
  newBooking: true,
  paymentUploaded: true,
  cancellation: true,
  rebooking: false,
  dailySummary: true,
};

const settingsTabs = [
  { id: 'profile', label: 'Profile', description: 'Personal and contact details', icon: User },
  { id: 'password', label: 'Password', description: 'Sign-in security', icon: Lock },
  { id: 'notifications', label: 'Notifications', description: 'Alerts and daily summaries', icon: Bell },
];

const SettingsPage = () => {
  const [activeTab, setActiveTab] = useState('profile');
  const [loading, setLoading] = useState(true);
  const [profile, setProfile] = useState({
    name: '',
    email: '',
    phone: '',
    position: 'Front Desk Receptionist',
  });

  const [passwords, setPasswords] = useState({ current: '', newPw: '', confirm: '' });
  const [showPw, setShowPw] = useState({ current: false, newPw: false, confirm: false });
  const [notifications, setNotifications] = useState(defaultNotifications);
  const [saving, setSaving] = useState({ profile: false, password: false, notif: false });

  const fetchProfile = async () => {
    try {
      setLoading(true);
      const response = await api.get('/receptionist/profile');
      const user = response.data || {};

      setProfile({
        name: user.name || '',
        email: user.email || '',
        phone: user.phone || '',
        position: user.role === 'admin' ? 'Administrator' : 'Front Desk Receptionist',
      });
      setNotifications({ ...defaultNotifications, ...(user.notification_preferences || {}) });
    } catch (err) {
      showToast(err?.response?.data?.message || 'Failed to load profile settings.', 'error');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchProfile();
  }, []);

  const togglePw = (field) =>
    setShowPw((prev) => ({ ...prev, [field]: !prev[field] }));

  const setSavingState = (section, value) => {
    setSaving((prev) => ({ ...prev, [section]: value }));
  };

  const saveProfile = async () => {
    try {
      setSavingState('profile', true);
      await api.put('/receptionist/profile', {
        name: profile.name,
        email: profile.email,
        phone: profile.phone,
      });
      showToast('Profile updated successfully.', 'success');
      await fetchProfile();
    } catch (err) {
      showToast(err?.response?.data?.message || 'Failed to update profile.', 'error');
    } finally {
      setSavingState('profile', false);
    }
  };

  const savePassword = async () => {
    if (passwords.newPw !== passwords.confirm) {
      showToast('New password and confirmation do not match.', 'error');
      return;
    }

    if (passwords.current === passwords.newPw) {
      showToast('New password must be different from your current password.', 'error');
      return;
    }

    if (
      passwords.newPw.length < 12
      || !/[a-z]/.test(passwords.newPw)
      || !/[A-Z]/.test(passwords.newPw)
      || !/\d/.test(passwords.newPw)
      || !/[^A-Za-z0-9]/.test(passwords.newPw)
    ) {
      showToast('Use 12+ characters with uppercase, lowercase, a number, and a symbol.', 'error');
      return;
    }

    try {
      setSavingState('password', true);
      await api.post('/receptionist/profile/change-password', {
        current_password: passwords.current,
        new_password: passwords.newPw,
        new_password_confirmation: passwords.confirm,
      });
      showToast('Password changed. Please sign in again.', 'success');
      setPasswords({ current: '', newPw: '', confirm: '' });
      clearUser('receptionist');
      window.setTimeout(() => window.location.assign('/login'), 800);
    } catch (err) {
      const validationMessage = Object.values(err?.response?.data?.errors || {}).flat()[0];
      showToast(validationMessage || err?.response?.data?.message || 'Failed to change password.', 'error');
    } finally {
      setSavingState('password', false);
    }
  };

  const saveNotifications = async () => {
    try {
      setSavingState('notif', true);
      await api.put('/receptionist/profile/notification-preferences', notifications);
      showToast('Notification preferences saved.', 'success');
    } catch (err) {
      showToast(err?.response?.data?.message || 'Failed to save notification preferences.', 'error');
    } finally {
      setSavingState('notif', false);
    }
  };

  if (loading) {
    return (
      <div className="r-settings-page">
        <div className="page-header">
          <div>
            <h1>Settings</h1>
            <p className="page-subtitle">Loading your account preferences...</p>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="r-settings-page">
      <div className="page-header">
        <div>
          <h1>Settings</h1>
          <p className="page-subtitle">Manage your profile, security, and alerts.</p>
        </div>
      </div>

      <div className="r-settings-shell">
        <nav className="r-settings-tabs" aria-label="Receptionist settings" role="tablist">
          {settingsTabs.map(({ id, label, description, icon: Icon }) => (
            <button
              key={id}
              type="button"
              role="tab"
              aria-selected={activeTab === id}
              aria-controls={`receptionist-settings-${id}`}
              className={`r-settings-tab ${activeTab === id ? 'is-active' : ''}`}
              onClick={() => setActiveTab(id)}
            >
              <span className="r-settings-tab-icon"><Icon size={18} /></span>
              <span className="r-settings-tab-copy">
                <strong>{label}</strong>
                <small>{description}</small>
              </span>
            </button>
          ))}
        </nav>

        <div className="r-settings-panel">
        <div
          id="receptionist-settings-profile"
          className="settings-card"
          role="tabpanel"
          hidden={activeTab !== 'profile'}
        >
          <div className="settings-card-header">
            <div className="settings-card-icon"><User size={18} /></div>
            <div>
              <h2>Profile Information</h2>
              <p>Update your personal details.</p>
            </div>
          </div>
          <div className="settings-fields">
            <div className="settings-field">
              <label>Full Name</label>
              <input
                type="text"
                value={profile.name}
                onChange={(e) => setProfile({ ...profile, name: e.target.value })}
              />
            </div>
            <div className="settings-field">
              <label>Email Address</label>
              <input
                type="email"
                value={profile.email}
                onChange={(e) => setProfile({ ...profile, email: e.target.value })}
              />
            </div>
            <div className="settings-field">
              <label>Phone Number</label>
              <input
                type="tel"
                value={profile.phone || ''}
                onChange={(e) => setProfile({ ...profile, phone: e.target.value })}
              />
            </div>
            <div className="settings-field">
              <label>Position</label>
              <input type="text" value={profile.position} disabled />
            </div>
          </div>
          <div className="settings-card-footer">
            <button className="settings-save-btn" onClick={saveProfile} disabled={saving.profile}>
              <Save size={16} />
              {saving.profile ? 'Saving...' : 'Save Changes'}
            </button>
          </div>
        </div>

        <div
          id="receptionist-settings-password"
          className="settings-card"
          role="tabpanel"
          hidden={activeTab !== 'password'}
        >
          <div className="settings-card-header">
            <div className="settings-card-icon icon-lock"><Lock size={18} /></div>
            <div>
              <h2>Change Password</h2>
              <p>Update your login credentials.</p>
            </div>
          </div>
          <div className="settings-fields">
            {[
              { key: 'current', label: 'Current Password' },
              { key: 'newPw', label: 'New Password' },
              { key: 'confirm', label: 'Confirm Password' },
            ].map(({ key, label }) => (
              <div className="settings-field" key={key}>
                <label>{label}</label>
                <div className="pw-input-wrap">
                  <input
                    type={showPw[key] ? 'text' : 'password'}
                    placeholder="••••••••"
                    value={passwords[key]}
                    onChange={(e) => setPasswords({ ...passwords, [key]: e.target.value })}
                  />
                  <button
                    type="button"
                    className="pw-toggle"
                    onClick={() => togglePw(key)}
                    aria-label={`${showPw[key] ? 'Hide' : 'Show'} ${label.toLowerCase()}`}
                  >
                    {showPw[key] ? <EyeOff size={16} /> : <Eye size={16} />}
                  </button>
                </div>
                {key === 'newPw' && (
                  <p className="password-requirements">
                    Use 12+ characters with uppercase, lowercase, a number, and a symbol.
                  </p>
                )}
              </div>
            ))}
          </div>
          <div className="settings-card-footer">
            <button className="settings-save-btn" onClick={savePassword} disabled={saving.password}>
              <Save size={16} />
              {saving.password ? 'Saving...' : 'Update Password'}
            </button>
          </div>
        </div>

        <div
          id="receptionist-settings-notifications"
          className="settings-card settings-card-full"
          role="tabpanel"
          hidden={activeTab !== 'notifications'}
        >
          <div className="settings-card-header">
            <div className="settings-card-icon icon-bell"><Bell size={18} /></div>
            <div>
              <h2>Notification Preferences</h2>
              <p>Choose which events you want to be notified about.</p>
            </div>
          </div>
          <div className="notif-list">
            {[
              { key: 'newBooking', label: 'New Booking', desc: 'Receive alert when a new booking is made.' },
              { key: 'paymentUploaded', label: 'Payment Uploaded', desc: 'Guest has submitted proof of payment.' },
              { key: 'cancellation', label: 'Cancellation', desc: 'A booking has been cancelled.' },
              { key: 'rebooking', label: 'Rebooking', desc: 'A guest has changed their booking.' },
              { key: 'dailySummary', label: 'Daily Summary', desc: "Morning overview of the day's schedule." },
            ].map(({ key, label, desc }) => (
              <div className="notif-item" key={key}>
                <div className="notif-info">
                  <span className="notif-label">{label}</span>
                  <span className="notif-desc">{desc}</span>
                </div>
                <button
                  type="button"
                  className={`toggle-btn ${notifications[key] ? 'on' : ''}`}
                  onClick={() => setNotifications({ ...notifications, [key]: !notifications[key] })}
                  aria-pressed={notifications[key]}
                >
                  <span className="toggle-thumb" />
                </button>
              </div>
            ))}
          </div>
          <div className="settings-card-footer">
            <button className="settings-save-btn" onClick={saveNotifications} disabled={saving.notif}>
              <Save size={16} />
              {saving.notif ? 'Saving...' : 'Save Preferences'}
            </button>
          </div>
        </div>
        </div>
      </div>
    </div>
  );
};

export default SettingsPage;


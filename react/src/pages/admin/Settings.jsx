import React, { useEffect, useRef, useState } from 'react';
import { Save, Globe, Bell, Lock, Mail, AlertTriangle, RefreshCw, Send, CheckCircle2, XCircle, QrCode, Upload, Trash2 } from 'lucide-react';
import api from '../../services/adminApi';
import { showToast } from '../../utils/showToast';
import ConfirmDialog from '../../components/ConfirmDialog';
import './Settings.css';

const initialState = {
  hotel_name: '',
  site_url: '',
  contact_email: '',
  contact_phone: '',
  address: '',
  check_in_time: '15:00',
  check_out_time: '12:00',
  cancellation_policy: '',
  downpayment_percentage: 50,
  tax_percentage: 12,
  timezone: 'Asia/Manila',
  currency: 'PHP',
  email_notifications: true,
  booking_notifications: true,
  maintenance_mode: false,
};

const settingsTabs = [
  { id: 'general', label: 'Hotel & booking', description: 'Property details and booking rules', icon: Globe },
  { id: 'payments', label: 'Payments', description: 'Manual GCash configuration', icon: QrCode },
  { id: 'notifications', label: 'Notifications', description: 'Staff alert preferences', icon: Bell },
  { id: 'security', label: 'Security', description: 'Public booking availability', icon: Lock },
  { id: 'email', label: 'Email delivery', description: 'Server status and diagnostics', icon: Mail },
];

const Settings = () => {
  const [activeTab, setActiveTab] = useState('general');
  const [settings, setSettings] = useState(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [confirmation, setConfirmation] = useState(null);
  const confirmationActionRef = useRef(null);

  const requestConfirmation = (dialog, action) => {
    confirmationActionRef.current = action;
    setConfirmation(dialog);
  };

  const closeConfirmation = () => {
    confirmationActionRef.current = null;
    setConfirmation(null);
  };

  const confirmAction = () => {
    const action = confirmationActionRef.current;
    closeConfirmation();
    action?.();
  };
  const [loadError, setLoadError] = useState(null);
  const [savedMaintenanceMode, setSavedMaintenanceMode] = useState(false);
  const [mailStatus, setMailStatus] = useState(null);
  const [mailStatusLoading, setMailStatusLoading] = useState(true);
  const [mailStatusError, setMailStatusError] = useState(null);
  const [sendingTestEmail, setSendingTestEmail] = useState(false);
  const [manualGcash, setManualGcash] = useState(null);
  const [manualGcashForm, setManualGcashForm] = useState({
    merchant_name: '',
    account_name: '',
    account_number: '',
  });
  const [manualGcashFile, setManualGcashFile] = useState(null);
  const [manualGcashOwnershipConfirmed, setManualGcashOwnershipConfirmed] = useState(false);
  const [manualGcashPreview, setManualGcashPreview] = useState(null);
  const [manualGcashLoading, setManualGcashLoading] = useState(true);
  const [manualGcashError, setManualGcashError] = useState(null);
  const [savingManualGcash, setSavingManualGcash] = useState(false);
  const [removingManualGcash, setRemovingManualGcash] = useState(false);
  const manualGcashPreviewRef = useRef(null);
  const manualGcashFileInputRef = useRef(null);

  const handleChange = (field, value) => {
    setSettings((prev) => ({ ...(prev || initialState), [field]: value }));
  };

  const fetchSettings = async () => {
    try {
      setLoading(true);
      setLoadError(null);
      const response = await api.get('/admin/settings');
      const loadedSettings = response.data || {};
      setSettings({ ...initialState, ...loadedSettings });
      setSavedMaintenanceMode(Boolean(loadedSettings.maintenance_mode));
    } catch (error) {
      const message = error?.response?.data?.message || 'Failed to load settings. Please check your connection and try again.';
      setSettings(null);
      setLoadError(message);
      showToast(message, 'error');
    } finally {
      setLoading(false);
    }
  };

  const fetchMailStatus = async () => {
    try {
      setMailStatusLoading(true);
      setMailStatusError(null);
      const response = await api.get('/admin/settings/mail-status');
      setMailStatus(response.data?.data || null);
    } catch (error) {
      setMailStatus(null);
      setMailStatusError(error?.response?.data?.message || 'Unable to load email delivery diagnostics.');
    } finally {
      setMailStatusLoading(false);
    }
  };

  const replaceManualGcashPreview = (url) => {
    if (manualGcashPreviewRef.current) {
      URL.revokeObjectURL(manualGcashPreviewRef.current);
    }

    manualGcashPreviewRef.current = url;
    setManualGcashPreview(url);
  };

  const fetchManualGcash = async () => {
    try {
      setManualGcashLoading(true);
      setManualGcashError(null);
      const response = await api.get('/admin/settings/manual-gcash');
      const status = response.data?.data || null;
      setManualGcash(status);
      setManualGcashForm({
        merchant_name: status?.merchant_name || '',
        account_name: status?.account_name || '',
        account_number: status?.account_number || '',
      });
      setManualGcashFile(null);
      setManualGcashOwnershipConfirmed(false);
      if (manualGcashFileInputRef.current) {
        manualGcashFileInputRef.current.value = '';
      }

      if (status?.qr?.available) {
        const qrResponse = await api.get('/admin/settings/manual-gcash/qr', {
          responseType: 'blob',
        });
        replaceManualGcashPreview(URL.createObjectURL(qrResponse.data));
      } else {
        replaceManualGcashPreview(null);
      }
    } catch (error) {
      replaceManualGcashPreview(null);
      setManualGcash(null);
      setManualGcashError(error?.response?.data?.message || 'Unable to load the manual GCash configuration.');
    } finally {
      setManualGcashLoading(false);
    }
  };

  useEffect(() => {
    fetchSettings();
    fetchMailStatus();
    fetchManualGcash();

    return () => {
      if (manualGcashPreviewRef.current) {
        URL.revokeObjectURL(manualGcashPreviewRef.current);
      }
    };
  }, []);

  const handleManualGcashField = (field, value) => {
    setManualGcashForm((current) => ({ ...current, [field]: value }));
  };

  const handleManualGcashFile = (event) => {
    const file = event.target.files?.[0] || null;
    if (!file) {
      setManualGcashFile(null);
      return;
    }

    if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) {
      event.target.value = '';
      showToast('Choose a PNG, JPEG, or WebP merchant QR image.', 'error');
      return;
    }

    if (file.size > 5 * 1024 * 1024) {
      event.target.value = '';
      showToast('The merchant QR image must not exceed 5 MB.', 'error');
      return;
    }

    setManualGcashFile(file);
    setManualGcashOwnershipConfirmed(false);
    replaceManualGcashPreview(URL.createObjectURL(file));
  };

  const handleSaveManualGcash = async (confirmed = false) => {
    if (savingManualGcash || !manualGcashForm.merchant_name.trim() || !manualGcashForm.account_name.trim()) {
      showToast('Merchant name and GCash account name are required.', 'error');
      return;
    }

    if (!manualGcash?.configured && !manualGcashFile) {
      showToast('Upload the official merchant GCash QR image.', 'error');
      return;
    }

    if (manualGcashFile && !manualGcashOwnershipConfirmed) {
      showToast('Scan the QR and confirm that it opens the official hotel GCash account.', 'error');
      return;
    }

    if (manualGcash?.qr?.available && manualGcashFile && confirmed !== true) {
      requestConfirmation({
        title: 'Replace merchant QR image?',
        message: 'Confirm that the new QR belongs to the official hotel GCash account. It will replace the current merchant QR image.',
        confirmLabel: 'Replace QR Image',
      }, () => handleSaveManualGcash(true));
      return;
    }

    const formData = new FormData();
    formData.append('merchant_name', manualGcashForm.merchant_name.trim());
    formData.append('account_name', manualGcashForm.account_name.trim());
    formData.append('account_number', manualGcashForm.account_number.trim());
    if (manualGcashFile) {
      formData.append('qr_image', manualGcashFile);
      formData.append('ownership_confirmed', '1');
    }

    try {
      setSavingManualGcash(true);
      const response = await api.post('/admin/settings/manual-gcash', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      showToast(response.data?.message || 'Manual GCash configuration saved.', 'success');
      await fetchManualGcash();
    } catch (error) {
      showToast(error?.response?.data?.message || 'Unable to save the manual GCash configuration.', 'error');
    } finally {
      setSavingManualGcash(false);
    }
  };

  const handleRemoveManualGcash = async (confirmed = false) => {
    if (!manualGcash?.configured || removingManualGcash) return;
    if (confirmed !== true) {
      requestConfirmation({
        title: 'Remove GCash configuration?',
        message: 'This will remove the merchant GCash configuration and private QR image. Manual GCash cannot be enabled without configuring it again.',
        confirmLabel: 'Remove Configuration',
        danger: true,
      }, () => handleRemoveManualGcash(true));
      return;
    }

    try {
      setRemovingManualGcash(true);
      const response = await api.delete('/admin/settings/manual-gcash');
      showToast(response.data?.message || 'Manual GCash configuration removed.', 'success');
      await fetchManualGcash();
    } catch (error) {
      showToast(error?.response?.data?.message || 'Unable to remove the manual GCash configuration.', 'error');
    } finally {
      setRemovingManualGcash(false);
    }
  };

  const handleSendTestEmail = async () => {
    if (!mailStatus?.configured || sendingTestEmail) {
      return;
    }

    try {
      setSendingTestEmail(true);
      const response = await api.post('/admin/settings/send-test-email');
      showToast(response.data?.message || 'Test email sent successfully.', 'success');
    } catch (error) {
      showToast(error?.response?.data?.message || 'The test email could not be delivered.', 'error');
      await fetchMailStatus();
    } finally {
      setSendingTestEmail(false);
    }
  };

  const handleSave = async (confirmed = false) => {
    if (!settings || saving) {
      return;
    }

    const enablingMaintenance = !savedMaintenanceMode && Boolean(settings.maintenance_mode);
    if (enablingMaintenance && confirmed !== true) {
      requestConfirmation({
        title: 'Enable maintenance mode?',
        message: 'New online room searches and booking creation will be blocked. Existing verification links, payments, cancellations, and staff operations will continue to work.',
        confirmLabel: 'Enable Maintenance Mode',
        danger: true,
      }, () => handleSave(true));
      return;
    }

    try {
      setSaving(true);
      const payload = {
        ...settings,
        downpayment_percentage: Number(settings.downpayment_percentage),
        tax_percentage: Number(settings.tax_percentage),
      };
      await api.put('/admin/settings', payload);
      showToast('Settings saved successfully!', 'success');
      await fetchSettings();
    } catch (error) {
      showToast(error?.response?.data?.message || 'Failed to save settings.', 'error');
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <div className="settings-page">
        <div className="page-header">
          <div>
            <h1>Settings</h1>
            <p className="page-subtitle">Loading system configuration...</p>
          </div>
        </div>
      </div>
    );
  }

  if (loadError || !settings) {
    return (
      <div className="settings-page">
        <div className="page-header">
          <div>
            <h1>Settings</h1>
            <p className="page-subtitle">Configure your hotel management system</p>
          </div>
        </div>
        <div className="settings-load-error" role="alert">
          <AlertTriangle size={40} />
          <h2>Unable to load settings</h2>
          <p>{loadError || 'Settings data is unavailable.'}</p>
          <p>Your saved settings were not changed. Retry before making any updates.</p>
          <button className="btn-primary" type="button" onClick={fetchSettings}>
            <RefreshCw size={18} />
            Retry Loading Settings
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="settings-page">
      <ConfirmDialog
        open={Boolean(confirmation)}
        {...confirmation}
        onConfirm={confirmAction}
        onCancel={closeConfirmation}
      />
      <div className="page-header">
        <div>
          <h1>Settings</h1>
          <p className="page-subtitle">Manage hotel operations from one place.</p>
        </div>
        {['general', 'notifications', 'security'].includes(activeTab) && (
          <button className="btn-primary" onClick={handleSave} disabled={saving}>
            <Save size={18} />
            {saving ? 'Saving...' : 'Save Changes'}
          </button>
        )}
      </div>

      <div className="settings-shell">
        <nav className="settings-tabs" aria-label="Admin settings" role="tablist">
          {settingsTabs.map(({ id, label, description, icon: Icon }) => (
            <button
              key={id}
              type="button"
              role="tab"
              aria-selected={activeTab === id}
              aria-controls={`admin-settings-${id}`}
              className={`settings-tab ${activeTab === id ? 'is-active' : ''}`}
              onClick={() => setActiveTab(id)}
            >
              <span className="settings-tab-icon"><Icon size={18} /></span>
              <span className="settings-tab-copy">
                <strong>{label}</strong>
                <small>{description}</small>
              </span>
            </button>
          ))}
        </nav>

        <div className="settings-panel">
        <div
          id="admin-settings-payments"
          className="settings-card settings-card-wide"
          role="tabpanel"
          hidden={activeTab !== 'payments'}
        >
          <div className="settings-card-header">
            <QrCode size={20} />
            <h2>Manual GCash Merchant QR</h2>
          </div>
          <div className="settings-form">
            {manualGcashLoading && (
              <div className="mail-status-loading">
                <RefreshCw size={18} className="spin" />
                Loading the private merchant QR configuration...
              </div>
            )}

            {!manualGcashLoading && manualGcashError && (
              <div className="mail-status-error" role="alert">
                <AlertTriangle size={20} />
                <div>
                  <strong>Manual GCash configuration unavailable</strong>
                  <p>{manualGcashError}</p>
                </div>
                <button type="button" className="btn-secondary" onClick={fetchManualGcash}>Retry</button>
              </div>
            )}

            {!manualGcashLoading && !manualGcashError && manualGcash && (
              <>
                <div className={`manual-gcash-summary ${manualGcash.configured ? 'is-ready' : 'has-issues'}`}>
                  {manualGcash.configured ? <CheckCircle2 size={24} /> : <AlertTriangle size={24} />}
                  <div>
                    <strong>{manualGcash.configured ? 'Merchant QR configuration is ready' : 'Merchant QR configuration is incomplete'}</strong>
                    <p>The QR is stored privately and can only be viewed by an authenticated administrator.</p>
                    <p>Phase 2 does not enable customer manual GCash checkout. That remains locked until Phase 3.</p>
                  </div>
                </div>

                <div className="manual-gcash-layout">
                  <div className="manual-gcash-fields">
                    <div className="form-group">
                      <label>Merchant Display Name</label>
                      <input
                        type="text"
                        maxLength={120}
                        value={manualGcashForm.merchant_name}
                        onChange={(event) => handleManualGcashField('merchant_name', event.target.value)}
                        placeholder="H+ Hotel"
                      />
                    </div>
                    <div className="form-group">
                      <label>GCash Account Name</label>
                      <input
                        type="text"
                        maxLength={120}
                        value={manualGcashForm.account_name}
                        onChange={(event) => handleManualGcashField('account_name', event.target.value)}
                        placeholder="Official registered account name"
                      />
                    </div>
                    <div className="form-group">
                      <label>GCash Account Number (Optional)</label>
                      <input
                        type="text"
                        maxLength={25}
                        value={manualGcashForm.account_number}
                        onChange={(event) => handleManualGcashField('account_number', event.target.value)}
                        placeholder="09XX XXX XXXX"
                      />
                      <span className="form-hint">This will be displayed to customers only after the Phase 3 checkout is approved.</span>
                    </div>
                    <div className="form-group">
                      <label htmlFor="manual-gcash-qr">Official Merchant QR Image</label>
                      <input
                        ref={manualGcashFileInputRef}
                        id="manual-gcash-qr"
                        type="file"
                        accept="image/png,image/jpeg,image/webp"
                        onChange={handleManualGcashFile}
                      />
                      <span className="form-hint">PNG, JPEG, or WebP only; 300–4000 pixels; maximum 5 MB. SVG and PDF files are rejected.</span>
                    </div>
                    {manualGcashFile && (
                      <label className="manual-gcash-confirmation">
                        <input
                          type="checkbox"
                          checked={manualGcashOwnershipConfirmed}
                          onChange={(event) => setManualGcashOwnershipConfirmed(event.target.checked)}
                        />
                        <span>I scanned this QR and confirmed that it opens the official hotel GCash merchant account.</span>
                      </label>
                    )}
                  </div>

                  <div className="manual-gcash-preview-panel">
                    <div className="manual-gcash-preview">
                      {manualGcashPreview ? (
                        <img src={manualGcashPreview} alt="Private manual GCash merchant QR preview" />
                      ) : (
                        <div className="manual-gcash-preview-empty">
                          <QrCode size={48} />
                          <span>No merchant QR uploaded</span>
                        </div>
                      )}
                    </div>
                    {manualGcash.qr?.available && !manualGcashFile && (
                      <dl className="manual-gcash-file-details">
                        <div><dt>File</dt><dd>{manualGcash.qr.original_name}</dd></div>
                        <div><dt>Dimensions</dt><dd>{manualGcash.qr.width} × {manualGcash.qr.height}</dd></div>
                        <div><dt>Uploaded By</dt><dd>{manualGcash.qr.uploaded_by || 'Administrator'}</dd></div>
                      </dl>
                    )}
                  </div>
                </div>

                {manualGcash.issues?.length > 0 && (
                  <div className="mail-configuration-issues" role="alert">
                    <strong>Configuration issues</strong>
                    <ul>{manualGcash.issues.map((issue) => <li key={issue}>{issue}</li>)}</ul>
                  </div>
                )}

                <div className="manual-gcash-actions">
                  <button type="button" className="btn-primary" onClick={handleSaveManualGcash} disabled={savingManualGcash}>
                    <Upload size={18} />
                    {savingManualGcash ? 'Saving Securely...' : manualGcash.configured ? 'Save or Replace Merchant QR' : 'Save Merchant QR Configuration'}
                  </button>
                  {manualGcash.configured && (
                    <button type="button" className="btn-danger-outline" onClick={handleRemoveManualGcash} disabled={removingManualGcash}>
                      <Trash2 size={18} />
                      {removingManualGcash ? 'Removing...' : 'Remove Configuration'}
                    </button>
                  )}
                </div>
              </>
            )}
          </div>
        </div>

        <div className="settings-columns">
        <div
          id="admin-settings-general"
          className="settings-card"
          role="tabpanel"
          hidden={activeTab !== 'general'}
        >
          <div className="settings-card-header">
            <Globe size={20} />
            <h2>General Settings</h2>
          </div>
          <div className="settings-form">
            <div className="form-group">
              <label>Hotel Name</label>
              <input
                type="text"
                value={settings.hotel_name}
                onChange={(e) => handleChange('hotel_name', e.target.value)}
              />
            </div>
            <div className="form-group">
              <label>Site URL</label>
              <input
                type="url"
                value={settings.site_url || ''}
                onChange={(e) => handleChange('site_url', e.target.value)}
              />
            </div>
            <div className="form-group">
              <label>Contact Email</label>
              <input
                type="email"
                value={settings.contact_email}
                onChange={(e) => handleChange('contact_email', e.target.value)}
              />
            </div>
            <div className="form-group">
              <label>Contact Phone</label>
              <input
                type="text"
                value={settings.contact_phone || ''}
                onChange={(e) => handleChange('contact_phone', e.target.value)}
              />
            </div>
            <div className="form-group">
              <label>Address</label>
              <input
                type="text"
                value={settings.address || ''}
                onChange={(e) => handleChange('address', e.target.value)}
              />
            </div>
            <div className="form-row">
              <div className="form-group">
                <label>Check-in Time</label>
                <input
                  type="time"
                  value={settings.check_in_time}
                  onChange={(e) => handleChange('check_in_time', e.target.value)}
                />
              </div>
              <div className="form-group">
                <label>Check-out Time</label>
                <input
                  type="time"
                  value={settings.check_out_time}
                  onChange={(e) => handleChange('check_out_time', e.target.value)}
                />
              </div>
            </div>
            <div className="form-row">
              <div className="form-group">
                <label>Downpayment %</label>
                <input
                  type="number"
                  min="1"
                  max="100"
                  step="0.01"
                  value={settings.downpayment_percentage}
                  onChange={(e) => handleChange('downpayment_percentage', e.target.value)}
                />
              </div>
              <div className="form-group">
                <label>Tax %</label>
                <input
                  type="number"
                  min="0"
                  max="100"
                  step="0.01"
                  value={settings.tax_percentage}
                  onChange={(e) => handleChange('tax_percentage', e.target.value)}
                />
              </div>
            </div>
            <div className="form-row">
              <div className="form-group">
                <label>Timezone</label>
                <input
                  type="text"
                  value={settings.timezone || ''}
                  onChange={(e) => handleChange('timezone', e.target.value)}
                />
              </div>
              <div className="form-group">
                <label>Currency</label>
                <input
                  type="text"
                  value={settings.currency || ''}
                  onChange={(e) => handleChange('currency', e.target.value)}
                />
              </div>
            </div>
            <div className="form-group">
              <label>Cancellation Policy</label>
              <textarea
                rows={3}
                value={settings.cancellation_policy || ''}
                onChange={(e) => handleChange('cancellation_policy', e.target.value)}
              />
            </div>
          </div>
        </div>

        <div className="settings-stack">
        <div
          id="admin-settings-notifications"
          className="settings-card"
          role="tabpanel"
          hidden={activeTab !== 'notifications'}
        >
          <div className="settings-card-header">
            <Bell size={20} />
            <h2>Notification Settings</h2>
          </div>
          <div className="settings-form">
            <div className="toggle-group">
              <div className="toggle-item">
                <div className="toggle-info">
                  <h3>Staff Email Notifications</h3>
                  <p>Master switch for optional staff email alerts. Customer and security emails remain enabled.</p>
                </div>
                <label className="toggle-switch">
                  <input
                    type="checkbox"
                    checked={Boolean(settings.email_notifications)}
                    onChange={(e) => handleChange('email_notifications', e.target.checked)}
                  />
                  <span className="toggle-slider"></span>
                </label>
              </div>
              <div className="toggle-item">
                <div className="toggle-info">
                  <h3>New Booking Alerts</h3>
                  <p>Notify active administrators and receptionists when a new online booking is submitted.</p>
                </div>
                <label className="toggle-switch">
                  <input
                    type="checkbox"
                    checked={Boolean(settings.booking_notifications)}
                    onChange={(e) => handleChange('booking_notifications', e.target.checked)}
                  />
                  <span className="toggle-slider"></span>
                </label>
              </div>
            </div>
          </div>
        </div>

        <div
          id="admin-settings-security"
          className="settings-card"
          role="tabpanel"
          hidden={activeTab !== 'security'}
        >
          <div className="settings-card-header">
            <Lock size={20} />
            <h2>Security Settings</h2>
          </div>
          <div className="settings-form">
            <div className="toggle-group">
              <div className="toggle-item">
                <div className="toggle-info">
                  <h3>Maintenance Mode</h3>
                  <p>Temporarily disable public booking flows</p>
                </div>
                <label className="toggle-switch">
                  <input
                    type="checkbox"
                    checked={Boolean(settings.maintenance_mode)}
                    onChange={(e) => handleChange('maintenance_mode', e.target.checked)}
                  />
                  <span className="toggle-slider"></span>
                </label>
              </div>
              {settings.maintenance_mode && (
                <div className="maintenance-warning" role="status">
                  Maintenance Mode will block new online room searches and bookings after you save. Existing guest payments and staff operations remain available.
                </div>
              )}
            </div>
          </div>
        </div>

        <div
          id="admin-settings-email"
          className="settings-card"
          role="tabpanel"
          hidden={activeTab !== 'email'}
        >
          <div className="settings-card-header">
            <Mail size={20} />
            <h2>Email Delivery Status</h2>
          </div>
          <div className="settings-form">
            {mailStatusLoading && (
              <div className="mail-status-loading">
                <RefreshCw size={18} className="spin" />
                Checking the active server configuration...
              </div>
            )}

            {!mailStatusLoading && mailStatusError && (
              <div className="mail-status-error" role="alert">
                <AlertTriangle size={20} />
                <div>
                  <strong>Diagnostics unavailable</strong>
                  <p>{mailStatusError}</p>
                </div>
                <button type="button" className="btn-secondary" onClick={fetchMailStatus}>
                  Retry
                </button>
              </div>
            )}

            {!mailStatusLoading && mailStatus && (
              <>
                <div className={`mail-status-summary ${mailStatus.configured ? 'is-ready' : 'has-issues'}`}>
                  {mailStatus.configured ? <CheckCircle2 size={24} /> : <XCircle size={24} />}
                  <div>
                    <strong>{mailStatus.configured ? 'Email delivery is configured' : 'Email configuration needs attention'}</strong>
                    <p>Credentials are managed securely in the server <code>.env</code> file and are never displayed here.</p>
                  </div>
                </div>

                <dl className="mail-diagnostics">
                  <div><dt>Mailer</dt><dd>{mailStatus.mailer}</dd></div>
                  <div><dt>SMTP Host</dt><dd>{mailStatus.host}</dd></div>
                  <div><dt>SMTP Port</dt><dd>{mailStatus.port || 'not configured'}</dd></div>
                  <div><dt>Encryption</dt><dd>{mailStatus.encryption}</dd></div>
                  <div><dt>Username</dt><dd>{mailStatus.username}</dd></div>
                  <div><dt>Sender</dt><dd>{mailStatus.from_name} &lt;{mailStatus.from_address}&gt;</dd></div>
                  <div><dt>Queue</dt><dd>{mailStatus.queue_connection}</dd></div>
                  <div><dt>Test Recipient</dt><dd>{mailStatus.test_recipient}</dd></div>
                </dl>

                {mailStatus.issues?.length > 0 && (
                  <div className="mail-configuration-issues" role="alert">
                    <strong>Configuration issues</strong>
                    <ul>
                      {mailStatus.issues.map((issue) => <li key={issue}>{issue}</li>)}
                    </ul>
                  </div>
                )}

                <div className="mail-test-action">
                  <button
                    type="button"
                    className="btn-primary"
                    onClick={handleSendTestEmail}
                    disabled={!mailStatus.configured || sendingTestEmail}
                  >
                    <Send size={18} />
                    {sendingTestEmail ? 'Sending Test Email...' : 'Send Test Email to My Account'}
                  </button>
                  <p>The test is sent only to the currently logged-in administrator and is recorded in the audit trail.</p>
                </div>
              </>
            )}
          </div>
        </div>
        </div>
        </div>
        </div>
      </div>
    </div>
  );
};

export default Settings;


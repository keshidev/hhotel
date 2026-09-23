import React, { useState, useEffect } from 'react';
import { Search, Plus, Edit, UserX, Mail, Phone, X, AlertTriangle } from 'lucide-react';
import userService from '../../services/userService';
import authService from '../../services/authService';
import { useAuth } from '../../context/AuthContext';
import Pagination from '../../components/Pagination';
import StatusBadge from '../../components/StatusBadge';
import TableActionButton from '../../components/TableActionButton';
import { showToast } from '../../utils/showToast';
import './UserManagement.css';

// ── Stat card — copied from RevenueReport ─────────────────────────────────
const StatCard = ({ label, value, sub, color = '#0d1b3e' }) => (
  <div className="report-stat-card">
    <div className="report-stat-label">{label}</div>
    <div className="report-stat-value" style={{ color }}>{value}</div>
    {sub && <div className="report-stat-sub">{sub}</div>}
  </div>
);

const UserManagement = () => {
  const [users, setUsers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [searchTerm, setSearchTerm] = useState('');
  const [roleFilter, setRoleFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [showModal, setShowModal] = useState(false);
  const [showDeleteModal, setShowDeleteModal] = useState(false);
  const [userToDelete, setUserToDelete] = useState(null);
  const [editingUser, setEditingUser] = useState(null);
  const [formData, setFormData] = useState({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    phone: '',
    role: 'receptionist',
    status: 'active',
    current_password: ''
  });
  const [deleteCurrentPassword, setDeleteCurrentPassword] = useState('');
  const [currentPage, setCurrentPage] = useState(1);
  const [itemsPerPage, setItemsPerPage] = useState(10);
  const [pagination, setPagination] = useState({ total: 0, lastPage: 1 });
  const [errors, setErrors] = useState({});
  const [stats, setStats] = useState({
    totalAdmins: 0,
    totalReceptionists: 0,
    activeUsers: 0
  });

  const { user: currentUser, updateUser } = useAuth();

  useEffect(() => {
    const delay = searchTerm ? 300 : 0;
    const timeout = setTimeout(() => {
      loadUsers();
    }, delay);

    return () => clearTimeout(timeout);
  }, [searchTerm, roleFilter, statusFilter, currentPage, itemsPerPage]);

  const loadUsers = async () => {
    try {
      setLoading(true);
      const params = {
        search: searchTerm || undefined,
        role: roleFilter || undefined,
        status: statusFilter || undefined,
        page: currentPage,
        per_page: itemsPerPage,
      };
      const response = await userService.getUsers(params);
      const userData = response.data || [];
      const usersArray = Array.isArray(userData) ? userData : [];
      setUsers(usersArray);
      setStats({
        totalAdmins: response.stats?.total_admins ?? 0,
        totalReceptionists: response.stats?.total_receptionists ?? 0,
        activeUsers: response.stats?.active_users ?? 0,
      });
      setPagination({
        total: response.pagination?.total ?? 0,
        lastPage: response.pagination?.last_page ?? 1,
      });

      if (usersArray.length === 0 && currentPage > 1) {
        setCurrentPage(currentPage - 1);
      }
      await new Promise(resolve => setTimeout(resolve, 300));
    } catch (error) {
      console.error('Error loading users:', error);
      setUsers([]);
      showToast('Failed to load users', 'error');
    } finally {
      setLoading(false);
    }
  };

  const handleNameChange = (value) => {
    if (/\d/.test(value)) {
      setErrors(prev => ({ ...prev, name: ['Name cannot contain numbers'] }));
    } else if (!/^[\p{L}\s.'\u2019-]*$/u.test(value)) {
      setErrors(prev => ({ ...prev, name: ['Name contains unsupported characters'] }));
    } else if (value.trim() && value.length < 2) {
      setErrors(prev => ({ ...prev, name: ['Name must be at least 2 characters'] }));
    } else {
      setErrors(prev => { const e = { ...prev }; delete e.name; return e; });
    }
    setFormData(prev => ({ ...prev, name: value }));
  };

  const handlePhoneChange = (value) => {
    const digitsOnly = value.replace(/\D/g, '');
    if (digitsOnly.length > 0) {
      if (!/^\d+$/.test(digitsOnly)) {
        setErrors(prev => ({ ...prev, phone: ['Phone number must contain numbers only'] }));
      } else if (!digitsOnly.startsWith('09')) {
        setErrors(prev => ({ ...prev, phone: ['Phone number must start with 09'] }));
      } else if (digitsOnly.length > 11) {
        return;
      } else if (digitsOnly.length === 11) {
        setErrors(prev => { const e = { ...prev }; delete e.phone; return e; });
      } else {
        setErrors(prev => { const e = { ...prev }; delete e.phone; return e; });
      }
    } else {
      setErrors(prev => { const e = { ...prev }; delete e.phone; return e; });
    }
    setFormData(prev => ({ ...prev, phone: digitsOnly }));
  };

  const validateForm = () => {
    const newErrors = {};

    if (!formData.name.trim()) {
      newErrors.name = ['Name is required'];
    } else if (formData.name.length < 2) {
      newErrors.name = ['Name must be at least 2 characters'];
    } else if (/\d/.test(formData.name)) {
      newErrors.name = ['Name cannot contain numbers'];
    } else if (!/^[\p{L}\s.'\u2019-]+$/u.test(formData.name)) {
      newErrors.name = ['Name contains unsupported characters'];
    }

    if (!formData.email.trim()) {
      newErrors.email = ['Email is required'];
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.email)) {
      newErrors.email = ['Please enter a valid email address'];
    }

    if (formData.phone) {
      if (!/^\d+$/.test(formData.phone)) {
        newErrors.phone = ['Phone number must contain numbers only — no letters or special characters'];
      } else if (!formData.phone.startsWith('09')) {
        newErrors.phone = ['Phone number must start with 09'];
      } else if (formData.phone.length !== 11) {
        newErrors.phone = [`Phone number must be exactly 11 digits (currently ${formData.phone.length})`];
      }
    }

    if (editingUser && formData.password) {
      if (formData.password.length < 12) {
        newErrors.password = ['Password must be at least 12 characters'];
      } else if (!/(?=.*[a-z])/.test(formData.password)) {
        newErrors.password = ['Password must contain at least one lowercase letter'];
      } else if (!/(?=.*[A-Z])/.test(formData.password)) {
        newErrors.password = ['Password must contain at least one uppercase letter'];
      } else if (!/(?=.*\d)/.test(formData.password)) {
        newErrors.password = ['Password must contain at least one number'];
      } else if (!/(?=.*[^A-Za-z0-9])/.test(formData.password)) {
        newErrors.password = ['Password must contain at least one symbol'];
      }

      if (formData.password !== formData.password_confirmation) {
        newErrors.password_confirmation = ['Passwords do not match'];
      }
    }

    if (!editingUser && !formData.current_password) {
      newErrors.current_password = ['Enter your administrator password to authorize account creation'];
    }

    if (editingUser) {
      const sensitiveChange = Boolean(formData.password)
        || formData.role !== editingUser.role
        || formData.status !== editingUser.status;

      if (sensitiveChange && !formData.current_password) {
        newErrors.current_password = ['Enter your administrator password to authorize this change'];
      }
    }

    setErrors(newErrors);
    return Object.keys(newErrors).length === 0;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!validateForm()) {
      showToast('Please fix the errors in the form', 'error');
      return;
    }
    setErrors({});

    try {
      if (editingUser) {
        const result = await userService.updateUser(editingUser.id, formData);
        if (
          currentUser?.id != null &&
          editingUser?.id != null &&
          String(currentUser.id) === String(editingUser.id)
        ) {
          if (result.session_revoked) {
            showToast('Password updated. Please sign in again.', 'success');
            await authService.logout('admin').catch(() => {});
            window.location.assign('/login');
            return;
          }
          const freshUser = await authService.me();
          updateUser(freshUser);
        }
        showToast(`User "${formData.name}" updated successfully!`, 'success');
      } else {
        const newUser = { ...formData };
        delete newUser.password;
        delete newUser.password_confirmation;
        const result = await userService.createUser(newUser);
        showToast(result.invitation_queued
          ? `Staff account for ${formData.name} created. A password setup link was queued for email.`
          : result.message, result.invitation_queued ? 'success' : 'warning');
      }

      setShowModal(false);
      resetForm();
      loadUsers();
    } catch (error) {
      if (error.response?.data?.errors) {
        setErrors(error.response.data.errors);
        const firstError = Object.values(error.response.data.errors)[0][0];
        showToast(firstError, 'error');
      } else {
        showToast(error.response?.data?.message || 'Failed to save user', 'error');
      }
    }
  };

  const getEditPermissions = (targetUser) => {
    if (!currentUser || !targetUser) {
      return { canEdit: false, canEditRole: false, canEditStatus: false, isReadOnly: false };
    }
    const isSelf = currentUser?.id === targetUser.id;
    const isTargetAdmin = targetUser.role === 'admin';
    const isTargetReceptionist = targetUser.role === 'receptionist';

    if (isTargetReceptionist && !isSelf) {
      return { canEdit: true, canEditRole: true, canEditStatus: true, isReadOnly: false, note: null };
    }
    if (isTargetAdmin && !isSelf) {
      return { canEdit: true, canEditRole: false, canEditStatus: false, isReadOnly: true, note: 'Admin accounts cannot be modified by other admins.' };
    }
    if (isSelf) {
      return { canEdit: true, canEditRole: false, canEditStatus: false, isReadOnly: false, note: 'You cannot change your own role or deactivate yourself.' };
    }
    return { canEdit: false, canEditRole: false, canEditStatus: false, isReadOnly: false };
  };

  const handleEdit = (user) => {
    const permissions = getEditPermissions(user);
    if (!permissions.canEdit) {
      showToast('You do not have permission to edit this user', 'error');
      return;
    }
    setEditingUser(user);
    setFormData({
      name: user.name,
      email: user.email,
      password: '',
      password_confirmation: '',
      phone: user.phone || '',
      role: user.role,
      status: user.status,
      current_password: ''
    });
    setShowModal(true);
  };

  const handleDeleteClick = (user) => {
    if (user.role === 'admin') {
      showToast('Admin accounts cannot be deleted by another admin.', 'error');
      return;
    }
    if (currentUser?.id === user.id) {
      showToast('You cannot delete your own account!', 'error');
      return;
    }
    if (user.status === 'inactive') {
      showToast('This account is already inactive and is retained for audit history.', 'info');
      return;
    }
    setUserToDelete(user);
    setDeleteCurrentPassword('');
    setShowDeleteModal(true);
  };

  const confirmDelete = async () => {
    if (!userToDelete) return;
    try {
      await userService.deleteUser(userToDelete.id, deleteCurrentPassword);
      showToast(`User "${userToDelete.name}" was deactivated and signed out.`, 'success');
      setShowDeleteModal(false);
      setUserToDelete(null);
      setDeleteCurrentPassword('');
      loadUsers();
    } catch (error) {
      const message = error.response?.data?.errors?.current_password?.[0]
        || error.response?.data?.message
        || 'Failed to archive user';
      showToast(message, 'error');
    }
  };

  const resetForm = () => {
    setFormData({
      name: '', email: '', password: '', password_confirmation: '',
      phone: '', role: 'receptionist', status: 'active', current_password: ''
    });
    setEditingUser(null);
    setErrors({});
  };

  const openAddModal = () => { resetForm(); setShowModal(true); };

  const TableSkeleton = () => (
    <>
      {[1, 2, 3, 4, 5].map((i) => (
        <tr key={i}>
          <td>
            <div className="user-cell">
              <div className="skeleton skeleton-avatar"></div>
              <div style={{ flex: 1 }}>
                <div className="skeleton skeleton-text" style={{ width: '60%', marginBottom: '8px' }}></div>
                <div className="skeleton skeleton-text" style={{ width: '80%' }}></div>
              </div>
            </div>
          </td>
          <td>
            <div className="skeleton skeleton-text" style={{ width: '100%', marginBottom: '8px' }}></div>
            <div className="skeleton skeleton-text" style={{ width: '70%' }}></div>
          </td>
          <td><div className="skeleton skeleton-badge"></div></td>
          <td><div className="skeleton skeleton-badge"></div></td>
          <td><div className="skeleton skeleton-text" style={{ width: '80%' }}></div></td>
          <td>
            <div style={{ display: 'flex', gap: '0.5rem' }}>
              <div className="skeleton skeleton-button"></div>
              <div className="skeleton skeleton-button"></div>
            </div>
          </td>
        </tr>
      ))}
    </>
  );

  const modalPermissions = editingUser ? getEditPermissions(editingUser) : null;

  return (
    <div className="user-management-page">
      <div className="page-header">
        <div>
          <h1>User Management</h1>
          <p className="page-subtitle">Manage your hotel users and their permissions</p>
        </div>
        <button className="btn-primary" onClick={openAddModal}>
          <Plus size={20} />
          Add New Users
        </button>
      </div>

      {/* ── Stat Cards — clean RevenueReport style ── */}
      <div className="report-stats-grid">
        <StatCard
          label="Total Admins"
          value={stats.totalAdmins}
        />
        <StatCard
          label="Total Receptionists"
          value={stats.totalReceptionists}
        />
        <StatCard
          label="Active Users"
          value={stats.activeUsers}
          color="#16a34a"
        />
      </div>

      <div className="page-toolbar">
        <div className="search-bar">
          <Search size={18} />
          <input
            type="text"
            placeholder="Search users by name or email..."
            value={searchTerm}
            onChange={(e) => {
              setSearchTerm(e.target.value);
              setCurrentPage(1);
            }}
          />
        </div>
        <div className="toolbar-actions">
          <select className="filter-select" value={roleFilter} onChange={(e) => {
            setRoleFilter(e.target.value);
            setCurrentPage(1);
          }}>
            <option value="">All Roles</option>
            <option value="admin">Admin</option>
            <option value="receptionist">Receptionist</option>
          </select>
          <select className="filter-select" value={statusFilter} onChange={(e) => {
            setStatusFilter(e.target.value);
            setCurrentPage(1);
          }}>
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
      </div>

      <div className="account-retention-note" role="note">
        Deactivation revokes access but keeps the account visible for booking and audit history. Use the Active status filter to hide inactive accounts.
      </div>

      <div className="content-card">
        <div className="table-container">
          <table className="data-table">
            <thead>
              <tr>
                <th>User</th><th>Contact</th><th>Role</th>
                <th>Status</th><th>Joined Date</th><th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <TableSkeleton />
              ) : users.length === 0 ? (
                <tr>
                  <td colSpan="6" style={{ textAlign: 'center', padding: '2rem' }}>No users found</td>
                </tr>
              ) : (
                users.map((user) => (
                  <tr key={user.id}>
                    <td>
                      <div className="user-cell">
                        <img
                          src={`https://ui-avatars.com/api/?name=${encodeURIComponent(user.name)}&background=1a4bcc&color=fff`}
                          alt={user.name}
                          className="user-avatar-sm"
                        />
                        <div>
                          <div className="user-name">{user.name}</div>
                          <div className="user-email-sm">{user.email}</div>
                        </div>
                      </div>
                    </td>
                    <td>
                      <div className="contact-cell">
                        <div className="contact-item"><Mail size={14} />{user.email}</div>
                        {user.phone && (
                          <div className="contact-item"><Phone size={14} />{user.phone}</div>
                        )}
                      </div>
                    </td>
                    <td>
                      <span className={`role-badge role-${user.role.toLowerCase()}`}>{user.role}</span>
                    </td>
                    <td>
                      <StatusBadge status={user.status} />
                    </td>
                    <td>{new Date(user.created_at).toLocaleDateString()}</td>
                    <td>
                      <div className="action-buttons">
                        <TableActionButton iconOnly label="Edit user" onClick={() => handleEdit(user)}>
                          <Edit size={16} />
                        </TableActionButton>
                        <TableActionButton
                          iconOnly
                          tone="danger"
                          label="Deactivate user"
                          title={
                            user.role === 'admin'
                              ? 'Admin accounts cannot be deactivated'
                              : user.status === 'inactive'
                                ? 'Account is already inactive'
                                : 'Revoke access and deactivate account'
                          }
                          onClick={() => handleDeleteClick(user)}
                          disabled={user.role === 'admin' || user.status === 'inactive'}
                        >
                          <UserX size={16} />
                        </TableActionButton>
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {!loading && users.length > 0 && (
          <Pagination
            currentPage={currentPage}
            totalPages={pagination.lastPage}
            totalItems={pagination.total}
            itemsPerPage={itemsPerPage}
            onPageChange={setCurrentPage}
            onItemsPerPageChange={(size) => {
              setItemsPerPage(size);
              setCurrentPage(1);
            }}
            pageSizeOptions={[10, 25, 50, 100]}
          />
        )}
      </div>

      {showModal && (
        <div className="modal-overlay" onClick={() => setShowModal(false)}>
          <div className="modal-content" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <h2>{editingUser ? 'Edit User' : 'Add New User'}</h2>
              <button className="modal-close" onClick={() => setShowModal(false)}>
                <X size={20} />
              </button>
            </div>

            {modalPermissions?.note && (
              <div className="modal-notice">
                <AlertTriangle size={16} />
                <span>{modalPermissions.note}</span>
              </div>
            )}

            <form onSubmit={handleSubmit}>
              <div className="form-grid">

                <div className="form-group">
                  <label>Name *</label>
                  <input
                    type="text"
                    value={formData.name}
                    onChange={(e) => handleNameChange(e.target.value)}
                    placeholder="Enter full staff name"
                    className={errors.name ? 'error-input' : ''}
                    disabled={modalPermissions?.isReadOnly}
                  />
                  {errors.name && <span className="error">{errors.name[0]}</span>}
                  <small className="form-hint">Letters, spaces, apostrophes, periods, and hyphens are allowed.</small>
                </div>

                <div className="form-group">
                  <label>Email *</label>
                  <input
                    type="email"
                    value={formData.email}
                    onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                    placeholder="user@example.com"
                    className={errors.email ? 'error-input' : ''}
                    disabled={modalPermissions?.isReadOnly}
                  />
                  {errors.email && <span className="error">{errors.email[0]}</span>}
                </div>

                <div className="form-group">
                  <label>Phone</label>
                  <input
                    type="text"
                    inputMode="numeric"
                    maxLength={11}
                    value={formData.phone}
                    onChange={(e) => handlePhoneChange(e.target.value)}
                    placeholder="09XXXXXXXXX"
                    className={errors.phone ? 'error-input' : ''}
                    disabled={modalPermissions?.isReadOnly}
                  />
                  {errors.phone
                    ? <span className="error">{errors.phone[0]}</span>
                    : <small className="form-hint">Must start with 09 — exactly 11 digits, numbers only</small>
                  }
                </div>

                <div className="form-group">
                  <label>Role *</label>
                  <select
                    value={formData.role}
                    onChange={(e) => setFormData({ ...formData, role: e.target.value })}
                    disabled={editingUser ? !modalPermissions?.canEditRole : false}
                  >
                    <option value="receptionist">Receptionist</option>
                    <option value="admin">Admin</option>
                  </select>
                  {!modalPermissions?.canEditRole && editingUser && (
                    <small className="form-hint">Role cannot be changed</small>
                  )}
                </div>

                {editingUser && <div className="form-group">
                  <label>Status *</label>
                  <select
                    value={formData.status}
                    onChange={(e) => setFormData({ ...formData, status: e.target.value })}
                    disabled={editingUser ? !modalPermissions?.canEditStatus : false}
                  >
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                  </select>
                  {!modalPermissions?.canEditStatus && editingUser && (
                    <small className="form-hint">Status cannot be changed</small>
                  )}
                </div>}

                {!editingUser && (
                  <div className="form-group full-width">
                    <p className="form-hint">A secure setup link will be emailed to this staff member. They will choose their own password. If the link expires, they can use Forgot Password on the staff login page.</p>
                  </div>
                )}

                {editingUser && currentUser?.id === editingUser.id && <div className="form-group">
                  <label>New Password</label>
                  <input
                    type="password"
                    value={formData.password}
                    onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                    placeholder="Leave blank to keep current"
                    className={errors.password ? 'error-input' : ''}
                    disabled={modalPermissions?.isReadOnly}
                  />
                  {errors.password && <span className="error">{errors.password[0]}</span>}
                  <small className="form-hint">Use 12+ characters, uppercase, lowercase, a number, and a symbol.</small>
                </div>}

                {editingUser && currentUser?.id === editingUser.id && <div className="form-group">
                  <label>Confirm New Password</label>
                  <input
                    type="password"
                    value={formData.password_confirmation}
                    onChange={(e) => setFormData({ ...formData, password_confirmation: e.target.value })}
                    placeholder="Re-enter password"
                    className={errors.password_confirmation ? 'error-input' : ''}
                    disabled={modalPermissions?.isReadOnly}
                  />
                  {errors.password_confirmation && <span className="error">{errors.password_confirmation[0]}</span>}
                </div>}

                {!modalPermissions?.isReadOnly && (
                  <div className="form-group full-width">
                    <label>Your Administrator Password *</label>
                    <input
                      type="password"
                      value={formData.current_password}
                      onChange={(e) => setFormData({ ...formData, current_password: e.target.value })}
                      placeholder={editingUser ? 'Required for password, role, or status changes' : 'Required to create a staff account'}
                      className={errors.current_password ? 'error-input' : ''}
                      autoComplete="current-password"
                    />
                    {errors.current_password && <span className="error">{errors.current_password[0]}</span>}
                    <small className="form-hint">
                      {editingUser ? 'Required for security-sensitive changes.' : 'Required to authorize account creation.'}
                    </small>
                  </div>
                )}

              </div>

              <div className="modal-footer">
                <button type="button" className="btn-secondary" onClick={() => setShowModal(false)}>
                  Cancel
                </button>
                <button type="submit" className="btn-primary" disabled={modalPermissions?.isReadOnly}>
                  {editingUser ? 'Update User' : 'Create User'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {showDeleteModal && userToDelete && (
        <div className="modal-overlay" onClick={() => setShowDeleteModal(false)}>
          <div className="modal-content modal-confirm" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <div className="warning-icon">
                <AlertTriangle size={24} color="#ef4444" />
              </div>
              <h2>Deactivate Staff Account</h2>
            </div>
            <div className="modal-body">
              <p className="confirm-message">This immediately removes access for:</p>
              <div className="user-info-box">
                <strong>{userToDelete.name}</strong>
                <span className="user-email">{userToDelete.email}</span>
                <span className={`role-badge role-${userToDelete.role}`}>{userToDelete.role}</span>
              </div>
              <div className="warning-box">
                <AlertTriangle size={16} />
                <p><strong>Important:</strong> The user will be signed out. Historical booking and audit records will be preserved.</p>
              </div>
              <div className="form-group" style={{ marginTop: '1rem' }}>
                <label>Your Administrator Password *</label>
                <input
                  type="password"
                  value={deleteCurrentPassword}
                  onChange={(e) => setDeleteCurrentPassword(e.target.value)}
                  placeholder="Confirm your password"
                  autoComplete="current-password"
                />
              </div>
            </div>
            <div className="modal-footer">
              <button type="button" className="btn-secondary" onClick={() => setShowDeleteModal(false)}>Cancel</button>
              <button
                type="button"
                className="btn-danger"
                onClick={confirmDelete}
                disabled={!deleteCurrentPassword}
              >
                Revoke Access and Deactivate
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default UserManagement;

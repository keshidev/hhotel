import React from 'react';
import { useAuth } from '../context/AuthContext';
import NotificationDropdown from './NotificationDropdown';
import { SidebarTrigger } from '@/components/ui/sidebar';
import './AdminHeader.css';

const AdminHeader = () => {
  const { user: currentUser } = useAuth();

  if (!currentUser) {
    return (
      <header className="admin-header">
        <div className="header-left">
          <SidebarTrigger className="staff-sidebar-trigger" />
        </div>
        <div className="header-right">
          <div className="user-profile-skeleton">
            <div className="skeleton-avatar"></div>
            <div className="skeleton-text-group">
              <div className="skeleton-text"></div>
              <div className="skeleton-text-small"></div>
            </div>
          </div>
        </div>
      </header>
    );
  }

  const avatarUrl = `https://ui-avatars.com/api/?name=${encodeURIComponent(currentUser.name)}&background=1A4BCC&color=fff`;
  const roleDisplay = currentUser.role === 'admin' ? 'Administrator' : 'Receptionist';

  return (
    <header className="admin-header">
      <div className="header-left">
        <SidebarTrigger className="staff-sidebar-trigger" />
      </div>

      <div className="header-right">
        {/* ✅ Notification bell with working dropdown */}
        <NotificationDropdown role="admin" />

        <div className="user-profile">
          <img
            src={avatarUrl}
            alt={currentUser.name}
            className="user-avatar"
          />
          <div className="user-info">
            <span className="user-name">{currentUser.name}</span>
            <span className="user-role">{roleDisplay}</span>
          </div>
        </div>
      </div>
    </header>
  );
};

export default AdminHeader;

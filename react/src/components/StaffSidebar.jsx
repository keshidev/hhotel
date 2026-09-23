import React, { useEffect, useMemo, useState } from 'react';
import { NavLink, useLocation, useNavigate } from 'react-router-dom';
import { ChevronDown, LogOut } from 'lucide-react';
import authService from '../services/authService';
import { useAuth } from '../context/AuthContext';
import ConfirmDialog from './ConfirmDialog';
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarGroup,
  SidebarGroupContent,
  SidebarGroupLabel,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuBadge,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarMenuSub,
  SidebarMenuSubButton,
  SidebarMenuSubItem,
  SidebarRail,
  useSidebar,
} from '@/components/ui/sidebar';
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible';
import './StaffSidebar.css';

const StaffSidebar = ({ navigation, panelLabel, role }) => {
  const { user } = useAuth();
  const { pathname } = useLocation();
  const navigate = useNavigate();
  const { isMobile, setOpen, setOpenMobile, state } = useSidebar();
  const [showLogoutConfirm, setShowLogoutConfirm] = useState(false);

  const initialOpenMenus = useMemo(() => {
    const openMenus = {};
    navigation.forEach((group) => {
      group.items.forEach((item) => {
        if (item.children?.some((child) => pathname === child.path)) {
          openMenus[item.key || item.label] = true;
        }
      });
    });
    return openMenus;
  }, [navigation, pathname]);

  const [openMenus, setOpenMenus] = useState(initialOpenMenus);

  useEffect(() => {
    setOpenMenus((current) => {
      const next = { ...current };
      let changed = false;

      Object.entries(initialOpenMenus).forEach(([key, value]) => {
        if (value && !next[key]) {
          next[key] = true;
          changed = true;
        }
      });

      return changed ? next : current;
    });
  }, [initialOpenMenus]);

  const closeMobileSidebar = () => {
    if (isMobile) setOpenMobile(false);
  };

  const handleParentToggle = (key, open) => {
    if (state === 'collapsed') setOpen(true);
    setOpenMenus((current) => ({ ...current, [key]: open }));
  };

  const handleLogout = async () => {
    try {
      await authService.logout();
    } catch (error) {
      console.error('Logout failed:', error);
    } finally {
      navigate('/login');
    }
  };

  const displayName = user?.name || (role === 'admin' ? 'Administrator' : 'Receptionist');
  const displayRole = role === 'admin' ? 'Administrator' : 'Receptionist';
  const avatarUrl = `https://ui-avatars.com/api/?name=${encodeURIComponent(displayName)}&background=1A4BCC&color=fff`;

  return (
    <>
      <Sidebar collapsible="icon" className="hhotel-staff-sidebar">
        <SidebarHeader className="hhotel-sidebar-header">
          <div className="hhotel-sidebar-brand">
            <div className="hhotel-sidebar-logo-wrap">
              <img src="/images/logo/logo-withoutbg.png" alt="H+ Hotel" className="hhotel-sidebar-logo" />
            </div>
            <span className="hhotel-sidebar-panel-label">{panelLabel}</span>
          </div>
        </SidebarHeader>

        <SidebarContent className="hhotel-sidebar-content">
          {navigation.map((group) => (
            <SidebarGroup key={group.label} className="hhotel-sidebar-group">
              <SidebarGroupLabel className="hhotel-sidebar-group-label">
                {group.label}
              </SidebarGroupLabel>
              <SidebarGroupContent>
                <SidebarMenu className="hhotel-sidebar-menu">
                  {group.items.map((item) => {
                    const Icon = item.icon;
                    const itemKey = item.key || item.label;
                    const nestedActive = item.children?.some((child) => pathname === child.path);

                    if (item.children) {
                      return (
                        <Collapsible
                          key={itemKey}
                          open={Boolean(openMenus[itemKey])}
                          onOpenChange={(open) => handleParentToggle(itemKey, open)}
                          render={<SidebarMenuItem />}
                        >
                          <SidebarMenuButton
                            render={<CollapsibleTrigger />}
                            isActive={nestedActive}
                            tooltip={item.label}
                            className="hhotel-sidebar-menu-button"
                          >
                            <Icon />
                            <span>{item.label}</span>
                            <ChevronDown className={`hhotel-sidebar-chevron ${openMenus[itemKey] ? 'open' : ''}`} />
                          </SidebarMenuButton>
                          <CollapsibleContent>
                            <SidebarMenuSub className="hhotel-sidebar-submenu">
                              {item.children.map((child) => (
                                <SidebarMenuSubItem key={child.path}>
                                  <SidebarMenuSubButton
                                    render={<NavLink to={child.path} onClick={closeMobileSidebar} />}
                                    isActive={pathname === child.path}
                                    className="hhotel-sidebar-submenu-button"
                                  >
                                    <span>{child.label}</span>
                                  </SidebarMenuSubButton>
                                </SidebarMenuSubItem>
                              ))}
                            </SidebarMenuSub>
                          </CollapsibleContent>
                        </Collapsible>
                      );
                    }

                    const isActive = pathname === item.path;

                    return (
                      <SidebarMenuItem key={item.path}>
                        <SidebarMenuButton
                          render={<NavLink to={item.path} onClick={closeMobileSidebar} />}
                          isActive={isActive}
                          tooltip={item.label}
                          className="hhotel-sidebar-menu-button"
                        >
                          <span className="hhotel-sidebar-icon-wrap">
                            <Icon />
                            {item.badge > 0 && (
                              <span className="hhotel-sidebar-icon-badge">
                                {item.badge > 99 ? '99+' : item.badge}
                              </span>
                            )}
                          </span>
                          <span>{item.label}</span>
                        </SidebarMenuButton>
                        {item.badge > 0 && (
                          <SidebarMenuBadge className="hhotel-sidebar-badge">
                            {item.badge > 99 ? '99+' : item.badge}
                          </SidebarMenuBadge>
                        )}
                      </SidebarMenuItem>
                    );
                  })}
                </SidebarMenu>
              </SidebarGroupContent>
            </SidebarGroup>
          ))}
        </SidebarContent>

        <SidebarFooter className="hhotel-sidebar-footer">
          <div className="hhotel-sidebar-user">
            <img src={avatarUrl} alt="" className="hhotel-sidebar-avatar" />
            <div className="hhotel-sidebar-user-copy">
              <strong>{displayName}</strong>
              <span>{displayRole}</span>
            </div>
          </div>
          <SidebarMenu>
            <SidebarMenuItem>
              <SidebarMenuButton
                onClick={() => setShowLogoutConfirm(true)}
                tooltip="Logout"
                className="hhotel-sidebar-menu-button hhotel-sidebar-logout"
              >
                <LogOut />
                <span>Logout</span>
              </SidebarMenuButton>
            </SidebarMenuItem>
          </SidebarMenu>
        </SidebarFooter>
        <SidebarRail />
      </Sidebar>

      <ConfirmDialog
        open={showLogoutConfirm}
        title="Logout Confirmation"
        message="Are you sure you want to logout?"
        confirmLabel="Logout"
        cancelLabel="Stay"
        danger
        onCancel={() => setShowLogoutConfirm(false)}
        onConfirm={async () => {
          setShowLogoutConfirm(false);
          await handleLogout();
        }}
      />
    </>
  );
};

export default StaffSidebar;

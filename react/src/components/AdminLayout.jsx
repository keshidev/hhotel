import NotificationContext from './NotificationContext';
import { Outlet } from 'react-router-dom';
import Sidebar from './Sidebar';
import AdminHeader from './AdminHeader';
import { SidebarInset, SidebarProvider } from '@/components/ui/sidebar';
import { TooltipProvider } from '@/components/ui/tooltip';
import '../layouts/StaffLayout.css';

const AdminLayout = () => {
  return (
    <TooltipProvider delay={250}>
      <SidebarProvider
        className="staff-shell admin-layout"
        style={{ '--sidebar-width': '17.5rem', '--sidebar-width-icon': '4.75rem' }}
      >
        <Sidebar />
        <SidebarInset className="staff-main admin-main">
          <AdminHeader />
          <div className="staff-content admin-content">
            <NotificationContext />
            <Outlet />
          </div>
        </SidebarInset>
      </SidebarProvider>
    </TooltipProvider>
  );
};

export default AdminLayout;

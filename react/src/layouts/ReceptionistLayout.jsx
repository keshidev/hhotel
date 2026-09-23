import NotificationContext from '../components/NotificationContext';
import { Outlet } from 'react-router-dom';
import ReceptionistSidebar from '../components/ReceptionistSidebar';
import ReceptionistHeader from './ReceptionistHeader';
import { SidebarInset, SidebarProvider } from '@/components/ui/sidebar';
import { TooltipProvider } from '@/components/ui/tooltip';
import './StaffLayout.css';

const ReceptionistLayout = () => {
  return (
    <TooltipProvider delay={250}>
      <SidebarProvider
        className="staff-shell receptionist-layout"
        style={{ '--sidebar-width': '17.5rem', '--sidebar-width-icon': '4.75rem' }}
      >
        <ReceptionistSidebar />
        <SidebarInset className="staff-main receptionist-main">
          <ReceptionistHeader />
          <div className="staff-content receptionist-content">
            <NotificationContext />
            <Outlet />
          </div>
        </SidebarInset>
      </SidebarProvider>
    </TooltipProvider>
  );
};

export default ReceptionistLayout;

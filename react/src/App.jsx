// react/src/App.jsx
import { lazy, Suspense } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider } from './context/AuthContext';
import { CmsProvider } from './context/CmsContext';

import ProtectedRoute from './components/ProtectedRoute';

import Header from './components/Header';
import Footer from './components/Footer';
import ScrollToTop from './components/ScrollToTop';
import PublicScrollMotion from './components/PublicScrollMotion';
import CookieConsent from './components/CookieConsent';
import BookingRecoveryModal from './components/BookingRecoveryModal';

import Home from './pages/Home';

const Login = lazy(() => import('./pages/login/Login'));
const ForgotPassword = lazy(() => import('./pages/login/ForgotPassword'));
const ResetPassword = lazy(() => import('./pages/login/ResetPassword'));
const SelectRoom = lazy(() => import('./pages/SelectRoom'));
const GuestDetails = lazy(() => import('./pages/GuestDetails'));
const Confirmation = lazy(() => import('./pages/Confirmation'));
const AddOns = lazy(() => import('./pages/AddOns'));
const BookingCart = lazy(() => import('./pages/BookingCart'));
const RoomDetail = lazy(() => import('./pages/Roomdetail'));
const Mybooking = lazy(() => import('./pages/Mybooking'));
const BookingDetails = lazy(() => import('./pages/BookingDetails'));
const BookingCancellationRequest = lazy(() => import('./pages/BookingCancellationRequest'));
const BookingRebookingRequest = lazy(() => import('./pages/BookingRebookingRequest'));
const ContactPage = lazy(() => import('./pages/ContactPage'));
const ClientPayment = lazy(() => import('./pages/Payment'));
const FeedbackPage = lazy(() => import('./pages/FeedbackPage'));
const NotFound = lazy(() => import('./pages/NotFound'));
const LegalPage = lazy(() => import('./pages/LegalPage'));
const FaqPage = lazy(() => import('./pages/FaqPage'));
const PoliciesPage = lazy(() => import('./pages/PoliciesPage'));
const SpecialOffersPage = lazy(() => import('./pages/SpecialOffersPage'));

const AdminLayout = lazy(() => import('./components/AdminLayout'));
const Dashboard = lazy(() => import('./pages/admin/Dashboard'));
const UserManagement = lazy(() => import('./pages/admin/UserManagement'));
const RoomManagement = lazy(() => import('./pages/admin/RoomManagement'));
const Settings = lazy(() => import('./pages/admin/Settings'));
const ReportManagement = lazy(() => import('./pages/admin/ReportManagement'));
const RevenueReport = lazy(() => import('./pages/admin/reports/RevenueReport'));
const OccupancyReport = lazy(() => import('./pages/admin/reports/OccupancyReport'));
const ReservationReport = lazy(() => import('./pages/admin/reports/ReservationReport'));
const AdminFeedbackReport = lazy(() => import('./pages/admin/reports/AdminFeedbackReport'));
const ModifiedReservationReport = lazy(() => import('./pages/admin/reports/ModifiedReservationReport'));
const AuditTrail = lazy(() => import('./pages/admin/AuditTrail'));
const PromoManagement = lazy(() => import('./pages/admin/PromoManagement'));
const AdminCms = lazy(() => import('./pages/admin/AdminCms'));
const AdminCancellationApprovals = lazy(() => import('./pages/admin/AdminCancellationApprovals'));
const AdminTransferApprovals = lazy(() => import('./pages/admin/AdminTransferApprovals'));
const AdminEarlyCheckInApprovals = lazy(() => import('./pages/admin/AdminEarlyCheckInApprovals'));
const RebookingApprovals = lazy(() => import('./pages/receptionist/Rebooking'));
const ManualGcashReviews = lazy(() => import('./pages/shared/ManualGcashReviews'));
const ContactInquiries = lazy(() => import('./pages/shared/ContactInquiries'));

const ReceptionistLayout = lazy(() => import('./layouts/ReceptionistLayout'));
const ReceptionistDashboard = lazy(() => import('./pages/receptionist/ReceptionistDashboard'));
const ReservationPage = lazy(() => import('./pages/receptionist/Reservation'));
const CancellationPage = lazy(() => import('./pages/receptionist/Cancellation'));
const RebookingPage = lazy(() => import('./pages/receptionist/Rebooking'));
const ReceptionistSettings = lazy(() => import('./pages/receptionist/Settings'));
const WalkIn = lazy(() => import('./pages/receptionist/WalkIn'));
const CheckIn = lazy(() => import('./pages/receptionist/CheckIn'));
const CheckOut = lazy(() => import('./pages/receptionist/CheckOut'));
const TransferRequestsPage = lazy(() => import('./pages/receptionist/TransferRequests'));

import './App.css';

function App() {
  const isPaymentTestMode = import.meta.env.VITE_PAYMENT_MODE === 'test';

  return (
    <AuthProvider>
      <CmsProvider>
        <BrowserRouter>
          {isPaymentTestMode && (
            <div className="test-mode-banner" role="status">
              TEST MODE - No real money will be charged. Staging data only.
            </div>
          )}
          <ScrollToTop />
          <PublicScrollMotion />
          <CookieConsent />
          <BookingRecoveryModal />

          <Suspense fallback={<div className="route-loading">Loading...</div>}>
          <Routes>

            {/* ================= CLIENT ROUTES ================= */}
            <Route path="/" element={
              <>
                <Header />
                <Home />
                <Footer />
              </>
            } />

            <Route path="/rooms" element={
              <>
                <Header />
                <SelectRoom />
                <Footer />
              </>
            } />

            <Route path="/booking" element={
              <>
                <Header />
                <SelectRoom />
                <Footer />
              </>
            } />

            <Route path="/select-room" element={
              <>
                <Header />
                <SelectRoom />
                <Footer />
              </>
            } />

            <Route path="/guest-details" element={
              <>
                <Header />
                <GuestDetails />
                <Footer />
              </>
            } />

            <Route path="/confirmation" element={
              <>
                <Header />
                <Confirmation />
                <Footer />
              </>
            } />
            <Route path="/booking-confirmation" element={
              <>
                <Header />
                <Confirmation />
                <Footer />
              </>
            } />

            <Route path="/add-ons" element={
              <>
                <Header />
                <AddOns />
                <Footer />
              </>
            } />

            <Route path="/cart" element={
              <>
                <Header />
                <BookingCart />
                <Footer />
              </>
            } />

            <Route path="/room/:roomId" element={
              <>
                <Header />
                <RoomDetail />
                <Footer />
              </>
            } />

            <Route path="/my-booking" element={
              <>
                <Header />
                <Mybooking />
                <Footer />
              </>
            } />
            <Route path="/bookings" element={
              <>
                <Header />
                <Mybooking />
                <Footer />
              </>
            } />
            <Route path="/contact" element={
              <>
                <Header />
                <ContactPage />
                <Footer />
              </>
            } />
            <Route path="/booking-details" element={
              <>
                <Header />
                <BookingDetails />
                <Footer />
              </>
            } />
            <Route path="/booking-details/cancellation" element={
              <>
                <Header />
                <BookingCancellationRequest />
                <Footer />
              </>
            } />
            <Route path="/booking-details/rebooking" element={
              <>
                <Header />
                <BookingRebookingRequest />
                <Footer />
              </>
            } />
            <Route path="/payment/:bookingId" element={
              <>
                <Header />
                <ClientPayment />
                <Footer />
              </>
            } />
            <Route path="/feedback/:token" element={<FeedbackPage />} />
            <Route path="/privacy" element={
              <>
                <Header />
                <LegalPage policy="privacy" />
                <Footer />
              </>
            } />
            <Route path="/terms" element={
              <>
                <Header />
                <LegalPage policy="terms" />
                <Footer />
              </>
            } />
            <Route path="/cookies" element={
              <>
                <Header />
                <LegalPage policy="cookies" />
                <Footer />
              </>
            } />
            <Route path="/faq" element={
              <>
                <Header />
                <FaqPage />
                <Footer />
              </>
            } />
            <Route path="/policies" element={
              <>
                <Header />
                <PoliciesPage />
                <Footer />
              </>
            } />
            <Route path="/offers" element={
              <>
                <Header />
                <SpecialOffersPage />
                <Footer />
              </>
            } />


            {/* ================= LOGIN ================= */}
            <Route path="/login" element={<Login />} />
            <Route path="/forgot-password" element={<ForgotPassword />} />
            <Route path="/reset-password" element={<ResetPassword />} />

            {/* ================= ADMIN ROUTES ================= */}
            <Route
              path="/admin"
              element={
                <ProtectedRoute allowedRoles={['admin']} redirectTo="/login">
                  <AdminLayout />
                </ProtectedRoute>
              }
            >
              <Route index element={<Navigate to="dashboard" replace />} />
              <Route path="dashboard"    element={<Dashboard />} />
              <Route path="users"        element={<UserManagement />} />
              <Route path="rooms"        element={<RoomManagement />} />
              <Route path="promo-codes"  element={<PromoManagement />} />
              <Route path="settings"     element={<Settings />} />
              <Route path="reports"      element={<ReportManagement />} />

              <Route path="reports/revenue"              element={<RevenueReport />} />
              <Route path="reports/occupancy"            element={<OccupancyReport />} />
              <Route path="reports/reservation"          element={<ReservationReport />} />
              <Route path="reports/reservation-modified" element={<ModifiedReservationReport />} />
              <Route path="reports/feedback"             element={<AdminFeedbackReport />} />
              <Route path="audit-trail"                  element={<AuditTrail />} />
              <Route path="cms"                          element={<AdminCms />} />  
              <Route path="cancellation-approvals"       element={<AdminCancellationApprovals />} />
              <Route path="transfer-approvals"           element={<AdminTransferApprovals />} />
              <Route path="early-check-in-approvals"     element={<AdminEarlyCheckInApprovals />} />
              <Route path="manual-gcash-reviews"         element={<ManualGcashReviews role="admin" />} />
              <Route path="contact-inquiries"            element={<ContactInquiries role="admin" />} />
              <Route path="rebooking-approvals"          element={<RebookingApprovals role="admin" />} />
            </Route>

            {/* ================= RECEPTIONIST ROUTES ================= */}
            <Route
              path="/receptionist"
              element={
                <ProtectedRoute allowedRoles={['receptionist']} redirectTo="/login">
                  <ReceptionistLayout />
                </ProtectedRoute>
              }
            >
              <Route index element={<Navigate to="dashboard" replace />} />
              <Route path="dashboard"   element={<ReceptionistDashboard />} />
              <Route path="reservation" element={<ReservationPage />} />
              <Route path="payment"     element={<Navigate to="/receptionist/manual-gcash-reviews?section=records" replace />} />
              <Route path="manual-gcash-reviews" element={<ManualGcashReviews role="receptionist" />} />
              <Route path="contact-inquiries" element={<ContactInquiries role="receptionist" />} />
              <Route path="cancellation" element={<CancellationPage />} />
              <Route path="rebooking"   element={<RebookingPage role="receptionist" />} />
              <Route path="settings"    element={<ReceptionistSettings />} />
              <Route path="transfer-requests" element={<TransferRequestsPage />} />
              <Route path="walk-in"     element={<WalkIn />} />
              <Route path="check-in"    element={<CheckIn />} />
              <Route path="check-out"   element={<CheckOut />} />
            </Route>

            {/* ================= UNAUTHORIZED ================= */}
            <Route path="/unauthorized" element={
              <div style={{ textAlign: 'center', marginTop: '100px' }}>
                <h1>403 - Unauthorized Access</h1>
                <p>You don't have permission to access this page.</p>
                <a href="/login">Go to Login</a>
              </div>
            } />

            {/* ================= FALLBACK ================= */}
            <Route path="*" element={
              <>
                <Header />
                <NotFound />
                <Footer />
              </>
            } />

          </Routes>
          </Suspense>
        </BrowserRouter>
      </CmsProvider>
    </AuthProvider>
  );
}

export default App;

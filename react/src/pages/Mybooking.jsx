import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Search } from 'lucide-react';
import Button from '../components/Button';
import clientBookingService from '../services/client/clientBookingService'; 
import { showToast } from '../utils/showToast';
import { rememberGuestBookingSession } from '../utils/guestBookingSession';
import './Mybooking.css';

const Mybooking = () => {
  const navigate = useNavigate();
  const [formData, setFormData] = useState({
    reservationId: '',
    email: ''
  });
  const [searching, setSearching] = useState(false);

  const handleInputChange = (field, value) => {
    setFormData(prev => ({
      ...prev,
      [field]: value
    }));
  };



  const handleSearch = async (e) => {
      e.preventDefault();
      if (searching) return;

      const reservationId = formData.reservationId.trim();
      const email = formData.email.trim();
      
      if (!reservationId || !email) {
          showToast('Please enter both Reservation ID and Email Address', 'warning');
          return;
      }

      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
          showToast('Please enter a valid email address.', 'warning');
          return;
      }

      setSearching(true);
      try {
          // Call API to check booking status
          const response = await clientBookingService.checkBookingStatus(
              email,
              reservationId
          );

          if (response.success) {
              const lookup = rememberGuestBookingSession({
                  email,
                  referenceNumber: reservationId,
                  bookingId: response.data?.id,
              });
              // Navigate to booking details with data
              navigate('/booking-details', { 
                  state: { 
                      booking: response.data,
                      reservationId: lookup.referenceNumber,
                      email: lookup.email,
                  } 
              });
          }
      } catch (error) {
          if (error.response?.status === 429) {
              showToast('Too many attempts. Please wait about 1 minute before trying again.', 'warning');
          } else if (error.response?.status === 404) {
              showToast('Booking not found. Please check your reference number and email.', 'error');
          } else {
              showToast('Failed to retrieve booking. Please try again.', 'error');
          }
      } finally {
          setSearching(false);
      }
  };

  return (
    <div className="my-booking-page">
      {/* Header Section */}
      <section className="my-booking-header">
        <div className="container">
          <h1 className="page-title">View, change, or cancel your reservation</h1>
        </div>
      </section>

      {/* Main Content */}
      <section className="my-booking-content">
        <div className="container">
          <div className="booking-card">
            <h2 className="card-title">Find Your Booking</h2>
            
            <form onSubmit={handleSearch} className="booking-form">
              <div className="form-group">
                <label htmlFor="reservationId" className="form-label">
                  Reservation ID
                </label>
                <input
                  type="text"
                  id="reservationId"
                  className="form-input"
                  placeholder="Enter your reservation or confirmation number"
                  value={formData.reservationId}
                  onChange={(e) => handleInputChange('reservationId', e.target.value)}
                  maxLength={100}
                  required
                />
              </div>

              <div className="form-group">
                <label htmlFor="email" className="form-label">
                  Email Address
                </label>
                <input
                  type="email"
                  id="email"
                  className="form-input"
                  placeholder="Enter the email used for booking"
                  value={formData.email}
                  onChange={(e) => handleInputChange('email', e.target.value)}
                  maxLength={255}
                  required
                />
              </div>

              <Button
                variant="primary"
                size="lg"
                fullWidth
                type="submit"
                disabled={searching}
              >
                <Search size={20} />
                {searching ? 'SEARCHING...' : 'SEARCH'}
              </Button>
            </form>

            <div className="help-section">
              <h3 className="help-title">Don't know your reservation ID?</h3>
              <p className="help-text">
                Your reservation ID was included in the confirmation email sent at the time of booking. 
                Please check your email to recover the number.
              </p>
            </div>
          </div>
        </div>
      </section>
    </div>
  );
};

export default Mybooking;

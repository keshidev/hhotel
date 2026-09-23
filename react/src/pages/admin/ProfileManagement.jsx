import React, { useState } from 'react';
import { Save, User, Mail, Phone, MapPin, Camera } from 'lucide-react';
import { showToast } from '../../utils/showToast';
import './ProfileManagement.css';

const ProfileManagement = () => {
  const [profile, setProfile] = useState({
    firstName: 'Admin',
    lastName: 'User',
    email: 'admin@comfyhomfy.com',
    phone: '+63 912 345 6789',
    role: 'Administrator',
    address: '1234 Paseo, Manila',
    city: 'Manila',
    country: 'Philippines',
    bio: 'Hotel Administrator managing daily operations and bookings.',
  });

  const handleChange = (field, value) => {
    setProfile({ ...profile, [field]: value });
  };

  const handleSave = () => {
    console.log('Saving profile:', profile);
    showToast('Profile updated successfully!', 'success');
  };

  const handleImageUpload = (e) => {
    const file = e.target.files[0];
    if (file) {
      console.log('Uploading image:', file.name);
      // Handle image upload logic here
    }
  };

  return (
    <div className="profile-management-page">
      <div className="page-header">
        <div>
          <h1>Profile Management</h1>
          <p className="page-subtitle">Manage your personal information and preferences</p>
        </div>
        <button className="btn-primary" onClick={handleSave}>
          <Save size={20} />
          Save Changes
        </button>
      </div>

      <div className="profile-grid">
        {/* Profile Picture Section */}
        <div className="profile-sidebar">
          <div className="profile-card">
            <div className="profile-picture-section">
              <div className="profile-picture">
                <img 
                  src={`https://ui-avatars.com/api/?name=${profile.firstName}+${profile.lastName}&background=B8975A&color=fff&size=200`}
                  alt="Profile"
                />
                <label className="upload-overlay">
                  <Camera size={24} />
                  <input 
                    type="file" 
                    accept="image/*" 
                    onChange={handleImageUpload}
                    hidden
                  />
                </label>
              </div>
              <h2>{profile.firstName} {profile.lastName}</h2>
              <p className="profile-role">{profile.role}</p>
            </div>

            <div className="profile-stats">
              <div className="stat-item">
                <span className="stat-value">156</span>
                <span className="stat-label">Total Bookings</span>
              </div>
              <div className="stat-item">
                <span className="stat-value">89</span>
                <span className="stat-label">Active Users</span>
              </div>
              <div className="stat-item">
                <span className="stat-value">42</span>
                <span className="stat-label">Rooms Managed</span>
              </div>
            </div>
          </div>
        </div>

        {/* Profile Information Section */}
        <div className="profile-main">
          <div className="settings-card">
            <div className="settings-card-header">
              <User size={20} />
              <h2>Personal Information</h2>
            </div>
            <div className="settings-form">
              <div className="form-row">
                <div className="form-group">
                  <label>First Name</label>
                  <input
                    type="text"
                    value={profile.firstName}
                    onChange={(e) => handleChange('firstName', e.target.value)}
                  />
                </div>
                <div className="form-group">
                  <label>Last Name</label>
                  <input
                    type="text"
                    value={profile.lastName}
                    onChange={(e) => handleChange('lastName', e.target.value)}
                  />
                </div>
              </div>

              <div className="form-group">
                <label>
                  <Mail size={16} />
                  Email Address
                </label>
                <input
                  type="email"
                  value={profile.email}
                  onChange={(e) => handleChange('email', e.target.value)}
                />
              </div>

              <div className="form-group">
                <label>
                  <Phone size={16} />
                  Phone Number
                </label>
                <input
                  type="tel"
                  value={profile.phone}
                  onChange={(e) => handleChange('phone', e.target.value)}
                />
              </div>

              <div className="form-group">
                <label>Role</label>
                <input
                  type="text"
                  value={profile.role}
                  disabled
                  className="disabled-input"
                />
                <small className="form-hint">Contact super admin to change your role</small>
              </div>

              <div className="form-group">
                <label>Bio</label>
                <textarea
                  rows="4"
                  value={profile.bio}
                  onChange={(e) => handleChange('bio', e.target.value)}
                  placeholder="Tell us about yourself..."
                />
              </div>
            </div>
          </div>

          <div className="settings-card">
            <div className="settings-card-header">
              <MapPin size={20} />
              <h2>Address Information</h2>
            </div>
            <div className="settings-form">
              <div className="form-group">
                <label>Street Address</label>
                <input
                  type="text"
                  value={profile.address}
                  onChange={(e) => handleChange('address', e.target.value)}
                />
              </div>

              <div className="form-row">
                <div className="form-group">
                  <label>City</label>
                  <input
                    type="text"
                    value={profile.city}
                    onChange={(e) => handleChange('city', e.target.value)}
                  />
                </div>
                <div className="form-group">
                  <label>Country</label>
                  <select
                    value={profile.country}
                    onChange={(e) => handleChange('country', e.target.value)}
                  >
                    <option value="Philippines">Philippines</option>
                    <option value="USA">United States</option>
                    <option value="UK">United Kingdom</option>
                    <option value="Japan">Japan</option>
                    <option value="Singapore">Singapore</option>
                  </select>
                </div>
              </div>
            </div>
          </div>

          <div className="settings-card">
            <div className="settings-card-header">
              <User size={20} />
              <h2>Account Security</h2>
            </div>
            <div className="settings-form">
              <div className="security-actions">
                <button className="btn-secondary">Change Password</button>
                <button className="btn-secondary">Enable Two-Factor Authentication</button>
                <button className="btn-danger">Delete Account</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default ProfileManagement;

import React from 'react';
import { Link } from 'react-router-dom';
import { MapPin, Phone, Mail, Facebook, Instagram } from 'lucide-react';
import { openCookiePreferences } from '../utils/cookieConsent';
import './Footer.css';

const Footer = () => {
  const currentYear = new Date().getFullYear();

  return (
    <footer className="footer">
      <div className="footer-container">
        <div className="footer-content">
          {/* Brand Section */}
          <div className="footer-brand">
            <div className="footer-logo">
              <img
                src="/images/logo/bw_logo.png"
                alt="H+ Hotel"
                className="footer-logo-image"
              />
            </div>
            <p className="footer-description">
            H+ Hotel QC offers clean, cozy, and comfortable rooms with Netflix, perfect for a relaxing stay. A budget-friendly option conveniently located near SM North Edsa, Trinoma, & Solaire Resort North, it's the ideal spot for affordable comfort and convenience.
            </p>
            <div className="footer-social">
              <a href="https://facebook.com" target="_blank" rel="noopener noreferrer" className="social-link">
                <Facebook size={18} />
              </a>
              <a href="https://instagram.com" target="_blank" rel="noopener noreferrer" className="social-link">
                <Instagram size={18} />
              </a>
            </div>
          </div>

          {/* Quick Links */}
          <div className="footer-section">
            <h4 className="footer-title">Quick Links</h4>
            <ul className="footer-links">
              <li><Link to="/" className="footer-link">Home</Link></li>
              <li><Link to="/rooms" className="footer-link">Rooms</Link></li>
              <li><Link to="/#about" className="footer-link">About Us</Link></li>
              <li><Link to="/amenities" className="footer-link">Amenities</Link></li>
              <li><Link to="/contact" className="footer-link">Contact</Link></li>
            </ul>
          </div>

          {/* Services */}
          <div className="footer-section">
            <h4 className="footer-title">Services</h4>
            <ul className="footer-links">
              <li><Link to="/booking" className="footer-link">Book a Room</Link></li>
              <li><Link to="/bookings" className="footer-link">My Bookings</Link></li>
              <li><Link to="/offers" className="footer-link">Special Offers</Link></li>
              <li><Link to="/policies" className="footer-link">Policies</Link></li>
              <li><Link to="/faq" className="footer-link">FAQ</Link></li>
            </ul>
          </div>

          {/* Contact */}
          <div className="footer-section">
            <h4 className="footer-title">Contact</h4>
            <div className="contact-info">
              <div className="contact-item">
                <MapPin />
                <span>
                  H+HOTEL,<br />
                  One Nenita Place 89 Road 1 Bagong Pagasa<br />
                  Quezon City, Philippines
                </span>
              </div>
              <div className="contact-item">
                <Phone />
                <a href="tel:+639178099482">+63 917 809 9482</a>
              </div>
              <div className="contact-item">
                <Mail />
                <a href="mailto:hhotelsph@gmail.com">hhotelsph@gmail.com</a>
              </div>
            </div>
          </div>
        </div>

        {/* Footer Bottom */}
        <div className="footer-bottom">
          <p className="footer-copyright">
            © {currentYear} H+HOTEL. All rights reserved.
          </p>
          <ul className="footer-legal">
            <li><Link to="/privacy">Privacy Policy</Link></li>
            <li><Link to="/terms">Terms of Service</Link></li>
            <li><Link to="/cookies">Cookie Policy</Link></li>
            <li><button type="button" className="footer-cookie-preferences" onClick={openCookiePreferences}>Cookie Preferences</button></li>
          </ul>
        </div>
      </div>
    </footer>
  );
};

export default Footer;

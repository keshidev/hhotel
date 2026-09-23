import React, { useState, useEffect, useRef } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { ChevronLeft, ChevronRight, Menu, ShoppingCart, X } from 'lucide-react';
import useBookingCartCount from '../hooks/useBookingCartCount';
import './Header.css';

const ROOM_TYPES = [
  { id: 'executive-suite',  name: 'Executive Suite',  path: '/room/executive-suite' },
  { id: 'deluxe',           name: 'Deluxe',            path: '/room/deluxe' },
  { id: 'superior-twin',    name: 'Superior Twin',     path: '/room/superior-twin' },
  { id: 'superior-queen',   name: 'Superior Queen',    path: '/room/superior-queen' },
  { id: 'premier',          name: 'Premier',           path: '/room/premier' },
];

const Header = () => {
  const [isScrolled, setIsScrolled] = useState(false);
  const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);
  const [mobileMenuView, setMobileMenuView] = useState('main');
  const [roomsDropdownOpen, setRoomsDropdownOpen] = useState(false);
  const location = useLocation();
  const navigate = useNavigate();
  const dropdownRef = useRef(null);
  const timeoutRef = useRef(null);
  const cartCount = useBookingCartCount();

  useEffect(() => {
    const handleScroll = () => {
      setIsScrolled(window.scrollY > 50);
    };

    window.addEventListener('scroll', handleScroll);
    return () => window.removeEventListener('scroll', handleScroll);
  }, []);

  // Close dropdown when clicking outside
  useEffect(() => {
    const handleClickOutside = (event) => {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target)) {
        setRoomsDropdownOpen(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  // Close menus on route change
  useEffect(() => {
    setIsMobileMenuOpen(false);
    setMobileMenuView('main');
    setRoomsDropdownOpen(false);
  }, [location]);

  const handleMobileMenuToggle = () => {
    setIsMobileMenuOpen((isOpen) => {
      if (isOpen) setMobileMenuView('main');
      return !isOpen;
    });
  };

  const closeMobileMenu = () => {
    setIsMobileMenuOpen(false);
    setMobileMenuView('main');
    setRoomsDropdownOpen(false);
  };

  const handleMouseEnter = () => {
    if (timeoutRef.current) {
      clearTimeout(timeoutRef.current);
    }
    setRoomsDropdownOpen(true);
  };

  const handleMouseLeave = () => {
    timeoutRef.current = setTimeout(() => {
      setRoomsDropdownOpen(false);
    }, 300);
  };

  const handleRoomClick = (path) => {
    setRoomsDropdownOpen(false);
    setIsMobileMenuOpen(false);
    navigate(path);
  };

  return (
    <header className={`header ${isScrolled ? 'scrolled' : ''}`}>
      <div className="header-container">
        <Link to="/" className="header-logo" onClick={closeMobileMenu}>
          <img src="/images/logo/logo-withoutbg.png" alt="H+ Hotel" className="header-logo-img" />
        </Link>
        <nav id="mobile-navigation" className={`header-nav ${isMobileMenuOpen ? 'active' : ''}`}>
          <ul className={`nav-links ${mobileMenuView !== 'main' ? 'mobile-main-menu-hidden' : ''}`}>
            <li>
              <Link 
                to="/" 
                className={`nav-link ${location.pathname === '/' ? 'active' : ''}`}
                onClick={closeMobileMenu}
              >
                Home
              </Link>
            </li>

            {/* Rooms Dropdown - Desktop */}
            <li 
              className="nav-dropdown desktop-only"
              ref={dropdownRef}
              onMouseEnter={handleMouseEnter}
              onMouseLeave={handleMouseLeave}
            >
              <button 
                className={`nav-link dropdown-trigger ${location.pathname.startsWith('/room') ? 'active' : ''}`}
                onClick={() => setRoomsDropdownOpen(!roomsDropdownOpen)}
              >
                Rooms
                <svg 
                  className={`dropdown-icon ${roomsDropdownOpen ? 'open' : ''}`}
                  width="12" 
                  height="12" 
                  viewBox="0 0 12 12" 
                  fill="none" 
                  stroke="currentColor" 
                  strokeWidth="2"
                >
                  <polyline points="2 4 6 8 10 4"></polyline>
                </svg>
              </button>

              {roomsDropdownOpen && (
                <div className="dropdown-menu">
                  {ROOM_TYPES.map((room) => (
                    <button
                      key={room.id}
                      className="dropdown-item"
                      onClick={() => handleRoomClick(room.path)}
                    >
                      {room.name}
                    </button>
                  ))}
                </div>
              )}
            </li>

            {/* Rooms Dropdown - Mobile */}
            <li className="nav-dropdown mobile-only">
              <button 
                type="button"
                className={`nav-link mobile-submenu-trigger ${location.pathname.startsWith('/room') ? 'active' : ''}`}
                onClick={() => setMobileMenuView('rooms')}
                aria-label="Open Rooms menu"
              >
                <span>Rooms</span>
                <ChevronRight className="mobile-submenu-chevron" aria-hidden="true" />
              </button>
            </li>

            {/* <li>
              <Link 
                to="/amenities" 
                className={`nav-link ${location.pathname === '/amenities' ? 'active' : ''}`}
                onClick={closeMobileMenu}
              >
                Amenities
              </Link>
            </li> */}
            <li>
              <Link 
                to="/faq" 
                className={`nav-link ${location.pathname === '/faq' ? 'active' : ''}`}
                onClick={closeMobileMenu}
              >
                FAQ
              </Link>
            </li>
            <li>
              <Link 
                to="/contact" 
                className={`nav-link ${location.pathname === '/contact' ? 'active' : ''}`}
                onClick={closeMobileMenu}
              >
                Contact
              </Link>
            </li>
            <li>
              <Link
                to="/my-booking"
                className="header-booking-btn"
                onClick={closeMobileMenu}
              >
                My Bookings
              </Link>
            </li>
          </ul>

          <div className={`mobile-submenu-panel ${mobileMenuView === 'rooms' ? 'active' : ''}`}>
            <div className="mobile-submenu-heading">
              <button
                type="button"
                className="mobile-submenu-back"
                onClick={() => setMobileMenuView('main')}
                aria-label="Return to main navigation"
              >
                <ChevronLeft aria-hidden="true" />
              </button>
              <span>Rooms</span>
            </div>

            <div className="mobile-submenu-links">
              <Link to="/select-room" className="mobile-submenu-link" onClick={closeMobileMenu}>
                All Rooms
              </Link>
              {ROOM_TYPES.map((room) => (
                <Link
                  key={room.id}
                  to={room.path}
                  className="mobile-submenu-link"
                  onClick={closeMobileMenu}
                >
                  {room.name}
                  <ChevronRight aria-hidden="true" />
                </Link>
              ))}
            </div>
          </div>
        </nav>

        <Link
          to="/cart"
          className={`header-cart ${location.pathname === '/cart' ? 'active' : ''}`}
          onClick={closeMobileMenu}
          aria-label={`Booking cart, ${cartCount} room${cartCount === 1 ? '' : 's'}`}
        >
          <ShoppingCart aria-hidden="true" />
          <span className="header-cart-label">Cart</span>
          <span className="header-cart-badge" aria-hidden="true">{cartCount > 99 ? '99+' : cartCount}</span>
        </Link>

        <button 
          type="button"
          className={`mobile-menu-toggle ${isMobileMenuOpen ? 'active' : ''}`}
          onClick={handleMobileMenuToggle}
          aria-label={isMobileMenuOpen ? 'Close navigation menu' : 'Open navigation menu'}
          aria-expanded={isMobileMenuOpen}
          aria-controls="mobile-navigation"
        >
          {isMobileMenuOpen ? <X aria-hidden="true" /> : <Menu aria-hidden="true" />}
        </button>
      </div>
    </header>
  );
};

export default Header;

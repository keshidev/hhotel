import React, { useEffect, useState } from 'react';
import { Cookie } from 'lucide-react';
import { Link, useLocation } from 'react-router-dom';
import {
  COOKIE_CONSENT_EVENT,
  COOKIE_CONSENT_KEY,
  COOKIE_PREFERENCES_EVENT,
  readCookieConsent,
  saveCookieConsent,
} from '../utils/cookieConsent';
import './CookieConsent.css';

const STAFF_PATHS = ['/admin', '/receptionist', '/login', '/forgot-password', '/reset-password'];

const CookieConsent = () => {
  const { pathname } = useLocation();
  const [isOpen, setIsOpen] = useState(() => readCookieConsent() === null);

  useEffect(() => {
    const openPreferences = () => setIsOpen(true);
    const syncConsent = () => setIsOpen(readCookieConsent() === null);
    const syncAcrossTabs = (event) => {
      if (event.key === COOKIE_CONSENT_KEY) syncConsent();
    };

    window.addEventListener(COOKIE_PREFERENCES_EVENT, openPreferences);
    window.addEventListener(COOKIE_CONSENT_EVENT, syncConsent);
    window.addEventListener('storage', syncAcrossTabs);

    return () => {
      window.removeEventListener(COOKIE_PREFERENCES_EVENT, openPreferences);
      window.removeEventListener(COOKIE_CONSENT_EVENT, syncConsent);
      window.removeEventListener('storage', syncAcrossTabs);
    };
  }, []);

  const isStaffPage = STAFF_PATHS.some((path) => pathname === path || pathname.startsWith(`${path}/`));
  if (!isOpen || isStaffPage) return null;

  const choose = (choice) => {
    saveCookieConsent(choice);
    setIsOpen(false);
  };

  return (
    <aside className="cookie-consent" role="dialog" aria-modal="false" aria-labelledby="cookie-consent-title">
      <div className="cookie-consent-icon" aria-hidden="true"><Cookie size={21} /></div>
      <div className="cookie-consent-content">
        <h2 id="cookie-consent-title">We value your privacy</h2>
        <p>
          Essential cookies keep reservations and security features working. With your permission,
          we also load optional third-party content such as our embedded map.
        </p>
        <Link to="/cookies">Read our Cookie Policy</Link>
        <div className="cookie-consent-actions">
          <button type="button" className="cookie-consent-reject" onClick={() => choose('rejected')}>Reject</button>
          <button type="button" className="cookie-consent-accept" onClick={() => choose('accepted')}>Accept</button>
        </div>
        <small>Rejecting optional cookies will not affect booking or account security.</small>
      </div>
    </aside>
  );
};

export default CookieConsent;

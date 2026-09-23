import { useEffect } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { ArrowRight, BedDouble, House } from 'lucide-react';
import './NotFound.css';

const NotFound = () => {
  const location = useLocation();

  useEffect(() => {
    document.title = 'Page Not Found | H+ Hotel';

    return () => {
      document.title = 'H+ Hotel';
    };
  }, []);

  return (
    <main className="not-found-page" aria-labelledby="not-found-title">
      <div className="not-found-decoration not-found-decoration-left" aria-hidden="true" />
      <div className="not-found-decoration not-found-decoration-right" aria-hidden="true" />

      <section className="not-found-card">
        <div className="not-found-copy">
          <p className="not-found-eyebrow">Lost your way?</p>
          <p className="not-found-code" aria-hidden="true">404</p>
          <h1 id="not-found-title">This page has checked out.</h1>
          <p className="not-found-message">
            The address may be incomplete, moved, or no longer available. Let us guide you back to a comfortable stay.
          </p>

          <div className="not-found-path" aria-label="Requested page">
            <span>Requested page</span>
            <code>{location.pathname}</code>
          </div>

          <div className="not-found-actions">
            <Link to="/" className="not-found-button not-found-button-primary">
              <House aria-hidden="true" />
              Return Home
            </Link>
            <Link to="/select-room" className="not-found-button not-found-button-secondary">
              <BedDouble aria-hidden="true" />
              Browse Rooms
            </Link>
          </div>

          <Link to="/contact" className="not-found-contact-link">
            Need help? Contact the hotel
            <ArrowRight aria-hidden="true" />
          </Link>
        </div>

        <div className="not-found-visual" aria-hidden="true">
          <div className="not-found-door-frame">
            <div className="not-found-door">
              <span className="not-found-room-number">404</span>
              <span className="not-found-door-handle" />
            </div>
          </div>
          <div className="not-found-floor-line" />
          <p>Wrong room. Right hotel.</p>
        </div>
      </section>
    </main>
  );
};

export default NotFound;

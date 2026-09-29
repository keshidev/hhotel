import { useEffect, useId, useRef, useState } from 'react';
import { formatCurrency } from '../utils/currency';
import './StickyBookingCart.css';

export default function StickyBookingCart({ className, rooms, total, onContinue, continueLabel = 'Continue', children }) {
  const [expanded, setExpanded] = useState(false);
  const sidebarRef = useRef(null);
  const dockRef = useRef(null);
  const detailsId = useId();

  useEffect(() => {
    const sidebar = sidebarRef.current;
    const page = sidebar.closest('.select-room-page, .addons-page, .checkout-page');
    const header = document.querySelector('.header');
    const banner = document.querySelector('.test-mode-banner');
    const details = sidebar.querySelector('.booking-sticky-details');
    const update = () => {
      const top = (header?.getBoundingClientRect().height || 0) + (banner?.getBoundingClientRect().height || 0) + 16;
      sidebar.style.setProperty('--booking-cart-height', `${details.getBoundingClientRect().height}px`);
      sidebar.style.setProperty('--booking-cart-top', `${top}px`);
      page?.style.setProperty('--booking-cart-dock-height', `${dockRef.current?.getBoundingClientRect().height || 0}px`);
    };
    const observer = new ResizeObserver(update);
    [header, banner, details, dockRef.current].filter(Boolean).forEach((element) => observer.observe(element));
    update();
    return () => {
      observer.disconnect();
      page?.style.removeProperty('--booking-cart-dock-height');
    };
  }, []);

  useEffect(() => {
    if (!expanded || !window.matchMedia('(max-width: 1024px)').matches) return;
    const frame = requestAnimationFrame(() => {
      sidebarRef.current?.querySelector('.booking-sticky-details')?.scrollIntoView({ block: 'start', behavior: 'instant' });
    });
    return () => cancelAnimationFrame(frame);
  }, [expanded]);
  return (
    <aside ref={sidebarRef} className={`${className} booking-sticky-cart`} data-expanded={expanded} aria-label="Your booking cart" onKeyDown={(event) => {
      if (event.key === 'Escape' && expanded) {
        setExpanded(false);
        dockRef.current?.querySelector('button')?.focus();
      }
    }}>
      <div id={detailsId} className="booking-sticky-details">{children}</div>
      <div ref={dockRef} className="booking-sticky-dock">
        <button type="button" className="booking-sticky-toggle" aria-expanded={expanded} aria-controls={detailsId} onClick={() => setExpanded(!expanded)}>
          <span className="booking-sticky-room">{rooms.length ? rooms[0].name || 'Selected room' : 'No rooms selected'}{rooms.length > 1 ? ` + ${rooms.length - 1} more` : ''}</span>
          <span className="booking-sticky-total">Total <strong>{formatCurrency(total)}</strong></span>
          <span className="booking-sticky-hint">{expanded ? 'Hide cart' : 'View cart'} {expanded ? '▾' : '▴'}</span>
        </button>
        <button type="button" className="booking-sticky-continue" disabled={!rooms.length} onClick={onContinue}>{continueLabel}</button>
      </div>
    </aside>
  );
}

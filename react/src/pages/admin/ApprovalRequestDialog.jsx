import { useEffect, useRef } from 'react';
import { X } from 'lucide-react';
import './ApprovalRequestDialog.css';

export default function ApprovalRequestDialog({ title, bookingReference, saving, onClose, actions, children, restoreFocusTo }) {
  const dialogRef = useRef(null);

  useEffect(() => {
    const dialog = dialogRef.current;
    const previousFocus = restoreFocusTo || document.activeElement;
    const previousOverflow = document.body.style.overflow;
    const banner = document.querySelector('.test-mode-banner');
    const updateBanner = () => dialog.style.setProperty('--approval-banner-height', `${banner?.getBoundingClientRect().height || 0}px`);
    const observer = new ResizeObserver(updateBanner);
    if (banner) observer.observe(banner);
    updateBanner();
    dialog.showModal();
    document.body.style.overflow = 'hidden';
    return () => {
      observer.disconnect();
      dialog.close();
      document.body.style.overflow = previousOverflow;
      if (previousFocus?.isConnected) previousFocus.focus();
    };
  }, [restoreFocusTo]);

  return (
    <dialog ref={dialogRef} className="ar-dialog" aria-labelledby="approval-request-title" onCancel={(event) => {
      event.preventDefault();
      if (!saving) onClose();
    }}>
      <div className="ar-backdrop" onClick={(event) => {
        if (event.target === event.currentTarget && !saving) onClose();
      }}>
        <section className="ar-modal" aria-busy={saving}>
          <header className="ar-header">
            <div>
              <span className="ar-reference">{bookingReference}</span>
              <h2 id="approval-request-title">{title}</h2>
            </div>
            <button type="button" className="ar-close" aria-label="Close request details" onClick={onClose} disabled={saving} autoFocus><X size={18} /></button>
          </header>
          <div className="ar-body">{children}</div>
          <footer className="ar-footer">{actions}</footer>
        </section>
      </div>
    </dialog>
  );
}

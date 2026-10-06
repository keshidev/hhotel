import { useEffect, useId, useRef } from 'react';
import { X } from 'lucide-react';
import './PaymentOperationsDialog.css';

export default function PaymentOperationsDialog({ title, reference, saving = false, onClose, actions, children }) {
  const dialogRef = useRef(null);
  const titleId = useId();

  useEffect(() => {
    const dialog = dialogRef.current;
    const previousFocus = document.activeElement;
    const previousOverflow = document.body.style.overflow;
    const banner = document.querySelector('.test-mode-banner');
    const updateBanner = () => dialog.style.setProperty('--payment-dialog-banner-height', `${banner?.getBoundingClientRect().height || 0}px`);
    const observer = new ResizeObserver(updateBanner);
    if (banner) observer.observe(banner);
    updateBanner();
    dialog.showModal();
    document.body.style.overflow = 'hidden';
    return () => {
      observer.disconnect();
      // Keep action feedback visible when a successful save closes the dialog.
      dialog.querySelectorAll('[data-toast-host] > .hhotel-toast').forEach((toast) => document.body.appendChild(toast));
      dialog.close();
      document.body.style.overflow = previousOverflow;
      if (previousFocus?.isConnected) previousFocus.focus();
    };
  }, []);

  return (
    <dialog ref={dialogRef} className="pm-dialog" aria-labelledby={titleId} onCancel={(event) => {
      event.preventDefault();
      if (!saving) onClose();
    }}>
      <div className="pm-backdrop" onClick={(event) => {
        if (event.target === event.currentTarget && !saving) onClose();
      }}>
        <section className="pm-modal" aria-busy={saving}>
          <header className="pm-header">
            <div><span className="pm-reference">{reference}</span><h2 id={titleId}>{title}</h2></div>
            <button type="button" className="pm-close" aria-label={`Close ${title.toLowerCase()}`} onClick={onClose} disabled={saving} autoFocus><X size={18} /></button>
          </header>
          <div className="pm-body">
            <fieldset className="pm-content" disabled={saving}>{children}</fieldset>
          </div>
          <footer className="pm-footer">
            <button type="button" onClick={onClose} disabled={saving}>Close</button>
            {actions}
          </footer>
        </section>
      </div>
      <div data-toast-host />
    </dialog>
  );
}

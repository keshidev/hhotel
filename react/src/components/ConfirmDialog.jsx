import React, { useEffect, useId, useRef } from 'react';
import { AlertTriangle } from 'lucide-react';
import './ConfirmDialog.css';

const ConfirmDialog = ({
  open,
  title = 'Confirm Action',
  message = '',
  confirmLabel = 'Confirm',
  cancelLabel = 'Cancel',
  danger = false,
  confirmDisabled = false,
  hideCancel = false,
  onConfirm,
  onCancel,
  children,
}) => {
  const dialogRef = useRef(null);
  const initialFocusRef = useRef(null);
  const titleId = useId();
  const messageId = useId();

  useEffect(() => {
    if (!open) return undefined;
    const dialog = dialogRef.current;
    const previousFocus = document.activeElement;
    const previousOverflow = document.body.style.overflow;
    dialog.showModal();
    initialFocusRef.current?.focus();
    document.body.style.overflow = 'hidden';
    return () => {
      dialog.close();
      document.body.style.overflow = previousOverflow;
      if (previousFocus?.isConnected) previousFocus.focus();
    };
  }, [open]);

  if (!open) return null;

  return (
    <dialog
      ref={dialogRef}
      className="confirm-dialog-native"
      onKeyDown={(event) => {
        if (event.key !== 'Tab') return;
        const controls = [...event.currentTarget.querySelectorAll(
          'button:not(:disabled), input:not(:disabled), textarea:not(:disabled), select:not(:disabled), a[href], [tabindex]:not([tabindex="-1"])'
        )].filter((element) => element.getClientRects().length > 0);
        const first = controls[0];
        const last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last?.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first?.focus();
        }
      }}
      aria-labelledby={titleId}
      aria-describedby={message ? messageId : undefined}
      onCancel={(event) => { event.preventDefault(); onCancel?.(); }}
    >
      <div className="confirm-dialog-overlay" onClick={onCancel}>
        <div className="confirm-dialog-box" onClick={(event) => event.stopPropagation()}>
          <div className="confirm-dialog-header">
            <div className={'confirm-dialog-icon ' + (danger ? 'danger' : '')}>
              <AlertTriangle size={18} aria-hidden="true" />
            </div>
            <h3 id={titleId}>{title}</h3>
          </div>

          {message ? <p id={messageId} className="confirm-dialog-message">{message}</p> : null}
          {children ? <div className="confirm-dialog-body">{children}</div> : null}

          <div className="confirm-dialog-actions">
            {!hideCancel && (
              <button ref={initialFocusRef} type="button" className="confirm-btn cancel" onClick={onCancel}>
                {cancelLabel}
              </button>
            )}
            <button
              ref={hideCancel ? initialFocusRef : undefined}
              type="button"
              className={'confirm-btn ' + (danger ? 'danger' : 'primary')}
              onClick={onConfirm}
              disabled={confirmDisabled}
            >
              {confirmLabel}
            </button>
          </div>
        </div>
      </div>
    </dialog>
  );
};

export default ConfirmDialog;

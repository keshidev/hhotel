const STYLE_ID = 'hhotel-toast-styles';

const VALID_TYPES = new Set(['success', 'error', 'warning', 'info']);

const ensureStyles = () => {
  if (document.getElementById(STYLE_ID)) return;

  const style = document.createElement('style');
  style.id = STYLE_ID;
  style.textContent = `
    .hhotel-toast {
      position: fixed; top: 20px; right: 20px;
      max-width: min(420px, calc(100vw - 40px));
      padding: 16px 24px; border-radius: 8px; color: #ffffff !important;
      font-size: 14px; font-weight: 500;
      box-shadow: 0 10px 40px rgba(0,0,0,0.2);
      z-index: 9999; transform: translateX(400px); opacity: 0;
      transition: all 0.3s ease;
    }
    .hhotel-toast.show { transform: translateX(0); opacity: 1; }
    .hhotel-toast-success { background: #10b981 !important; }
    .hhotel-toast-error   { background: #ef4444 !important; }
    .hhotel-toast-warning { background: #f59e0b !important; }
    .hhotel-toast-info    { background: #3b82f6 !important; }
    .hhotel-toast-content { display: grid; gap: 4px; }
    .hhotel-toast-title { font-size: 14px; font-weight: 800; line-height: 1.2; }
    .hhotel-toast-ref { font-size: 13px; font-weight: 600; opacity: 0.96; }
    .hhotel-toast-link {
      color: #ffffff;
      font-size: 13px;
      font-weight: 700;
      text-decoration: underline;
      width: fit-content;
    }
  `;
  document.head.appendChild(style);
};

export const showToast = (message, type = 'success') => {
  ensureStyles();
  const toastType = VALID_TYPES.has(type) ? type : 'info';
  const toast = document.createElement('div');
  toast.className = `hhotel-toast hhotel-toast-${toastType}`;
  toast.setAttribute('role', toastType === 'error' ? 'alert' : 'status');
  toast.setAttribute('aria-live', toastType === 'error' ? 'assertive' : 'polite');
  toast.textContent = message;
  document.body.appendChild(toast);
  setTimeout(() => toast.classList.add('show'), 10);
  setTimeout(() => {
    toast.classList.remove('show');
    setTimeout(() => {
      if (toast.parentNode) document.body.removeChild(toast);
    }, 300);
  }, 3000);
};

export const showBookingConfirmedToast = (referenceNumber, bookingLink = '/my-booking') => {
  ensureStyles();

  const toast = document.createElement('div');
  toast.className = 'hhotel-toast hhotel-toast-success';
  toast.setAttribute('role', 'status');
  toast.setAttribute('aria-live', 'polite');

  const content = document.createElement('div');
  content.className = 'hhotel-toast-content';

  const title = document.createElement('div');
  title.className = 'hhotel-toast-title';
  title.textContent = 'Booking Confirmed!';

  const ref = document.createElement('div');
  ref.className = 'hhotel-toast-ref';
  ref.textContent = `Ref: ${String(referenceNumber || 'N/A')}`;

  const link = document.createElement('a');
  link.className = 'hhotel-toast-link';
  link.href = bookingLink;
  link.textContent = 'View Booking';

  content.appendChild(title);
  content.appendChild(ref);
  content.appendChild(link);
  toast.appendChild(content);
  document.body.appendChild(toast);

  setTimeout(() => toast.classList.add('show'), 10);
  setTimeout(() => {
    toast.classList.remove('show');
    setTimeout(() => {
      if (toast.parentNode) {
        document.body.removeChild(toast);
      }
    }, 300);
  }, 5500);
};

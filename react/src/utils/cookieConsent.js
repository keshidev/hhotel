export const COOKIE_CONSENT_KEY = 'hhotel_cookie_consent';
export const COOKIE_CONSENT_EVENT = 'hhotel:cookie-consent-change';
export const COOKIE_PREFERENCES_EVENT = 'hhotel:open-cookie-preferences';

const VALID_CHOICES = new Set(['accepted', 'rejected']);

export const readCookieConsent = () => {
  try {
    const stored = JSON.parse(localStorage.getItem(COOKIE_CONSENT_KEY) || 'null');
    return stored?.version === 1 && VALID_CHOICES.has(stored.choice) ? stored.choice : null;
  } catch {
    return null;
  }
};

export const saveCookieConsent = (choice) => {
  if (!VALID_CHOICES.has(choice)) return false;

  try {
    localStorage.setItem(COOKIE_CONSENT_KEY, JSON.stringify({
      version: 1,
      choice,
      savedAt: new Date().toISOString(),
    }));
    window.dispatchEvent(new CustomEvent(COOKIE_CONSENT_EVENT, { detail: { choice } }));
    return true;
  } catch {
    return false;
  }
};

export const openCookiePreferences = () => {
  window.dispatchEvent(new Event(COOKIE_PREFERENCES_EVENT));
};

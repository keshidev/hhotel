import { useEffect } from 'react';

// This commits with the initial route, including any lazy-loaded route modules.
export default function StartupReady() {
  useEffect(() => {
    const loader = document.getElementById('hotel-startup');
    if (!loader) return;

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const remaining = reducedMotion ? 0 : Math.max(0, 1500 - performance.now());
    let removeTimer;
    const readyTimer = window.setTimeout(() => {
      document.getElementById('root')?.removeAttribute('inert');
      loader.classList.add('is-ready');
      removeTimer = window.setTimeout(() => loader.remove(), reducedMotion ? 0 : 180);
    }, remaining);

    return () => {
      window.clearTimeout(readyTimer);
      window.clearTimeout(removeTimer);
    };
  }, []);

  return null;
}

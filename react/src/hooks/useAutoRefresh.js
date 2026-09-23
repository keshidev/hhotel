import { useEffect, useRef } from 'react';

const useAutoRefresh = (
  callback,
  {
    enabled = true,
    intervalMs = 15000,
    refreshOnFocus = true,
    refreshOnVisible = true,
    deps = [],
  } = {}
) => {
  const callbackRef = useRef(callback);

  useEffect(() => {
    callbackRef.current = callback;
  }, [callback]);

  useEffect(() => {
    if (!enabled) {
      return undefined;
    }

    const tick = () => callbackRef.current?.();
    const intervalId = window.setInterval(tick, intervalMs);

    const handleFocus = () => {
      if (refreshOnFocus) {
        tick();
      }
    };

    const handleVisibility = () => {
      if (refreshOnVisible && document.visibilityState === 'visible') {
        tick();
      }
    };

    if (refreshOnFocus) {
      window.addEventListener('focus', handleFocus);
    }

    if (refreshOnVisible) {
      document.addEventListener('visibilitychange', handleVisibility);
    }

    return () => {
      window.clearInterval(intervalId);
      if (refreshOnFocus) {
        window.removeEventListener('focus', handleFocus);
      }
      if (refreshOnVisible) {
        document.removeEventListener('visibilitychange', handleVisibility);
      }
    };
  }, [enabled, intervalMs, refreshOnFocus, refreshOnVisible, ...deps]);
};

export default useAutoRefresh;

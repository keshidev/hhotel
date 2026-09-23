import { useEffect, useRef } from 'react';
import { useLocation } from 'react-router-dom';

// Reapply filters for a fresh notification, including navigation within the same page.
// Ordinary filter edits do not reapply the notification target.
export function useNotificationTarget(apply) {
  const location = useLocation();
  const callback = useRef(apply);
  callback.current = apply;
  const target = location.state?.notificationTarget;
  useEffect(() => {
    if (target) return callback.current(target);
  }, [location.key]); // eslint-disable-line react-hooks/exhaustive-deps
  return target;
}

import { useState, useEffect } from "react";
import { useLocation } from "react-router-dom";

export function useNotificationNav() {
  const location = useLocation();
  const state    = location.state || {};

  const [targetBookingId, setTargetBookingId] = useState(state.openBookingId || null);
  const [targetPaymentId, setTargetPaymentId] = useState(state.openPaymentId || null);
  const [highlightRoom,   setHighlightRoom]   = useState(state.highlightRoom  || null);
  const [notifType,       setNotifType]       = useState(state.notifType      || null);

  useEffect(() => {
    if (state.openBookingId) setTargetBookingId(state.openBookingId);
    if (state.openPaymentId) setTargetPaymentId(state.openPaymentId);
    if (state.highlightRoom) setHighlightRoom(state.highlightRoom);
    if (state.notifType)     setNotifType(state.notifType);
  }, [state.openBookingId, state.openPaymentId, state.highlightRoom, state.notifType]);

  const clearTarget = () => {
    setTargetBookingId(null);
    setTargetPaymentId(null);
    setHighlightRoom(null);
    setNotifType(null);

    // Wipe location state so a browser refresh doesn't re-trigger the modal
    window.history.replaceState({}, document.title);
  };

  return { targetBookingId, targetPaymentId, highlightRoom, notifType, clearTarget };
}
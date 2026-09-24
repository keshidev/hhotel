import { useEffect, useRef, useState } from 'react';
import { Bed, Users, Maximize, X } from 'lucide-react';
import './RoomDetailsModal.css';

const amenityLabel = (value) => {
  const name = String(value).replace(/[_-]+/g, ' ').trim().toLowerCase();
  return ({ wifi: 'Wi-Fi', tv: 'TV', ac: 'Air conditioning', minibar: 'Mini bar' })[name]
    || name.charAt(0).toUpperCase() + name.slice(1);
};

export default function RoomDetailsModal({ room, label, image, getAmenityIcon, onClose }) {
  const dialogRef = useRef(null);
  const [imageFailed, setImageFailed] = useState(false);

  useEffect(() => {
    const dialog = dialogRef.current;
    const trigger = document.activeElement;
    const previousOverflow = document.body.style.overflow;
    dialog.showModal();
    document.body.style.overflow = 'hidden';
    return () => {
      dialog.close();
      document.body.style.overflow = previousOverflow;
      if (trigger?.isConnected) trigger.focus({ preventScroll: true });
    };
  }, []);

  return (
    <dialog ref={dialogRef} className="room-detail-dialog" aria-labelledby="room-detail-title"
      onKeyDown={(event) => {
        if (event.key !== 'Tab') return;
        const buttons = event.currentTarget.querySelectorAll('button');
        const first = buttons[0];
        const last = buttons[buttons.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault(); last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault(); first.focus();
        }
      }}
      onCancel={(event) => { event.preventDefault(); onClose(); }}
      onClick={(event) => { if (event.target === event.currentTarget) onClose(); }}>
      <div className="room-detail-panel">
        <header className="room-detail-header">
          <span>Room details</span>
          <button type="button" autoFocus onClick={onClose} aria-label="Close room details"><X size={20} /></button>
        </header>
        <div className="room-detail-scroll">
          {image && !imageFailed ? (
            <div className="room-detail-photo"><img src={image} alt={label} onError={() => setImageFailed(true)} /></div>
          ) : (
            <div className="room-detail-photo-fallback"><Bed size={28} aria-hidden="true" /><span>Room photo unavailable</span></div>
          )}
          <div className="room-detail-content">
            <p className="room-detail-eyebrow">H+ HOTEL</p>
            <h2 id="room-detail-title">{label}</h2>
            <div className="room-detail-facts">
              {room.capacity > 0 && <span><Users size={16} aria-hidden="true" />Up to {room.capacity} guests</span>}
              {room.bed_type && <span><Bed size={16} aria-hidden="true" />{amenityLabel(room.bed_type)}</span>}
              {room.size_sqm > 0 && <span><Maximize size={16} aria-hidden="true" />{room.size_sqm} m²</span>}
            </div>
            <p className="room-detail-description">{room.description || 'Comfortable room with all essential amenities.'}</p>
            {room.amenities?.length > 0 && <section className="room-detail-amenities" aria-labelledby="room-detail-amenities-title">
              <h3 id="room-detail-amenities-title">Room amenities</h3>
              <ul>{room.amenities.map((amenity, index) => <li key={index}>
                <span className="room-detail-amenity-icon" aria-hidden="true">{getAmenityIcon(String(amenity).replace(/[_-]+/g, ' '))}</span>
                <span>{amenityLabel(amenity)}</span>
              </li>)}</ul>
            </section>}
          </div>
        </div>
        <footer className="room-detail-footer"><button type="button" onClick={onClose}>Back to rooms</button></footer>
      </div>
    </dialog>
  );
}

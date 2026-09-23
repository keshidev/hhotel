import React, { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import {
  ArrowRight,
  BedDouble,
  Check,
  ChevronLeft,
  ChevronRight,
  Maximize2,
  ShieldCheck,
  Star,
  Users,
} from 'lucide-react';
import { formatCurrency } from '../utils/currency';
import { useCms } from '../context/CmsContext';
import './Roomdetail.css';

const FALLBACK_ROOM_TYPES = {
  executive_suite: {
    slug: 'executive-suite',
    name: 'Executive Suite',
    tagline: 'Luxury and space combined',
    description:
      'Indulge in our Executive Suite, offering separate living and sleeping areas with panoramic city views. Perfect for extended stays or guests seeking premium comfort.',
    capacity: 3,
    bedType: '1 King Bed',
    size: '55 sqm',
    images: ['/images/Executive Suite/executive_room.jpg', '/images/Executive Suite/executive_2.jpg'],
    amenities: ['Free Wi-Fi', 'Netflix Access', 'Air conditioning', 'Mini refrigerator', '24h room service'],
    suiteHighlights: ['Panoramic City Views', 'Executive Work Space', 'Premium Furnishings'],
    hotelServices: ['Priority dining', 'Personal concierge', 'VIP amenities'],
  },
  deluxe: {
    slug: 'deluxe',
    name: 'Deluxe',
    tagline: 'A blend of elegance and comfort',
    description:
      'Experience comfort and sophistication in our Deluxe Room, designed with modern amenities and elegant furnishings for business or leisure stays.',
    capacity: 2,
    bedType: '1 Queen Bed',
    size: '28 sqm',
    images: ['/images/Deluxe Room/deluxe_room.jpg', '/images/Deluxe Room/deluxe_2.jpg'],
    amenities: ['Free Wi-Fi', 'Netflix Access', 'Air conditioning', 'Mini refrigerator', '24h room service'],
    suiteHighlights: ['Elegant Interior Design', 'European Sheets', 'Separate Living Area'],
    hotelServices: ['In-room dining', 'Concierge assistance', 'Excursions available'],
  },
  superior_twin: {
    slug: 'superior-twin',
    name: 'Superior Twin',
    tagline: 'Modern comfort for two',
    description:
      'Our Superior Twin room features two comfortable single beds, ideal for friends or colleagues traveling together.',
    capacity: 2,
    bedType: '2 Single Beds',
    size: '32 sqm',
    images: ['/images/Superior Twin/superior_twin_room.jpg', '/images/Superior Twin/superior_twin_2.jpg'],
    amenities: ['Free Wi-Fi', 'Netflix Access', 'Air conditioning', 'Mini refrigerator', '24h room service'],
    suiteHighlights: ['Contemporary Design', 'Premium Bedding', 'Work Station'],
    hotelServices: ['Airport transfer', 'Express check-in', 'Laundry service'],
  },
  superior_queen: {
    slug: 'superior-queen',
    name: 'Superior Queen',
    tagline: 'Classic comfort with a queen touch',
    description:
      'Our Superior Queen room offers a plush queen bed and refined interiors for guests seeking a comfortable and stylish stay.',
    capacity: 2,
    bedType: '1 Queen Bed',
    size: '32 sqm',
    images: ['/images/Superior Queen/superior_queen_room.jpg', '/images/Superior Queen/superior_queen_2.jpg'],
    amenities: ['Free Wi-Fi', 'Netflix Access', 'Air conditioning', 'Mini refrigerator', '24h room service'],
    suiteHighlights: ['Contemporary Design', 'Premium Bedding', 'Work Station'],
    hotelServices: ['Airport transfer', 'Express check-in', 'Laundry service'],
  },
  premier: {
    slug: 'premier',
    name: 'Premier',
    tagline: 'Elevated living at its finest',
    description:
      'Our Premier Room combines sophisticated design with premium comforts for a truly elevated stay.',
    capacity: 2,
    bedType: '1 King Bed',
    size: '38 sqm',
    images: ['/images/Premier Room/premier_room.jpg', '/images/Premier Room/premier_2.jpg'],
    amenities: ['Free Wi-Fi', 'Netflix Access', 'Air conditioning', 'Mini refrigerator', '24h room service'],
    suiteHighlights: ['Premium Interior Design', 'Luxury Bedding', 'Dedicated Work Area'],
    hotelServices: ['In-room dining', 'Concierge assistance', 'Excursions available'],
  },
};

const SLUG_TO_TYPE = Object.fromEntries(
  Object.entries(FALLBACK_ROOM_TYPES).map(([type, room]) => [room.slug, type])
);

const TYPE_ORDER = ['executive_suite', 'deluxe', 'superior_twin', 'superior_queen', 'premier'];
const CLIENT_API_BASE =
  (import.meta.env.VITE_API_URL || 'http://localhost:8000/api') + '/client';

const publishedRate = (value) => {
  const rate = Number(value);
  return Number.isFinite(rate) && rate > 0 ? rate : null;
};

const RoomDetail = () => {
  const navigate = useNavigate();
  const { roomId } = useParams();
  const { getRoomContent } = useCms();

  const [currentImageIndex, setCurrentImageIndex] = useState(0);
  const [relatedRoomDetails, setRelatedRoomDetails] = useState({});
  const [roomTypeDetails, setRoomTypeDetails] = useState(null);
  const [loadingRoomType, setLoadingRoomType] = useState(true);
  const [roomTypeNotFound, setRoomTypeNotFound] = useState(false);

  const roomType = SLUG_TO_TYPE[roomId];

  useEffect(() => {
    const controller = new AbortController();
    const loadRoomTypeDetails = async () => {
      setLoadingRoomType(true);
      setRoomTypeNotFound(false);

      try {
        const res = await fetch(`${CLIENT_API_BASE}/room-types/${encodeURIComponent(roomId)}`, {
          cache: 'no-store',
          signal: controller.signal,
        });

        if (res.status === 404) {
          setRoomTypeDetails(null);
          setRoomTypeNotFound(true);
          return;
        }

        const payload = await res.json();
        if (res.ok && payload?.success && payload?.data) {
          setRoomTypeDetails(payload.data);
          setRoomTypeNotFound(false);
          return;
        }

        setRoomTypeDetails(null);
        setRoomTypeNotFound(true);
      } catch {
        if (controller.signal.aborted) return;
        setRoomTypeDetails(null);
        setRoomTypeNotFound(true);
      } finally {
        if (!controller.signal.aborted) setLoadingRoomType(false);
      }
    };

    const loadRelatedRoomDetails = async () => {
      setRelatedRoomDetails({});
      await Promise.all(TYPE_ORDER.filter((type) => type !== roomType).map(async (type) => {
        try {
          const response = await fetch(`${CLIENT_API_BASE}/room-types/${FALLBACK_ROOM_TYPES[type].slug}`, {
            cache: 'no-store',
            signal: controller.signal,
          });
          const payload = await response.json();
          if (!controller.signal.aborted && response.ok && payload?.success && payload?.data) {
            setRelatedRoomDetails((current) => ({ ...current, [type]: payload.data }));
          }
        } catch {
          // Keep missing rates unavailable instead of advertising a zero-priced room.
        }
      }));
    };

    loadRoomTypeDetails();
    loadRelatedRoomDetails();
    setCurrentImageIndex(0);
    window.scrollTo(0, 0);
    return () => controller.abort();
  }, [navigate, roomId, roomType]);

  const room = useMemo(() => {
    const fallback = FALLBACK_ROOM_TYPES[roomType] || {
      slug: roomId,
      name: 'Room',
      tagline: '',
      description: '',
      capacity: 0,
      bedType: 'N/A',
      size: 'N/A',
      images: [],
      amenities: [],
      suiteHighlights: [],
      hotelServices: [],
    };

    const cmsContent = getRoomContent(roomType || roomTypeDetails?.room_type || '');
    if (!roomTypeDetails) {
      return null;
    }

    const imageCandidates = [
      ...(cmsContent.image ? [cmsContent.image] : []),
      ...(Array.isArray(roomTypeDetails.images) ? roomTypeDetails.images : []),
      ...fallback.images,
    ].filter(Boolean);

    return {
      ...fallback,
      name: roomTypeDetails.name || fallback.name,
      tagline: cmsContent.tagline || fallback.tagline,
      description: String(roomTypeDetails.description || cmsContent.description || fallback.description),
      bedType: roomTypeDetails.bed_type || 'N/A',
      capacity: Number(roomTypeDetails.capacity ?? 0),
      size: roomTypeDetails.size || 'N/A',
      pricePerNight: publishedRate(roomTypeDetails.price_per_night),
      images: imageCandidates,
      amenities: Array.isArray(roomTypeDetails.amenities) && roomTypeDetails.amenities.length > 0
        ? roomTypeDetails.amenities
        : fallback.amenities,
    };
  }, [getRoomContent, roomId, roomType, roomTypeDetails]);

  const relatedRooms = useMemo(() => {
    return TYPE_ORDER
      .filter((type) => type !== roomType)
      .map((type) => {
        const fallback = FALLBACK_ROOM_TYPES[type];
        const cmsContent = getRoomContent(type);
        const details = relatedRoomDetails[type];

        return {
          id: fallback.slug,
          name: cmsContent.label || fallback.name,
          tagline: cmsContent.tagline || fallback.tagline,
          description: cmsContent.description || fallback.description,
          capacity: Number(details?.capacity || fallback.capacity || 2),
          size: fallback.size,
          image: cmsContent.image || details?.images?.[0] || fallback.images[0] || '',
          pricePerNight: publishedRate(details?.price_per_night),
        };
      });
  }, [getRoomContent, relatedRoomDetails, roomType]);

  if (roomTypeNotFound) {
    return (
      <main className="room-detail-page">
        <section className="room-detail-status">
          <div className="room-detail-container">
            <span className="room-detail-eyebrow">Room Collection</span>
            <h1>Room not found</h1>
            <p>The room type you requested is unavailable or does not exist.</p>
            <button type="button" className="room-primary-action" onClick={() => navigate('/rooms')}>
              Back to rooms <ArrowRight size={16} aria-hidden="true" />
            </button>
          </div>
        </section>
      </main>
    );
  }

  if (loadingRoomType || !room) {
    return (
      <main className="room-detail-page">
        <div className="room-detail-loading" role="status" aria-live="polite">
          <span className="room-detail-loading-mark" aria-hidden="true" />
          Preparing your room view...
        </div>
      </main>
    );
  }

  const specs = [
    { label: 'Bed Type', value: room.bedType || 'N/A', icon: BedDouble },
    { label: 'Capacity', value: `Up to ${room.capacity} guests`, icon: Users },
    { label: 'Room Size', value: room.size || 'N/A', icon: Maximize2 },
  ];

  const primaryImage = room.images[currentImageIndex] || room.images[0] || '';
  const nextImageIndex = room.images.length > 1 ? (currentImageIndex + 1) % room.images.length : 0;
  const secondaryImage = room.images[nextImageIndex] || primaryImage;
  const showPreviousImage = () => setCurrentImageIndex((current) => (
    current === 0 ? room.images.length - 1 : current - 1
  ));
  const showNextImage = () => setCurrentImageIndex((current) => (
    current === room.images.length - 1 ? 0 : current + 1
  ));

  return (
    <main className="room-detail-page">
      <section className="room-detail-intro">
        <div className="room-detail-container room-detail-intro-grid">
          <div>
            <button type="button" className="room-back-link" onClick={() => navigate('/rooms')}>
              <ChevronLeft size={15} aria-hidden="true" /> All rooms
            </button>
            <span className="room-detail-eyebrow">H+ Hotel Room Collection</span>
            <h1>{room.name}</h1>
            <p>{room.tagline}</p>
          </div>
          <div className="room-intro-rate">
            {room.pricePerNight !== null && <span>From</span>}
            <strong>{room.pricePerNight !== null ? formatCurrency(room.pricePerNight) : 'Rate unavailable'}</strong>
            {room.pricePerNight !== null && <small>per night</small>}
          </div>
        </div>
      </section>

      <section className="room-gallery-section" aria-label={`${room.name} gallery`}>
        <div className="room-gallery-shell">
          <figure className="room-gallery-primary">
            {primaryImage ? (
              <img src={primaryImage} alt={`${room.name} featured view`} />
            ) : (
              <div className="room-gallery-placeholder">Image coming soon</div>
            )}
            {room.images.length > 1 && (
              <div className="room-gallery-controls">
                <button type="button" onClick={showPreviousImage} aria-label="View previous room photo">
                  <ChevronLeft size={18} aria-hidden="true" />
                </button>
                <span>{String(currentImageIndex + 1).padStart(2, '0')} / {String(room.images.length).padStart(2, '0')}</span>
                <button type="button" onClick={showNextImage} aria-label="View next room photo">
                  <ChevronRight size={18} aria-hidden="true" />
                </button>
              </div>
            )}
          </figure>

          {room.images.length > 1 && (
            <button type="button" className="room-gallery-secondary" onClick={showNextImage} aria-label="View next room photo">
              <img src={secondaryImage} alt={`${room.name} alternate view`} />
              <span>Next view <ArrowRight size={15} aria-hidden="true" /></span>
            </button>
          )}
        </div>

        {room.images.length > 1 && (
          <div className="room-detail-container">
            <div className="room-thumbnail-row">
              {room.images.map((image, index) => (
                <button
                  key={`${image}-${index}`}
                  type="button"
                  className={index === currentImageIndex ? 'is-active' : ''}
                  onClick={() => setCurrentImageIndex(index)}
                  aria-label={`View room photo ${index + 1}`}
                  aria-pressed={index === currentImageIndex}
                >
                  <img src={image} alt="" />
                </button>
              ))}
            </div>
          </div>
        )}
      </section>

      <section className="room-overview-section">
        <div className="room-detail-container room-overview-grid">
          <article className="room-overview-copy">
            <span className="room-detail-eyebrow">About This Room</span>
            <h2>Comfort in every detail.</h2>
            <p>{room.description}</p>

            <div className="room-spec-grid">
              {specs.map(({ label, value, icon: Icon }) => (
                <div key={label} className="room-spec-item">
                  <Icon size={20} strokeWidth={1.6} aria-hidden="true" />
                  <span>{label}</span>
                  <strong>{value}</strong>
                </div>
              ))}
            </div>
          </article>

          <aside className="room-booking-card">
            <span className="room-booking-label">Plan Your Stay</span>
            <div className="room-booking-price">
              <strong>{room.pricePerNight !== null ? formatCurrency(room.pricePerNight) : 'Rate unavailable'}</strong>
              {room.pricePerNight !== null && <span>per night</span>}
            </div>
            <p>Choose your dates to see live availability and the complete stay total.</p>
            <ul>
              <li><ShieldCheck size={17} aria-hidden="true" /> Secure reservation</li>
              <li><Check size={17} aria-hidden="true" /> Transparent pricing</li>
            </ul>
            <button type="button" className="room-primary-action" onClick={() => navigate('/select-room')}>
              Check availability <ArrowRight size={16} aria-hidden="true" />
            </button>
          </aside>
        </div>
      </section>

      <section className="room-facilities-section">
        <div className="room-detail-container">
          <div className="room-section-heading">
            <div>
              <span className="room-detail-eyebrow">Included With Your Stay</span>
              <h2>Room facilities</h2>
            </div>
            <p>Thoughtfully selected essentials for a comfortable stay in the {room.name}.</p>
          </div>

          <div className="room-facility-grid">
            {room.amenities.map((amenity, index) => (
              <div key={`${amenity}-${index}`} className="room-facility-item">
                <span><Check size={15} aria-hidden="true" /></span>
                {amenity}
              </div>
            ))}
          </div>
        </div>
      </section>

      <section className="room-experience-section">
        <div className="room-detail-container room-experience-grid">
          <div className="room-experience-intro">
            <span className="room-detail-eyebrow light">The H+ Experience</span>
            <h2>More than a place to sleep.</h2>
            <p>Simple comforts and attentive service come together for a stay that feels effortless.</p>
            <Star size={28} strokeWidth={1.5} aria-hidden="true" />
          </div>

          <div className="room-experience-list">
            <div>
              <span>01</span>
              <h3>Room highlights</h3>
              <ul>
                {room.suiteHighlights.map((highlight, index) => (
                  <li key={`${highlight}-${index}`}>{highlight}</li>
                ))}
              </ul>
            </div>
            <div>
              <span>02</span>
              <h3>Hotel services</h3>
              <ul>
                {room.hotelServices.map((service, index) => (
                  <li key={`${service}-${index}`}>{service}</li>
                ))}
              </ul>
            </div>
          </div>
        </div>
      </section>

      <section className="related-rooms-section">
        <div className="room-detail-container">
          <div className="room-section-heading">
            <div>
              <span className="room-detail-eyebrow">Continue Exploring</span>
              <h2>Other rooms you may like</h2>
            </div>
            <p>Discover more comfortable stays from the H+ Hotel room collection.</p>
          </div>

          <div className="related-rooms-grid">
            {relatedRooms.map((relatedRoom) => (
              <article key={relatedRoom.id} className="related-room-card">
                <div className="related-room-image-wrap">
                  {relatedRoom.image ? (
                    <img src={relatedRoom.image} alt={relatedRoom.name} className="related-room-image" />
                  ) : (
                    <div className="related-room-placeholder">No Image Available</div>
                  )}
                  <span className="related-room-capacity"><Users size={14} aria-hidden="true" /> Up to {relatedRoom.capacity}</span>
                </div>

                <div className="related-room-content">
                  <span className="related-room-kicker">H+ Room</span>
                  <h3 className="related-room-name">{relatedRoom.name}</h3>
                  <p className="related-room-tagline">{relatedRoom.tagline}</p>

                  <div className="related-room-footer">
                    <div className="related-room-price">
                      {relatedRoom.pricePerNight !== null ? formatCurrency(relatedRoom.pricePerNight) : 'Rate unavailable'}
                      {relatedRoom.pricePerNight !== null && <span> per night</span>}
                    </div>
                    <button
                      type="button"
                      className="view-room-btn"
                      onClick={() => navigate(`/room/${relatedRoom.id}`)}
                    >
                      View room <ArrowRight size={15} aria-hidden="true" />
                    </button>
                  </div>
                </div>
              </article>
            ))}
          </div>
        </div>
      </section>
    </main>
  );
};

export default RoomDetail;

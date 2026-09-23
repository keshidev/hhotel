import React, { useCallback, useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ChevronLeft, ChevronRight, ExternalLink, Images, MapPin, X } from 'lucide-react';
import BookingModal from '../components/BookingModal';
import './Home.css';
import { useCms } from '../context/CmsContext';
import {
  COOKIE_CONSENT_EVENT,
  openCookiePreferences,
  readCookieConsent,
} from '../utils/cookieConsent';
import {
  beginNewBooking,
  getBookingCart,
  resetBookingRecoveryAcknowledgement,
} from '../utils/bookingCart';

const Home = () => {
  const [isBookingModalOpen, setIsBookingModalOpen] = useState(false);
  const [activeGalleryIndex, setActiveGalleryIndex] = useState(null);
  const [activeTestimonialIndex, setActiveTestimonialIndex] = useState(0);
  const [optionalCookiesAllowed, setOptionalCookiesAllowed] = useState(() => readCookieConsent() === 'accepted');
  const testimonialCarouselRef = useRef(null);
  const navigate = useNavigate();
  const { get, highlightsItems, statsItems, testimonialsItems, nearbyItems, galleryItems } = useCms();
  const visibleGalleryItems = galleryItems.slice(0, 5);
  const galleryCount = visibleGalleryItems.length;

  const closeGallery = useCallback(() => setActiveGalleryIndex(null), []);
  const showPreviousGalleryImage = useCallback(() => setActiveGalleryIndex((current) => (
    current === null || galleryCount === 0 ? null : (current - 1 + galleryCount) % galleryCount
  )), [galleryCount]);
  const showNextGalleryImage = useCallback(() => setActiveGalleryIndex((current) => (
    current === null || galleryCount === 0 ? null : (current + 1) % galleryCount
  )), [galleryCount]);

  const handleTestimonialScroll = () => {
    const carousel = testimonialCarouselRef.current;
    if (!carousel) return;

    const cards = Array.from(carousel.children);
    if (cards.length === 0) return;

    const nearestIndex = cards.reduce((closestIndex, card, index) => (
      Math.abs(card.offsetLeft - carousel.scrollLeft) < Math.abs(cards[closestIndex].offsetLeft - carousel.scrollLeft)
        ? index
        : closestIndex
    ), 0);

    setActiveTestimonialIndex(nearestIndex);
  };

  useEffect(() => {
    const updateCookieConsent = () => setOptionalCookiesAllowed(readCookieConsent() === 'accepted');
    window.addEventListener(COOKIE_CONSENT_EVENT, updateCookieConsent);
    window.addEventListener('storage', updateCookieConsent);
    return () => {
      window.removeEventListener(COOKIE_CONSENT_EVENT, updateCookieConsent);
      window.removeEventListener('storage', updateCookieConsent);
    };
  }, []);

  useEffect(() => {
    if (activeGalleryIndex === null) return undefined;

    const handleKeyDown = (event) => {
      if (event.key === 'Escape') closeGallery();
      if (event.key === 'ArrowLeft') showPreviousGalleryImage();
      if (event.key === 'ArrowRight') showNextGalleryImage();
    };

    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    window.addEventListener('keydown', handleKeyDown);

    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [activeGalleryIndex, closeGallery, showNextGalleryImage, showPreviousGalleryImage]);

  const handleBookingProceed = (bookingData) => {
    const incompleteCart = getBookingCart();
    if (incompleteCart?.selectedRooms?.length) {
      resetBookingRecoveryAcknowledgement();
      setIsBookingModalOpen(false);
      navigate('/select-room');
      return;
    }

    const keysToRemove = [
      'guestDetails',
      'bookingReference', 'paymentAccessToken',
    ];
    keysToRemove.forEach((k) => sessionStorage.removeItem(k));
    ['bookingEmail', 'bookingReference', 'bookingId'].forEach((k) => {
      localStorage.removeItem(k);
    });
    beginNewBooking(bookingData);
    navigate('/select-room');
  };

  const goToCmsLink = (cmsKey, fallback, { bookingModalForSelectRoom = false } = {}) => {
    const link = get(cmsKey, fallback) || fallback;
    if (link === '/select-room' && bookingModalForSelectRoom) {
      setIsBookingModalOpen(true);
      return;
    }
    if (String(link).startsWith('/')) {
      navigate(link);
      return;
    }
    window.location.assign(link);
  };

  return (
    <div className="home">
      <section className="hero">
        <div className="hero-container">
          <div className="hero-left">
            <div className="hero-est">- EST. 2024</div>
            <h1 className="hero-title">
              <span className="hero-title-dark">{get('hero_title_line1', 'YOUR COMFORT,')}</span>
              <span className="hero-title-accent">{get('hero_title_line2', 'OUR PRIORITY.')}</span>
            </h1>
            <p className="hero-subtitle">
              {get('hero_subtitle', 'Redefining hotel stays through seamless booking, transparent billing, and rooms built for real comfort.')}
            </p>
            <div className="hero-cta">
              <button className="btn-hero-primary" onClick={() => goToCmsLink('hero_primary_button_link', '/select-room', { bookingModalForSelectRoom: true })}>
                {get('hero_primary_button_text', 'BOOK YOUR STAY')}
              </button>
              <button className="btn-hero-secondary" onClick={() => goToCmsLink('hero_secondary_button_link', '/rooms')}>
                {get('hero_secondary_button_text', 'EXPLORE ROOMS')}
              </button>
            </div>
          </div>
          <div className="hero-right">
            <img
              src={get('hero_image', 'https://lh3.googleusercontent.com/p/AF1QipNZxN7W4PDstBqEwTSr8N6CSDkjxsVjzPgq7rcY=s1360-w1360-h1020-rw')}
              alt="H+ Hotel"
            />
          </div>
        </div>
      </section>

      <section id="about" className="section-about" aria-labelledby="home-about-title">
        <div className="section-container">
          <div className="about-layout">
            <div className="about-visual">
              <img
                src={get('about_image', '/images/Executive%20Suite/executive_2.jpg')}
                alt="Comfortable interior at H+ Hotel"
                loading="lazy"
              />
              <div className="about-established"><span>EST.</span><strong>2024</strong></div>
            </div>
            <div className="about-content">
              <div className="section-label">{get('about_eyebrow', 'ABOUT H+ HOTEL')}</div>
              <h2 id="home-about-title" className="about-title">{get('about_title', 'A STAY BUILT AROUND COMFORT.')}</h2>
              <p className="about-lead">
                {get('about_description', 'H+ Hotel QC offers clean, cozy, and thoughtfully prepared rooms for guests who value comfort, convenience, and straightforward service.')}
              </p>
              <p className="about-copy">
                {get('about_secondary_text', 'Located near key shopping, dining, and entertainment destinations in Quezon City, we make it easy to settle in, recharge, and enjoy the city at your own pace.')}
              </p>
              <div className="about-values" aria-label="H+ Hotel qualities">
                <div><strong>Comfort</strong><span>Thoughtful rooms</span></div>
                <div><strong>Clarity</strong><span>Simple booking</span></div>
                <div><strong>Convenience</strong><span>Prime location</span></div>
              </div>
              <button type="button" className="about-action" onClick={() => navigate('/rooms')}>Explore Our Rooms</button>
            </div>
          </div>
        </div>
      </section>

      <section className="section-highlights">
        <div className="section-container">
          <div className="section-label">WHAT WE OFFER</div>
          <h2 className="highlights-title">{get('highlights_title', 'ENGINEERED FOR YOUR STAY.')}</h2>
          <p className="highlights-sub">
            {get('highlights_sub', 'Designed for comfort, built around convenience. Our system and facilities work together so your stay is effortless from start to finish.')}
          </p>
          <div className="highlights-grid">
            {highlightsItems.map((item, index) => (
              <div key={`${item.title}-${index}`} className="highlight-card">
                <h3 className="highlight-name">{item.title}</h3>
                <p className="highlight-desc">{item.description}</p>
                <div className="highlight-bar" />
              </div>
            ))}
          </div>
        </div>
      </section>

      {visibleGalleryItems.length > 0 && (
        <section className="section-gallery" aria-labelledby="home-gallery-title">
          <div className="section-container">
            <div className="gallery-heading-row">
              <div>
                <div className="section-label">HOTEL GALLERY</div>
                <h2 id="home-gallery-title" className="gallery-title">{get('gallery_title', 'A CLOSER LOOK')}</h2>
              </div>
              <p className="gallery-description">
                {get('gallery_description', 'Step inside our rooms and discover the comfortable details that make every stay feel effortless.')}
              </p>
            </div>
            <div className="gallery-grid">
              {visibleGalleryItems.map((item, index) => (
                <button
                  key={`${item.image}-${index}`}
                  type="button"
                  className={`gallery-item gallery-item-${index + 1}`}
                  onClick={() => setActiveGalleryIndex(index)}
                  aria-label={`View ${item.title || `gallery image ${index + 1}`}`}
                >
                  <img src={item.image} alt={item.alt || item.title || 'H+ Hotel room'} loading="lazy" />
                  <span className="gallery-overlay">
                    <span className="gallery-item-title">{item.title || 'H+ Hotel'}</span>
                    <span className="gallery-view-label"><Images size={15} /> View photo</span>
                  </span>
                </button>
              ))}
            </div>
          </div>
        </section>
      )}

      <section className="section-stats">
        <div className="stats-left">
          <h2 className="stats-heading">{get('stats_heading', 'BUILT FOR EFFICIENCY')}</h2>
          <div className="stats-grid">
            {statsItems.map((item, index) => (
              <div key={`${item.label}-${index}`} className="stat-item">
                <div className="stat-value">{item.value}</div>
                <div className="stat-label">{item.label}</div>
              </div>
            ))}
          </div>
        </div>
        <div className="stats-right">
          <img
            src={get('stats_image', 'https://lh3.googleusercontent.com/aida-public/AB6AXuBNEWxsbCNvFA8r_lgbeAv_BIUow6gwfwREyTkiPzrpfJYnvW2jJx1gpH4jO79kdF2-kuLo3V_pw85RKIKg1UIqAmHt_iyFm77zEbtuN_lXyFH2nKStzBRZDkbuSEXFVH2oXiE6TMeR92y5KlcWvS-p2okYwI_roMbx_33Odi4vTWNFcCg6_j3MRmNzE4bAGoqjhy3kIOc-_0Mu0_ISjW3ap0bFhwxPTb7oSgMzB7uRBwNW8H7_oylVSCpOlEIzGzkpRYmVgxy2qis')}
            alt="H+ Hotel interior"
          />
        </div>
      </section>

      <section className="section-testimonials">
        <div className="section-container">
          <div className="section-label blue">GUEST REVIEWS</div>
          <h2 className="testimonials-title">{get('testimonials_title', 'TRUSTED BY OUR GUESTS.')}</h2>
          {testimonialsItems.length > 0 ? (
            <>
              <div className="testimonials-grid" ref={testimonialCarouselRef} onScroll={handleTestimonialScroll}>
              {testimonialsItems.map((item, index) => (
                <div key={item.id ?? index} className="testimonial-card">
                  <div className="testimonial-stars">{'\u2605'.repeat(item.rating_overall ?? 5)}</div>
                  <p className="testimonial-text">"{item.review}"</p>
                  <div className="testimonial-author">
                    <div className="testimonial-avatar">{(item.guest_name || 'G')[0].toUpperCase()}</div>
                    <div>
                      <div className="testimonial-name">{String(item.guest_name || 'Guest').toUpperCase()}</div>
                      <div className="testimonial-role">{item.role ?? 'VERIFIED GUEST'}</div>
                    </div>
                  </div>
                </div>
              ))}
              </div>
              <div className="testimonial-mobile-controls" aria-label="Guest feedback position">
              <div className="testimonial-dots" aria-hidden="true">
                {testimonialsItems.map((item, index) => (
                  <span
                    key={`testimonial-dot-${item.id ?? index}`}
                    className={index === activeTestimonialIndex ? 'is-active' : ''}
                  />
                ))}
              </div>
              </div>
            </>
          ) : (
            <div className="testimonials-empty" role="status">
              <span className="testimonials-empty-mark" aria-hidden="true">—</span>
              <h3>No guest feedback yet</h3>
              <p>Guest experiences will appear here once they have been reviewed and published.</p>
            </div>
          )}
        </div>
      </section>

      <section className="section-cta">
        <h2 className="cta-title">
          {get('cta_title', 'BOOK YOUR STAY')} <span className="cta-accent">{get('cta_accent', 'TODAY.')}</span>
        </h2>
        <button className="btn-cta" onClick={() => setIsBookingModalOpen(true)}>
          {get('cta_button', 'RESERVE YOUR ROOM')}
        </button>
      </section>

      {nearbyItems.length > 0 && (
        <section className="section-nearby" aria-labelledby="home-nearby-title">
          <div className="section-container">
            <div className="nearby-heading-row">
              <div>
                <div className="section-label">NEARBY PLACES</div>
                <h2 id="home-nearby-title" className="nearby-title">{get('nearby_title', 'EXPLORE THE NEIGHBORHOOD')}</h2>
              </div>
              <p className="nearby-description">
                {get('nearby_description', 'Shopping, dining, entertainment, and city landmarks are within easy reach of H+ Hotel.')}
              </p>
            </div>
            <div className="nearby-grid">
              {nearbyItems.map((item, index) => (
                <a
                  key={`${item.name}-${index}`}
                  className="nearby-card"
                  href={item.map_url || `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(item.name || 'Quezon City')}`}
                  target="_blank"
                  rel="noopener noreferrer"
                  aria-label={`Open ${item.name} in Google Maps`}
                >
                  <span className="nearby-card-media">
                    {item.image ? (
                      <img src={item.image} alt={item.name || 'Nearby destination'} loading="lazy" />
                    ) : (
                      <span className="nearby-card-placeholder" aria-hidden="true">
                        <MapPin size={36} strokeWidth={1.4} />
                      </span>
                    )}
                    <span className="nearby-number">{String(index + 1).padStart(2, '0')}</span>
                  </span>
                  <span className="nearby-card-body">
                    <span className="nearby-category">{item.category}</span>
                    <h3>{item.name}</h3>
                    <p>{item.description}</p>
                    <span className="nearby-map-link">View on map <ExternalLink size={14} /></span>
                  </span>
                </a>
              ))}
            </div>
          </div>
        </section>
      )}

      <section className="section-location">
        <div className="section-container">
          <div className="location-inner">
            <div className="location-content">
              <div className="section-label">FIND US</div>
              <h3 className="location-title">{get('location_title', 'HOW TO GET HERE')}</h3>
              <p className="location-description">
                {get('location_description', 'Located in the vibrant heart of Quezon City, H+ Hotel is easily accessible from major transportation hubs.')}
              </p>
              <div className="location-address">
                <div className="location-address-label">ADDRESS</div>
                <div className="location-address-text">
                  {get('location_address1', 'One Nenita Place 89 Road 1')}<br />
                  {get('location_address2', 'Bagong Pagasa, Quezon City')}<br />
                  {get('location_address3', 'Philippines')}<br />
                  {get('location_contact_number', '+63 917 809 9482')}<br />
                  {get('location_contact_email', 'hhotelsph@gmail.com')}
                </div>
              </div>
            </div>
            <div className="location-map">
              {optionalCookiesAllowed ? (
                <iframe
                  src={get('location_map_url', 'https://maps.google.com/maps?q=One+Nenita+Place+89+Road+1+Bagong+Pagasa+Quezon+City+Philippines&t=&z=17&ie=UTF8&iwloc=&output=embed')}
                  width="100%"
                  height="100%"
                  style={{ border: 0 }}
                  allowFullScreen=""
                  loading="lazy"
                  referrerPolicy="no-referrer-when-downgrade"
                  title="H+ Hotel Location"
                />
              ) : (
                <div className="location-map-consent">
                  <MapPin size={30} />
                  <strong>Optional map content is disabled</strong>
                  <p>Accept optional cookies to load the embedded Google map.</p>
                  <button type="button" onClick={openCookiePreferences}>Review Cookie Preferences</button>
                  <a href="https://www.google.com/maps/search/?api=1&query=One+Nenita+Place+89+Road+1+Bagong+Pagasa+Quezon+City+Philippines" target="_blank" rel="noopener noreferrer">Open Google Maps <ExternalLink size={14} /></a>
                </div>
              )}
            </div>
          </div>
        </div>
      </section>

      <BookingModal
        isOpen={isBookingModalOpen}
        onClose={() => setIsBookingModalOpen(false)}
        onProceed={handleBookingProceed}
      />

      {activeGalleryIndex !== null && visibleGalleryItems[activeGalleryIndex] && (
        <div className="gallery-lightbox" role="dialog" aria-modal="true" aria-label="Hotel gallery viewer" onClick={closeGallery}>
          <button type="button" className="gallery-lightbox-close" onClick={closeGallery} aria-label="Close gallery">
            <X size={24} />
          </button>
          {visibleGalleryItems.length > 1 && (
            <button type="button" className="gallery-lightbox-nav gallery-lightbox-prev" onClick={(event) => { event.stopPropagation(); showPreviousGalleryImage(); }} aria-label="Previous photo">
              <ChevronLeft size={30} />
            </button>
          )}
          <figure className="gallery-lightbox-content" onClick={(event) => event.stopPropagation()}>
            <img
              src={visibleGalleryItems[activeGalleryIndex].image}
              alt={visibleGalleryItems[activeGalleryIndex].alt || visibleGalleryItems[activeGalleryIndex].title || 'H+ Hotel room'}
            />
            <figcaption>
              <span>{visibleGalleryItems[activeGalleryIndex].title || 'H+ Hotel'}</span>
              <small>{activeGalleryIndex + 1} / {visibleGalleryItems.length}</small>
            </figcaption>
          </figure>
          {visibleGalleryItems.length > 1 && (
            <button type="button" className="gallery-lightbox-nav gallery-lightbox-next" onClick={(event) => { event.stopPropagation(); showNextGalleryImage(); }} aria-label="Next photo">
              <ChevronRight size={30} />
            </button>
          )}
        </div>
      )}
    </div>
  );
};

export default Home;

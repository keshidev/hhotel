import { useEffect, useState } from 'react';
import { CalendarDays, Check, Copy, Gift, Tag } from 'lucide-react';
import { Link, useNavigate } from 'react-router-dom';
import api from '../services/api';
import './SpecialOffersPage.css';

const formatDate = (value) => {
  if (!value) return '';

  return new Intl.DateTimeFormat('en-PH', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    timeZone: 'UTC',
  }).format(new Date(`${value}T00:00:00Z`));
};

const formatAmount = (value) => new Intl.NumberFormat('en-PH', {
  style: 'currency',
  currency: 'PHP',
  maximumFractionDigits: Number(value) % 1 === 0 ? 0 : 2,
}).format(Number(value) || 0);

const discountLabel = (offer) => (
  offer.discount_type === 'percentage'
    ? `${Number(offer.discount_value).toLocaleString('en-PH')}% OFF`
    : `${formatAmount(offer.discount_value)} OFF`
);

const offerConditions = (offer) => {
  const conditions = [];

  if (offer.min_nights > 1) conditions.push(`Minimum ${offer.min_nights}-night stay`);
  if (offer.max_nights) conditions.push(`Maximum ${offer.max_nights}-night stay`);
  if (offer.booking_start_date && offer.booking_end_date) {
    conditions.push(`Stay from ${formatDate(offer.booking_start_date)} to ${formatDate(offer.booking_end_date)}`);
  } else if (offer.booking_start_date) {
    conditions.push(`Check in on or after ${formatDate(offer.booking_start_date)}`);
  } else if (offer.booking_end_date) {
    conditions.push(`Check in on or before ${formatDate(offer.booking_end_date)}`);
  }
  if (offer.max_discount_amount) conditions.push(`Maximum discount ${formatAmount(offer.max_discount_amount)}`);

  return conditions;
};

export default function SpecialOffersPage() {
  const navigate = useNavigate();
  const [offers, setOffers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [copiedCode, setCopiedCode] = useState('');

  const loadOffers = async () => {
    setLoading(true);
    setError('');

    try {
      const response = await api.get('/client/promo-codes/offers');
      setOffers(Array.isArray(response.data?.data) ? response.data.data : []);
    } catch {
      setError('Special offers could not be loaded right now. Please try again.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    const previousTitle = document.title;
    document.title = 'Special Offers | H+ Hotel';
    loadOffers();

    return () => {
      document.title = previousTitle;
    };
  }, []);

  const copyCode = async (code) => {
    try {
      await navigator.clipboard.writeText(code);
      setCopiedCode(code);
      window.setTimeout(() => setCopiedCode(''), 1800);
    } catch {
      setCopiedCode('');
    }
  };

  const bookOffer = (code) => {
    sessionStorage.setItem('promoCode', code);
    sessionStorage.removeItem('promoResult');
    navigate('/booking');
  };

  return (
    <main className="offers-page">
      <section className="offers-hero" aria-labelledby="offers-page-title">
        <div className="offers-shell offers-hero-content">
          <div className="offers-hero-icon" aria-hidden="true"><Gift size={30} /></div>
          <p className="offers-eyebrow">More Value, Same Comfort</p>
          <h1 id="offers-page-title">Special Offers</h1>
          <p>Explore currently available hotel promotions and apply the offer code when selecting your room.</p>
        </div>
      </section>

      <section className="offers-shell offers-content" aria-live="polite">
        <div className="offers-section-heading">
          <div>
            <p className="offers-eyebrow">Available Now</p>
            <h2>Offers for your next stay</h2>
          </div>
          <p>Availability and final savings are verified against your selected dates, rooms, and stay details during booking.</p>
        </div>

        {loading && (
          <div className="offers-grid" aria-label="Loading special offers">
            {[1, 2, 3].map((item) => <div className="offer-card offer-card-loading" key={item} />)}
          </div>
        )}

        {!loading && error && (
          <div className="offers-state">
            <Gift size={34} aria-hidden="true" />
            <h2>We could not load the offers.</h2>
            <p>{error}</p>
            <button type="button" onClick={loadOffers}>Try Again</button>
          </div>
        )}

        {!loading && !error && offers.length === 0 && (
          <div className="offers-state">
            <Gift size={34} aria-hidden="true" />
            <h2>No special offers are active today.</h2>
            <p>You can still view current room availability and standard rates.</p>
            <Link to="/booking">Browse Available Rooms</Link>
          </div>
        )}

        {!loading && !error && offers.length > 0 && (
          <div className="offers-grid">
            {offers.map((offer) => {
              const conditions = offerConditions(offer);

              return (
                <article className="offer-card" key={offer.id}>
                  <div className="offer-card-topline">
                    <span><Tag size={16} /> Limited offer</span>
                    <small>Ends {formatDate(offer.end_date)}</small>
                  </div>
                  <div className="offer-discount">{discountLabel(offer)}</div>
                  <h2>{offer.name}</h2>
                  <p className="offer-description">{offer.description || 'Enjoy special savings on an eligible H+ Hotel reservation.'}</p>

                  {conditions.length > 0 && (
                    <ul className="offer-conditions">
                      {conditions.map((condition) => <li key={condition}><Check size={15} /> {condition}</li>)}
                    </ul>
                  )}

                  <div className="offer-validity">
                    <CalendarDays size={17} aria-hidden="true" />
                    <span>Book by {formatDate(offer.end_date)}</span>
                  </div>

                  <div className="offer-code-row">
                    <div>
                      <small>Offer code</small>
                      <strong>{offer.code}</strong>
                    </div>
                    <button type="button" onClick={() => copyCode(offer.code)} aria-label={`Copy offer code ${offer.code}`}>
                      {copiedCode === offer.code ? <Check size={18} /> : <Copy size={18} />}
                      {copiedCode === offer.code ? 'Copied' : 'Copy'}
                    </button>
                  </div>

                  <button className="offer-book-button" type="button" onClick={() => bookOffer(offer.code)}>
                    Book This Offer
                  </button>
                </article>
              );
            })}
          </div>
        )}
      </section>
    </main>
  );
}

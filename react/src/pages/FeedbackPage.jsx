import React, { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { Star, CheckCircle, AlertCircle, Loader2, ThumbsUp, ThumbsDown, ChevronDown } from 'lucide-react';
import Header from '../components/Header';
import Footer from '../components/Footer';
import './FeedbackPage.css';

const API = (import.meta.env.VITE_API_URL || 'https://hhotelbooking.com/api') + '/client';

const ISSUE_TYPES = [
  'Room Cleanliness',
  'Noise / Disturbance',
  'Air Conditioning / Heating',
  'Plumbing / Hot Water',
  'Wi-Fi / TV / Facilities',
  'Staff Behaviour',
  'Food / Room Service',
  'Safety Concern',
  'Other',
];

// ── Star Rating Component ──────────────────────────────────────────────────
const StarRating = ({ value, onChange, disabled = false }) => {
  const [hovered, setHovered] = useState(0);

  return (
    <div className="fb-stars" onMouseLeave={() => setHovered(0)}>
      {[1, 2, 3, 4, 5].map((star) => (
        <button
          key={star}
          type="button"
          className={`fb-star ${(hovered || value) >= star ? 'fb-star--active' : ''}`}
          onMouseEnter={() => !disabled && setHovered(star)}
          onClick={() => !disabled && onChange(star)}
          disabled={disabled}
          aria-label={`Rate ${star} out of 5`}
        >
          <Star size={28} fill={(hovered || value) >= star ? '#F5A623' : 'none'} strokeWidth={1.5} />
        </button>
      ))}
    </div>
  );
};

// ── Rating Row ─────────────────────────────────────────────────────────────
const RatingRow = ({ label, value, onChange, disabled }) => (
  <div className="fb-rating-row">
    <span className="fb-rating-label">{label}</span>
    <StarRating value={value} onChange={onChange} disabled={disabled} />
  </div>
);

// ─────────────────────────────────────────────────────────────────────────────

export default function FeedbackPage() {
  const { token } = useParams();
  const navigate  = useNavigate();

  const [pageState, setPageState]           = useState('loading');
  const [bookingInfo, setBookingInfo]       = useState(null);
  const [existingFeedback, setExistingFeedback] = useState(null);
  const [submitting, setSubmitting]         = useState(false);
  const [errorMsg, setErrorMsg]             = useState('');

  const [ratings, setRatings] = useState({
    cleanliness: 0,
    comfort:     0,
    staff:       0,
    facilities:  0,
    overall:     0,
  });
  const [review,         setReview]         = useState('');
  const [hasIssue,       setHasIssue]       = useState(false);
  const [issueType,      setIssueType]      = useState('');
  const [issueOther,     setIssueOther]     = useState('');
  const [wouldRecommend, setWouldRecommend] = useState(null);
  const [errors,         setErrors]         = useState({});

  // ── Auto-calculate overall from 4 categories ──────────────────────────────
  useEffect(() => {
    const { cleanliness, comfort, staff, facilities } = ratings;
    const filled = [cleanliness, comfort, staff, facilities].filter(v => v > 0);
    if (filled.length === 0) return;
    const avg = Math.round(filled.reduce((a, b) => a + b, 0) / filled.length);
    setRatings(r => ({ ...r, overall: avg }));
  }, [ratings.cleanliness, ratings.comfort, ratings.staff, ratings.facilities]);

  // ── Load token ────────────────────────────────────────────────────────────
  useEffect(() => {
    const load = async () => {
      try {
        const res  = await fetch(`${API}/feedback/${token}`);
        const data = await res.json();

        if (!res.ok) {
          setPageState('error');
          setErrorMsg(data.message || 'Invalid or expired feedback link.');
          return;
        }

        if (data.already_done) {
          setExistingFeedback(data.feedback);
          setPageState('already_done');
          return;
        }

        setBookingInfo(data.booking);
        setPageState('form');
      } catch {
        setPageState('error');
        setErrorMsg('Could not load feedback form. Please try again.');
      }
    };
    load();
  }, [token]);

  // ── Validation ────────────────────────────────────────────────────────────
  const validate = () => {
    const errs = {};
    if (!ratings.cleanliness) errs.cleanliness = true;
    if (!ratings.comfort)     errs.comfort     = true;
    if (!ratings.staff)       errs.staff       = true;
    if (!ratings.facilities)  errs.facilities  = true;
    if (!ratings.overall)     errs.overall     = true;
    if (hasIssue && !issueType) errs.issueType = true;
    if (hasIssue && issueType === 'Other' && !issueOther.trim()) errs.issueOther = true;
    setErrors(errs);
    return Object.keys(errs).length === 0;
  };

  // ── Submit ────────────────────────────────────────────────────────────────
  const handleSubmit = async () => {
    if (!validate()) {
      window.scrollTo({ top: 0, behavior: 'smooth' });
      return;
    }

    setSubmitting(true);
    try {
      const res = await fetch(`${API}/feedback/${token}`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          rating_cleanliness: ratings.cleanliness,
          rating_comfort:     ratings.comfort,
          rating_staff:       ratings.staff,
          rating_facilities:  ratings.facilities,
          rating_overall:     ratings.overall,
          review:             review.trim() || null,
          has_issue:          hasIssue,
          issue_type:         hasIssue
                                ? (issueType === 'Other' ? `Other: ${issueOther.trim()}` : issueType)
                                : null,
          would_recommend:    wouldRecommend,
        }),
      });

      const data = await res.json();

      if (data.already_done) {
        setPageState('already_done');
        return;
      }

      if (!res.ok) {
        setErrorMsg(data.message || 'Submission failed. Please try again.');
        return;
      }

      setPageState('submitted');
    } catch {
      setErrorMsg('Network error. Please check your connection.');
    } finally {
      setSubmitting(false);
    }
  };

  // ── Format date for display ───────────────────────────────────────────────
  const fmtDate = (str) => {
    if (!str) return 'N/A';
    const d = new Date(str);
    if (isNaN(d)) return str;
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  };

  // ─────────────────────────────────────────────────────────────────────────
  // LOADING
  // ─────────────────────────────────────────────────────────────────────────
  if (pageState === 'loading') {
    return (
      <>
        <Header />
        <div className="fb-page">
          <div className="fb-card fb-card--center">
            <Loader2 size={36} className="fb-spinner" />
            <p className="fb-muted">Loading your feedback form…</p>
          </div>
        </div>
        <Footer />
      </>
    );
  }

  // ─────────────────────────────────────────────────────────────────────────
  // ERROR
  // ─────────────────────────────────────────────────────────────────────────
  if (pageState === 'error') {
    return (
      <>
        <Header />
        <div className="fb-page">
          <div className="fb-card fb-card--center">
            <AlertCircle size={40} style={{ color: '#ef4444', marginBottom: '1rem' }} />
            <h2 className="fb-heading">Link Unavailable</h2>
            <p className="fb-muted">{errorMsg}</p>
            <button className="fb-btn fb-btn--primary" onClick={() => navigate('/')}>Go to Homepage</button>
          </div>
        </div>
        <Footer />
      </>
    );
  }

  // ─────────────────────────────────────────────────────────────────────────
  // ALREADY SUBMITTED
  // ─────────────────────────────────────────────────────────────────────────
  if (pageState === 'already_done') {
    return (
      <>
        <Header />
        <div className="fb-page">
          <div className="fb-card fb-card--center">
            <CheckCircle size={48} style={{ color: '#1a4bcc', marginBottom: '1rem' }} />
            <h2 className="fb-heading">Feedback Already Submitted</h2>
            <p className="fb-muted">You have already submitted feedback for this booking. Thank you!</p>
            {existingFeedback?.rating_overall && (
              <div className="fb-submitted-summary">
                <div className="fb-submitted-stars">
                  {[1,2,3,4,5].map((s) => (
                    <Star key={s} size={24} fill={s <= existingFeedback.rating_overall ? '#F5A623 ' : 'none'} strokeWidth={1.5} style={{ color: '#F5A623' }} />
                  ))}
                </div>
                {existingFeedback.review && <p className="fb-submitted-review">"{existingFeedback.review}"</p>}
              </div>
            )}
            <button className="fb-btn fb-btn--primary" style={{ marginTop: '1.5rem' }} onClick={() => navigate('/')}>Back to Homepage</button>
          </div>
        </div>
        <Footer />
      </>
    );
  }

  // ─────────────────────────────────────────────────────────────────────────
  // THANK YOU
  // ─────────────────────────────────────────────────────────────────────────
  if (pageState === 'submitted') {
    return (
      <>
        <Header />
        <div className="fb-page">
          <div className="fb-card fb-card--center">
            <div className="fb-success-icon">
              <CheckCircle size={48} />
            </div>
            <h2 className="fb-heading">Thank You!</h2>
            <p className="fb-muted" style={{ maxWidth: 360 }}>
              Your feedback has been received. We appreciate you taking the time to share your experience and look forward to welcoming you again.
            </p>
            <div className="fb-divider" />
            <div className="fb-submitted-stars">
              {[1,2,3,4,5].map((s) => (
                <Star key={s} size={26} fill={s <= ratings.overall ? '#F5A623 ' : 'none'} strokeWidth={1.5} style={{ color: '#F5A623' }} />
              ))}
            </div>
            <button className="fb-btn fb-btn--primary" style={{ marginTop: '2rem' }} onClick={() => navigate('/')}>Back to Homepage</button>
          </div>
        </div>
        <Footer />
      </>
    );
  }

  // ─────────────────────────────────────────────────────────────────────────
  // FORM
  // ─────────────────────────────────────────────────────────────────────────
  const hasErrors = Object.keys(errors).length > 0;

  return (
    <>
      <Header />
      <div className="fb-page">
        <div className="fb-container">
          {/* Title */}
          <div className="fb-hero">
            <h1 className="fb-hero-title">We Value Your Feedback</h1>
            <p className="fb-hero-sub">Help us improve your stay experience</p>
          </div>

          {/* Booking info strip */}
          {bookingInfo && (
            <div className="fb-booking-strip">
              <div className="fb-strip-item">
                <span className="fb-strip-label">Guest</span>
                <span className="fb-strip-value">{bookingInfo.guest_name}</span>
              </div>
              <div className="fb-strip-item">
                <span className="fb-strip-label">Room</span>
                <span className="fb-strip-value">{bookingInfo.room}</span>
              </div>
              <div className="fb-strip-item">
                <span className="fb-strip-label">Stay Duration</span>
                <span className="fb-strip-value">
                  {bookingInfo.stay_type === 'day_use'
                    ? `${fmtDate(bookingInfo.check_in)} · Day Use`
                    : `${fmtDate(bookingInfo.check_in)} – ${fmtDate(bookingInfo.check_out)}`
                  }
                </span>
              </div>
            </div>
          )}

          {/* Error banner */}
          {hasErrors && (
            <div className="fb-error-banner">
              <AlertCircle size={16} />
              <span>Please complete all required fields before submitting.</span>
            </div>
          )}

          {/* Ratings card */}
          <div className="fb-card">
            <h2 className="fb-section-title">Experience Rating</h2>

            <RatingRow
              label="Cleanliness"
              value={ratings.cleanliness}
              onChange={(v) => setRatings((r) => ({ ...r, cleanliness: v }))}
            />
            {errors.cleanliness && <p className="fb-field-error">Required</p>}

            <RatingRow
              label="Comfort"
              value={ratings.comfort}
              onChange={(v) => setRatings((r) => ({ ...r, comfort: v }))}
            />
            {errors.comfort && <p className="fb-field-error">Required</p>}

            <RatingRow
              label="Staff Service"
              value={ratings.staff}
              onChange={(v) => setRatings((r) => ({ ...r, staff: v }))}
            />
            {errors.staff && <p className="fb-field-error">Required</p>}

            <RatingRow
              label="Facilities"
              value={ratings.facilities}
              onChange={(v) => setRatings((r) => ({ ...r, facilities: v }))}
            />
            {errors.facilities && <p className="fb-field-error">Required</p>}

            <div className="fb-divider" />

            <RatingRow
              label={<strong>Overall Experience</strong>}
              value={ratings.overall}
              onChange={() => {}}
              disabled={true}
            />
            {errors.overall && <p className="fb-field-error">Please rate all categories above</p>}
          </div>

          {/* Review card */}
          <div className="fb-card">
            <h2 className="fb-section-title">Tell Us About Your Experience</h2>
            <textarea
              className="fb-textarea"
              rows={5}
              placeholder="Share your thoughts with us…"
              value={review}
              onChange={(e) => setReview(e.target.value)}
              maxLength={2000}
            />
            <p className="fb-char-count">{review.length} / 2000</p>
          </div>

          {/* Issue report card */}
          <div className="fb-card">
            <label className="fb-checkbox-row">
              <input
                type="checkbox"
                className="fb-checkbox"
                checked={hasIssue}
                onChange={(e) => {
                  setHasIssue(e.target.checked);
                  if (!e.target.checked) {
                    setIssueType('');
                    setIssueOther('');
                  }
                }}
              />
              <span>Report an issue during stay</span>
            </label>

            {hasIssue && (
              <>
                <div className="fb-select-wrap" style={{ marginTop: '1rem' }}>
                  <select
                    className="fb-select"
                    value={issueType}
                    onChange={(e) => {
                      setIssueType(e.target.value);
                      setIssueOther('');
                    }}
                  >
                    <option value="">Select type of issue</option>
                    {ISSUE_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
                  </select>
                  <ChevronDown size={16} className="fb-select-icon" />
                  {errors.issueType && <p className="fb-field-error">Please select an issue type</p>}
                </div>

                {issueType === 'Other' && (
                  <div style={{ marginTop: '0.75rem' }}>
                    <input
                      type="text"
                      className="fb-input"
                      placeholder="Please specify the issue…"
                      value={issueOther}
                      onChange={(e) => setIssueOther(e.target.value)}
                      maxLength={200}
                    />
                    {errors.issueOther && <p className="fb-field-error">Please specify the issue</p>}
                  </div>
                )}
              </>
            )}
          </div>

          {/* Recommend card */}
          <div className="fb-card">
            <div className="fb-recommend-row">
              <span className="fb-recommend-label">Would you recommend us to others?</span>
              <div className="fb-recommend-btns">
                <button
                  type="button"
                  className={`fb-recommend-btn ${wouldRecommend === true ? 'fb-recommend-btn--yes' : ''}`}
                  onClick={() => setWouldRecommend(wouldRecommend === true ? null : true)}
                >
                  <ThumbsUp size={16} /> Yes
                </button>
                <button
                  type="button"
                  className={`fb-recommend-btn ${wouldRecommend === false ? 'fb-recommend-btn--no' : ''}`}
                  onClick={() => setWouldRecommend(wouldRecommend === false ? null : false)}
                >
                  <ThumbsDown size={16} /> No
                </button>
              </div>
            </div>
          </div>

          {/* Submit */}
          {errorMsg && (
            <div className="fb-error-banner">
              <AlertCircle size={16} />
              <span>{errorMsg}</span>
            </div>
          )}

          <button
            className="fb-btn fb-btn--submit"
            onClick={handleSubmit}
            disabled={submitting}
          >
            {submitting
              ? <><Loader2 size={18} className="fb-spinner-sm" /> Submitting…</>
              : 'Submit Feedback →'
            }
          </button>

          <p className="fb-footer-note">
            © {new Date().getFullYear()} H+ Hotel &nbsp;·&nbsp; Powered by H+ Hotel PMS
          </p>
        </div>
      </div>
      <Footer />
    </>
  );
}
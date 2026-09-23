import { useState } from 'react';
import {
  CheckCircle,
  Facebook,
  Instagram,
  Mail,
  MapPin,
  Phone,
  Send,
  Twitter,
} from 'lucide-react';
import { useCms } from '../context/CmsContext';
import api from '../services/api';
import './ContactPageContent.css';

const emptyContactForm = {
  firstName: '',
  lastName: '',
  email: '',
  phone: '',
  bookingReference: '',
  subject: 'general',
  message: '',
  website: '',
};

async function getContactCaptchaToken() {
  const siteKey = import.meta.env.VITE_RECAPTCHA_SITE_KEY;
  if (!siteKey) return null;

  if (!window.grecaptcha) {
    await new Promise((resolve, reject) => {
      const existing = document.querySelector('script[data-contact-recaptcha]');
      if (existing) {
        let attempts = 0;
        const waitForCaptcha = window.setInterval(() => {
          attempts += 1;
          if (window.grecaptcha) {
            window.clearInterval(waitForCaptcha);
            resolve();
          } else if (attempts >= 100) {
            window.clearInterval(waitForCaptcha);
            reject(new Error('Security check did not load.'));
          }
        }, 100);
        return;
      }

      const script = document.createElement('script');
      script.src = `https://www.google.com/recaptcha/api.js?render=${encodeURIComponent(siteKey)}`;
      script.async = true;
      script.dataset.contactRecaptcha = 'true';
      script.onload = resolve;
      script.onerror = reject;
      document.head.appendChild(script);
    });
  }

  return new Promise((resolve, reject) => {
    window.grecaptcha.ready(() => {
      window.grecaptcha
        .execute(siteKey, { action: 'contact_submit' })
        .then(resolve)
        .catch(reject);
    });
  });
}

export default function ContactPage() {
  const { get } = useCms();
  const [form, setForm] = useState(emptyContactForm);
  const [submitted, setSubmitted] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState('');
  const [referenceNumber, setReferenceNumber] = useState('');

  const contactInfo = {
    phone: get('location_contact_number', '+63 917 809 9482'),
    phoneHref: String(get('location_contact_number', '+639178099482')).replace(/[^+\d]/g, ''),
    email: get('location_contact_email', 'hhotelsph@gmail.com'),
    addressLine1: get('location_address1', 'One Nenita Place 89 Road 1'),
    addressLine2: get('location_address2', 'Bagong Pagasa, Quezon City'),
    addressLine3: get('location_address3', 'Philippines'),
  };

  const subjects = [
    { value: 'general', label: 'General Inquiry' },
    { value: 'reservation', label: 'Reservation' },
    { value: 'billing', label: 'Billing' },
    { value: 'feedback', label: 'Feedback' },
  ];

  const updateForm = (field, value) => {
    setForm((current) => ({ ...current, [field]: value }));
  };

  const resetForm = () => {
    setSubmitted(false);
    setReferenceNumber('');
    setSubmitError('');
    setForm(emptyContactForm);
  };

  const handleSubmit = async (event) => {
    event.preventDefault();
    if (submitting) return;

    setSubmitting(true);
    setSubmitError('');

    try {
      const captchaToken = await getContactCaptchaToken();
      const response = await api.post('/client/contact-inquiries', {
        first_name: form.firstName.trim(),
        last_name: form.lastName.trim(),
        email: form.email.trim(),
        phone: form.phone.trim() || null,
        booking_reference: form.bookingReference.trim() || null,
        subject: form.subject,
        message: form.message.trim(),
        website: form.website,
        captcha_token: captchaToken,
      });

      setReferenceNumber(response.data?.data?.reference_number || '');
      setSubmitted(true);
    } catch (error) {
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors).flat()[0] : null;
      setSubmitError(
        firstError
          || error.response?.data?.message
          || 'Your message could not be sent. Please try again.',
      );
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <main className="contact-page">
      <section className="contact-hero">
        <div className="contact-shell contact-hero-grid">
          <div className="contact-hero-copy">
            <p className="contact-kicker">Contact H+ Hotel</p>
            <h1 className="contact-title">
              <span>Let&apos;s plan</span>
              <span className="contact-title-accent">your stay.</span>
            </h1>
            <p className="contact-intro">
              Questions about a room, an existing reservation, or your hotel experience?
              Send us the details and our team will take it from here.
            </p>
          </div>

          <div className="contact-hero-note" aria-label="Contact response information">
            <span className="contact-note-number">01</span>
            <div>
              <strong>Direct hotel support</strong>
              <p>Every message is saved with a reference number for easy follow-up.</p>
            </div>
          </div>
        </div>
      </section>

      <section className="contact-workspace">
        <div className="contact-shell">
          <div className="contact-card">
            <aside className="contact-info-panel">
              <div>
                <p className="contact-panel-label">Direct contact</p>
                <h2>We&apos;re ready when you are.</h2>
                <p className="contact-panel-intro">
                  Reach the hotel directly or use the message form for a trackable inquiry.
                </p>
              </div>

              <address className="contact-info-list">
                <a className="contact-info-item" href={`tel:${contactInfo.phoneHref}`}>
                  <span className="contact-info-icon"><Phone size={18} /></span>
                  <span>
                    <small>Call us</small>
                    <strong>{contactInfo.phone}</strong>
                  </span>
                </a>

                <a className="contact-info-item" href={`mailto:${contactInfo.email}`}>
                  <span className="contact-info-icon"><Mail size={18} /></span>
                  <span>
                    <small>Email us</small>
                    <strong>{contactInfo.email}</strong>
                  </span>
                </a>

                <div className="contact-info-item">
                  <span className="contact-info-icon"><MapPin size={18} /></span>
                  <span>
                    <small>Visit us</small>
                    <strong>
                      {contactInfo.addressLine1}<br />
                      {contactInfo.addressLine2}<br />
                      {contactInfo.addressLine3}
                    </strong>
                  </span>
                </div>
              </address>

              <div className="contact-socials" aria-label="Hotel social links">
                <a href="https://instagram.com" target="_blank" rel="noreferrer" aria-label="Instagram">
                  <Instagram size={16} />
                </a>
                <a href="https://facebook.com" target="_blank" rel="noreferrer" aria-label="Facebook">
                  <Facebook size={16} />
                </a>
                <a href="https://twitter.com" target="_blank" rel="noreferrer" aria-label="Twitter">
                  <Twitter size={16} />
                </a>
              </div>
            </aside>

            <div className="contact-form-panel">
              {submitted ? (
                <div className="contact-success">
                  <div className="contact-success-icon">
                    <CheckCircle size={34} />
                  </div>
                  <p className="contact-panel-label">Inquiry submitted</p>
                  <h2>Message received.</h2>
                  <p>
                    Your message is saved for the hotel team. Keep this inquiry reference
                    for follow-up.
                  </p>
                  {referenceNumber && (
                    <div className="contact-reference">{referenceNumber}</div>
                  )}
                  <button type="button" className="contact-secondary-btn" onClick={resetForm}>
                    Send another message
                  </button>
                </div>
              ) : (
                <>
                  <div className="contact-form-heading">
                    <p className="contact-panel-label">Message form</p>
                    <h2>How can we help?</h2>
                    <p>Complete the form below. Required fields are marked clearly.</p>
                  </div>

                  <form onSubmit={handleSubmit}>
                    <div className="contact-form-grid">
                      <label className="contact-field">
                        <span>First name</span>
                        <input
                          type="text"
                          autoComplete="given-name"
                          placeholder="John"
                          value={form.firstName}
                          onChange={(event) => updateForm('firstName', event.target.value)}
                          maxLength={80}
                          required
                        />
                      </label>

                      <label className="contact-field">
                        <span>Last name</span>
                        <input
                          type="text"
                          autoComplete="family-name"
                          placeholder="Doe"
                          value={form.lastName}
                          onChange={(event) => updateForm('lastName', event.target.value)}
                          maxLength={80}
                          required
                        />
                      </label>

                      <label className="contact-field">
                        <span>Email address</span>
                        <input
                          type="email"
                          autoComplete="email"
                          placeholder="john@example.com"
                          value={form.email}
                          onChange={(event) => updateForm('email', event.target.value)}
                          maxLength={255}
                          required
                        />
                      </label>

                      <label className="contact-field">
                        <span>Phone number <em>Optional</em></span>
                        <input
                          type="tel"
                          autoComplete="tel"
                          placeholder="+63 917 809 9482"
                          value={form.phone}
                          onChange={(event) => updateForm('phone', event.target.value)}
                          maxLength={30}
                        />
                      </label>

                      <label className="contact-field contact-field-full">
                        <span>Booking reference <em>Optional</em></span>
                        <input
                          type="text"
                          placeholder="Add it if your message is about a booking"
                          value={form.bookingReference}
                          onChange={(event) => updateForm('bookingReference', event.target.value)}
                          maxLength={40}
                        />
                      </label>

                      <fieldset className="contact-field contact-field-full">
                        <legend>Select subject</legend>
                        <div className="contact-subject-row">
                          {subjects.map((subject) => (
                            <button
                              type="button"
                              key={subject.value}
                              className={`contact-chip${form.subject === subject.value ? ' active' : ''}`}
                              aria-pressed={form.subject === subject.value}
                              onClick={() => updateForm('subject', subject.value)}
                            >
                              <span />
                              {subject.label}
                            </button>
                          ))}
                        </div>
                      </fieldset>

                      <label className="contact-field contact-field-full">
                        <span>Message</span>
                        <textarea
                          placeholder="Tell us how we can help..."
                          rows={5}
                          value={form.message}
                          onChange={(event) => updateForm('message', event.target.value)}
                          minLength={10}
                          maxLength={3000}
                          required
                        />
                      </label>

                      <div className="contact-honeypot" aria-hidden="true">
                        <label>
                          Website
                          <input
                            tabIndex="-1"
                            autoComplete="off"
                            value={form.website}
                            onChange={(event) => updateForm('website', event.target.value)}
                          />
                        </label>
                      </div>
                    </div>

                    {submitError && (
                      <div className="contact-submit-error" role="alert">
                        {submitError}
                      </div>
                    )}

                    <div className="contact-submit-row">
                      <p>Protected by reCAPTCHA</p>
                      <button type="submit" className="contact-submit-btn" disabled={submitting}>
                        {submitting ? 'Sending...' : 'Send message'}
                        <Send size={16} strokeWidth={2.2} />
                      </button>
                    </div>
                  </form>
                </>
              )}
            </div>
          </div>
        </div>
      </section>
    </main>
  );
}

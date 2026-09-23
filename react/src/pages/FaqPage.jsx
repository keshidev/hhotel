import { useEffect, useMemo, useState } from 'react';
import { ChevronDown, HelpCircle, Mail, MessageCircle, Phone, Search } from 'lucide-react';
import { Link } from 'react-router-dom';
import { useCms } from '../context/CmsContext';
import './FaqPage.css';

const categories = ['All', 'Reservations', 'Payments', 'Your Stay', 'Changes'];

const buildFaqItems = (get) => [
  {
    category: 'Reservations',
    question: 'How do I book a room online?',
    answer: 'Select your stay dates and number of guests, choose an available room, enter the required guest details, and continue directly to the GCash payment page. Review the booking summary before submitting your reservation.',
  },
  {
    category: 'Reservations',
    question: 'How will I know that my reservation is confirmed?',
    answer: 'A successful reservation displays a confirmation page and sends the booking details to the email address you provided. Keep your reservation reference number because you will need it to view or manage the booking.',
  },
  {
    category: 'Reservations',
    question: 'How can I find my existing reservation?',
    answer: 'Open My Bookings and enter the reservation reference number together with the email address used during booking. The reference number is included in your booking confirmation email.',
  },
  {
    category: 'Payments',
    question: 'What online payment method is available?',
    answer: 'The website supports GCash through the secure payment flow shown during checkout. Follow the on-screen instructions and never submit a second payment if GCash has already deducted the amount while verification is still in progress.',
  },
  {
    category: 'Payments',
    question: 'Why is my GCash payment still being verified?',
    answer: 'Some payments require confirmation against the official payment record before the reservation can be finalized. Keep your payment reference and wait for the verification result instead of attempting another payment.',
  },
  {
    category: 'Payments',
    question: 'Is a down payment required?',
    answer: get('policy_downpayment', 'A down payment is required to confirm the reservation.'),
  },
  {
    category: 'Your Stay',
    question: 'What time is check-in?',
    answer: get('policy_checkin', 'Check-in is after 3:00 PM.'),
  },
  {
    category: 'Your Stay',
    question: 'What time is check-out?',
    answer: get('policy_checkout', 'Check-out is before 12:00 PM.'),
  },
  {
    category: 'Your Stay',
    question: 'What do I need to present at check-in?',
    answer: get('policy_id', 'Please present a valid government-issued ID during check-in.'),
  },
  {
    category: 'Changes',
    question: 'Can I cancel my reservation?',
    answer: get('policy_cancellation', 'Cancellation eligibility and refund treatment depend on the timing and status of the reservation.'),
  },
  {
    category: 'Changes',
    question: 'Can I move my reservation to another date?',
    answer: get('policy_rebooking', 'Rebooking requests are subject to room availability, possible rate differences, and staff approval.'),
  },
  {
    category: 'Changes',
    question: 'How many cancellation or rebooking requests can I submit?',
    answer: get('policy_requests', 'Guests may submit only one cancellation request and one rebooking request.'),
  },
];

export default function FaqPage() {
  const { get } = useCms();
  const [activeCategory, setActiveCategory] = useState('All');
  const [searchTerm, setSearchTerm] = useState('');
  const faqItems = useMemo(() => buildFaqItems(get), [get]);
  const email = get('location_contact_email', 'hhotelsph@gmail.com');
  const phone = get('location_contact_number', '+63 917 809 9482');
  const phoneHref = String(phone).replace(/[^+\d]/g, '');

  const filteredItems = useMemo(() => {
    const query = searchTerm.trim().toLowerCase();

    return faqItems.filter((item) => {
      const categoryMatches = activeCategory === 'All' || item.category === activeCategory;
      const queryMatches = query === ''
        || item.question.toLowerCase().includes(query)
        || String(item.answer).toLowerCase().includes(query);

      return categoryMatches && queryMatches;
    });
  }, [activeCategory, faqItems, searchTerm]);

  useEffect(() => {
    const previousTitle = document.title;
    document.title = 'Frequently Asked Questions | H+ Hotel';

    return () => {
      document.title = previousTitle;
    };
  }, []);

  return (
    <main className="faq-page">
      <section className="faq-hero" aria-labelledby="faq-page-title">
        <div className="faq-shell faq-hero-content">
          <div className="faq-hero-icon" aria-hidden="true"><HelpCircle size={30} /></div>
          <p className="faq-eyebrow">Guest Support</p>
          <h1 id="faq-page-title">Frequently Asked Questions</h1>
          <p>Find quick answers about reservations, payments, hotel policies, and managing your stay.</p>

          <label className="faq-search">
            <Search size={20} aria-hidden="true" />
            <span className="sr-only">Search frequently asked questions</span>
            <input
              type="search"
              value={searchTerm}
              onChange={(event) => setSearchTerm(event.target.value)}
              placeholder="Search questions or keywords"
            />
          </label>
        </div>
      </section>

      <section className="faq-shell faq-content" aria-label="Frequently asked questions">
        <div className="faq-category-list" role="group" aria-label="Filter questions by category">
          {categories.map((category) => (
            <button
              key={category}
              type="button"
              className={activeCategory === category ? 'active' : ''}
              aria-pressed={activeCategory === category}
              onClick={() => setActiveCategory(category)}
            >
              {category}
            </button>
          ))}
        </div>

        <div className="faq-results" aria-live="polite">
          <p className="faq-result-count">
            {filteredItems.length} {filteredItems.length === 1 ? 'answer' : 'answers'} found
          </p>

          {filteredItems.length > 0 ? (
            <div className="faq-accordion">
              {filteredItems.map((item, index) => (
                <details className="faq-item" key={item.question} open={index === 0 && searchTerm === '' && activeCategory === 'All'}>
                  <summary>
                    <span>
                      <small>{item.category}</small>
                      {item.question}
                    </span>
                    <ChevronDown size={20} aria-hidden="true" />
                  </summary>
                  <div className="faq-answer"><p>{item.answer}</p></div>
                </details>
              ))}
            </div>
          ) : (
            <div className="faq-empty">
              <HelpCircle size={32} aria-hidden="true" />
              <h2>No matching questions</h2>
              <p>Try another keyword or view all categories.</p>
              <button type="button" onClick={() => { setSearchTerm(''); setActiveCategory('All'); }}>
                Show all questions
              </button>
            </div>
          )}
        </div>
      </section>

      <section className="faq-support" aria-labelledby="faq-support-title">
        <div className="faq-shell faq-support-content">
          <div>
            <p className="faq-eyebrow">Still Need Help?</p>
            <h2 id="faq-support-title">Talk to our hotel team.</h2>
            <p>Contact us for reservation-specific questions or assistance that is not covered here.</p>
          </div>
          <div className="faq-support-actions">
            <Link to="/contact"><MessageCircle size={18} /> Send an inquiry</Link>
            <a href={`tel:${phoneHref}`}><Phone size={18} /> {phone}</a>
            <a href={`mailto:${email}`}><Mail size={18} /> {email}</a>
          </div>
        </div>
      </section>
    </main>
  );
}

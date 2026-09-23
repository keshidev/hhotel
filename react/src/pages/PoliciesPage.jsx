import { useEffect } from 'react';
import { CalendarClock, CreditCard, FileCheck2, IdCard, RefreshCw, ShieldCheck } from 'lucide-react';
import { Link } from 'react-router-dom';
import { useCms } from '../context/CmsContext';
import './PoliciesPage.css';

const policyIcon = (title) => {
  const normalized = String(title).toLowerCase();

  if (normalized.includes('payment') || normalized.includes('down')) return CreditCard;
  if (normalized.includes('rebook') || normalized.includes('request')) return RefreshCw;
  if (normalized.includes('id')) return IdCard;
  if (normalized.includes('check')) return CalendarClock;
  return FileCheck2;
};

export default function PoliciesPage() {
  const { policiesItems } = useCms();

  useEffect(() => {
    const previousTitle = document.title;
    document.title = 'Hotel Policies | H+ Hotel';

    return () => {
      document.title = previousTitle;
    };
  }, []);

  return (
    <main className="policies-page">
      <section className="policies-hero" aria-labelledby="policies-page-title">
        <div className="policies-shell policies-hero-content">
          <div className="policies-hero-icon" aria-hidden="true"><ShieldCheck size={30} /></div>
          <p className="policies-eyebrow">Plan With Confidence</p>
          <h1 id="policies-page-title">Hotel Policies</h1>
          <p>Review the important conditions for reservations, arrival, departure, payments, and booking changes.</p>
        </div>
      </section>

      <section className="policies-shell policies-content" aria-label="Active hotel policies">
        <div className="policies-intro">
          <div>
            <p className="policies-eyebrow">Before Your Stay</p>
            <h2>Everything you need to know.</h2>
          </div>
          <p>These policies are maintained by the hotel and may be updated when operational requirements change. The policies shown during checkout also apply to your reservation.</p>
        </div>

        <div className="policies-grid">
          {policiesItems.map((policy, index) => {
            const Icon = policyIcon(policy.title);

            return (
              <article className="policy-card" key={`${policy.title}-${index}`}>
                <div className="policy-card-heading">
                  <span className="policy-card-icon" aria-hidden="true"><Icon size={21} /></span>
                  <span className="policy-card-number">{String(index + 1).padStart(2, '0')}</span>
                </div>
                <h3>{policy.title}</h3>
                <p>{policy.body}</p>
              </article>
            );
          })}
        </div>

        <div className="policies-notice">
          <FileCheck2 size={24} aria-hidden="true" />
          <div>
            <h2>Reservation-specific conditions</h2>
            <p>Your confirmation and booking summary contain the dates, room selection, rates, and payment conditions that apply to your reservation.</p>
          </div>
          <Link to="/terms">Read Terms of Service</Link>
        </div>
      </section>

      <section className="policies-cta" aria-labelledby="policies-cta-title">
        <div className="policies-shell policies-cta-content">
          <div>
            <p className="policies-eyebrow">Ready to Reserve?</p>
            <h2 id="policies-cta-title">Find the right room for your stay.</h2>
          </div>
          <div className="policies-cta-actions">
            <Link className="policies-primary-link" to="/booking">Book a Room</Link>
            <Link className="policies-secondary-link" to="/contact">Ask a Question</Link>
          </div>
        </div>
      </section>
    </main>
  );
}

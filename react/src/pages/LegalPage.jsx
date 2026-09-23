import { useEffect } from 'react';
import { Cookie, FileText, Mail, MapPin, ShieldCheck } from 'lucide-react';
import { Link } from 'react-router-dom';
import { useCms } from '../context/CmsContext';
import './LegalPage.css';

const LAST_UPDATED = 'September 2, 2026';

const policyLinks = [
  { key: 'privacy', label: 'Privacy Policy', to: '/privacy' },
  { key: 'terms', label: 'Terms of Service', to: '/terms' },
  { key: 'cookies', label: 'Cookie Policy', to: '/cookies' },
];

const buildPolicies = (get) => ({
  privacy: {
    eyebrow: 'Your information, handled responsibly',
    title: 'Privacy Policy',
    summary: 'This policy explains what personal information H+ Hotel collects, why we use it, how we protect it, and the choices available to you.',
    Icon: ShieldCheck,
    sections: [
      {
        title: '1. Scope and commitment',
        paragraphs: [
          get('policy_privacy_terms', 'We collect only the information needed to manage reservations, payments, guest communication, support requests, and hotel operations. We do not sell personal data.'),
          'This policy applies when you browse this website, make or manage a reservation, submit payment information, send an inquiry, provide feedback, or otherwise interact with H+ Hotel.',
        ],
      },
      {
        title: '2. Information we collect',
        paragraphs: ['Depending on how you use our services, we may collect:'],
        bullets: [
          'Identity and contact details, including guest names, email address, telephone number, and information needed to verify a booking.',
          'Reservation details, including stay dates, room selections, number of guests, add-ons, promotions, preferences, and special requests.',
          'Payment and transaction details, including payment method, amount, transaction reference, status, and payment proof when manual GCash verification is used. We do not ask you to submit a payment-account password or PIN.',
          'Communications and service records, including contact inquiries, feedback, cancellation or rebooking requests, and staff responses.',
          'Technical and security information, such as IP address, browser information, request logs, security events, and reCAPTCHA verification data.',
        ],
      },
      {
        title: '3. How we use information',
        bullets: [
          'Create, verify, confirm, manage, modify, or cancel reservations.',
          'Process and reconcile payments, refunds, disputes, and transaction records.',
          'Communicate booking updates, receipts, verification links, reminders, and support responses.',
          'Prevent fraud, protect guest accounts, investigate incidents, and maintain service reliability.',
          'Meet accounting, legal, regulatory, audit, and legitimate hotel-operation requirements.',
          'Improve rooms, services, website usability, and guest support using appropriately limited information.',
        ],
      },
      {
        title: '4. Basis for processing',
        paragraphs: [
          'We process personal information when it is necessary to provide requested hotel and reservation services, when you have given consent, when required by law, or when reasonably necessary for legitimate and proportionate hotel operations, security, recordkeeping, and service improvement.',
        ],
      },
      {
        title: '5. When information is shared',
        paragraphs: ['We limit access to people and providers that need the information for a stated purpose. These may include:'],
        bullets: [
          'Authorized H+ Hotel administrators, receptionists, and operational personnel.',
          'Hosting, database, email, backup, and security providers supporting this website and hotel operations.',
          'Authorized hotel staff who review manual GCash payment records.',
          'Google reCAPTCHA and embedded Google services used for security or location features.',
          'Government authorities, courts, regulators, or professional advisers when disclosure is legally required or necessary to protect lawful rights.',
        ],
      },
      {
        title: '6. Retention and security',
        paragraphs: [
          'We retain information only for as long as reasonably necessary for the purpose for which it was collected, including reservation service, accounting, dispute handling, security, legal compliance, and audit requirements. Different record types may have different retention periods.',
          'H+ Hotel uses administrative and technical safeguards such as access controls, protected sessions, private evidence storage, audit records, transport encryption, and restricted staff permissions. No internet service can guarantee absolute security, so please contact us promptly if you suspect unauthorized activity.',
        ],
      },
      {
        title: '7. Your privacy rights',
        paragraphs: [
          'Subject to applicable law and reasonable identity verification, you may request information about processing, access to your personal data, correction of inaccurate data, objection or withdrawal of consent where applicable, erasure or blocking where legally available, and data portability. You may also file a complaint with the National Privacy Commission.',
        ],
        resources: [
          { label: 'National Privacy Commission: Data Subject Rights', href: 'https://privacy.gov.ph/data-subject-rights/' },
          { label: 'Republic Act No. 10173: Data Privacy Act of 2012', href: 'https://privacy.gov.ph/data-privacy-act/' },
        ],
      },
      {
        title: '8. Updates to this policy',
        paragraphs: ['We may update this policy when our services, legal obligations, or data practices change. The date displayed at the top identifies the latest published version.'],
      },
    ],
  },
  terms: {
    eyebrow: 'Clear conditions for every stay',
    title: 'Terms of Service',
    summary: 'These terms govern use of the H+ Hotel website and the reservation, payment, and guest-service features available through it.',
    Icon: FileText,
    sections: [
      {
        title: '1. Agreement and eligibility',
        paragraphs: [
          'By using this website or submitting a reservation, you agree to these terms and the policies presented during booking. The person making a reservation must have legal capacity to enter into the transaction and must provide complete and accurate information for every guest.',
        ],
      },
      {
        title: '2. Reservations and confirmation',
        paragraphs: [get('policy_booking_conditions', 'Reservations are confirmed only after required payment and verification steps are completed. Rates, inclusions, and availability remain subject to validation at the time of booking.')],
        bullets: [
          'A submitted booking request is not guaranteed until the required verification and payment conditions are completed and a confirmation is issued.',
          'Room assignments may change when operationally necessary, but the hotel will use reasonable efforts to provide the confirmed room type or an appropriate alternative.',
          'Guests must review confirmation details promptly and contact the hotel if any information is incorrect.',
        ],
      },
      {
        title: '3. Rates, charges, and payments',
        bullets: [
          'Displayed rates, taxes, required down payments, add-ons, discounts, and totals are calculated using the active hotel configuration at the time of booking.',
          get('policy_downpayment', 'A down payment is required to confirm the reservation.'),
          'Payment instructions must be followed exactly. Manual GCash submissions remain subject to verification against the hotel merchant record, while supported gateway payments are confirmed through the payment provider.',
          'Suspected duplicate, incorrect, fraudulent, reversed, or disputed transactions may be placed under review before a booking or refund is finalized.',
        ],
      },
      {
        title: '4. Arrival and departure',
        bullets: [
          `Check-in: ${get('policy_checkin', 'After 3:00 PM')}.`,
          `Check-out: ${get('policy_checkout', 'Before 12:00 PM')}.`,
          get('policy_id', 'Guests must present a valid government-issued ID during check-in.'),
          'Early arrival and late departure requests depend on availability, hotel approval, and any applicable additional charge.',
        ],
      },
      {
        title: '5. Cancellation, rebooking, and no-show',
        bullets: [
          get('policy_cancellation', 'Cancellation eligibility and refund treatment depend on the timing and status of the reservation.'),
          get('policy_rebooking', 'Rebooking requests may involve rate differences and require staff approval.'),
          get('policy_requests', 'The number of active modification requests may be limited.'),
          'Failure to arrive or provide required contact evidence may result in no-show handling under the active hotel policy. Paid or disputed cases may require manual financial review.',
          'Approved refunds are processed through the applicable payment workflow and may require additional verification or transfer evidence.',
        ],
      },
      {
        title: '6. Guest responsibilities',
        bullets: [
          'Guests must comply with hotel rules, occupancy limits, safety requirements, lawful staff instructions, and policies concerning noise, smoking, prohibited activity, and use of hotel property.',
          'The booking holder is responsible for the conduct of accompanying guests and for reasonable charges arising from loss, damage, excessive cleaning, unauthorized occupancy, or unpaid services.',
          'The hotel may refuse service or end a stay when necessary for safety, security, unlawful conduct, material policy violations, or non-payment, subject to applicable law.',
        ],
      },
      {
        title: '7. Website and third-party services',
        paragraphs: [
          'We work to keep information and online services accurate and available, but temporary interruptions, maintenance, provider outages, or errors may occur. Payment gateways, email services, maps, and reCAPTCHA are operated by third parties under their own terms and policies.',
        ],
      },
      {
        title: '8. Liability and uncontrollable events',
        paragraphs: [
          'To the extent permitted by law, H+ Hotel is not responsible for delays or inability to perform caused by events reasonably beyond its control. Nothing in these terms excludes rights or responsibilities that cannot lawfully be excluded.',
        ],
      },
      {
        title: '9. Changes and governing law',
        paragraphs: [
          'The terms applicable to a reservation are those presented when the reservation is made, together with lawful later amendments accepted as part of a requested change. Website terms may be updated for future use. These terms are governed by applicable laws of the Republic of the Philippines.',
        ],
      },
    ],
  },
  cookies: {
    eyebrow: 'How this website remembers and protects your session',
    title: 'Cookie Policy',
    summary: 'This policy describes cookies and browser storage used to operate booking, payment, security, and staff-interface features.',
    Icon: Cookie,
    sections: [
      {
        title: '1. What cookies and browser storage are',
        paragraphs: [
          'Cookies are small pieces of data stored by your browser and sent with relevant web requests. Session storage keeps limited information inside the current browser tab. We use these technologies where needed to operate the website securely and preserve your progress.',
        ],
      },
      {
        title: '2. Technologies we use',
        bullets: [
          'Security cookies, including CSRF protection, help verify that requests originate from this website.',
          'Guest booking and payment-access cookies protect access to reservation, payment, cancellation, and rebooking functions. Sensitive access cookies are configured with security restrictions and expire automatically.',
          'Session storage temporarily remembers booking dates, selected rooms, add-ons, promotion details, booking references, and current booking progress. It is generally cleared when the tab or session ends or when the booking flow resets.',
          'Staff session storage holds authenticated staff-session information for the active browser tab. Staff credentials are not intentionally stored in persistent local storage.',
          'A staff sidebar preference cookie may remember whether the navigation panel is open for up to seven days.',
        ],
      },
      {
        title: '3. Third-party technologies',
        paragraphs: [
          'Google reCAPTCHA is used to reduce automated abuse and may process technical information or set its own cookies under Google policies. Embedded Google location services may also use their own technologies when those features are loaded.',
        ],
      },
      {
        title: '4. Your cookie choices',
        paragraphs: [
          'On your first public-site visit, the cookie notice lets you accept or reject optional third-party content. Rejecting keeps optional embedded content, such as the Google location map, disabled while essential reservation and security functions remain available.',
          'You can change your selection at any time through Cookie Preferences in the website footer. Essential security cookies and temporary booking storage cannot be disabled through this preference because they are required to provide features you request.',
        ],
      },
      {
        title: '5. Essential use and advertising',
        paragraphs: [
          'The cookies and browser storage currently used by H+ Hotel support essential security, reservation, payment, session, and interface functions. We do not currently use them for behavioral advertising. Because essential cookies protect and operate requested services, disabling them may prevent booking, payment, or account features from working correctly.',
        ],
      },
      {
        title: '6. Your browser controls',
        paragraphs: [
          'You can inspect, block, or delete cookies and site data using your browser settings. You can also close the tab or clear site storage to remove temporary booking information. If you block essential cookies, some secure features will no longer function.',
        ],
      },
      {
        title: '7. Policy updates',
        paragraphs: ['We will update this policy when the website begins using materially different cookies, storage technologies, or third-party services.'],
      },
    ],
  },
});

export default function LegalPage({ policy }) {
  const { get } = useCms();
  const policies = buildPolicies(get);
  const content = policies[policy] || policies.privacy;
  const { Icon } = content;
  const email = get('location_contact_email', 'hhotelsph@gmail.com');
  const phone = get('location_contact_number', '+63 917 809 9482');
  const address = [
    get('location_address1', 'One Nenita Place 89 Road 1'),
    get('location_address2', 'Bagong Pagasa, Quezon City'),
    get('location_address3', 'Philippines'),
  ].filter(Boolean).join(', ');

  useEffect(() => {
    const previousTitle = document.title;
    document.title = `${content.title} | H+ Hotel`;
    return () => {
      document.title = previousTitle;
    };
  }, [content.title]);

  return (
    <main className="legal-page">
      <section className="legal-hero" aria-labelledby="legal-page-title">
        <div className="legal-shell legal-hero-inner">
          <div className="legal-icon" aria-hidden="true"><Icon size={28} /></div>
          <p className="legal-eyebrow">{content.eyebrow}</p>
          <h1 id="legal-page-title">{content.title}</h1>
          <p className="legal-summary">{content.summary}</p>
          <p className="legal-updated">Last updated: {LAST_UPDATED}</p>
        </div>
      </section>

      <div className="legal-shell legal-layout">
        <aside className="legal-sidebar">
          <nav aria-label="Legal pages">
            <p className="legal-nav-label">Legal information</p>
            {policyLinks.map((link) => (
              <Link
                key={link.key}
                to={link.to}
                className={`legal-nav-link ${link.key === policy ? 'active' : ''}`}
                aria-current={link.key === policy ? 'page' : undefined}
              >
                {link.label}
              </Link>
            ))}
          </nav>
          <div className="legal-help-card">
            <strong>Questions?</strong>
            <p>Contact the hotel for policy, reservation, or privacy assistance.</p>
            <a href={`mailto:${email}`}><Mail size={15} /> {email}</a>
          </div>
        </aside>

        <article className="legal-content">
          <div className="legal-introduction">
            <span>H+ Hotel</span>
            <p>Please read this information carefully. If you have questions about how a policy applies to a reservation, contact us before completing your booking.</p>
          </div>

          {content.sections.map((section) => (
            <section className="legal-section" key={section.title}>
              <h2>{section.title}</h2>
              {section.paragraphs?.map((paragraph) => <p key={paragraph}>{paragraph}</p>)}
              {section.bullets && (
                <ul>
                  {section.bullets.map((bullet) => <li key={bullet}>{bullet}</li>)}
                </ul>
              )}
              {section.resources && (
                <div className="legal-resources">
                  {section.resources.map((resource) => (
                    <a key={resource.href} href={resource.href} target="_blank" rel="noreferrer">
                      {resource.label}
                    </a>
                  ))}
                </div>
              )}
            </section>
          ))}

          <section className="legal-contact" aria-label="Hotel contact information">
            <div>
              <p className="legal-nav-label">Contact H+ Hotel</p>
              <h2>We are here to help.</h2>
            </div>
            <div className="legal-contact-details">
              <a href={`mailto:${email}`}><Mail size={17} /> {email}</a>
              <a href={`tel:${String(phone).replace(/[^+\d]/g, '')}`}>{phone}</a>
              <span><MapPin size={17} /> {address}</span>
            </div>
          </section>
        </article>
      </div>
    </main>
  );
}

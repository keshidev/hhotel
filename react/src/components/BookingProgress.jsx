import { Check } from 'lucide-react';
import './BookingProgress.css';

const BOOKING_STEPS = [
  'Select Dates',
  'Select Room',
  'Add-ons',
  'Guest Details',
  'GCash Payment',
  'Confirmation',
];

const BookingProgress = ({ currentStep }) => (
  <nav className="booking-progress" aria-label="Booking progress">
    <div className="booking-progress__mobile" aria-current="step">
      <span>Step {currentStep} of {BOOKING_STEPS.length}</span>
      <strong>{BOOKING_STEPS[currentStep - 1]}</strong>
    </div>
    <ol className="booking-progress__list">
      {BOOKING_STEPS.map((label, index) => {
        const step = index + 1;
        const isComplete = step < currentStep;
        const isActive = step === currentStep;

        return (
          <li
            className={`booking-progress__step${isComplete ? ' is-complete' : ''}${isActive ? ' is-active' : ''}`}
            key={label}
            aria-current={isActive ? 'step' : undefined}
          >
            {index > 0 && <span className="booking-progress__connector" aria-hidden="true" />}
            <span className="booking-progress__marker" aria-hidden="true">
              {isComplete ? <Check size={17} strokeWidth={3} /> : step}
            </span>
            <span className="booking-progress__label">{label}</span>
          </li>
        );
      })}
    </ol>
  </nav>
);

export default BookingProgress;

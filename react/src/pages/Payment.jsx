import { useParams } from 'react-router-dom';
import ManualGcashPayment from './ManualGcashPayment';

const Payment = () => {
  const { bookingId } = useParams();

  return <ManualGcashPayment bookingId={bookingId} />;
};

export default Payment;

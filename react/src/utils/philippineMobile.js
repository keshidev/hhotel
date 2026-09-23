export const normalizePhilippineMobileInput = (value = '') => {
  const raw = String(value).trim();
  let digits = raw.replace(/\D/g, '');

  if (raw.startsWith('+63')) {
    digits = digits.slice(2);
  } else if (digits.startsWith('63') && digits.length > 10) {
    digits = digits.slice(2);
  } else if (digits.startsWith('09')) {
    digits = digits.slice(1);
  }

  return digits.slice(0, 10);
};

export const isPhilippineMobileInput = (value = '') => /^9[0-9]{9}$/.test(value);

export const formatPhilippineMobile = (value = '') => {
  const nationalNumber = normalizePhilippineMobileInput(value);
  return isPhilippineMobileInput(nationalNumber) ? `+63${nationalNumber}` : null;
};

export const toAmount = (value) => {
  const amount = Number.parseFloat(value ?? 0);
  return Number.isFinite(amount) ? amount : 0;
};

export const formatCurrency = (value, { prefix = '\u20B1', locale = 'en-PH' } = {}) => {
  const amount = toAmount(value);
  return `${prefix}${amount.toLocaleString(locale, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
};

export const formatCurrencyCode = (value, code = 'PHP') => {
  const amount = toAmount(value);
  return `${code} ${amount.toLocaleString('en-PH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
};

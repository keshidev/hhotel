export const PDF_COLORS = {
  NAVY: [13, 27, 62],
  BLUE: [26, 75, 204],
  WHITE: [255, 255, 255],
  LIGHT: [247, 249, 255],
  BORDER: [221, 227, 240],
  MUTED: [107, 114, 128],
  SUCCESS: [22, 163, 74],
  DANGER: [239, 68, 68],
  WARN: [234, 179, 8],
  GRAY: [100, 116, 139],
};

const FIELD_LABELS = {
  booking_status: 'Booking Status',
  room_id: 'Room Assignment',
  room_number: 'Room Number',
  check_in: 'Check-In Date',
  check_out: 'Check-Out Date',
  total_amount: 'Total Amount',
  payment_status: 'Payment Status',
};

const toNumeric = (value) => {
  const parsed = Number.parseFloat(value ?? 0);
  return Number.isFinite(parsed) ? parsed : 0;
};

const toWords = (value) =>
  String(value ?? '')
    .replace(/[_-]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();

const toTitleCase = (value) =>
  toWords(value)
    .split(' ')
    .filter(Boolean)
    .map((token) => token.charAt(0).toUpperCase() + token.slice(1).toLowerCase())
    .join(' ');

export const sanitizePdfText = (value) => String(value ?? '').replace(/[^\x20-\x7E]/g, '');

export const formatRoomType = (type) => {
  const raw = toWords(type);
  if (!raw) return 'N/A';
  return raw
    .split(',')
    .map((part) => toTitleCase(part))
    .filter(Boolean)
    .join(' / ');
};

export const formatStatus = (status) => {
  const normalized = toWords(status).toLowerCase();
  const map = {
    checked_in: 'Checked In',
    checked_out: 'Checked Out',
    no_show: 'No Show',
    confirmed: 'Confirmed',
    cancelled: 'Cancelled',
    expired: 'Expired',
    pending: 'Pending',
    completed: 'Completed',
  };
  return map[normalized.replace(/\s+/g, '_')] || toTitleCase(normalized) || 'N/A';
};

export const formatPaymentMethod = (method) => {
  const source = Array.isArray(method) ? method.join(',') : String(method ?? '');
  const parts = source
    .split(/[,/]/)
    .map((part) => part.trim())
    .filter(Boolean);

  if (parts.length === 0) return 'N/A';

  const mapped = parts.map((part) => {
    const lowered = part.toLowerCase();
    if (lowered.includes('gcash')) return 'GCash';
    if (lowered.includes('cash')) return 'Cash';
    if (lowered.includes('bank')) return 'Bank Transfer';
    if (lowered.includes('card')) return 'Card';
    return toTitleCase(part);
  });

  return [...new Set(mapped)].join(' / ');
};

export const formatChangedFields = (fields) => {
  const list = Array.isArray(fields) ? fields : String(fields ?? '').split(',');
  const cleaned = list.map((item) => String(item).trim()).filter(Boolean);
  if (cleaned.length === 0) return 'N/A';

  return cleaned
    .map((field) => FIELD_LABELS[field] || toTitleCase(field))
    .join(', ');
};

export const formatCurrencyPDF = (amount) => {
  const num = toNumeric(amount);
  const formatted = num.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  return `PHP ${formatted}`;
};

export const drawPageHeader = (
  doc,
  { title, subtitle = '', dateRange = null, totalRecords = null } = {}
) => {
  const pageWidth = doc.internal.pageSize.getWidth();
  const generated = new Date().toLocaleString('en-PH', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });

  doc.setFillColor(...PDF_COLORS.NAVY);
  doc.rect(0, 0, pageWidth, 68, 'F');

  doc.setTextColor(...PDF_COLORS.WHITE);
  doc.setFontSize(22);
  doc.setFont('helvetica', 'bold');
  doc.text('H+ HOTEL', 40, 28);

  doc.setTextColor(...PDF_COLORS.BLUE);
  doc.setFontSize(11);
  doc.setFont('helvetica', 'normal');
  doc.text(title || 'Report', 40, 45);
  if (subtitle) {
    doc.setTextColor(...PDF_COLORS.MUTED);
    doc.setFontSize(9);
    doc.text(subtitle, 40, 58);
  }

  doc.setTextColor(...PDF_COLORS.MUTED);
  doc.setFontSize(9);
  doc.text(`Generated: ${generated}`, pageWidth - 40, 30, { align: 'right' });

  const rightMeta = dateRange
    ? `Period: ${dateRange.start} to ${dateRange.end}`
    : `Total Records: ${Number(totalRecords ?? 0)}`;
  doc.text(rightMeta, pageWidth - 40, 44, { align: 'right' });

  if (dateRange && totalRecords != null) {
    doc.text(`Total Records: ${Number(totalRecords ?? 0)}`, pageWidth - 40, 58, { align: 'right' });
  }

  doc.setFillColor(...PDF_COLORS.BLUE);
  doc.rect(0, 68, pageWidth, 3, 'F');
};

export const drawPageFooter = (doc, { reportTitle } = {}) => {
  const pageWidth = doc.internal.pageSize.getWidth();
  const pageHeight = doc.internal.pageSize.getHeight();
  const pageCount = doc.internal.getNumberOfPages();
  const currentPage = doc.internal.getCurrentPageInfo().pageNumber;
  const generated = new Date().toLocaleString('en-PH', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });

  doc.setFillColor(...PDF_COLORS.NAVY);
  doc.rect(0, pageHeight - 22, pageWidth, 22, 'F');

  doc.setFontSize(8);
  doc.setTextColor(...PDF_COLORS.BLUE);
  doc.text('H+ HOTEL -- Confidential', 40, pageHeight - 8);

  doc.setTextColor(...PDF_COLORS.MUTED);
  doc.text(
    `Page ${currentPage} of ${pageCount}  |  ${reportTitle || 'Report'}  |  Generated ${generated}`,
    pageWidth / 2,
    pageHeight - 8,
    { align: 'center' }
  );
};

export const drawSummaryCards = (doc, cards, startY = 84) => {
  const list = Array.isArray(cards) ? cards.filter(Boolean) : [];
  if (list.length === 0) return startY;

  const pageWidth = doc.internal.pageSize.getWidth();
  const cardsPerRow = Math.min(list.length, 4);
  const rowCount = Math.ceil(list.length / cardsPerRow);
  const boxHeight = 48;
  const rowGap = 10;
  const gap = 10;
  const boxWidth = (pageWidth - 80 - gap * (cardsPerRow - 1)) / cardsPerRow;

  list.forEach((card, index) => {
    const row = Math.floor(index / cardsPerRow);
    const col = index % cardsPerRow;
    const x = 40 + col * (boxWidth + gap);
    const y = startY + row * (boxHeight + rowGap);

    doc.setFillColor(...PDF_COLORS.LIGHT);
    doc.roundedRect(x, y, boxWidth, boxHeight, 4, 4, 'F');
    doc.setDrawColor(...PDF_COLORS.BORDER);
    doc.roundedRect(x, y, boxWidth, boxHeight, 4, 4, 'S');

    doc.setFont('helvetica', 'normal');
    doc.setFontSize(7);
    doc.setTextColor(...PDF_COLORS.MUTED);
    let labelLines = doc.splitTextToSize(
      String(card.label ?? '').toUpperCase(),
      Math.max(10, boxWidth - 12)
    );
    if (labelLines.length > 2) {
      labelLines = labelLines.slice(0, 2);
      const last = String(labelLines[1] ?? '');
      labelLines[1] = last.length > 3 ? `${last.slice(0, -3)}...` : `${last}...`;
    }
    doc.text(labelLines, x + 6, y + 10, { baseline: 'top' });

    doc.setFont('helvetica', 'bold');
    const valueText = String(card.value ?? '');
    doc.setFontSize(valueText.length > 10 ? 10 : 13);
    doc.setTextColor(...(card.valueColor || PDF_COLORS.NAVY));
    doc.text(valueText, x + 6, y + boxHeight - 10);
  });

  return startY + rowCount * boxHeight + (rowCount - 1) * rowGap + 16;
};

export const drawSectionDivider = (doc, label, y) => {
  const pageWidth = doc.internal.pageSize.getWidth();
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(10);
  doc.setTextColor(...PDF_COLORS.NAVY);
  doc.text(String(label ?? '').toUpperCase(), 40, y);
  doc.setDrawColor(...PDF_COLORS.BLUE);
  doc.setLineWidth(1);
  doc.line(40, y + 4, pageWidth - 40, y + 4);
  return y + 18;
};

export const BASE_TABLE_STYLES = {
  margin: { left: 40, right: 40, top: 88 },
  styles: {
    fontSize: 7.5,
    cellPadding: { top: 6, right: 8, bottom: 6, left: 8 },
    textColor: PDF_COLORS.NAVY,
    lineColor: PDF_COLORS.BORDER,
    lineWidth: 0.5,
    lineHeight: 1.4,
  },
  headStyles: {
    fillColor: PDF_COLORS.NAVY,
    textColor: PDF_COLORS.WHITE,
    fontStyle: 'bold',
    fontSize: 8,
  },
  alternateRowStyles: {
    fillColor: PDF_COLORS.LIGHT,
  },
};

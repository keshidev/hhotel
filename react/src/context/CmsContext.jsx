import React, { createContext, useContext, useEffect, useMemo, useRef, useState } from 'react';

const CmsContext = createContext({
  cms: {},
  loaded: false,
  highlightsItems: [],
  statsItems: [],
  testimonialsItems: [],
  nearbyItems: [],
  galleryItems: [],
  policiesItems: [],
  addonsItems: [],
  brandSettings: {},
  taxRate: 0.12,
  downpaymentRate: 0.5,
  get: (key, fallback = '') => fallback,
  getPolicies: () => [],
  getRoomLabel: (t) => t ?? '',
  getRoomContent: () => ({ label: '', tagline: '', description: '', image: '' }),
  getAddonContent: () => ({ image: '', description: '' }),
});

const CLIENT_API = (import.meta.env.VITE_API_URL || 'http://localhost:8000/api') + '/client';

const DEFAULTS = {
  hero_title: 'Comfort Crafted Around You',
  hero_title_line1: 'YOUR COMFORT,',
  hero_title_line2: 'OUR PRIORITY.',
  hero_subtitle: 'Fast Booking - Clear Billing - Comfortable Rooms',
  hero_image: 'https://i.pinimg.com/1200x/87/03/42/87034203c6d682ac34ca22c8b42f8d20.jpg',
  hero_primary_button_text: 'BOOK YOUR STAY',
  hero_primary_button_link: '/select-room',
  hero_secondary_button_text: 'EXPLORE ROOMS',
  hero_secondary_button_link: '/rooms',

  about_eyebrow: 'ABOUT H+ HOTEL',
  about_title: 'A STAY BUILT AROUND COMFORT.',
  about_description: 'H+ Hotel QC offers clean, cozy, and thoughtfully prepared rooms for guests who value comfort, convenience, and straightforward service.',
  about_secondary_text: 'Located near key shopping, dining, and entertainment destinations in Quezon City, we make it easy to settle in, recharge, and enjoy the city at your own pace.',
  about_image: '/images/Executive%20Suite/executive_2.jpg',

  policy_checkin: 'After 3:00 PM',
  policy_checkout: 'Before 12:00 PM',
  policy_downpayment: 'Down payment is required to confirm the reservation.',
  policy_cancellation: 'If cancellation is requested within 24 hours of check-in time, it is non-refundable.',
  policy_requests: 'Guests may submit only one cancellation request and one rebooking request.',
  policy_rebooking: 'Rebooking requests may have rate differences and require staff approval.',
  policy_id: 'Please present a valid government-issued ID during check-in.',
  policy_privacy_terms: 'We collect guest information such as your name, email, contact number, and booking details to process reservations, payments, and support requests. We use this data only for reservation management, guest communication, compliance, and service improvements. Your information is protected with appropriate administrative and technical safeguards, and we do not sell personal data. If you have questions or concerns about your data, please contact our support team through the hotel contact details provided on this website.',
  policy_booking_conditions: 'Reservations are confirmed only after required payment and verification steps are completed. Published rates, inclusions, and availability are subject to validation at the time of booking. Cancellation, rebooking, and no-show handling follow the active hotel policy shown during booking and in confirmation communications. Check-in and check-out schedules must be observed, and guests are required to present a valid government-issued ID upon arrival.',

  location_contact_number: '+63 917 809 9482',
  location_contact_email: 'hhotelsph@gmail.com',

  nearby_title: 'EXPLORE THE NEIGHBORHOOD',
  nearby_description: 'Shopping, dining, entertainment, and city landmarks are within easy reach of H+ Hotel.',
  gallery_title: 'A CLOSER LOOK',
  gallery_description: 'Step inside our rooms and discover the comfortable details that make every stay feel effortless.',

  room_executive_suite_label: 'Executive Suite',
  room_executive_suite_tagline: 'Luxury and space combined',
  room_executive_suite_description: 'Indulge in our Executive Suite, offering separate living and sleeping areas with panoramic city views.',
  room_family_label: 'Family Room',
  room_family_tagline: 'Perfect for the whole family',
  room_family_description: 'Our spacious Family Room accommodates up to 4 guests comfortably with multiple sleeping arrangements.',
  room_deluxe_label: 'Deluxe',
  room_deluxe_tagline: 'A blend of elegance and comfort',
  room_deluxe_description: 'Experience comfort and sophistication in our Deluxe Room.',
  room_superior_twin_label: 'Superior Twin',
  room_superior_twin_tagline: 'Modern comfort for two',
  room_superior_twin_description: 'Our Superior Twin room features two comfortable single beds.',
  room_superior_queen_label: 'Superior Queen',
  room_superior_queen_tagline: 'Classic comfort with a queen touch',
  room_superior_queen_description: 'Our Superior Queen room offers a plush queen bed and refined interiors.',
  room_premier_label: 'Premier',
  room_premier_tagline: 'Elevated living at its finest',
  room_premier_description: 'Our Premier Room combines sophisticated design with premium comforts.',

  brand_primary_color: '#1a4bcc',
  brand_accent_color: '#0d1b3e',
  brand_button_color: '#1a4bcc',
  brand_button_hover_color: '#1340b8',
};

const FALLBACK_HIGHLIGHTS = [
  { title: 'FAST BOOKING', description: 'Reserve your room in under 2 minutes with our streamlined booking system.' },
  { title: 'CLEAR BILLING', description: 'Transparent pricing with no hidden fees. Pay securely via GCash or cash at check-in.' },
  { title: 'COMFORTABLE ROOMS', description: 'Every room is equipped with Netflix, air conditioning, and premium bedding for a restful stay.' },
  { title: 'PRIME LOCATION', description: 'Minutes from SM North EDSA, Trinoma, and Solaire Resort North.' },
];

const FALLBACK_STATS = [
  { value: '2min', label: 'AVERAGE BOOKING TIME' },
  { value: '24/7', label: 'FRONT DESK SUPPORT' },
  { value: '100%', label: 'TRANSPARENT BILLING' },
  { value: '6+', label: 'ROOM TYPES AVAILABLE' },
];

const FALLBACK_NEARBY = [
  { name: 'SM North EDSA', category: 'Shopping & Dining', description: 'A major Quezon City destination for shopping, dining, and entertainment.', map_url: 'https://www.google.com/maps/search/?api=1&query=SM+North+EDSA', image: '' },
  { name: 'TriNoma', category: 'Shopping & Transit', description: 'Shopping and dining with convenient access to nearby transport connections.', map_url: 'https://www.google.com/maps/search/?api=1&query=TriNoma+Quezon+City', image: '' },
  { name: 'Solaire Resort North', category: 'Entertainment', description: 'A Quezon City destination for dining, events, and entertainment.', map_url: 'https://www.google.com/maps/search/?api=1&query=Solaire+Resort+North', image: '' },
  { name: 'Quezon Memorial Circle', category: 'City Landmark', description: 'A landmark park with green spaces, museums, and recreational attractions.', map_url: 'https://www.google.com/maps/search/?api=1&query=Quezon+Memorial+Circle', image: '' },
];

const FALLBACK_GALLERY = [
  { image: '/images/Executive%20Suite/executive_room.jpg', title: 'Executive Suite', alt: 'Executive Suite sleeping area' },
  { image: '/images/Deluxe%20Room/deluxe_room.jpg', title: 'Deluxe Room', alt: 'Deluxe Room interior' },
  { image: '/images/Superior%20Twin/superior_twin_room.jpg', title: 'Superior Twin', alt: 'Superior Twin room interior' },
  { image: '/images/Superior%20Queen/superior_queen_2.jpg', title: 'Superior Queen', alt: 'Superior Queen room interior' },
  { image: '/images/Premier%20Room/premier_room.jpg', title: 'Premier Room', alt: 'Premier Room interior' },
];

const FALLBACK_ADDONS = [
  { id: 'rollaway_bed', name: 'Rollaway Bed', display_description: 'Add extra sleeping space for a more restful stay.', image: 'https://images.unsplash.com/photo-1566665797739-1674de7a421a?w=560&h=360&fit=crop' },
  { id: 'extra_pillows_and_blankets', name: 'Extra Pillows and Blankets', display_description: 'Make your room feel even more relaxing with extra pillows and blankets.', image: 'https://images.unsplash.com/photo-1616628182509-6f0a8a7f3f5a?w=560&h=360&fit=crop' },
  { id: 'breakfast_package', name: 'Breakfast Package', display_description: 'Enjoy a satisfying breakfast to begin your morning with ease.', image: 'https://images.unsplash.com/photo-1525351484163-7529414344d8?w=560&h=360&fit=crop' },
  { id: 'early_check_in', name: 'Early Check-In', display_description: 'Settle into your room sooner and enjoy more time to relax.', image: 'https://images.unsplash.com/photo-1590490360182-c33d57733427?w=560&h=360&fit=crop' },
  { id: 'late_check_out', name: 'Late Check-Out', display_description: 'Extend your departure and enjoy a more flexible final day.', image: 'https://images.unsplash.com/photo-1455587734955-081b22074882?w=560&h=360&fit=crop' },
  { id: 'extra_toiletries_kit', name: 'Extra Toiletries Kit', display_description: 'Enjoy added essentials for a more convenient and comfortable stay.', image: 'https://images.unsplash.com/photo-1540555700478-4be289fbecef?w=560&h=360&fit=crop' },
  { id: 'laundry_service', name: 'Laundry Service', display_description: 'Keep your wardrobe fresh throughout your stay with our laundry service.', image: 'https://images.unsplash.com/photo-1626806787461-102c1a0f4f79?w=560&h=360&fit=crop' },
];

const parseJsonArray = (value, fallback = []) => {
  if (Array.isArray(value)) {
    return value;
  }

  if (typeof value !== 'string' || value.trim() === '') {
    return fallback;
  }

  try {
    const parsed = JSON.parse(value);
    return Array.isArray(parsed) ? parsed : fallback;
  } catch {
    return fallback;
  }
};

export const CmsProvider = ({ children }) => {
  const [cms, setCms] = useState(DEFAULTS);
  const [taxRate, setTaxRate] = useState(0.12);
  const [downpaymentRate, setDownpaymentRate] = useState(0.5);
  const [loaded, setLoaded] = useState(false);
  const lastFetchedAtRef = useRef(0);

  useEffect(() => {
    let mounted = true;

    const fetchAll = async ({ force = false } = {}) => {
      const nowTs = Date.now();
      if (!force && nowTs - lastFetchedAtRef.current < 10000) {
        return;
      }

      try {
        const cmsResult = await fetch(`${CLIENT_API}/cms?_=${nowTs}`, {
          cache: 'no-store',
          headers: {
            'Cache-Control': 'no-cache',
            Pragma: 'no-cache',
          },
        }).then((r) => r.json());

        if (cmsResult?.success && cmsResult?.data) {
          if (mounted) {
            setCms((prev) => ({ ...prev, ...cmsResult.data }));
            const publicTaxRate = Number(cmsResult?.meta?.booking_configuration?.tax_rate);
            if (Number.isFinite(publicTaxRate) && publicTaxRate >= 0 && publicTaxRate <= 1) {
              setTaxRate(publicTaxRate);
            }
            const publicDownpaymentRate = Number(cmsResult?.meta?.booking_configuration?.downpayment_rate);
            if (Number.isFinite(publicDownpaymentRate) && publicDownpaymentRate > 0 && publicDownpaymentRate <= 1) {
              setDownpaymentRate(publicDownpaymentRate);
            }
            lastFetchedAtRef.current = nowTs;
          }
        }
      } catch {
        // keep defaults when CMS endpoint is unavailable
      } finally {
        if (mounted) {
          setLoaded(true);
        }
      }
    };

    fetchAll({ force: true });

    const onFocus = () => {
      fetchAll({ force: true });
    };

    const onVisibilityChange = () => {
      if (document.visibilityState === 'visible') {
        fetchAll({ force: true });
      }
    };

    const intervalId = window.setInterval(() => {
      fetchAll();
    }, 300000);

    window.addEventListener('focus', onFocus);
    document.addEventListener('visibilitychange', onVisibilityChange);

    return () => {
      mounted = false;
      window.clearInterval(intervalId);
      window.removeEventListener('focus', onFocus);
      document.removeEventListener('visibilitychange', onVisibilityChange);
    };
  }, []);

  const get = (key, fallback = '') => cms[key] ?? fallback;

  const highlightsItems = useMemo(() => {
    const items = parseJsonArray(cms.highlights_items, null);
    if (Array.isArray(items)) {
      return items;
    }

    return [1, 2, 3, 4].map((n, i) => ({
      title: get(`highlight_${n}_title`, FALLBACK_HIGHLIGHTS[i]?.title || ''),
      description: get(`highlight_${n}_desc`, FALLBACK_HIGHLIGHTS[i]?.description || ''),
    }));
  }, [cms]);

  const statsItems = useMemo(() => {
    const items = parseJsonArray(cms.stats_items, null);
    if (Array.isArray(items)) {
      return items;
    }

    return [1, 2, 3, 4].map((n, i) => ({
      value: get(`stat_${n}_value`, FALLBACK_STATS[i]?.value || ''),
      label: get(`stat_${n}_label`, FALLBACK_STATS[i]?.label || ''),
    }));
  }, [cms]);

  const testimonialsItems = useMemo(() => {
    const parsedTestimonials = parseJsonArray(cms.testimonials_items, null);
    if (!Array.isArray(parsedTestimonials)) {
      return [];
    }

    return parsedTestimonials
      .filter((item) => item && item.is_active !== false)
      .map((item, index) => ({
        id: item.id ?? `cms-${index}`,
        guest_name: String(item.guest_name ?? 'Guest'),
        role: String(item.role ?? 'VERIFIED GUEST'),
        rating_overall: Number(item.star_rating ?? item.rating_overall ?? 5),
        review: String(item.review_text ?? item.review ?? ''),
        date: item.date ?? null,
      }))
      .filter((item) => item.review.trim() !== '');
  }, [cms]);

  const nearbyItems = useMemo(() => {
    const items = parseJsonArray(cms.nearby_items, null);
    return Array.isArray(items) ? items : FALLBACK_NEARBY;
  }, [cms]);

  const galleryItems = useMemo(() => {
    const items = parseJsonArray(cms.gallery_items, null);
    return Array.isArray(items) ? items.filter((item) => String(item?.image ?? '').trim() !== '') : FALLBACK_GALLERY;
  }, [cms]);

  const policiesItems = useMemo(() => {
    const policies = parseJsonArray(cms.policies_items, null);
    if (Array.isArray(policies)) {
      return policies;
    }

    return [
      { title: 'Check-In Time', body: get('policy_checkin', DEFAULTS.policy_checkin) },
      { title: 'Check-Out Time', body: get('policy_checkout', DEFAULTS.policy_checkout) },
      { title: 'Down Payment Policy', body: get('policy_downpayment', DEFAULTS.policy_downpayment) },
      { title: 'Cancellation Policy', body: get('policy_cancellation', DEFAULTS.policy_cancellation) },
      { title: 'Request Limit', body: get('policy_requests', DEFAULTS.policy_requests) },
      { title: 'Rebooking Policy', body: get('policy_rebooking', DEFAULTS.policy_rebooking) },
      { title: 'ID Requirement', body: get('policy_id', DEFAULTS.policy_id) },
    ].filter((policy) => String(policy.body ?? '').trim() !== '');
  }, [cms]);

  const addonsItems = useMemo(() => {
    const items = parseJsonArray(cms.addons_items, null);
    return Array.isArray(items) ? items : FALLBACK_ADDONS;
  }, [cms]);

  const brandSettings = useMemo(() => ({
    primary: get('brand_primary_color', DEFAULTS.brand_primary_color),
    accent: get('brand_accent_color', DEFAULTS.brand_accent_color),
    button: get('brand_button_color', DEFAULTS.brand_button_color),
    buttonHover: get('brand_button_hover_color', DEFAULTS.brand_button_hover_color),
  }), [cms]);

  useEffect(() => {
    const root = document.documentElement;
    root.style.setProperty('--color-primary', brandSettings.primary || DEFAULTS.brand_primary_color);
    root.style.setProperty('--color-accent', brandSettings.accent || DEFAULTS.brand_accent_color);
    root.style.setProperty('--color-button', brandSettings.button || DEFAULTS.brand_button_color);
    root.style.setProperty('--color-button-hover', brandSettings.buttonHover || DEFAULTS.brand_button_hover_color);
  }, [brandSettings]);

  const getPolicies = () => policiesItems.map((policy) => policy.body).filter(Boolean);

  const getRoomLabel = (type) => {
    const key = `room_${type?.toLowerCase().replace(/-/g, '_')}_label`;
    return get(key) || type;
  };

  const getRoomContent = (slug) => {
    const key = slug?.toLowerCase().replace(/-/g, '_');
    return {
      label: get(`room_${key}_label`),
      tagline: get(`room_${key}_tagline`),
      description: get(`room_${key}_description`),
      image: get(`room_${key}_image`),
    };
  };

  const getAddonContent = (addonId) => {
    const match = addonsItems.find((addon) => String(addon.id) === String(addonId));
    return {
      image: match?.image ?? '',
      description: match?.display_description ?? '',
    };
  };

  return (
    <CmsContext.Provider
      value={{
        cms,
        loaded,
        get,
        getPolicies,
        getRoomLabel,
        getRoomContent,
        getAddonContent,
        highlightsItems,
        statsItems,
        testimonialsItems,
        nearbyItems,
        galleryItems,
        policiesItems,
        addonsItems,
        brandSettings,
        taxRate,
        downpaymentRate,
      }}
    >
      {children}
    </CmsContext.Provider>
  );
};

export const useCms = () => useContext(CmsContext);

export default CmsContext;

import { useLayoutEffect } from 'react';
import { useLocation } from 'react-router-dom';
import './PublicScrollMotion.css';

const NON_PUBLIC_PREFIXES = [
  '/admin',
  '/receptionist',
  '/login',
  '/forgot-password',
  '/reset-password',
  '/unauthorized',
];

const revealGroups = [
  {
    variant: 'left',
    selector: [
      '.home .hero-left',
      '.home .stats-left',
      '.home .location-content',
      '.room-detail-page .overview-gallery',
      '.contact-page .contact-hero-copy',
      '.contact-page .contact-info-panel',
    ].join(', '),
  },
  {
    variant: 'right',
    selector: [
      '.home .hero-right',
      '.home .stats-right',
      '.home .location-map',
      '.room-detail-page .overview-details',
      '.contact-page .contact-hero-note',
      '.contact-page .contact-form-panel',
    ].join(', '),
  },
  {
    variant: 'up',
    selector: [
      '.home .section-highlights .section-label',
      '.home .highlights-title',
      '.home .highlights-sub',
      '.home .highlight-card',
      '.home .gallery-heading-row > *',
      '.home .gallery-item',
      '.home .stat-item',
      '.home .section-testimonials .section-label',
      '.home .testimonials-title',
      '.home .testimonial-card',
      '.home .section-cta > *',
      '.home .nearby-heading-row > *',
      '.home .nearby-card',
      '.select-room-page .hotel-info-card',
      '.select-room-page .booking-summary-inline',
      '.select-room-page .page-header .container',
      '.select-room-page .filter-bar .container',
      '.select-room-page .section-heading',
      '.select-room-page .room-card',
      '.select-room-page .cart-card',
      '.room-detail-page .room-hero-meta .container',
      '.room-detail-page .section-header',
      '.room-detail-page .facility-pill',
      '.room-detail-page .text-card',
      '.room-detail-page .related-room-card',
      '.contact-page .contact-info-item',
      '.my-booking-page .page-title',
      '.my-booking-page .booking-card',
      '.addons-page .addons-title',
      '.addons-page .addon-category-title',
      '.addons-page .addon-card',
      '.addons-page .cart-card',
      '.booking-details-page .details-header .container',
      '.booking-details-page .alert',
      '.booking-details-page .details-card',
      '.booking-details-page .summary-card',
      '.footer .footer-content > *',
      '.footer .footer-bottom',
    ].join(', '),
  },
];

const isPublicPath = (pathname) => !NON_PUBLIC_PREFIXES.some(
  (prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`),
);

const PublicScrollMotion = () => {
  const { pathname } = useLocation();

  useLayoutEffect(() => {
    if (!isPublicPath(pathname) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      return undefined;
    }

    const animatedElements = new Set();
    const supportsObserver = 'IntersectionObserver' in window;
    const revealObserver = supportsObserver
      ? new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;

          entry.target.classList.add('is-revealed');
          revealObserver.unobserve(entry.target);
        });
      }, {
        threshold: 0.12,
        rootMargin: '0px 0px -7% 0px',
      })
      : null;

    const registerElement = (element, variant, selector) => {
      if (animatedElements.has(element)) return;

      const matchingSiblings = element.parentElement
        ? Array.from(element.parentElement.children).filter((sibling) => sibling.matches(selector))
        : [];
      const siblingIndex = Math.max(0, matchingSiblings.indexOf(element));

      element.classList.add('public-scroll-reveal', `public-scroll-reveal--${variant}`);
      element.style.setProperty('--public-reveal-delay', `${Math.min(siblingIndex, 3) * 70}ms`);
      animatedElements.add(element);

      if (revealObserver) {
        revealObserver.observe(element);
      } else {
        element.classList.add('is-revealed');
      }
    };

    const registerWithin = (root) => {
      revealGroups.forEach(({ selector, variant }) => {
        if (root instanceof Element && root.matches(selector)) {
          registerElement(root, variant, selector);
        }

        root.querySelectorAll?.(selector).forEach((element) => {
          registerElement(element, variant, selector);
        });
      });
    };

    document.documentElement.classList.add('public-scroll-motion-enabled');
    registerWithin(document);

    const mutationObserver = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
          if (node instanceof Element) registerWithin(node);
        });
      });
    });

    mutationObserver.observe(document.body, { childList: true, subtree: true });

    return () => {
      mutationObserver.disconnect();
      revealObserver?.disconnect();
      document.documentElement.classList.remove('public-scroll-motion-enabled');

      animatedElements.forEach((element) => {
        element.classList.remove(
          'public-scroll-reveal',
          'public-scroll-reveal--up',
          'public-scroll-reveal--left',
          'public-scroll-reveal--right',
          'is-revealed',
        );
        element.style.removeProperty('--public-reveal-delay');
      });
    };
  }, [pathname]);

  return null;
};

export default PublicScrollMotion;

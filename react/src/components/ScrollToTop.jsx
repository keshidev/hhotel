import React, { useState, useEffect, useRef } from 'react';
import { ChevronUp } from 'lucide-react';
import { useLocation } from 'react-router-dom';
import './ScrollToTop.css';

const ScrollToTop = () => {
  const { pathname, hash } = useLocation();
  const [isVisible, setIsVisible] = useState(false);
  const [isMouseMoving, setIsMouseMoving] = useState(false);
  const mouseMoveTimeout = useRef(null);

  const toggleVisibility = () => {
    if (window.pageYOffset > 300) {
      setIsVisible(true);
    } else {
      setIsVisible(false);
    }
  };

  const handleMouseMove = () => {
    // Show button when mouse moves
    setIsMouseMoving(true);

    // Clear existing timeout
    if (mouseMoveTimeout.current) {
      clearTimeout(mouseMoveTimeout.current);
    }

    // Hide button after 2 seconds of no mouse movement
    mouseMoveTimeout.current = setTimeout(() => {
      setIsMouseMoving(false);
    }, 2000);
  };

  const scrollToTop = () => {
    window.scrollTo({
      top: 0,
      behavior: 'smooth'
    });
  };

  useEffect(() => {
    if (hash) {
      window.requestAnimationFrame(() => {
        document.getElementById(hash.slice(1))?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    } else {
      window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
    }
    setIsVisible(false);
  }, [pathname, hash]);

  useEffect(() => {
    window.addEventListener('scroll', toggleVisibility);
    window.addEventListener('mousemove', handleMouseMove);
    
    return () => {
      window.removeEventListener('scroll', toggleVisibility);
      window.removeEventListener('mousemove', handleMouseMove);
      if (mouseMoveTimeout.current) {
        clearTimeout(mouseMoveTimeout.current);
      }
    };
  }, []);

  return (
    <button
      className={`scroll-to-top ${isVisible && isMouseMoving ? 'visible' : ''}`}
      onClick={scrollToTop}
      aria-label="Scroll to top"
      title="Back to top"
      style={{ pointerEvents: isVisible && isMouseMoving ? 'auto' : 'none' }}
    >
      <ChevronUp size={20} />
    </button>
  );
};

export default ScrollToTop;

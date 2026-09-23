import React, { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Eye, EyeOff, AlertCircle } from 'lucide-react';
import { useAuth } from '../../context/AuthContext';
import './Login.css';

const LOGIN_RETRY_STORAGE_KEY = 'staffLoginRetryUntil';

const readStoredRetryUntil = () => {
  try {
    const storedValue = Number(sessionStorage.getItem(LOGIN_RETRY_STORAGE_KEY));
    return Number.isFinite(storedValue) && storedValue > Date.now() ? storedValue : 0;
  } catch {
    return 0;
  }
};

const storeRetryUntil = (timestamp) => {
  try {
    sessionStorage.setItem(LOGIN_RETRY_STORAGE_KEY, String(timestamp));
    return true;
  } catch {
    return false;
  }
};

const clearStoredRetryUntil = () => {
  try {
    sessionStorage.removeItem(LOGIN_RETRY_STORAGE_KEY);
    return true;
  } catch {
    return false;
  }
};

const secondsUntil = (timestamp) => Math.max(0, Math.ceil((timestamp - Date.now()) / 1000));

const resolveRetrySeconds = (error) => {
  const responseSeconds = Number(error.response?.data?.retry_after_seconds);
  if (Number.isFinite(responseSeconds) && responseSeconds > 0) {
    return Math.ceil(responseSeconds);
  }

  const headers = error.response?.headers;
  const retryAfter = typeof headers?.get === 'function'
    ? headers.get('retry-after')
    : headers?.['retry-after'];
  const numericSeconds = Number(retryAfter);
  if (Number.isFinite(numericSeconds) && numericSeconds > 0) {
    return Math.ceil(numericSeconds);
  }

  const retryDate = Date.parse(retryAfter);
  return Number.isNaN(retryDate) ? 60 : Math.max(1, secondsUntil(retryDate));
};

const formatCountdown = (totalSeconds) => {
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;
  return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
};

const Login = () => {
  const navigate = useNavigate();
  const { login } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const [retryUntil, setRetryUntil] = useState(readStoredRetryUntil);
  const [retrySeconds, setRetrySeconds] = useState(() => secondsUntil(readStoredRetryUntil()));

  useEffect(() => {
    if (!retryUntil) return undefined;

    const updateCountdown = () => {
      const remaining = secondsUntil(retryUntil);
      setRetrySeconds(remaining);

      if (remaining === 0) {
        setRetryUntil(0);
        setError('');
        clearStoredRetryUntil();
      }
    };

    updateCountdown();
    const intervalId = window.setInterval(updateCountdown, 1000);
    return () => window.clearInterval(intervalId);
  }, [retryUntil]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (retrySeconds > 0 || loading) return;

    const normalizedEmail = email.trim().toLowerCase();
    if (!normalizedEmail || !password) {
      setError('Email and password are required.');
      return;
    }

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(normalizedEmail)) {
      setError('Please enter a valid email address.');
      return;
    }

    setError('');
    setLoading(true);
    try {
      const data = await login(normalizedEmail, password);
      clearStoredRetryUntil();
      if (data.user.role === 'admin') {
        navigate('/admin/dashboard');
      } else if (data.user.role === 'receptionist') {
        navigate('/receptionist/dashboard');
      } else {
        navigate('/dashboard');
      }
    } catch (err) {
      if (err.response?.status === 429) {
        const nextRetryUntil = Date.now() + (resolveRetrySeconds(err) * 1000);
        setRetryUntil(nextRetryUntil);
        setRetrySeconds(secondsUntil(nextRetryUntil));
        setError(err.response?.data?.message || 'Too many login attempts. Please wait before trying again.');
        storeRetryUntil(nextRetryUntil);
      } else {
        setError(err.response?.data?.message || 'Invalid email or password. Please try again.');
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="login-root">

      {/* ── Left decorative panel ── */}
      <div className="login-panel-left">
        {/* <div className="lp-logo">
          <div className="lp-logo-mark">
            //<svg viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M14 4C9.58172 4 6 7.58172 6 12C6 15.3137 7.97898 18.1597 10.8 19.4776V22H13V24H15V22H17V19.4776C19.822 18.1597 22 15.3137 22 12C22 7.58172 18.4183 4 14 4Z" fill="white" fillOpacity="0.9"/>
              <circle cx="14" cy="12" r="3" fill="currentColor" />
            </svg>
          </div>
          <div className="lp-logo-name">H+ HOTEL</div>
        </div> */}
        <div style={{ height: '52px' }} />

        <div className="lp-hero">
          <h2 className="lp-headline">
            Welcome to<br />
            <em>H+ HOTEL</em><br />
            Staff Portal
          </h2>
          <p className="lp-tagline">
            Your all-in-one property management hub. Manage bookings, guests, and operations with elegance.
          </p>
        </div>

        <div className="lp-footer">
          &copy; {new Date().getFullYear()} H+ HOTEL. All rights reserved.
        </div>
      </div>

      {/* ── Right form panel ── */}
      <div className="login-panel-right">
        <div className="login-form-wrap">
          <div className="login-greeting">
            {/* Logo on right panel */}
            <img
              src="/images/logo/logo-withoutbg.png"
              alt="H+ Hotel"
              style={{ height: '64px', width: 'auto', objectFit: 'contain', marginBottom: '1.25rem', display: 'block' }}
            />
            <h1>Welcome Back</h1>
            <p>Sign in to your staff account to continue.</p>
          </div>

          <form className="login-form" onSubmit={handleSubmit} noValidate>
            {error && (
              <div className="login-error" role="alert" aria-live="assertive">
                <AlertCircle size={16} />
                <div className="login-error-copy">
                  <span>{error}</span>
                  {retrySeconds > 0 && (
                    <strong>Try again in {formatCountdown(retrySeconds)}</strong>
                  )}
                </div>
              </div>
            )}

            {/* Email */}
            <div className="form-group">
              <label htmlFor="email">Email Address</label>
              <input
                id="email"
                className="form-input"
                type="email"
                placeholder="Enter your email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                maxLength={254}
                required
                autoComplete="email"
              />
            </div>

            {/* Password */}
            <div className="form-group">
              <label htmlFor="password">Password</label>
              <div className="password-wrap">
                <input
                  id="password"
                  className="form-input password-input"
                  type={showPassword ? 'text' : 'password'}
                  placeholder="Enter your password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  maxLength={255}
                  required
                  autoComplete="current-password"
                />
                <button
                  type="button"
                  className="eye-toggle"
                  onClick={() => setShowPassword(!showPassword)}
                  aria-label="Toggle password visibility"
                >
                  {showPassword ? <EyeOff size={17} /> : <Eye size={17} />}
                </button>
              </div>
            </div>

            <div className="form-options">
              <Link to="/forgot-password" className="forgot-link">Forgot password?</Link>
            </div>

            <button type="submit" className="login-submit" disabled={loading || retrySeconds > 0}>
              <span>{loading ? 'Signing in…' : 'Sign In'}</span>
            </button>
          </form>
        </div>
      </div>
    </div>
  );
};

export default Login;

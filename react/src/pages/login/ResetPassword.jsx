import React, { useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import authService from '../../services/authService';
import './AuthRecovery.css';

const firstValidationMessage = (error) => {
  const errors = error?.response?.data?.errors;
  const firstError = errors && Object.values(errors).flat()[0];
  return firstError || error?.response?.data?.message;
};

const maskEmail = (email) => {
  const [name, domain] = email.split('@');
  if (!name || !domain) return email;
  const visible = name.slice(0, Math.min(2, name.length));
  return `${visible}${'*'.repeat(Math.max(3, name.length - visible.length))}@${domain}`;
};

const ResetPassword = () => {
  const navigate = useNavigate();
  const [recovery] = useState(() => {
    const params = new URLSearchParams(window.location.search);
    return {
      token: params.get('token') || '',
      email: params.get('email') || '',
      mode: params.get('mode') || '',
    };
  });
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const redirectTimerRef = useRef(null);
  const hasValidLink = Boolean(recovery.token && recovery.email);
  const isAccountSetup = recovery.mode === 'setup';

  useEffect(() => {
    window.history.replaceState({}, document.title, window.location.pathname);

    return () => {
      if (redirectTimerRef.current) clearTimeout(redirectTimerRef.current);
    };
  }, []);

  const handleSubmit = async (event) => {
    event.preventDefault();
    setMessage('');
    setError('');

    if (!hasValidLink) {
      setError('This reset link is incomplete. Please request a new one.');
      return;
    }

    if (password !== passwordConfirmation) {
      setError('Password confirmation does not match.');
      return;
    }

    setLoading(true);

    try {
      const response = await authService.resetPassword({
        email: recovery.email,
        token: recovery.token,
        password,
        password_confirmation: passwordConfirmation,
      });
      setPassword('');
      setPasswordConfirmation('');
      setMessage(isAccountSetup ? 'Password set successfully. Please sign in.' : (response?.message || 'Password reset successful. Please log in.'));
      redirectTimerRef.current = setTimeout(() => navigate('/login', { replace: true }), 1800);
    } catch (requestError) {
      setError(firstValidationMessage(requestError) || 'Unable to reset password. Please request a new link.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="auth-recovery-root">
      <div className="auth-recovery-card">
        <div className="auth-recovery-eyebrow">{isAccountSetup ? 'Staff account setup' : 'Staff account recovery'}</div>
        <h1>{isAccountSetup ? 'Set Your Password' : 'Set a New Password'}</h1>
        <p className="auth-recovery-sub">
          {isAccountSetup
            ? 'Choose a strong password for your new staff account.'
            : 'Choose a strong password. Your other signed-in sessions will be closed for safety.'}
        </p>

        {!hasValidLink && (
          <div className="auth-recovery-message error">
            This reset link is incomplete. Request a new password reset email.
          </div>
        )}
        {message && <div className="auth-recovery-message success">{message}</div>}
        {error && <div className="auth-recovery-message error">{error}</div>}

        {hasValidLink && (
          <div className="auth-recovery-account">
            <span>{isAccountSetup ? 'Setting up account for' : 'Resetting password for'}</span>
            <strong>{maskEmail(recovery.email)}</strong>
          </div>
        )}

        <form onSubmit={handleSubmit}>
          <div className="auth-recovery-field">
            <label htmlFor="password">New Password</label>
            <input
              id="password"
              className="auth-recovery-input"
              type="password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              autoComplete="new-password"
              minLength={12}
              disabled={!hasValidLink || loading}
              required
            />
          </div>

          <div className="auth-recovery-field">
            <label htmlFor="password_confirmation">Confirm New Password</label>
            <input
              id="password_confirmation"
              className="auth-recovery-input"
              type="password"
              value={passwordConfirmation}
              onChange={(event) => setPasswordConfirmation(event.target.value)}
              autoComplete="new-password"
              minLength={12}
              disabled={!hasValidLink || loading}
              required
            />
          </div>

          <ul className="auth-recovery-requirements">
            <li>At least 12 characters</li>
            <li>Uppercase and lowercase letters</li>
            <li>At least one number and one symbol</li>
          </ul>

          <button className="auth-recovery-btn" disabled={!hasValidLink || loading} type="submit">
            {loading ? 'Securing Account...' : (isAccountSetup ? 'Set Password' : 'Reset Password')}
          </button>
        </form>

        <div className="auth-recovery-footer">
          {!hasValidLink && <Link to="/forgot-password">Request a new reset link</Link>}
          {hasValidLink && <Link to="/login">Back to login</Link>}
        </div>
      </div>
    </div>
  );
};

export default ResetPassword;

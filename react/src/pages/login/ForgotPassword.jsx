import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import authService from '../../services/authService';
import './AuthRecovery.css';

const firstValidationMessage = (error) => {
  const errors = error?.response?.data?.errors;
  const firstError = errors && Object.values(errors).flat()[0];
  return firstError || error?.response?.data?.message;
};

const ForgotPassword = () => {
  const [email, setEmail] = useState('');
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);
    setMessage('');
    setError('');

    try {
      const response = await authService.forgotPassword(email.trim());
      setMessage(response?.message || 'If an active staff account uses that email, a reset link has been sent.');
    } catch (err) {
      setError(firstValidationMessage(err) || 'Unable to send reset link right now.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="auth-recovery-root">
      <div className="auth-recovery-card">
        <div className="auth-recovery-eyebrow">Staff account recovery</div>
        <h1>Forgot Password</h1>
        <p className="auth-recovery-sub">
          Enter your account email. If it exists, we will send a reset link.
        </p>

        {message && <div className="auth-recovery-message success">{message}</div>}
        {error && <div className="auth-recovery-message error">{error}</div>}

        <form onSubmit={handleSubmit}>
          <div className="auth-recovery-field">
            <label htmlFor="email">Email Address</label>
            <input
              id="email"
              className="auth-recovery-input"
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              autoComplete="username"
              maxLength={254}
              required
            />
          </div>

          <button className="auth-recovery-btn" disabled={loading} type="submit">
            {loading ? 'Sending...' : 'Send Reset Link'}
          </button>
        </form>

        <div className="auth-recovery-footer">
          <Link to="/login">Back to login</Link>
        </div>
      </div>
    </div>
  );
};

export default ForgotPassword;

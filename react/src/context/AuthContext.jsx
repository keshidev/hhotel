// src/context/AuthContext.jsx
import React, { createContext, useState, useContext, useEffect } from 'react';
import authService, { clearUser } from '../services/authService';

const AuthContext = createContext(null);

export const AuthProvider = ({ children }) => {
    const [user, setUser] = useState(null);
    const [loading, setLoading] = useState(false);
    const [initializing, setInitializing] = useState(true);
    const [isAuthenticated, setIsAuthenticated] = useState(false);

    useEffect(() => {
        const checkAuth = async () => {
            try {
                const savedUser = authService.getUser();
                if (!savedUser) {
                    setUser(null);
                    setIsAuthenticated(false);
                    return;
                }

                setUser(savedUser);
                setIsAuthenticated(true);

                try {
                    const freshUser = await authService.me();
                    setUser(freshUser);
                    setIsAuthenticated(true);
                } catch (error) {
                    if ([401, 403].includes(error.response?.status)) {
                        clearUser();
                        setUser(null);
                        setIsAuthenticated(false);
                    }
                }
            } catch (error) {
                console.error('Auth check failed:', error);
                clearUser();
                setUser(null);
                setIsAuthenticated(false);
            } finally {
                setInitializing(false);
            }
        };

        checkAuth();
    }, []);

    useEffect(() => {
        if (!isAuthenticated) return;

        const syncCurrentUser = async () => {
            try {
                const freshUser = await authService.me();
                setUser(freshUser);
            } catch (error) {
                if ([401, 403].includes(error.response?.status)) {
                    clearUser();
                    setUser(null);
                    setIsAuthenticated(false);
                }
            }
        };

        const onFocus = () => syncCurrentUser();
        window.addEventListener('focus', onFocus);

        return () => {
            window.removeEventListener('focus', onFocus);
        };
    }, [isAuthenticated]);

    useEffect(() => {
        const role = user?.role;
        const isStaffSession = isAuthenticated && (role === 'admin' || role === 'receptionist');
        if (!isStaffSession) return;

        const IDLE_TIMEOUT_MS = 15 * 60 * 1000;
        const activityEvents = ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click'];
        let idleTimer = null;

        const forceLogout = async () => {
            try {
                await authService.logout();
            } catch {
                clearUser();
            } finally {
                setUser(null);
                setIsAuthenticated(false);
                window.location.href = '/login';
            }
        };

        const resetIdleTimer = () => {
            if (idleTimer) clearTimeout(idleTimer);
            idleTimer = setTimeout(forceLogout, IDLE_TIMEOUT_MS);
        };

        const onVisibilityChange = () => {
            if (!document.hidden) resetIdleTimer();
        };

        activityEvents.forEach((eventName) => {
            window.addEventListener(eventName, resetIdleTimer, { passive: true });
        });
        document.addEventListener('visibilitychange', onVisibilityChange);
        resetIdleTimer();

        return () => {
            if (idleTimer) clearTimeout(idleTimer);
            activityEvents.forEach((eventName) => {
                window.removeEventListener(eventName, resetIdleTimer);
            });
            document.removeEventListener('visibilitychange', onVisibilityChange);
        };
    }, [isAuthenticated, user?.role]);

    const login = async (email, password) => {
        setLoading(true);
        try {
            const data = await authService.login(email, password);
            setUser(data.user);
            setIsAuthenticated(true);
            return data;
        } finally {
            setLoading(false);
        }
    };

    const logout = async () => {
        setLoading(true);
        try {
            await authService.logout();
        } finally {
            setUser(null);
            setIsAuthenticated(false);
            setLoading(false);
        }
    };

    const updateUser = (userData) => {
        setUser(userData);
        authService.setUser(userData);
    };

    return (
        <AuthContext.Provider
            value={{
                user,
                loading,
                initializing,
                isAuthenticated,
                login,
                logout,
                updateUser,
                isAdmin: user?.role === 'admin',
                isReceptionist: user?.role === 'receptionist',
            }}
        >
            {children}
        </AuthContext.Provider>
    );
};

export const useAuth = () => {
    const context = useContext(AuthContext);
    if (!context) {
        throw new Error('useAuth must be used within an AuthProvider');
    }
    return context;
};

export default AuthContext;

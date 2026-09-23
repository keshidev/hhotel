const STAFF_ROLES = ['admin', 'receptionist'];
const ACTIVE_ROLE_KEY = 'active_staff_role';

const ROLE_STORAGE = {
    admin: {
        token: 'admin_token',
        user: 'admin_user',
    },
    receptionist: {
        token: 'receptionist_token',
        user: 'receptionist_user',
    },
};

const isBrowser = typeof window !== 'undefined';

if (isBrowser) {
    Object.values(ROLE_STORAGE).forEach(({ token, user }) => {
        localStorage.removeItem(token);
        localStorage.removeItem(user);
    });
}

const safeJsonParse = (value) => {
    if (!value) {
        return null;
    }

    try {
        return JSON.parse(value);
    } catch {
        return null;
    }
};

const inferRoleFromPath = () => {
    if (!isBrowser) {
        return null;
    }

    const path = window.location.pathname.toLowerCase();

    if (path.startsWith('/admin')) {
        return 'admin';
    }

    if (path.startsWith('/receptionist')) {
        return 'receptionist';
    }

    return null;
};

const getRoleConfig = (role) => ROLE_STORAGE[role] ?? null;

export const isStaffRole = (role) => STAFF_ROLES.includes(role);

export const setActiveRole = (role) => {
    if (!isBrowser || !isStaffRole(role)) {
        return;
    }

    sessionStorage.setItem(ACTIVE_ROLE_KEY, role);
};

export const getActiveRole = () => {
    if (!isBrowser) {
        return null;
    }

    const storedRole = sessionStorage.getItem(ACTIVE_ROLE_KEY);
    if (isStaffRole(storedRole)) {
        return storedRole;
    }

    const pathRole = inferRoleFromPath();
    if (pathRole) {
        sessionStorage.setItem(ACTIVE_ROLE_KEY, pathRole);
        return pathRole;
    }

    return null;
};

export const resolveRole = (role) => {
    if (isStaffRole(role)) {
        return role;
    }

    return getActiveRole();
};

export const getRoleToken = (role) => {
    if (!isBrowser) {
        return null;
    }

    const config = getRoleConfig(role);
    if (!config) {
        return null;
    }

    return sessionStorage.getItem(config.token);
};

export const getRoleUser = (role) => {
    if (!isBrowser) {
        return null;
    }

    const config = getRoleConfig(role);
    if (!config) {
        return null;
    }

    return safeJsonParse(sessionStorage.getItem(config.user));
};

export const setRoleUser = (role, user) => {
    if (!isBrowser) {
        return;
    }

    const config = getRoleConfig(role);
    if (!config || !user) {
        return;
    }

    sessionStorage.setItem(config.user, JSON.stringify(user));
    setActiveRole(role);
};

export const setRoleAuth = (role, payload = {}) => {
    if (!isBrowser) {
        return;
    }

    const config = getRoleConfig(role);
    if (!config) {
        return;
    }

    const { user, token } = payload;

    if (user) {
        sessionStorage.setItem(config.user, JSON.stringify(user));
    }

    if (token) {
        sessionStorage.setItem(config.token, token);
    }

    setActiveRole(role);
};

export const clearRoleAuth = (role) => {
    if (!isBrowser) {
        return;
    }

    const config = getRoleConfig(role);
    if (!config) {
        return;
    }

    sessionStorage.removeItem(config.user);
    sessionStorage.removeItem(config.token);
    localStorage.removeItem(config.user);
    localStorage.removeItem(config.token);

    if (sessionStorage.getItem(ACTIVE_ROLE_KEY) === role) {
        sessionStorage.removeItem(ACTIVE_ROLE_KEY);
    }
};

export const clearAllStaffAuth = () => {
    STAFF_ROLES.forEach((role) => clearRoleAuth(role));
};

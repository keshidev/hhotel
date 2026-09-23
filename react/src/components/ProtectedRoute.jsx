// src/components/ProtectedRoute.jsx
import React, { useEffect, useState } from 'react';
import { Navigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

const ProtectedRoute = ({ children, allowedRoles = [], redirectTo = '/login' }) => {
    const { isAuthenticated, loading, initializing, user } = useAuth();

    // Wait for tab-scoped auth hydration and any async auth operations.
    // Without this, user=null on first render → wrongly redirects to /unauthorized
    if (initializing || loading) {
        return <ReceptionistSkeletonLoader />;
    }

    if (!isAuthenticated) {
        return <Navigate to={redirectTo} replace />;
    }

    if (allowedRoles.length > 0 && !allowedRoles.includes(user?.role)) {
        return <Navigate to="/unauthorized" replace />;
    }

    return children;
};

// Page cache manager - stores when each page was last loaded
const PAGE_CACHE_DURATION = 2 * 60 * 60 * 1000; // 2 hours

export const usePageCache = (pageName) => {
    const [shouldShowSkeleton, setShouldShowSkeleton] = useState(true);

    useEffect(() => {
        const cacheKey = `page_cache_${pageName}`;
        const cachedData = localStorage.getItem(cacheKey);

        if (cachedData) {
            try {
                const { timestamp } = JSON.parse(cachedData);
                const now = Date.now();

                if (now - timestamp < PAGE_CACHE_DURATION) {
                    setShouldShowSkeleton(false);
                } else {
                    localStorage.removeItem(cacheKey);
                    setShouldShowSkeleton(true);
                }
            } catch {
                localStorage.removeItem(cacheKey);
                setShouldShowSkeleton(true);
            }
        } else {
            setShouldShowSkeleton(true);
        }
    }, [pageName]);

    const markPageAsLoaded = () => {
        const cacheKey = `page_cache_${pageName}`;
        localStorage.setItem(cacheKey, JSON.stringify({ timestamp: Date.now() }));
    };

    return { shouldShowSkeleton, markPageAsLoaded };
};

// Receptionist Panel Skeleton Loader
export const ReceptionistSkeletonLoader = () => {
    return (
        <div style={{ minHeight: '100vh', backgroundColor: '#f9fafb' }}>
            {/* Sidebar Skeleton */}
            <div style={{
                position: 'fixed',
                left: 0,
                top: 0,
                bottom: 0,
                width: '240px',
                backgroundColor: '#fff',
                borderRight: '1px solid #e5e7eb',
                padding: '1.5rem 1rem',
                zIndex: 100
            }}>
                {/* Logo Skeleton */}
                <div style={{
                    height: '40px',
                    backgroundColor: '#e5e7eb',
                    borderRadius: '8px',
                    marginBottom: '2rem',
                    animation: 'pulse 1.5s ease-in-out infinite'
                }}></div>

                {/* Menu Items Skeleton */}
                {[1, 2, 3, 4, 5, 6].map((i) => (
                    <div key={i} style={{
                        height: '44px',
                        backgroundColor: '#f3f4f6',
                        borderRadius: '8px',
                        marginBottom: '0.5rem',
                        animation: 'pulse 1.5s ease-in-out infinite',
                        animationDelay: `${i * 0.1}s`
                    }}></div>
                ))}
            </div>

            {/* Main Content Skeleton */}
            <div style={{ marginLeft: '240px', padding: '2rem' }}>
                {/* Page Header Skeleton */}
                <div style={{ marginBottom: '2rem' }}>
                    <div style={{
                        height: '32px',
                        width: '200px',
                        backgroundColor: '#e5e7eb',
                        borderRadius: '6px',
                        marginBottom: '0.5rem',
                        animation: 'pulse 1.5s ease-in-out infinite'
                    }}></div>
                    <div style={{
                        height: '20px',
                        width: '300px',
                        backgroundColor: '#f3f4f6',
                        borderRadius: '6px',
                        animation: 'pulse 1.5s ease-in-out infinite',
                        animationDelay: '0.1s'
                    }}></div>
                </div>

                {/* Stats Cards Skeleton */}
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(auto-fit, minmax(250px, 1fr))',
                    gap: '1.5rem',
                    marginBottom: '2rem'
                }}>
                    {[1, 2, 3, 4].map((i) => (
                        <div key={i} style={{
                            backgroundColor: '#fff',
                            borderRadius: '12px',
                            padding: '1.5rem',
                            border: '1px solid #e5e7eb'
                        }}>
                            <div style={{
                                display: 'flex',
                                justifyContent: 'space-between',
                                marginBottom: '1rem'
                            }}>
                                <div style={{
                                    height: '48px',
                                    width: '48px',
                                    backgroundColor: '#f3f4f6',
                                    borderRadius: '10px',
                                    animation: 'pulse 1.5s ease-in-out infinite',
                                    animationDelay: `${i * 0.1}s`
                                }}></div>
                                <div style={{
                                    height: '24px',
                                    width: '60px',
                                    backgroundColor: '#f3f4f6',
                                    borderRadius: '6px',
                                    animation: 'pulse 1.5s ease-in-out infinite',
                                    animationDelay: `${i * 0.1}s`
                                }}></div>
                            </div>
                            <div style={{
                                height: '32px',
                                width: '80px',
                                backgroundColor: '#e5e7eb',
                                borderRadius: '6px',
                                marginBottom: '0.5rem',
                                animation: 'pulse 1.5s ease-in-out infinite',
                                animationDelay: `${i * 0.1}s`
                            }}></div>
                            <div style={{
                                height: '20px',
                                width: '120px',
                                backgroundColor: '#f3f4f6',
                                borderRadius: '6px',
                                animation: 'pulse 1.5s ease-in-out infinite',
                                animationDelay: `${i * 0.1}s`
                            }}></div>
                        </div>
                    ))}
                </div>

                {/* Table Skeleton */}
                <div style={{
                    backgroundColor: '#fff',
                    borderRadius: '12px',
                    border: '1px solid #e5e7eb',
                    overflow: 'hidden'
                }}>
                    {/* Table Header */}
                    <div style={{ padding: '1.5rem', borderBottom: '1px solid #e5e7eb' }}>
                        <div style={{
                            height: '24px',
                            width: '150px',
                            backgroundColor: '#e5e7eb',
                            borderRadius: '6px',
                            animation: 'pulse 1.5s ease-in-out infinite'
                        }}></div>
                    </div>

                    {/* Table Rows */}
                    <div style={{ padding: '1rem' }}>
                        {[1, 2, 3, 4, 5].map((row) => (
                            <div key={row} style={{
                                display: 'grid',
                                gridTemplateColumns: 'repeat(5, 1fr)',
                                gap: '1rem',
                                padding: '1rem',
                                borderBottom: '1px solid #f3f4f6'
                            }}>
                                {[1, 2, 3, 4, 5].map((col) => (
                                    <div key={col} style={{
                                        height: '20px',
                                        backgroundColor: '#f3f4f6',
                                        borderRadius: '4px',
                                        animation: 'pulse 1.5s ease-in-out infinite',
                                        animationDelay: `${(row * 0.1) + (col * 0.05)}s`
                                    }}></div>
                                ))}
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            <style>{`
                @keyframes pulse {
                    0%, 100% { opacity: 1; }
                    50%       { opacity: 0.5; }
                }
            `}</style>
        </div>
    );
};

// Reusable page skeleton for individual pages
export const PageSkeletonLoader = ({ showStats = false }) => {
    return (
        <div className="page-skeleton">
            <div className="page-header">
                <div>
                    <div style={{
                        height: '32px',
                        width: '200px',
                        backgroundColor: '#e5e7eb',
                        borderRadius: '6px',
                        marginBottom: '0.5rem',
                        animation: 'pulse 1.5s ease-in-out infinite'
                    }}></div>
                    <div style={{
                        height: '20px',
                        width: '300px',
                        backgroundColor: '#f3f4f6',
                        borderRadius: '6px',
                        animation: 'pulse 1.5s ease-in-out infinite'
                    }}></div>
                </div>
            </div>

            {showStats && (
                <div style={{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))',
                    gap: '1rem',
                    marginBottom: '2rem'
                }}>
                    {[1, 2, 3, 4].map((i) => (
                        <div key={i} style={{
                            backgroundColor: '#fff',
                            borderRadius: '12px',
                            padding: '1.5rem',
                            border: '1px solid #e5e7eb'
                        }}>
                            <div style={{
                                height: '48px',
                                width: '48px',
                                backgroundColor: '#f3f4f6',
                                borderRadius: '10px',
                                marginBottom: '1rem',
                                animation: 'pulse 1.5s ease-in-out infinite'
                            }}></div>
                            <div style={{
                                height: '32px',
                                width: '80px',
                                backgroundColor: '#e5e7eb',
                                borderRadius: '6px',
                                marginBottom: '0.5rem',
                                animation: 'pulse 1.5s ease-in-out infinite'
                            }}></div>
                            <div style={{
                                height: '20px',
                                width: '120px',
                                backgroundColor: '#f3f4f6',
                                borderRadius: '6px',
                                animation: 'pulse 1.5s ease-in-out infinite'
                            }}></div>
                        </div>
                    ))}
                </div>
            )}

            <div style={{ display: 'flex', gap: '1rem', marginBottom: '1.5rem' }}>
                <div style={{
                    height: '40px',
                    flex: 1,
                    backgroundColor: '#f3f4f6',
                    borderRadius: '8px',
                    animation: 'pulse 1.5s ease-in-out infinite'
                }}></div>
                <div style={{
                    height: '40px',
                    width: '150px',
                    backgroundColor: '#f3f4f6',
                    borderRadius: '8px',
                    animation: 'pulse 1.5s ease-in-out infinite'
                }}></div>
            </div>

            <div className="table-card">
                <div style={{ padding: '1rem' }}>
                    {[1, 2, 3, 4, 5, 6].map((row) => (
                        <div key={row} style={{
                            display: 'grid',
                            gridTemplateColumns: 'repeat(6, 1fr)',
                            gap: '1rem',
                            padding: '1rem',
                            borderBottom: '1px solid #f3f4f6'
                        }}>
                            {[1, 2, 3, 4, 5, 6].map((col) => (
                                <div key={col} style={{
                                    height: '20px',
                                    backgroundColor: '#f3f4f6',
                                    borderRadius: '4px',
                                    animation: 'pulse 1.5s ease-in-out infinite',
                                    animationDelay: `${(row * 0.05) + (col * 0.02)}s`
                                }}></div>
                            ))}
                        </div>
                    ))}
                </div>
            </div>

            <style>{`
                @keyframes pulse {
                    0%, 100% { opacity: 1; }
                    50%       { opacity: 0.5; }
                }
            `}</style>
        </div>
    );
};

export default ProtectedRoute;

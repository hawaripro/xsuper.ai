import React, { createContext, useContext, useState, useEffect } from 'react';

const AuthContext = createContext(null);

function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]')?.content;
    if (meta) return meta;
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export function AuthProvider({ children }) {
    const [user, setUser] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => { checkAuth(); }, []);

    const checkAuth = async () => {
        try {
            const r = await fetch('/api/user', {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (r.ok) {
                const d = await r.json();
                setUser(d && d.id ? d : null);
            } else { setUser(null); }
        } catch { setUser(null); }
        finally { setLoading(false); }
    };

    const finishLogin = async () => {
        await checkAuth();
        return true;
    };

    const postAuth = async (path, body) => {
        const r = await fetch(path, {
            method: 'POST', credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify(body),
        });
        const data = await r.json().catch(() => ({}));
        if (!r.ok) {
            const error = new Error(data.message || 'Login gagal.');
            error.status = r.status;
            throw error;
        }
        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        if (csrfMeta && typeof data.csrf_token === 'string') csrfMeta.content = data.csrf_token;
        return data;
    };

    // Resolves to `{ twoFactor: true }` when the account needs an authenticator code before the session opens.
    const login = async (email, password) => {
        const data = await postAuth('/api/login', { email, password });
        if (data.two_factor === true) return { twoFactor: true };
        return finishLogin();
    };

    const completeTwoFactor = async ({ code, recoveryCode }) => {
        await postAuth('/api/login/two-factor', recoveryCode ? { recovery_code: recoveryCode } : { code });
        return finishLogin();
    };

    const logout = async () => {
        try {
            await fetch('/api/logout', {
                method: 'POST', credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
            });
        } catch {}
        setUser(null);
    };

    return (
        <AuthContext.Provider value={{ user, loading, login, completeTwoFactor, logout, refreshUser: checkAuth }}>
            {children}
        </AuthContext.Provider>
    );
}

export function useAuth() {
    const context = useContext(AuthContext);
    if (!context) throw new Error('useAuth must be used within AuthProvider');
    return context;
}

import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { useAuth } from './AuthContext';
import { apiRequest } from '../lib/api';

const NotificationContext = createContext(null);
const FIRST_PAGE = { current_page: 1, last_page: 1, per_page: 20, total: 0 };

function safeActionPath(value) {
    if (typeof value !== 'string' || !value.startsWith('/') || value.startsWith('//')) return null;
    try {
        const decoded = decodeURIComponent(value);
        if (/[\\\u0000-\u0020\u007f]/.test(decoded) || decoded.startsWith('//') || decoded.startsWith('/en//')) return null;
        const url = new URL(value, window.location.origin);
        if (url.origin !== window.location.origin || url.username || url.password) return null;
        const path = url.pathname === '/en' ? '/' : url.pathname.startsWith('/en/') ? url.pathname.slice(3) : url.pathname;
        if (path.startsWith('//') || decodeURIComponent(path).startsWith('//')) return null;
        return `${path}${url.search}${url.hash}`;
    } catch {
        return null;
    }
}

export function NotificationProvider({ children }) {
    const { user } = useAuth();
    const userId = user?.id ? String(user.id) : null;

    // A new identity never renders the preceding identity's inbox, even for the
    // render before effect cleanup. Socket and request lifetimes follow this key.
    return <NotificationSession key={userId || 'signed-out'} userId={userId}>{children}</NotificationSession>;
}

function NotificationSession({ userId, children }) {
    const [data, setData] = useState({ items: [], unreadCount: 0, pagination: FIRST_PAGE, loading: Boolean(userId), error: null });
    const [connectionState, setConnectionState] = useState(userId ? 'connecting' : 'disconnected');
    const alive = useRef(true);
    const sessionInvalid = useRef(false);
    const page = useRef(1);
    const lastPage = useRef(1);
    const requestVersion = useRef(0);
    const inboxRequest = useRef(null);
    const pendingRequests = useRef(new Set());
    const mutations = useRef(new Map());
    const disconnect = useRef(() => {});

    const handleAuthFailure = useCallback((error) => {
        // 401/419 mean the session is gone. 403 does not: it is an authorisation
        // refusal (unverified email, device policy) on a still-valid session, and
        // latching it here permanently bricked the inbox for the rest of the visit.
        if (error.status !== 401 && error.status !== 419) return false;
        sessionInvalid.current = true;
        requestVersion.current += 1;
        inboxRequest.current?.abort();
        pendingRequests.current.forEach(controller => controller.abort());
        disconnect.current();
        page.current = 1;
        lastPage.current = 1;
        setConnectionState('disconnected');
        setData({ items: [], unreadCount: 0, pagination: FIRST_PAGE, loading: false, error });
        return true;
    }, []);

    const refresh = useCallback(async () => {
        if (!alive.current || sessionInvalid.current || !userId) return false;
        const version = ++requestVersion.current;
        inboxRequest.current?.abort();
        const controller = new AbortController();
        inboxRequest.current = controller;
        setData(current => ({ ...current, loading: true, error: null }));
        try {
            const response = await apiRequest(`/api/notifications?page=${page.current}&per_page=20`, { signal: controller.signal });
            if (!alive.current || controller.signal.aborted || version !== requestVersion.current) return false;
            lastPage.current = response.pagination.last_page;
            page.current = response.pagination.current_page;
            setData({
                items: response.notifications.map(item => ({ ...item, action_url: safeActionPath(item.action_url) })),
                unreadCount: response.unread_count,
                pagination: response.pagination,
                loading: false,
                error: null,
            });
            return true;
        } catch (error) {
            if (!alive.current || controller.signal.aborted || version !== requestVersion.current) return false;
            if (!handleAuthFailure(error)) setData(current => ({ ...current, loading: false, error }));
            return false;
        } finally {
            if (inboxRequest.current === controller) inboxRequest.current = null;
        }
    }, [userId, handleAuthFailure]);

    const setPage = useCallback((nextPage) => {
        const number = Number(nextPage);
        if (!alive.current || !Number.isInteger(number) || number < 1 || number > lastPage.current) return;
        if (page.current !== number) {
            page.current = number;
            setData(current => ({ ...current, items: [], loading: true, pagination: { ...current.pagination, current_page: number } }));
        }
        void refresh();
    }, [refresh]);

    const mutate = useCallback((key, path, method) => {
        if (!alive.current || sessionInvalid.current || !userId) return Promise.resolve(false);
        if (mutations.current.has(key)) return mutations.current.get(key);
        const controller = new AbortController();
        pendingRequests.current.add(controller);
        setData(current => ({ ...current, error: null }));
        const promise = (async () => {
            try {
                await apiRequest(path, { method, signal: controller.signal });
                if (!alive.current || controller.signal.aborted) return false;
                // HTTP is authoritative. A local decrement would double-count a
                // simultaneous websocket event or the same read in another tab.
                return await refresh();
            } catch (error) {
                if (alive.current && !controller.signal.aborted && !handleAuthFailure(error)) {
                    setData(current => ({ ...current, error }));
                }
                return false;
            } finally {
                pendingRequests.current.delete(controller);
                mutations.current.delete(key);
            }
        })();
        mutations.current.set(key, promise);
        return promise;
    }, [userId, refresh, handleAuthFailure]);

    const markRead = useCallback((id) => {
        if (!Number.isSafeInteger(Number(id)) || Number(id) < 1) return Promise.resolve(false);
        return mutate(`read:${id}`, `/api/notifications/${encodeURIComponent(id)}/read`, 'PATCH');
    }, [mutate]);

    const markAllRead = useCallback(() => mutate('read-all', '/api/notifications/read-all', 'POST'), [mutate]);

    useEffect(() => {
        alive.current = true;
        if (!userId) return () => { alive.current = false; };

        let echo = null;
        let connecting = false;
        let configRequest = null;
        let stopConnectionListener = null;
        let disposed = false;
        const channelName = `App.Models.User.${userId}`;

        const stopRealtime = () => {
            stopConnectionListener?.();
            stopConnectionListener = null;
            if (echo) {
                echo.leave(channelName);
                echo.disconnect();
                echo = null;
            }
        };
        disconnect.current = stopRealtime;

        const startRealtime = async () => {
            if (disposed || sessionInvalid.current || connecting || echo) return;
            connecting = true;
            configRequest = new AbortController();
            setConnectionState('connecting');
            try {
                const config = await apiRequest('/api/realtime/config', { signal: configRequest.signal });
                if (disposed || sessionInvalid.current) return;
                if (!config.enabled) {
                    setConnectionState('disabled');
                    return;
                }
                if (config.auth_endpoint !== '/api/broadcasting/auth' || !['http', 'https'].includes(config.scheme)) {
                    setConnectionState('unavailable');
                    return;
                }
                const [{ default: Echo }, { default: Pusher }] = await Promise.all([import('laravel-echo'), import('pusher-js')]);
                if (disposed || sessionInvalid.current) return;
                echo = new Echo({
                    broadcaster: 'reverb',
                    Pusher,
                    key: config.key,
                    wsHost: config.host,
                    wsPort: config.port,
                    wssPort: config.port,
                    forceTLS: config.scheme === 'https',
                    enabledTransports: ['ws', 'wss'],
                    enableStats: false,
                    withoutInterceptors: true,
                    authorizer: channel => ({
                        authorize: (socketId, callback) => {
                            const controller = new AbortController();
                            pendingRequests.current.add(controller);
                            apiRequest(config.auth_endpoint, {
                                method: 'POST',
                                body: { socket_id: socketId, channel_name: channel.name },
                                signal: controller.signal,
                            }).then(response => {
                                if (!disposed && !controller.signal.aborted) callback(null, response);
                            }).catch(error => {
                                if (!disposed && !controller.signal.aborted) {
                                    callback(error, null);
                                    stopRealtime();
                                    if (!handleAuthFailure(error)) setConnectionState('unavailable');
                                }
                            }).finally(() => pendingRequests.current.delete(controller));
                        },
                    }),
                });
                stopConnectionListener = echo.connector.onConnectionChange(state => {
                    if (disposed) return;
                    // Connected is only shown once the private subscription is
                    // authorized, not merely when the public socket opens.
                    setConnectionState(state === 'connected' ? 'connecting' : state === 'failed' ? 'unavailable' : state);
                    if (state === 'connected') void refresh();
                });
                echo.private(channelName)
                    .listen('.notification.changed', () => { if (!disposed) void refresh(); })
                    .subscribed(() => {
                        if (!disposed) {
                            setConnectionState('connected');
                            void refresh();
                        }
                    })
                    .error(() => {
                        if (!disposed) {
                            setConnectionState('unavailable');
                            stopRealtime();
                        }
                    });
            } catch (error) {
                if (!disposed && !configRequest.signal.aborted) {
                    stopRealtime();
                    if (!handleAuthFailure(error)) setConnectionState('unavailable');
                }
            } finally {
                connecting = false;
            }
        };

        const onFocus = () => {
            void refresh();
            void startRealtime();
        };
        const onVisibility = () => { if (document.visibilityState === 'visible') onFocus(); };
        void refresh();
        void startRealtime();
        window.addEventListener('focus', onFocus);
        window.addEventListener('online', onFocus);
        document.addEventListener('visibilitychange', onVisibility);
        return () => {
            disposed = true;
            alive.current = false;
            requestVersion.current += 1;
            inboxRequest.current?.abort();
            configRequest?.abort();
            pendingRequests.current.forEach(controller => controller.abort());
            pendingRequests.current.clear();
            mutations.current.clear();
            stopRealtime();
            disconnect.current = () => {};
            window.removeEventListener('focus', onFocus);
            window.removeEventListener('online', onFocus);
            document.removeEventListener('visibilitychange', onVisibility);
        };
    }, [userId, refresh, handleAuthFailure]);

    const value = useMemo(() => ({ ...data, connectionState, refresh, setPage, markRead, markAllRead }), [data, connectionState, refresh, setPage, markRead, markAllRead]);
    return <NotificationContext.Provider value={value}>{children}</NotificationContext.Provider>;
}

export function useNotifications() {
    const context = useContext(NotificationContext);
    if (!context) throw new Error('useNotifications must be used within NotificationProvider');
    return context;
}

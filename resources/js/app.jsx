import '../css/app.css';
import { createElement } from 'react';
import { createRoot } from 'react-dom/client';
import { Routes, Route, Navigate, createBrowserRouter, RouterProvider, useLocation } from 'react-router-dom';
import { AuthProvider, useAuth } from './contexts/AuthContext';
import { ThemeProvider } from './contexts/ThemeContext';
import { LocaleProvider, useLocale } from './contexts/LocaleContext';
import { NotificationProvider } from './contexts/NotificationContext';
import PageErrorBoundary from './components/dashboard/PageErrorBoundary';

// Pages
import Login from './pages/Login';
import Register from './pages/Register';
import ForgotPassword from './pages/ForgotPassword';
import ResetPassword from './pages/ResetPassword';
import Dashboard from './pages/Dashboard';
import ChatFullPage from './pages/ChatFullPage';
import Profile from './pages/Profile';
import VideoDownloader from './pages/VideoDownloader';
import MediaConverter from './pages/MediaConverter';
import RemoveBackground from './pages/RemoveBackground';
import ErrorPage from './pages/ErrorPage';
import StudioPage from './pages/StudioPage';
import { legacyStudioHref } from './components/studios/studioLinks';

// User pages
import TemplatePrompt from './pages/TemplatePrompt';
import Library from './pages/Library';
import TokenPemakaian from './pages/TokenPemakaian';
import Deposit from './pages/Deposit';
import Referral from './pages/Referral';
import Bantuan from './pages/Bantuan';
import Notifications from './pages/Notifications';
import ApiAccess from './pages/ApiAccess';

// Admin pages (existing)
import AdminUsers from './pages/AdminUsers';
import TokenUsage from './pages/TokenUsage';

// Consolidated admin control center
import AdminOverview from './pages/admin/AdminOverview';
import Operations from './pages/admin/Operations';
import ContentSupport from './pages/admin/ContentSupport';
import AICatalog from './pages/admin/AICatalog';
import ProviderDetail from './pages/admin/ProviderDetail';
import MediaQueue from './pages/admin/MediaQueue';
import SystemActivity from './pages/admin/SystemActivity';
import Settings from './pages/admin/Settings';
import ApiKeys from './pages/admin/ApiKeys';
import Security from './pages/admin/Security';

// Layout
import DashboardLayout from './layouts/DashboardLayout';

// Protected Route wrapper
function ProtectedRoute({ children, adminOnly = false, permission = null }) {
    const { user, loading } = useAuth();
    const { t, localizedPath } = useLocale();

    if (loading) {
        return (
            <div className="min-h-screen bg-[#fafbfc] flex items-center justify-center">
                <div className="flex flex-col items-center gap-4">
                    <div className="w-10 h-10 border-2 border-red-500 border-t-transparent rounded-full animate-spin" />
                    <span className="text-gray-500 text-sm">{t('Memuat...')}</span>
                </div>
            </div>
        );
    }

    if (!user) return <Navigate to={localizedPath('/login')} replace />;
    // Unactivated accounts may only open the profile, where activation lives.
    if (user.role !== 'admin' && user.email_verified === false && !window.location.pathname.endsWith('/profile')) {
        return <Navigate to={localizedPath('/profile')} replace />;
    }
    if (adminOnly && user.role !== 'admin') return <ErrorPage code={403} />;

    // A permission list admits any one of its grants (the global media workspace serves every studio).
    if (permission && user.role !== 'admin') {
        const perms = user.permissions || {};
        if (![permission].flat().some(name => perms[name])) return <ErrorPage code={403} />;
    }

    return children;
}

// Guest Route
function GuestRoute({ children }) {
    const { user, loading } = useAuth();
    const { localizedPath } = useLocale();
    if (loading) {
        return (
            <div className="min-h-screen bg-gray-950 flex items-center justify-center">
                <div className="w-10 h-10 border-2 border-red-500 border-t-transparent rounded-full animate-spin" />
            </div>
        );
    }
    if (user) return <Navigate to={localizedPath('/dashboard')} replace />;
    return children;
}

// Every permission that makes a unified media capability eligible on the server.
const MEDIA_PERMISSIONS = ['image_generator', 'video_generator', 'audio_generator', 'chat'];

// The former studio pages redirect into /studio with their query (job, track, model links keep working)
// and keep the permission gate each page had.
function LegacyStudioRedirect({ from }) {
    const { localizedPath } = useLocale();
    const { search, hash } = useLocation();
    return <Navigate to={`${localizedPath(legacyStudioHref(from, search))}${hash}`} replace />;
}
const legacyStudio = (from, permission) => <ProtectedRoute permission={permission}><LegacyStudioRedirect from={from} /></ProtectedRoute>;

function LocalizedAppRoutes() {
    const { locale } = useLocale();
    const prefix = locale === 'en' ? '/en' : '';
    const path = (value) => `${prefix}${value}`;

    return (
        <Routes>
            <Route path={path('/login')} element={<GuestRoute><Login /></GuestRoute>} />
            <Route path={path('/register')} element={<GuestRoute><Register /></GuestRoute>} />
            <Route path={path('/forgot-password')} element={<GuestRoute><ForgotPassword /></GuestRoute>} />
            <Route path={path('/reset-password')} element={<GuestRoute><ResetPassword /></GuestRoute>} />
            <Route path={path('/chat')} element={<ProtectedRoute permission="chat"><PageErrorBoundary><ChatFullPage /></PageErrorBoundary></ProtectedRoute>} />
            <Route path={path('/dashboard')} element={<DL><Dashboard /></DL>} />
            <Route path={path('/profile')} element={<DL><Profile /></DL>} />
            <Route path={path('/studio')} element={<ProtectedRoute permission={MEDIA_PERMISSIONS}><PageErrorBoundary><StudioPage /></PageErrorBoundary></ProtectedRoute>} />
            <Route path={path('/video')} element={legacyStudio('/video', 'video_generator')} />
            <Route path={path('/audio')} element={legacyStudio('/audio', 'audio_generator')} />
            <Route path={path('/avatar')} element={legacyStudio('/avatar', 'video_generator')} />
            <Route path={path('/3d')} element={legacyStudio('/3d', 'image_generator')} />
            <Route path={path('/media')} element={legacyStudio('/media', MEDIA_PERMISSIONS)} />
            <Route path={path('/downloads')} element={<DL permission="video_downloader"><VideoDownloader /></DL>} />
            <Route path={path('/converter')} element={<DL permission="media_converter"><MediaConverter /></DL>} />
            <Route path={path('/remove-background')} element={<DL permission="media_converter"><RemoveBackground /></DL>} />
            <Route path={path('/templates')} element={<DL><TemplatePrompt /></DL>} />
            <Route path={path('/library')} element={<DL><Library /></DL>} />
            <Route path={path('/history')} element={<Navigate to={path('/chat')} replace />} />
            <Route path={path('/generate-image')} element={legacyStudio('/generate-image', 'image_generator')} />
            <Route path={path('/token-usage')} element={<DL><TokenPemakaian /></DL>} />
            <Route path={path('/deposit')} element={<DL><Deposit /></DL>} />
            <Route path={path('/api-access')} element={<DL permission="ai_api"><ApiAccess /></DL>} />
            <Route path={path('/paket')} element={<Navigate to={`${path('/deposit')}?tab=subscription`} replace />} />
            <Route path={path('/referral')} element={<DL><Referral /></DL>} />
            <Route path={path('/bantuan')} element={<DL><Bantuan /></DL>} />
            <Route path={path('/notifications')} element={<DL><Notifications /></DL>} />
            <Route path={path('/admin/users')} element={<DL adminOnly><AdminUsers /></DL>} />
            <Route path={path('/admin/token-usage')} element={<DL adminOnly><TokenUsage /></DL>} />
            <Route path={path('/admin/overview')} element={<DL adminOnly><AdminOverview /></DL>} />
            <Route path={path('/admin/operations')} element={<DL adminOnly><Operations /></DL>} />
            <Route path={path('/admin/content')} element={<DL adminOnly><ContentSupport /></DL>} />
            <Route path={path('/admin/ai')} element={<DL adminOnly><AICatalog /></DL>} />
            <Route path={path('/admin/ai/queue')} element={<DL adminOnly><MediaQueue /></DL>} />
            <Route path={path('/admin/ai/:providerId')} element={<DL adminOnly><ProviderDetail /></DL>} />
            <Route path={path('/admin/system')} element={<DL adminOnly><SystemActivity /></DL>} />
            <Route path={path('/admin/settings')} element={<DL adminOnly><Settings /></DL>} />
            <Route path={path('/admin/api-keys')} element={<DL adminOnly><ApiKeys /></DL>} />
            <Route path={path('/admin/security')} element={<DL adminOnly><Security /></DL>} />
            <Route path={path('/admin')} element={<Navigate to={path('/admin/overview')} replace />} />
            <Route path={path('/usage')} element={<Navigate to={path('/admin/token-usage')} replace />} />
            <Route path="*" element={<ErrorPage code={404} />} />
        </Routes>
    );
}

// Helper to wrap with DashboardLayout + ProtectedRoute
function DL({ children, adminOnly = false, permission = null }) {
    return (
        <ProtectedRoute adminOnly={adminOnly} permission={permission}>
            <DashboardLayout><PageErrorBoundary>{children}</PageErrorBoundary></DashboardLayout>
        </ProtectedRoute>
    );
}

// A data router enables navigation blockers (unsaved chat drafts/artifacts). Routes stay declarative
// in LocalizedAppRoutes; the single splat route keeps descendant <Routes> matching the full path.
function App({ router }) {
    return (
        <ThemeProvider>
            <AuthProvider>
                <RouterProvider router={router} />
            </AuthProvider>
        </ThemeProvider>
    );
}

const container = document.getElementById('app');
if (container) {
    const router = createBrowserRouter([{
        path: '*',
        element: <LocaleProvider><NotificationProvider><LocalizedAppRoutes /></NotificationProvider></LocaleProvider>,
    }]);
    createRoot(container).render(createElement(App, { router }));
}

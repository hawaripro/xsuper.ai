import '../css/app.css';
import { createElement } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
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
import VideoGenerator from './pages/VideoGenerator';
import AudioGenerator from './pages/AudioGenerator';
import AvatarStudio from './pages/AvatarStudio';
import ThreeDStudio from './pages/ThreeDStudio';
import VideoDownloader from './pages/VideoDownloader';
import MediaConverter from './pages/MediaConverter';
import RemoveBackground from './pages/RemoveBackground';
import ErrorPage from './pages/ErrorPage';

// User pages
import TemplatePrompt from './pages/TemplatePrompt';
import Library from './pages/Library';
import GenerateImage from './pages/GenerateImage';
import TokenPemakaian from './pages/TokenPemakaian';
import Deposit from './pages/Deposit';
import Referral from './pages/Referral';
import Bantuan from './pages/Bantuan';
import Notifications from './pages/Notifications';

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

    if (permission && user.role !== 'admin') {
        const perms = user.permissions || {};
        if (!perms[permission]) return <ErrorPage code={403} />;
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
            <Route path={path('/video')} element={<DL permission="video_generator"><VideoGenerator /></DL>} />
            <Route path={path('/audio')} element={<DL permission="audio_generator"><AudioGenerator /></DL>} />
            <Route path={path('/avatar')} element={<DL permission="video_generator"><AvatarStudio /></DL>} />
            <Route path={path('/3d')} element={<DL permission="image_generator"><ThreeDStudio /></DL>} />
            <Route path={path('/downloads')} element={<DL permission="video_downloader"><VideoDownloader /></DL>} />
            <Route path={path('/converter')} element={<DL permission="media_converter"><MediaConverter /></DL>} />
            <Route path={path('/remove-background')} element={<DL permission="media_converter"><RemoveBackground /></DL>} />
            <Route path={path('/templates')} element={<DL><TemplatePrompt /></DL>} />
            <Route path={path('/library')} element={<DL><Library /></DL>} />
            <Route path={path('/history')} element={<Navigate to={path('/chat')} replace />} />
            <Route path={path('/generate-image')} element={<DL permission="image_generator"><GenerateImage /></DL>} />
            <Route path={path('/token-usage')} element={<DL><TokenPemakaian /></DL>} />
            <Route path={path('/deposit')} element={<DL><Deposit /></DL>} />
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

function App() {
    return (
        <ThemeProvider>
            <AuthProvider>
                <BrowserRouter>
                    <LocaleProvider>
                        <NotificationProvider>
                            <LocalizedAppRoutes />
                        </NotificationProvider>
                    </LocaleProvider>
                </BrowserRouter>
            </AuthProvider>
        </ThemeProvider>
    );
}

const container = document.getElementById('app');
if (container) {
    createRoot(container).render(createElement(App));
}

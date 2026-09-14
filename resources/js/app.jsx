import '../css/app.css';
import { createElement } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider, useAuth } from './contexts/AuthContext';
import { ThemeProvider } from './contexts/ThemeContext';

// Pages
import Login from './pages/Login';
import Dashboard from './pages/Dashboard';
import ChatFullPage from './pages/ChatFullPage';
import Profile from './pages/Profile';
import VideoGenerator from './pages/VideoGenerator';
import ErrorPage from './pages/ErrorPage';

// User pages
import TemplatePrompt from './pages/TemplatePrompt';
import ChatHistory from './pages/ChatHistory';
import GenerateImage from './pages/GenerateImage';
import TokenPemakaian from './pages/TokenPemakaian';
import PaketPerpanjangan from './pages/PaketPerpanjangan';
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
import SystemActivity from './pages/admin/SystemActivity';
import Settings from './pages/admin/Settings';

// Layout
import DashboardLayout from './layouts/DashboardLayout';

// Protected Route wrapper
function ProtectedRoute({ children, adminOnly = false, permission = null }) {
    const { user, loading } = useAuth();

    if (loading) {
        return (
            <div className="min-h-screen bg-[#fafbfc] flex items-center justify-center">
                <div className="flex flex-col items-center gap-4">
                    <div className="w-10 h-10 border-2 border-red-500 border-t-transparent rounded-full animate-spin" />
                    <span className="text-gray-500 text-sm">Memuat...</span>
                </div>
            </div>
        );
    }

    if (!user) return <Navigate to="/login" replace />;
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
    if (loading) {
        return (
            <div className="min-h-screen bg-gray-950 flex items-center justify-center">
                <div className="w-10 h-10 border-2 border-red-500 border-t-transparent rounded-full animate-spin" />
            </div>
        );
    }
    if (user) return <Navigate to="/dashboard" replace />;
    return children;
}

// Helper to wrap with DashboardLayout + ProtectedRoute
function DL({ children, adminOnly = false, permission = null }) {
    return (
        <ProtectedRoute adminOnly={adminOnly} permission={permission}>
            <DashboardLayout>{children}</DashboardLayout>
        </ProtectedRoute>
    );
}

function App() {
    return (
        <ThemeProvider>
        <AuthProvider>
            <BrowserRouter>
                <Routes>
                    {/* Public */}
                    <Route path="/login" element={<GuestRoute><Login /></GuestRoute>} />

                    {/* Protected - Full Page (no dashboard layout) */}
                    <Route path="/chat" element={<ProtectedRoute permission="chat"><ChatFullPage /></ProtectedRoute>} />

                    {/* User routes */}
                    <Route path="/dashboard" element={<DL><Dashboard /></DL>} />
                    <Route path="/profile" element={<DL><Profile /></DL>} />
                    <Route path="/video" element={<DL permission="video_generator"><VideoGenerator /></DL>} />
                    <Route path="/templates" element={<DL><TemplatePrompt /></DL>} />
                    <Route path="/history" element={<DL permission="chat_history"><ChatHistory /></DL>} />
                    <Route path="/generate-image" element={<DL><GenerateImage /></DL>} />
                    <Route path="/token-usage" element={<DL><TokenPemakaian /></DL>} />
                    <Route path="/paket" element={<DL><PaketPerpanjangan /></DL>} />
                    <Route path="/referral" element={<DL><Referral /></DL>} />
                    <Route path="/bantuan" element={<DL><Bantuan /></DL>} />

                    <Route path="/notifications" element={<DL><Notifications /></DL>} />
                    {/* Admin routes */}
                    <Route path="/admin/users" element={<DL adminOnly><AdminUsers /></DL>} />
                    <Route path="/admin/token-usage" element={<DL adminOnly><TokenUsage /></DL>} />
                    <Route path="/admin/overview" element={<DL adminOnly><AdminOverview /></DL>} />
                    <Route path="/admin/operations" element={<DL adminOnly><Operations /></DL>} />
                    <Route path="/admin/content" element={<DL adminOnly><ContentSupport /></DL>} />
                    <Route path="/admin/ai" element={<DL adminOnly><AICatalog /></DL>} />
                    <Route path="/admin/system" element={<DL adminOnly><SystemActivity /></DL>} />
                    <Route path="/admin/settings" element={<DL adminOnly><Settings /></DL>} />
                    <Route path="/admin/periods" element={<Navigate to="/admin/operations" replace />} />
                    <Route path="/admin/revenue" element={<Navigate to="/admin/overview" replace />} />
                    <Route path="/admin/orders" element={<Navigate to="/admin/operations" replace />} />
                    <Route path="/admin/cost" element={<Navigate to="/admin/token-usage" replace />} />
                    <Route path="/admin/expiring" element={<Navigate to="/admin/operations" replace />} />
                    <Route path="/admin/broadcast" element={<Navigate to="/admin/content" replace />} />
                    <Route path="/admin/referral" element={<Navigate to="/admin/content" replace />} />
                    <Route path="/admin/feedback" element={<Navigate to="/admin/content" replace />} />
                    <Route path="/admin/providers" element={<Navigate to="/admin/ai" replace />} />
                    <Route path="/admin/models" element={<Navigate to="/admin/ai" replace />} />
                    <Route path="/admin/video-queue" element={<Navigate to="/admin/ai" replace />} />
                    <Route path="/admin/landing" element={<Navigate to="/admin/content" replace />} />
                    <Route path="/admin/analytics" element={<Navigate to="/admin/system" replace />} />
                    <Route path="/admin/audit" element={<Navigate to="/admin/system" replace />} />
                    {/* Legacy redirects */}
                    <Route path="/admin" element={<Navigate to="/admin/overview" replace />} />
                    <Route path="/usage" element={<Navigate to="/admin/token-usage" replace />} />

                    {/* Catch all — 404 */}
                    <Route path="*" element={<ErrorPage code={404} />} />
                </Routes>
            </BrowserRouter>
        </AuthProvider>
        </ThemeProvider>
    );
}

const container = document.getElementById('app');
if (container) {
    createRoot(container).render(createElement(App));
}

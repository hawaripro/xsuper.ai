import '../css/app.css';
import React from 'react';
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

// Admin pages (existing)
import AdminUsers from './pages/AdminUsers';
import TokenUsage from './pages/TokenUsage';
import SessionChat from './pages/SessionChat';
import PeriodManagement from './pages/PeriodManagement';

// Admin pages (new)
import RevenueOverview from './pages/admin/RevenueOverview';
import OrdersPayments from './pages/admin/OrdersPayments';
import CostProfit from './pages/admin/CostProfit';
import ExpiringUsers from './pages/admin/ExpiringUsers';
import BroadcastCRM from './pages/admin/BroadcastCRM';
import ReferralManagement from './pages/admin/ReferralManagement';
import FeedbackTestimoni from './pages/admin/FeedbackTestimoni';
import AIProviderManager from './pages/admin/AIProviderManager';
import ModelManagement from './pages/admin/ModelManagement';
import VideoJobQueue from './pages/admin/VideoJobQueue';
import LandingPageManager from './pages/admin/LandingPageManager';
import AnalyticsFunnel from './pages/admin/AnalyticsFunnel';
import AuditLog from './pages/admin/AuditLog';
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

                    {/* Admin routes */}
                    <Route path="/admin/users" element={<DL adminOnly><AdminUsers /></DL>} />
                    <Route path="/admin/token-usage" element={<DL adminOnly><TokenUsage /></DL>} />
                    <Route path="/admin/sessions" element={<DL adminOnly><SessionChat /></DL>} />
                    <Route path="/admin/periods" element={<DL adminOnly><PeriodManagement /></DL>} />
                    <Route path="/admin/revenue" element={<DL adminOnly><RevenueOverview /></DL>} />
                    <Route path="/admin/orders" element={<DL adminOnly><OrdersPayments /></DL>} />
                    <Route path="/admin/cost" element={<DL adminOnly><CostProfit /></DL>} />
                    <Route path="/admin/expiring" element={<DL adminOnly><ExpiringUsers /></DL>} />
                    <Route path="/admin/broadcast" element={<DL adminOnly><BroadcastCRM /></DL>} />
                    <Route path="/admin/referral" element={<DL adminOnly><ReferralManagement /></DL>} />
                    <Route path="/admin/feedback" element={<DL adminOnly><FeedbackTestimoni /></DL>} />
                    <Route path="/admin/providers" element={<DL adminOnly><AIProviderManager /></DL>} />
                    <Route path="/admin/models" element={<DL adminOnly><ModelManagement /></DL>} />
                    <Route path="/admin/video-queue" element={<DL adminOnly><VideoJobQueue /></DL>} />
                    <Route path="/admin/landing" element={<DL adminOnly><LandingPageManager /></DL>} />
                    <Route path="/admin/analytics" element={<DL adminOnly><AnalyticsFunnel /></DL>} />
                    <Route path="/admin/audit" element={<DL adminOnly><AuditLog /></DL>} />
                    <Route path="/admin/settings" element={<DL adminOnly><Settings /></DL>} />

                    {/* Legacy redirects */}
                    <Route path="/admin" element={<Navigate to="/admin/users" replace />} />
                    <Route path="/usage" element={<Navigate to="/admin/token-usage" replace />} />
                    <Route path="/sessions" element={<Navigate to="/admin/sessions" replace />} />
                    <Route path="/periods" element={<Navigate to="/admin/periods" replace />} />

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
    createRoot(container).render(<App />);
}

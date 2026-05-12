import '../css/app.css';
import React from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider, useAuth } from './contexts/AuthContext';
import { ThemeProvider } from './contexts/ThemeContext';

// Pages
import Landing from './pages/Landing';
import Login from './pages/Login';
import Dashboard from './pages/Dashboard';
import AdminUsers from './pages/AdminUsers';
import ChatFullPage from './pages/ChatFullPage';
import Profile from './pages/Profile';
import VideoGenerator from './pages/VideoGenerator';
import TokenUsage from './pages/TokenUsage';
import SessionChat from './pages/SessionChat';
import ErrorPage from './pages/ErrorPage';

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

    // Check specific permission (admin always has access)
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

function App() {
    return (
        <ThemeProvider>
        <AuthProvider>
            <BrowserRouter>
                <Routes>
                    {/* Public */}
                    <Route path="/" element={<Landing />} />
                    <Route path="/login" element={<GuestRoute><Login /></GuestRoute>} />

                    {/* Protected - Full Page (no dashboard layout) */}
                    <Route path="/chat" element={
                        <ProtectedRoute permission="chat"><ChatFullPage /></ProtectedRoute>
                    } />

                    {/* Protected - Dashboard Layout */}
                    <Route path="/dashboard" element={
                        <ProtectedRoute><DashboardLayout><Dashboard /></DashboardLayout></ProtectedRoute>
                    } />
                    <Route path="/profile" element={
                        <ProtectedRoute><DashboardLayout><Profile /></DashboardLayout></ProtectedRoute>
                    } />
                    <Route path="/video" element={
                        <ProtectedRoute permission="video_generator"><DashboardLayout><VideoGenerator /></DashboardLayout></ProtectedRoute>
                    } />

                    {/* Admin Only */}
                    <Route path="/admin" element={
                        <ProtectedRoute adminOnly><DashboardLayout><AdminUsers /></DashboardLayout></ProtectedRoute>
                    } />
                    <Route path="/usage" element={
                        <ProtectedRoute adminOnly><DashboardLayout><TokenUsage /></DashboardLayout></ProtectedRoute>
                    } />
                    <Route path="/sessions" element={
                        <ProtectedRoute adminOnly><DashboardLayout><SessionChat /></DashboardLayout></ProtectedRoute>
                    } />

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

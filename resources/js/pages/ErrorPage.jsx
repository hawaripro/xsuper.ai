
/* ============================================================
   Error page — animated 404 / 403.
   Props: code (404|403), message (optional)
   ============================================================ */

export default function ErrorPage({ code = 404 }) {
    const is403 = code === 403;

    const title = is403 ? 'Akses Ditolak' : 'Halaman Tidak Ditemukan';
    const description = is403
        ? 'Kamu tidak punya izin untuk mengakses halaman ini. Hubungi admin jika ini kesalahan.'
        : 'Halaman yang kamu cari tidak ada atau sudah dipindahkan.';

    return (
        <div className="min-h-dvh flex items-center justify-center bg-[#fafbfc] relative overflow-hidden px-4">
            {/* Background effects */}
            <div className="absolute inset-0 hero-dots opacity-30 pointer-events-none" />
            <div
                className="absolute top-[-100px] left-1/2 -translate-x-1/2 w-[600px] h-[600px] rounded-full blur-[140px] pointer-events-none"
                style={{ background: is403 ? 'rgba(239, 68, 68, 0.12)' : 'rgba(99, 102, 241, 0.12)' }}
            />

            <div className="relative text-center max-w-lg mx-auto">
                {/* Animated error code */}
                <div className="relative mb-8">
                    {/* Large background number */}
                    <span
                        className="block text-[180px] md:text-[220px] font-black leading-none tracking-tighter select-none animate-float"
                        style={{
                            background: is403
                                ? 'linear-gradient(135deg, #ef4444 0%, #f97316 100%)'
                                : 'linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%)',
                            WebkitBackgroundClip: 'text',
                            WebkitTextFillColor: 'transparent',
                            opacity: 0.15,
                        }}
                    >
                        {code}
                    </span>

                    {/* Foreground number with glow */}
                    <span
                        className="absolute inset-0 flex items-center justify-center text-[100px] md:text-[140px] font-black tracking-tighter animate-pulse-soft"
                        style={{
                            background: is403
                                ? 'linear-gradient(135deg, #ef4444 0%, #f97316 100%)'
                                : 'linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%)',
                            WebkitBackgroundClip: 'text',
                            WebkitTextFillColor: 'transparent',
                        }}
                    >
                        {code}
                    </span>

                    {/* Floating icon */}
                    <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 animate-bounce-subtle">
                        <div
                            className={`w-20 h-20 rounded-3xl flex items-center justify-center shadow-2xl ${
                                is403
                                    ? 'bg-gradient-to-br from-red-500 to-orange-500 shadow-red-500/30'
                                    : 'bg-gradient-to-br from-indigo-500 to-purple-500 shadow-indigo-500/30'
                            }`}
                        >
                            {is403 ? (
                                <svg className="w-10 h-10 text-white" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                                </svg>
                            ) : (
                                <svg className="w-10 h-10 text-white" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
                                    <circle cx="11" cy="11" r="8" />
                                    <line x1="21" y1="21" x2="16.65" y2="16.65" />
                                    <line x1="11" y1="8" x2="11" y2="14" />
                                    <line x1="8" y1="11" x2="14" y2="11" />
                                </svg>
                            )}
                        </div>
                    </div>
                </div>

                {/* Text */}
                <h1 className="text-2xl md:text-3xl font-extrabold text-slate-900 mb-3 animate-fade-in-up">
                    {title}
                </h1>
                <p className="text-base text-slate-500 mb-8 max-w-sm mx-auto animate-fade-in-up" style={{ animationDelay: '80ms' }}>
                    {description}
                </p>

                {/* Actions */}
                <div className="flex flex-wrap justify-center gap-3 animate-fade-in-up" style={{ animationDelay: '160ms' }}>
                    <a
                        href="/"
                        className="ui-btn-primary px-6 py-3"
                    >
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2.4" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                            <polyline points="9 22 9 12 15 12 15 22" />
                        </svg>
                        Kembali ke Beranda
                    </a>
                    <button
                        onClick={() => window.history.back()}
                        className="ui-btn-ghost px-6 py-3"
                    >
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2.4" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round">
                            <path d="m15 18-6-6 6-6" />
                        </svg>
                        Kembali
                    </button>
                </div>

                {/* Decorative floating particles */}
                <div className="absolute -top-10 -left-10 w-4 h-4 rounded-full bg-red-400/30 animate-float" style={{ animationDelay: '0.5s' }} />
                <div className="absolute -bottom-8 -right-8 w-3 h-3 rounded-full bg-indigo-400/30 animate-float" style={{ animationDelay: '1.2s' }} />
                <div className="absolute top-20 -right-16 w-5 h-5 rounded-full bg-orange-400/20 animate-float" style={{ animationDelay: '0.8s' }} />
            </div>
        </div>
    );
}

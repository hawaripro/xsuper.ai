import React from 'react';

/* ============================================================
   BrandIcons — official-style inline SVG logos for brands
   referenced on the UltrAI landing page.
   All icons are 1:1, use currentColor where appropriate, and
   are sized via the `className` prop (default w-6 h-6).
   ============================================================ */

const wrap = (children, className = 'w-6 h-6') => (
    <span className={`inline-flex items-center justify-center ${className}`}>{children}</span>
);

/* ------------------------------------------------------------
   AI MODEL LOGOS
   ------------------------------------------------------------ */

export const ClaudeLogo = ({ className = 'w-6 h-6' }) =>
    wrap(
        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" className="w-full h-full">
            <path fill="#D97757" d="M4.7 16.6l4.3-2.4.08-.2-.08-.12h-.2L8.15 14l-2.26-.13-1.96-.1-1.9-.23L.6 13.25l-.48-.6L.59 12c3.9-.27 6.82-.24 9.32-.32l.15-.1-.02-.03H9.88l-1.8-.46c-1.96-.43-3.37-.67-5.15-1.2-.32-.1-.53-.32-.67-.5l-.28-.67.48-.37 1-.05c1.68.1 3.15.3 5 .6l2.4.46h.34l-.05-.14-.8-.57-1.64-1.45L7.03 5.9 6.1 4.7l-.4-1.05L5.84 3l.47-.33 1.05.2 2.53 1.36 1.98 1.1 1.4.82.5.22-.03-.06-.22-.27-1.85-2.2-1.98-2.4-.9-1.11-.24-.55L8.73.3 9.03 0l.7.1L11.2.64l3.1 1.76 3.97 2.95 2.05 1.9.7.88 1.2 2.05.66 1.83.15 1.2-.08 2.2L22 15.18l-1.05 2.13-.66 1.82-1.22 2.05-1.13 1.37-2.16 1.1-1.83.68-2.82.4-3.8-.24L4.9 21.3l-2.13-.88-2.05-1.22-.7-.82-.6-1.4L.1 15.25l.3-.78.55-.18 1.12.14 2.63.95z" />
        </svg>,
        className
    );

export const ChatGPTLogo = ({ className = 'w-6 h-6' }) =>
    wrap(
        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" className="w-full h-full">
            <path fill="#000" d="M22.2819 9.8211a5.9847 5.9847 0 0 0-.5157-4.9108 6.0462 6.0462 0 0 0-6.5098-2.9A6.0651 6.0651 0 0 0 4.9807 4.1818a5.9847 5.9847 0 0 0-3.9977 2.9 6.0462 6.0462 0 0 0 .7427 7.0966 5.98 5.98 0 0 0 .511 4.9107 6.051 6.051 0 0 0 6.5146 2.9001A5.9847 5.9847 0 0 0 13.2599 24a6.0557 6.0557 0 0 0 5.7718-4.2058 5.9894 5.9894 0 0 0 3.9977-2.9001 6.0557 6.0557 0 0 0-.7475-7.0729zm-9.022 12.6081a4.4755 4.4755 0 0 1-2.8764-1.0408l.1419-.0804 4.7783-2.7582a.7948.7948 0 0 0 .3927-.6813v-6.7369l2.02 1.1686a.071.071 0 0 1 .038.052v5.5826a4.504 4.504 0 0 1-4.4945 4.4944zm-9.6607-4.1254a4.4708 4.4708 0 0 1-.5346-3.0137l.142.0852 4.783 2.7582a.7712.7712 0 0 0 .7806 0l5.8428-3.3685v2.3324a.0804.0804 0 0 1-.0332.0615L9.74 19.9502a4.4992 4.4992 0 0 1-6.1408-1.6464zM2.3408 7.8956a4.485 4.485 0 0 1 2.3655-1.9728V11.6a.7664.7664 0 0 0 .3879.6765l5.8144 3.3543-2.0201 1.1685a.0757.0757 0 0 1-.071 0l-4.8303-2.7865A4.504 4.504 0 0 1 2.3408 7.872zm16.5963 3.8558L13.1038 8.364 15.1192 7.2a.0757.0757 0 0 1 .071 0l4.8303 2.7913a4.4944 4.4944 0 0 1-.6765 8.1042v-5.6772a.79.79 0 0 0-.407-.667zm2.0107-3.0231l-.142-.0852-4.7735-2.7818a.7759.7759 0 0 0-.7854 0L9.409 9.2297V6.8974a.0662.0662 0 0 1 .0284-.0615l4.8303-2.7866a4.4992 4.4992 0 0 1 6.6802 4.66zM8.3065 12.863l-2.02-1.1638a.0804.0804 0 0 1-.038-.0567V6.0742a4.4992 4.4992 0 0 1 7.3757-3.4537l-.142.0805L8.704 5.459a.7948.7948 0 0 0-.3927.6813zm1.0976-2.3654l2.602-1.4998 2.6069 1.4998v2.9994l-2.5974 1.4997-2.6067-1.4997Z" />
        </svg>,
        className
    );

export const GeminiLogo = ({ className = 'w-6 h-6' }) =>
    wrap(
        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" className="w-full h-full">
            <defs>
                <linearGradient id="gem-g" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" stopColor="#1C7FFD" />
                    <stop offset="50%" stopColor="#8A5BFF" />
                    <stop offset="100%" stopColor="#E34CA3" />
                </linearGradient>
            </defs>
            <path fill="url(#gem-g)" d="M12 2c0 3.7 6.3 10 10 10-3.7 0-10 6.3-10 10 0-3.7-6.3-10-10-10 3.7 0 10-6.3 10-10z" />
        </svg>,
        className
    );

export const DeepSeekLogo = ({ className = 'w-6 h-6' }) =>
    wrap(
        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" className="w-full h-full">
            <path fill="#4D6BFE" d="M21.6 5.54c-.2-.1-.28.09-.4.18a2.23 2.23 0 0 1-.27.24c-.78.64-1.69.97-2.75.88a4.62 4.62 0 0 0-2.77.56 17.7 17.7 0 0 0-.9-1.02c-2.63-2.56-6.13-3.08-8.6-1.42-1.48 1-2.58 2.42-3.02 4.2-.07.28-.18.57-.3.86l-.03.06c.01.12-.1.17-.2.2C2.04 10.5 1.58 10.92 1.23 11.45.85 12.03.6 12.7.52 13.4c-.02.12-.04.23-.04.35l-.03.2.03.64c.15 1.93 1.2 3.53 2.83 4.48.73.43 1.52.68 2.37.75.85.07 1.68.11 2.54-.05.08-.01.15-.02.22-.05.64-.03 1.27.1 1.9.3l1.87.59c.29.1.58.1.87 0 .2-.09.24-.24.17-.42-.07-.17-.21-.32-.35-.44-.4-.37-.9-.58-1.45-.77-1.06-.37-2.15-.54-3.26-.6-.74-.04-1.48-.08-2.22-.03-.17.02-.34.03-.5-.02-.18-.05-.2-.15-.1-.28.17-.2.4-.31.64-.4.5-.18 1.02-.22 1.54-.22 1.3 0 2.58.16 3.83.52 1.22.36 2.3.95 3.18 1.9.3.31.57.65.85.98.58.68 1.31 1.03 2.22 1.02 1.47-.02 2.97-.15 4.46-.1.44.02.72-.17.85-.49.1-.25.03-.38-.13-.52-.62-.55-1.3-1.02-2.06-1.39-.32-.16-.68-.25-1-.4-.1-.05-.22-.11-.22-.21 0-.07.1-.13.2-.18.13-.06.27-.08.43-.08a6.2 6.2 0 0 0 3.4-1.6c.75-.69 1.37-1.52 1.8-2.45 1.04-2.28.74-4.53-.45-6.78z" />
        </svg>,
        className
    );

export const QwenLogo = ({ className = 'w-6 h-6' }) =>
    wrap(
        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" className="w-full h-full">
            <defs>
                <linearGradient id="qwen-g" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" stopColor="#615CED" />
                    <stop offset="100%" stopColor="#8F5DFF" />
                </linearGradient>
            </defs>
            <path fill="url(#qwen-g)" d="M12 2L2 8v8l10 6 10-6V8L12 2zm0 2.3l7.5 4.5v6.4L12 19.7 4.5 15.2V8.8L12 4.3zM8 10l2 3.5L8 17h2.2l2-3.5 2 3.5H16.5L14.5 13.5 16.5 10H14.3l-2.1 3.5L10 10H8z" />
        </svg>,
        className
    );

export const GLMLogo = ({ className = 'w-6 h-6' }) =>
    wrap(
        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" className="w-full h-full">
            <defs>
                <linearGradient id="glm-g" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" stopColor="#0EA5E9" />
                    <stop offset="100%" stopColor="#0B6FFF" />
                </linearGradient>
            </defs>
            <rect x="2.5" y="2.5" width="19" height="19" rx="4.5" fill="url(#glm-g)" />
            <path fill="#FFF" d="M8.2 8.4h2v5.8h3.6v1.8H8.2zm7 0h1.8l-1.2 4 .9 3.6h-2l-.5-2.2-.5 2.2h-2l.9-3.6-1.2-4h1.8l.4 2 .4-2z" />
        </svg>,
        className
    );

/* ------------------------------------------------------------
   DEVELOPER TOOLS
   ------------------------------------------------------------ */

export const VSCodeLogo = ({ className = 'w-6 h-6' }) =>
    wrap(
        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" className="w-full h-full">
            <defs>
                <linearGradient id="vsc-g" x1="0%" y1="0%" x2="0%" y2="100%">
                    <stop offset="0%" stopColor="#0BA4FF" />
                    <stop offset="100%" stopColor="#0065A9" />
                </linearGradient>
            </defs>
            <path fill="url(#vsc-g)" d="M23.15 2.587L18.21.21a1.494 1.494 0 0 0-1.705.29l-9.46 8.63-4.12-3.128a1 1 0 0 0-1.277.057L.327 7.261A1 1 0 0 0 .326 8.74L3.9 12 .326 15.26a1 1 0 0 0 .001 1.479L1.65 17.94a1 1 0 0 0 1.276.057l4.12-3.128 9.46 8.63a1.494 1.494 0 0 0 1.704.29l4.942-2.377A1.5 1.5 0 0 0 24 20.06V3.939a1.5 1.5 0 0 0-.85-1.352zm-5.146 14.8L10.828 12l7.176-5.387z" />
        </svg>,
        className
    );

export const CursorLogo = ({ className = 'w-6 h-6' }) =>
    wrap(
        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" className="w-full h-full">
            <defs>
                <linearGradient id="cur-g" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" stopColor="#1A1A1A" />
                    <stop offset="100%" stopColor="#3C3C3C" />
                </linearGradient>
            </defs>
            <path fill="url(#cur-g)" d="M11.925 24l10.425-6v-12L11.925 0 1.5 6v12z" />
            <path fill="#FFF" fillOpacity="0.6" d="M22.35 6L11.925 12v12L22.35 18z" />
            <path fill="#FFF" fillOpacity="0.85" d="M11.925 12L1.5 6l10.425-6L22.35 6z" />
        </svg>,
        className
    );

export const OpenCodeLogo = ({ className = 'w-6 h-6' }) =>
    wrap(
        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" className="w-full h-full">
            <rect x="2" y="2" width="20" height="20" rx="4" fill="#0F172A" />
            <path fill="none" stroke="#F97316" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" d="M9 8l-3 4 3 4m6-8l3 4-3 4" />
        </svg>,
        className
    );

/* ============================================================
   PAYMENT METHODS — proper brand logomarks (not just colored
   text boxes). Artwork redrawn to match each brand's logomark
   shape at glanceable accuracy on small sizes.
   ============================================================ */

/* QRIS — QR dot icon + bold black wordmark (official style) */
export const QRISLogo = ({ className = 'w-full h-full' }) => (
    <span className={`inline-flex items-center justify-center ${className}`}>
        <svg viewBox="0 0 140 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" className="w-full h-full">
            <g transform="translate(4 6)">
                <rect x="0"  y="0"  width="14" height="14" fill="#E3061A" rx="1.5"/>
                <rect x="3"  y="3"  width="3"  height="3"  fill="#fff"/>
                <rect x="8"  y="3"  width="3"  height="3"  fill="#fff"/>
                <rect x="3"  y="8"  width="3"  height="3"  fill="#fff"/>
                <rect x="22" y="0"  width="14" height="14" fill="#E3061A" rx="1.5"/>
                <rect x="25" y="3"  width="3"  height="3"  fill="#fff"/>
                <rect x="30" y="3"  width="3"  height="3"  fill="#fff"/>
                <rect x="25" y="8"  width="3"  height="3"  fill="#fff"/>
                <rect x="0"  y="22" width="14" height="14" fill="#E3061A" rx="1.5"/>
                <rect x="3"  y="25" width="3"  height="3"  fill="#fff"/>
                <rect x="8"  y="25" width="3"  height="3"  fill="#fff"/>
                <rect x="3"  y="30" width="3"  height="3"  fill="#fff"/>
            </g>
            <text x="52" y="34" fontFamily="Inter, system-ui, -apple-system, sans-serif" fontWeight="900" fontSize="26" fill="#1E1E1E" letterSpacing="1.2">QRIS</text>
        </svg>
    </span>
);

/* BCA — blue chip with italic serif BCA wordmark */
export const BCALogo = ({ className = 'w-full h-full' }) => (
    <span className={`inline-flex items-center justify-center ${className}`}>
        <svg viewBox="0 0 120 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" className="w-full h-full">
            <rect x="4" y="6" width="112" height="36" rx="6" fill="#0060AF"/>
            <rect x="4" y="6" width="112" height="18" rx="6" fill="#0774CC" opacity="0.35"/>
            <text x="60" y="33" fontFamily="Georgia, 'Times New Roman', serif" fontWeight="900" fontStyle="italic" fontSize="22" fill="#FFFFFF" textAnchor="middle" letterSpacing="2">BCA</text>
        </svg>
    </span>
);

/* Mandiri — yellow sun arc + blue italic serif wordmark */
export const MandiriLogo = ({ className = 'w-full h-full' }) => (
    <span className={`inline-flex items-center justify-center ${className}`}>
        <svg viewBox="0 0 180 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" className="w-full h-full">
            <g transform="translate(6 10)">
                <path d="M2 22 a14 14 0 0 1 28 0" fill="none" stroke="#FFB800" strokeWidth="3.2" strokeLinecap="round"/>
                <path d="M7 15 l-3 -4" stroke="#FFB800" strokeWidth="2.6" strokeLinecap="round"/>
                <path d="M16 10 l0 -5" stroke="#FFB800" strokeWidth="2.6" strokeLinecap="round"/>
                <path d="M25 15 l3 -4" stroke="#FFB800" strokeWidth="2.6" strokeLinecap="round"/>
            </g>
            <text x="46" y="32" fontFamily="Georgia, serif" fontWeight="700" fontStyle="italic" fontSize="22" fill="#003D79" letterSpacing="-0.5">mandiri</text>
        </svg>
    </span>
);

/* BRI — dark blue chip with white wordmark + gold accent line */
export const BRILogo = ({ className = 'w-full h-full' }) => (
    <span className={`inline-flex items-center justify-center ${className}`}>
        <svg viewBox="0 0 120 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" className="w-full h-full">
            <rect x="4" y="6" width="112" height="36" rx="6" fill="#003D8C"/>
            <text x="60" y="31" fontFamily="Inter, system-ui, sans-serif" fontWeight="900" fontStyle="italic" fontSize="22" fill="#FFFFFF" textAnchor="middle" letterSpacing="2">BRI</text>
            <rect x="34" y="36" width="52" height="2.2" fill="#F59E0B" rx="1"/>
        </svg>
    </span>
);

/* GoPay — cyan ring icon + dark blue wordmark */
export const GopayLogo = ({ className = 'w-full h-full' }) => (
    <span className={`inline-flex items-center justify-center ${className}`}>
        <svg viewBox="0 0 160 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" className="w-full h-full">
            <circle cx="22" cy="24" r="14" fill="#00A9E0"/>
            <circle cx="22" cy="24" r="8"  fill="#FFFFFF"/>
            <circle cx="22" cy="24" r="4"  fill="#00A9E0"/>
            <text x="44" y="32" fontFamily="Inter, system-ui, sans-serif" fontWeight="900" fontSize="22" fill="#001A72" letterSpacing="-0.5">gopay</text>
        </svg>
    </span>
);

/* ShopeePay — shopping bag icon + orange wordmark */
export const ShopeePayLogo = ({ className = 'w-full h-full' }) => (
    <span className={`inline-flex items-center justify-center ${className}`}>
        <svg viewBox="0 0 190 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" className="w-full h-full">
            <g transform="translate(4 8)">
                <rect x="2" y="10" width="26" height="24" rx="3" fill="#EE4D2D"/>
                <path d="M8 10 V7 a7 7 0 0 1 14 0 V10" fill="none" stroke="#EE4D2D" strokeWidth="2.6" strokeLinecap="round"/>
                <text x="15" y="28" fontFamily="Inter, system-ui, sans-serif" fontWeight="900" fontSize="15" fill="#FFFFFF" textAnchor="middle">S</text>
            </g>
            <text x="38" y="32" fontFamily="Inter, system-ui, sans-serif" fontWeight="900" fontSize="20" fill="#EE4D2D" letterSpacing="-0.3">ShopeePay</text>
        </svg>
    </span>
);

/* ------------------------------------------------------------
   ROUND BADGE WRAPPER — for consistent display inside chips
   ------------------------------------------------------------ */
export function LogoChip({ children, label, className = 'w-10 h-10' }) {
    return (
        <span className={`inline-flex items-center justify-center rounded-xl bg-white shadow-[0_4px_12px_-2px_rgba(15,23,42,0.08)] ring-1 ring-gray-200/80 p-2 ${className}`} aria-label={label}>
            {children}
        </span>
    );
}

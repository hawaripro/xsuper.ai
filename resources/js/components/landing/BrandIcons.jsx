import React from 'react';

/* ============================================================
   BrandIcons — real official brand logos.

   Strategy:
   - AI models + dev tools: Iconify CDN (<img>-based). Iconify is a
     widely used MIT-licensed icon aggregator. Each logo is
     rendered via their hosted SVG endpoint, giving us the real
     polychrome brand mark without bundling icon fonts.
   - Indonesian payment brands: local files at /brands/*.svg if
     available, with a best-effort inline SVG fallback that
     closely matches each brand's actual logomark.
   ============================================================ */

/* ------------------------------------------------------------
   Iconify image helper
   ------------------------------------------------------------ */
function IconifyImg({ iconId, alt, className = 'w-full h-full', color }) {
    // api.iconify.design serves SVG; we optionally tint for monochrome icons.
    const qs = color ? `?color=${encodeURIComponent(color)}` : '';
    return (
        <img
            src={`https://api.iconify.design/${iconId}.svg${qs}`}
            alt={alt}
            className={`object-contain ${className}`}
            loading="lazy"
            decoding="async"
            aria-hidden="true"
            onError={(e) => { e.currentTarget.style.opacity = '0.4'; }}
        />
    );
}

/* Round container for when a logo is rendered on colored backgrounds */
const wrap = (children, className = 'w-6 h-6') => (
    <span className={`inline-flex items-center justify-center ${className}`}>{children}</span>
);

/* ------------------------------------------------------------
   AI MODEL LOGOS — Iconify (logos / simple-icons)
   ------------------------------------------------------------ */

export const ClaudeLogo = ({ className = 'w-6 h-6', alt = 'Claude' }) =>
    wrap(<IconifyImg iconId="simple-icons:anthropic" color="#D97757" alt={alt} />, className);

export const ChatGPTLogo = ({ className = 'w-6 h-6', alt = 'ChatGPT' }) =>
    wrap(<IconifyImg iconId="simple-icons:openai" color="#000000" alt={alt} />, className);

export const GeminiLogo = ({ className = 'w-6 h-6', alt = 'Google Gemini' }) =>
    wrap(<IconifyImg iconId="logos:google-gemini" alt={alt} />, className);

export const DeepSeekLogo = ({ className = 'w-6 h-6', alt = 'DeepSeek' }) =>
    wrap(<IconifyImg iconId="simple-icons:deepseek" color="#4D6BFE" alt={alt} />, className);

export const QwenLogo = ({ className = 'w-6 h-6', alt = 'Qwen' }) =>
    wrap(<IconifyImg iconId="simple-icons:qwen" color="#615CED" alt={alt} />, className);

export const GLMLogo = ({ className = 'w-6 h-6', alt = 'GLM / ChatGLM' }) =>
    wrap(<IconifyImg iconId="simple-icons:zhihu" color="#1ECC00" alt={alt} />, className);

/* ------------------------------------------------------------
   DEVELOPER TOOLS — Iconify
   ------------------------------------------------------------ */

export const VSCodeLogo = ({ className = 'w-6 h-6', alt = 'Visual Studio Code' }) =>
    wrap(<IconifyImg iconId="logos:visual-studio-code" alt={alt} />, className);

export const CursorLogo = ({ className = 'w-6 h-6', alt = 'Cursor' }) =>
    wrap(<IconifyImg iconId="simple-icons:cursor" color="#000000" alt={alt} />, className);

export const OpenCodeLogo = ({ className = 'w-6 h-6', alt = 'OpenCode' }) =>
    wrap(<IconifyImg iconId="simple-icons:opensourceinitiative" color="#3DA639" alt={alt} />, className);

/* ============================================================
   PAYMENT METHODS
   - Each component tries `/brands/{name}.svg` first (drop official
     logo files there), then falls back to a hand-crafted inline SVG
     that matches each brand's actual logomark.
   - User can override by placing real brand SVGs/PNGs at:
       /public/brands/qris.svg
       /public/brands/bca.svg
       /public/brands/mandiri.svg
       /public/brands/bri.svg
       /public/brands/gopay.svg
       /public/brands/shopeepay.svg
   ============================================================ */

function LocalBrandImg({ name, alt, className }) {
    // Single <img> with onError hiding broken image — parent still shows fallback SVG below
    const [ok, setOk] = React.useState(true);
    if (!ok) return null;
    return (
        <img
            src={`/brands/${name}.svg`}
            alt={alt}
            className={`object-contain max-w-full max-h-full ${className || ''}`}
            onError={() => setOk(false)}
            loading="lazy"
            decoding="async"
        />
    );
}

function BrandSlot({ name, alt, className = 'w-full h-full', children }) {
    /* Try local file; when it errors, React re-renders with fallback. */
    const [localOk, setLocalOk] = React.useState(true);
    return (
        <span className={`inline-flex items-center justify-center ${className}`}>
            {localOk ? (
                <img
                    src={`/brands/${name}.svg`}
                    alt={alt}
                    className="object-contain max-w-full max-h-full"
                    onError={() => setLocalOk(false)}
                    loading="lazy"
                    decoding="async"
                />
            ) : (
                children
            )}
        </span>
    );
}

/* ---------- QRIS ---------- */
/* Official logomark: QR-dot symbol (three corners) + red/black wordmark */
export const QRISLogo = ({ className = 'w-full h-full' }) => (
    <BrandSlot name="qris" alt="QRIS" className={className}>
        <svg viewBox="0 0 160 56" xmlns="http://www.w3.org/2000/svg" className="w-full h-full" aria-hidden="true">
            {/* QR finder pattern */}
            <g transform="translate(4 8)">
                <rect x="0"  y="0"  width="14" height="14" fill="#1E1E1E" rx="1.5"/>
                <rect x="3"  y="3"  width="8"  height="8"  fill="#fff" />
                <rect x="5"  y="5"  width="4"  height="4"  fill="#1E1E1E"/>
                <rect x="22" y="0"  width="14" height="14" fill="#1E1E1E" rx="1.5"/>
                <rect x="25" y="3"  width="8"  height="8"  fill="#fff" />
                <rect x="27" y="5"  width="4"  height="4"  fill="#1E1E1E"/>
                <rect x="0"  y="22" width="14" height="14" fill="#1E1E1E" rx="1.5"/>
                <rect x="3"  y="25" width="8"  height="8"  fill="#fff" />
                <rect x="5"  y="27" width="4"  height="4"  fill="#1E1E1E"/>
                {/* Random QR noise */}
                <rect x="22" y="22" width="3" height="3" fill="#E3061A"/>
                <rect x="27" y="22" width="3" height="3" fill="#1E1E1E"/>
                <rect x="32" y="22" width="3" height="3" fill="#E3061A"/>
                <rect x="22" y="27" width="3" height="3" fill="#1E1E1E"/>
                <rect x="27" y="27" width="3" height="3" fill="#E3061A"/>
                <rect x="32" y="27" width="3" height="3" fill="#1E1E1E"/>
                <rect x="22" y="32" width="3" height="3" fill="#E3061A"/>
                <rect x="32" y="32" width="3" height="3" fill="#1E1E1E"/>
            </g>
            {/* QRIS text — red */}
            <text x="60" y="37" fontFamily="Inter, system-ui, sans-serif" fontWeight="900" fontSize="30" fill="#E3061A" letterSpacing="1">QRIS</text>
        </svg>
    </BrandSlot>
);

/* ---------- BCA ---------- */
export const BCALogo = ({ className = 'w-full h-full' }) => (
    <BrandSlot name="bca" alt="BCA" className={className}>
        <svg viewBox="0 0 100 40" xmlns="http://www.w3.org/2000/svg" className="w-full h-full" aria-hidden="true">
            <rect x="2" y="2" width="96" height="36" rx="5" fill="#0060AF"/>
            <rect x="2" y="2" width="96" height="18" rx="5" fill="#1478C8" opacity="0.45"/>
            <text x="50" y="27" fontFamily="Georgia, 'Times New Roman', serif" fontStyle="italic" fontWeight="900" fontSize="20" fill="#FFFFFF" textAnchor="middle" letterSpacing="1.5">BCA</text>
        </svg>
    </BrandSlot>
);

/* ---------- Mandiri ---------- */
export const MandiriLogo = ({ className = 'w-full h-full' }) => (
    <BrandSlot name="mandiri" alt="mandiri" className={className}>
        <svg viewBox="0 0 200 56" xmlns="http://www.w3.org/2000/svg" className="w-full h-full" aria-hidden="true">
            {/* Sun arc logomark */}
            <g transform="translate(8 14)">
                <path d="M2 22 a16 16 0 0 1 32 0" fill="none" stroke="#FFB800" strokeWidth="4" strokeLinecap="round"/>
                <path d="M7 14 l-3.5 -5" stroke="#FFB800" strokeWidth="3" strokeLinecap="round"/>
                <path d="M18 9 l0 -6"    stroke="#FFB800" strokeWidth="3" strokeLinecap="round"/>
                <path d="M29 14 l3.5 -5" stroke="#FFB800" strokeWidth="3" strokeLinecap="round"/>
            </g>
            {/* mandiri wordmark — italic navy */}
            <text x="52" y="36" fontFamily="Georgia, serif" fontStyle="italic" fontWeight="700" fontSize="26" fill="#003D79" letterSpacing="-0.4">mandiri</text>
        </svg>
    </BrandSlot>
);

/* ---------- BRI ---------- */
export const BRILogo = ({ className = 'w-full h-full' }) => (
    <BrandSlot name="bri" alt="BRI" className={className}>
        <svg viewBox="0 0 140 56" xmlns="http://www.w3.org/2000/svg" className="w-full h-full" aria-hidden="true">
            <rect x="4" y="8" width="132" height="40" rx="6" fill="#003D8C"/>
            <text x="70" y="36" fontFamily="Inter, system-ui, sans-serif" fontStyle="italic" fontWeight="900" fontSize="26" fill="#FFFFFF" textAnchor="middle" letterSpacing="1.2">BRI</text>
            <rect x="44" y="40" width="52" height="2.4" fill="#F59E0B" rx="1"/>
        </svg>
    </BrandSlot>
);

/* ---------- GoPay ---------- */
export const GopayLogo = ({ className = 'w-full h-full' }) => (
    <BrandSlot name="gopay" alt="GoPay" className={className}>
        <svg viewBox="0 0 180 56" xmlns="http://www.w3.org/2000/svg" className="w-full h-full" aria-hidden="true">
            {/* Ring mark */}
            <circle cx="24" cy="28" r="16" fill="#00A9E0"/>
            <circle cx="24" cy="28" r="10" fill="#FFFFFF"/>
            <circle cx="24" cy="28" r="5"  fill="#00A9E0"/>
            {/* gopay wordmark */}
            <text x="48" y="38" fontFamily="Inter, system-ui, sans-serif" fontWeight="900" fontSize="28" fill="#001A72" letterSpacing="-0.6">gopay</text>
        </svg>
    </BrandSlot>
);

/* ---------- ShopeePay ---------- */
export const ShopeePayLogo = ({ className = 'w-full h-full' }) => (
    <BrandSlot name="shopeepay" alt="ShopeePay" className={className}>
        <svg viewBox="0 0 160 40" xmlns="http://www.w3.org/2000/svg" className="w-full h-full" aria-hidden="true">
            {/* Bag icon */}
            <g transform="translate(2 4)">
                <rect x="1" y="9" width="28" height="26" rx="3.5" fill="#EE4D2D"/>
                <path d="M7 9 V6 a8 8 0 0 1 16 0 V9" fill="none" stroke="#EE4D2D" strokeWidth="2.8" strokeLinecap="round"/>
                <text x="15" y="27" fontFamily="Inter, system-ui, sans-serif" fontWeight="900" fontSize="15" fill="#FFFFFF" textAnchor="middle">S</text>
            </g>
            <text x="36" y="27" fontFamily="Inter, system-ui, sans-serif" fontWeight="900" fontSize="19" fill="#EE4D2D" letterSpacing="-0.3">ShopeePay</text>
        </svg>
    </BrandSlot>
);

/* ------------------------------------------------------------
   Rounded badge wrapper for chips
   ------------------------------------------------------------ */
export function LogoChip({ children, label, className = 'w-10 h-10' }) {
    return (
        <span
            className={`inline-flex items-center justify-center rounded-xl bg-white shadow-[0_4px_12px_-2px_rgba(15,23,42,0.08)] ring-1 ring-gray-200/80 p-2 ${className}`}
            aria-label={label}
        >
            {children}
        </span>
    );
}

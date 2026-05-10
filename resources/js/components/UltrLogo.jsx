import React from 'react';

/* ============================================================
   UltrAI logo — single source of truth using /ultr-icons.png
   Usage: <UltrLogo size={36} />  or  <UltrLogo className="w-9 h-9" />
   ============================================================ */

export default function UltrLogo({ size, className, alt = 'UltrAI' }) {
    const style = size ? { width: size, height: size } : undefined;
    const cls = className || 'w-9 h-9';
    return (
        <span className={`relative inline-flex items-center justify-center rounded-xl overflow-hidden bg-white shadow-[0_8px_20px_-4px_rgba(239,68,68,0.45)] ${cls}`} style={style} aria-label={alt}>
            <img
                src="/ultr-icons.png"
                alt={alt}
                className="w-full h-full object-cover"
                loading="eager"
                decoding="async"
                width={size || 36}
                height={size || 36}
            />
            <span className="absolute inset-0 rounded-xl ring-1 ring-white/30 pointer-events-none" />
        </span>
    );
}

/* Plain image variant — no chip, no shadow. For inline logo at any size. */
export function UltrLogoPlain({ size = 32, className = '', alt = 'UltrAI' }) {
    return (
        <img
            src="/ultr-icons.png"
            alt={alt}
            className={`inline-block object-contain ${className}`}
            style={{ width: size, height: size }}
            loading="eager"
            decoding="async"
            width={size}
            height={size}
        />
    );
}

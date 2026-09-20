import React from 'react';

/* ============================================================
   XSuper.ai horizontal lockup — X + wordmark baked in.
   Two variants are shipped because the wordmark is near-black: the light one
   dies on a dark sidebar and vice versa. Both are rendered and one is hidden
   by the `dark` class on <html> (ThemeContext), so switching themes never
   flashes a wrong-coloured logo.
   NEVER place a text wordmark next to this — it would read twice.
   ============================================================ */

export function UltrLockup({ height = 32, className = '', alt = 'XSuper.ai' }) {
    const style = { height, width: 'auto' };
    return (
        <span className={`inline-flex items-center ${className}`}>
            <img
                src="/xsuper-logo-horizontal.png"
                alt={alt}
                className="block dark:hidden object-contain"
                style={style}
                width={Math.round((height * 669) / 113)}
                height={height}
                loading="eager"
                decoding="async"
            />
            <img
                src="/xsuper-logo-horizontal-white.png"
                alt=""
                aria-hidden="true"
                className="hidden dark:block object-contain"
                style={style}
                width={Math.round((height * 669) / 113)}
                height={height}
                loading="eager"
                decoding="async"
            />
        </span>
    );
}

/* ============================================================
   XSuper.ai app icon — red plate + white X, legible on dark & light
   Usage: <UltrLogo size={36} />  or  <UltrLogo className="w-9 h-9" />
   ============================================================ */

export default function UltrLogo({ size, className, alt = 'XSuper.ai' }) {
    const style = size ? { width: size, height: size } : undefined;
    const cls = className || 'w-9 h-9';
    return (
        <span className={`relative inline-flex items-center justify-center ${cls}`} style={style} aria-label={alt}>
            <img
                src="/xsuper-icon.png"
                alt={alt}
                className="w-full h-full object-contain"
                loading="eager"
                decoding="async"
                width={size || 36}
                height={size || 36}
            />
            
        </span>
    );
}

/* Plain image variant — no chip, no shadow. For inline logo at any size. */
export function UltrLogoPlain({ size = 32, className = '', alt = 'XSuper.ai' }) {
    return (
        <img
            src="/xsuper-icon.png"
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

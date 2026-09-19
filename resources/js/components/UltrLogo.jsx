import React from 'react';

/* ============================================================
   XSuper.ai mark — icon-only (owl+X), safe on dark & light
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
                className="w-full h-full object-contain drop-shadow-[0_2px_8px_rgba(239,68,68,0.35)]"
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

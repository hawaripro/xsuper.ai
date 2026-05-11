import React, { useState, useCallback, useEffect, useRef } from 'react';

/* ============================================================
   Carousel — shows multiple items visible, scrolls 1 at a time.
   - Mobile: ~1.15 items visible (peek next)
   - Tablet: ~2.2 items visible
   - Desktop: 3 items visible
   - Prev/Next arrows + dot indicators
   - Optional auto-play with pause on hover
   ============================================================ */

export default function Carousel({ children, className = '', autoPlay = false, interval = 5000 }) {
    const items = React.Children.toArray(children);
    const total = items.length;
    const [current, setCurrent] = useState(0);
    const timerRef = useRef(null);

    const next = useCallback(() => {
        setCurrent((prev) => (prev >= total - 1 ? 0 : prev + 1));
    }, [total]);

    const prev = useCallback(() => {
        setCurrent((prev) => (prev <= 0 ? total - 1 : prev - 1));
    }, [total]);

    useEffect(() => {
        if (!autoPlay) return;
        timerRef.current = setInterval(next, interval);
        return () => clearInterval(timerRef.current);
    }, [autoPlay, interval, next]);

    const pause = () => { if (timerRef.current) clearInterval(timerRef.current); };
    const resume = () => {
        if (!autoPlay) return;
        if (timerRef.current) clearInterval(timerRef.current);
        timerRef.current = setInterval(next, interval);
    };

    return (
        <div
            className={`relative ${className}`}
            onMouseEnter={pause}
            onMouseLeave={resume}
        >
            {/* Track */}
            <div className="overflow-hidden">
                <div
                    className="carousel-track flex transition-transform duration-500 ease-out"
                    style={{ transform: `translateX(calc(-${current} * var(--carousel-item-w)))` }}
                >
                    {items.map((item, i) => (
                        <div
                            key={i}
                            className="carousel-item shrink-0 px-2"
                        >
                            {item}
                        </div>
                    ))}
                </div>
            </div>

            {/* Dots */}
            <div className="flex items-center justify-center gap-1.5 mt-5">
                {items.map((_, i) => (
                    <button
                        key={i}
                        onClick={() => setCurrent(i)}
                        aria-label={`Slide ${i + 1}`}
                        className={`rounded-full transition-all duration-300 ${
                            i === current
                                ? 'w-6 h-2 bg-gradient-to-r from-red-500 to-orange-500'
                                : 'w-2 h-2 bg-slate-300 hover:bg-slate-400'
                        }`}
                    />
                ))}
            </div>

            {/* Prev/Next */}
            {total > 1 && (
                <>
                    <button
                        onClick={prev}
                        aria-label="Previous"
                        className="absolute left-1 md:-left-5 top-[40%] -translate-y-1/2 z-10 w-9 h-9 md:w-10 md:h-10 rounded-full bg-white/90 backdrop-blur border border-gray-200 shadow-[0_4px_12px_-2px_rgba(15,23,42,0.12)] flex items-center justify-center text-slate-600 hover:text-red-500 hover:border-red-200 transition-all duration-200"
                    >
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2.4" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                    </button>
                    <button
                        onClick={next}
                        aria-label="Next"
                        className="absolute right-1 md:-right-5 top-[40%] -translate-y-1/2 z-10 w-9 h-9 md:w-10 md:h-10 rounded-full bg-white/90 backdrop-blur border border-gray-200 shadow-[0_4px_12px_-2px_rgba(15,23,42,0.12)] flex items-center justify-center text-slate-600 hover:text-red-500 hover:border-red-200 transition-all duration-200"
                    >
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2.4" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                    </button>
                </>
            )}
        </div>
    );
}

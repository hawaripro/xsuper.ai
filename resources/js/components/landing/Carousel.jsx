import React, { useState, useCallback, useEffect, useRef } from 'react';

/* ============================================================
   Carousel — infinite seamless loop.
   - Multiple items visible (responsive via CSS --carousel-item-w)
   - Scrolls 1 item at a time
   - When reaching the end, seamlessly wraps to start (no jump)
   - Items are duplicated internally for the illusion
   - Prev/Next arrows + dots
   - Optional auto-play with pause on hover
   ============================================================ */

export default function Carousel({ children, className = '', autoPlay = false, interval = 5000 }) {
    const items = React.Children.toArray(children);
    const total = items.length;
    const [current, setCurrent] = useState(0);
    const [isTransitioning, setIsTransitioning] = useState(true);
    const timerRef = useRef(null);
    const trackRef = useRef(null);

    // We duplicate items: [items... items...] so when we scroll past
    // the original set, we silently reset position without animation.
    const duplicated = [...items, ...items];

    const next = useCallback(() => {
        setIsTransitioning(true);
        setCurrent((prev) => prev + 1);
    }, []);

    const prev = useCallback(() => {
        setIsTransitioning(true);
        setCurrent((prev) => prev - 1);
    }, []);

    // When current goes past total, silently reset
    useEffect(() => {
        if (current >= total) {
            const timer = setTimeout(() => {
                setIsTransitioning(false);
                setCurrent(0);
            }, 520); // wait for transition to finish
            return () => clearTimeout(timer);
        }
        if (current < 0) {
            const timer = setTimeout(() => {
                setIsTransitioning(false);
                setCurrent(total - 1);
            }, 520);
            return () => clearTimeout(timer);
        }
    }, [current, total]);

    // Re-enable transition after silent reset
    useEffect(() => {
        if (!isTransitioning) {
            const raf = requestAnimationFrame(() => setIsTransitioning(true));
            return () => cancelAnimationFrame(raf);
        }
    }, [isTransitioning]);

    // Auto-play
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

    // Dot index (always 0..total-1)
    const dotIndex = ((current % total) + total) % total;

    return (
        <div
            className={`relative ${className}`}
            onMouseEnter={pause}
            onMouseLeave={resume}
        >
            {/* Track — centered with equal peek left/right */}
            <div className="overflow-hidden" style={{ padding: '0 calc((100% - var(--carousel-item-w)) / 2)' }}>
                <div
                    ref={trackRef}
                    className={`carousel-track flex ${isTransitioning ? 'transition-transform duration-500 ease-out' : ''}`}
                    style={{ transform: `translateX(calc(-${current} * var(--carousel-item-w)))` }}
                >
                    {duplicated.map((item, i) => (
                        <div key={i} className="carousel-item shrink-0 px-2">
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
                        onClick={() => { setIsTransitioning(true); setCurrent(i); }}
                        aria-label={`Slide ${i + 1}`}
                        className={`rounded-full transition-all duration-300 ${
                            i === dotIndex
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

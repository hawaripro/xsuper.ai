import React, { useState, useCallback, useRef, useEffect } from 'react';

/* ============================================================
   Carousel — lightweight prev/next slider.
   - Shows 1 item at a time on mobile, configurable on desktop.
   - Smooth CSS transition (transform translateX).
   - Prev/Next arrow buttons.
   - Optional auto-play.
   - No external dependencies.
   ============================================================ */

export default function Carousel({ children, className = '', autoPlay = false, interval = 5000 }) {
    const items = React.Children.toArray(children);
    const [current, setCurrent] = useState(0);
    const total = items.length;
    const timerRef = useRef(null);

    const next = useCallback(() => {
        setCurrent((prev) => (prev + 1) % total);
    }, [total]);

    const prev = useCallback(() => {
        setCurrent((prev) => (prev - 1 + total) % total);
    }, [total]);

    // Auto-play
    useEffect(() => {
        if (!autoPlay) return;
        timerRef.current = setInterval(next, interval);
        return () => clearInterval(timerRef.current);
    }, [autoPlay, interval, next]);

    // Pause on hover
    const pause = () => { if (timerRef.current) clearInterval(timerRef.current); };
    const resume = () => {
        if (!autoPlay) return;
        timerRef.current = setInterval(next, interval);
    };

    return (
        <div
            className={`relative ${className}`}
            onMouseEnter={pause}
            onMouseLeave={resume}
        >
            {/* Track */}
            <div className="overflow-hidden rounded-2xl">
                <div
                    className="flex transition-transform duration-500 ease-out"
                    style={{ transform: `translateX(-${current * 100}%)` }}
                >
                    {items.map((item, i) => (
                        <div key={i} className="w-full shrink-0 px-2">
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

            {/* Prev/Next buttons */}
            {total > 1 && (
                <>
                    <button
                        onClick={prev}
                        aria-label="Previous"
                        className="absolute left-0 top-1/2 -translate-y-1/2 -translate-x-1/2 md:-translate-x-full z-10 w-10 h-10 rounded-full bg-white border border-gray-200 shadow-[0_4px_12px_-2px_rgba(15,23,42,0.12)] flex items-center justify-center text-slate-600 hover:text-red-500 hover:border-red-200 hover:shadow-[0_8px_20px_-4px_rgba(239,68,68,0.2)] transition-all duration-200"
                    >
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2.4" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                    </button>
                    <button
                        onClick={next}
                        aria-label="Next"
                        className="absolute right-0 top-1/2 -translate-y-1/2 translate-x-1/2 md:translate-x-full z-10 w-10 h-10 rounded-full bg-white border border-gray-200 shadow-[0_4px_12px_-2px_rgba(15,23,42,0.12)] flex items-center justify-center text-slate-600 hover:text-red-500 hover:border-red-200 hover:shadow-[0_8px_20px_-4px_rgba(239,68,68,0.2)] transition-all duration-200"
                    >
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2.4" viewBox="0 0 24 24" strokeLinecap="round" strokeLinejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                    </button>
                </>
            )}
        </div>
    );
}

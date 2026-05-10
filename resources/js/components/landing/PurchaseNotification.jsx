import React, { useState, useEffect } from 'react';

/* ============================================================
   PurchaseNotification — fake social proof toast.
   Shows "X baru saja membeli paket Y" every 5 seconds,
   cycling through a list of fake buyers.
   Slides in from bottom-left, stays 3.5s, slides out.
   ============================================================ */

const BUYERS = [
    { name: 'Andi S.', city: 'Jakarta', plan: '1 Bulan' },
    { name: 'Rina M.', city: 'Bandung', plan: '3 Bulan' },
    { name: 'Budi P.', city: 'Surabaya', plan: '1 Minggu' },
    { name: 'Sari W.', city: 'Yogyakarta', plan: '6 Bulan' },
    { name: 'Dimas K.', city: 'Semarang', plan: '1 Bulan' },
    { name: 'Nadia F.', city: 'Medan', plan: '12 Bulan' },
    { name: 'Rizky A.', city: 'Makassar', plan: '1 Hari' },
    { name: 'Putri L.', city: 'Bali', plan: '1 Bulan' },
    { name: 'Hendra T.', city: 'Malang', plan: '3 Bulan' },
    { name: 'Dewi R.', city: 'Bekasi', plan: '1 Minggu' },
];

export default function PurchaseNotification() {
    const [visible, setVisible] = useState(false);
    const [index, setIndex] = useState(0);

    useEffect(() => {
        // Initial delay 3s before first notification
        const startDelay = setTimeout(() => {
            setVisible(true);
            startCycle();
        }, 3000);

        let cycleInterval;
        function startCycle() {
            cycleInterval = setInterval(() => {
                setVisible(false);
                setTimeout(() => {
                    setIndex((prev) => (prev + 1) % BUYERS.length);
                    setVisible(true);
                }, 600); // wait for exit animation
            }, 5000);
        }

        return () => {
            clearTimeout(startDelay);
            clearInterval(cycleInterval);
        };
    }, []);

    const buyer = BUYERS[index];

    return (
        <div
            className={`fixed bottom-5 left-5 z-50 max-w-[320px] transition-all duration-500 ease-out ${
                visible
                    ? 'translate-y-0 opacity-100'
                    : 'translate-y-4 opacity-0 pointer-events-none'
            }`}
            aria-live="polite"
            role="status"
        >
            <div className="flex items-center gap-3 px-4 py-3 rounded-2xl bg-white/95 backdrop-blur-xl border border-gray-200/80 shadow-[0_16px_40px_-8px_rgba(15,23,42,0.18),0_6px_16px_-4px_rgba(239,68,68,0.1)]">
                {/* Avatar */}
                <div className="flex-shrink-0 w-10 h-10 rounded-xl bg-gradient-to-br from-red-500 to-orange-500 text-white font-black text-sm flex items-center justify-center shadow-[0_6px_14px_-4px_rgba(239,68,68,0.4)]">
                    {buyer.name[0]}
                </div>
                {/* Content */}
                <div className="flex-1 min-w-0">
                    <p className="text-xs font-bold text-slate-900 truncate">
                        {buyer.name} <span className="font-normal text-slate-500">dari {buyer.city}</span>
                    </p>
                    <p className="text-[11px] text-slate-600 mt-0.5">
                        Baru saja membeli paket <span className="font-bold text-red-500">{buyer.plan}</span>
                    </p>
                </div>
                {/* Time badge */}
                <span className="flex-shrink-0 text-[9px] font-semibold text-slate-400 bg-slate-100 px-2 py-0.5 rounded-md">
                    baru saja
                </span>
            </div>
        </div>
    );
}

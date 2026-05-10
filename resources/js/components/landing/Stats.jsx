import React from 'react';

const STATS = [
    { number: '50', suffix: '+',  label: 'AI Models'      },
    { number: '1000', suffix: '+', label: 'Happy Users'    },
    { number: '99.9', suffix: '%', label: 'Uptime'         },
    { number: '24', suffix: '/7',  label: 'Support'        },
];

export default function Stats() {
    return (
        <section className="relative border-t border-gray-200/60 bg-gradient-to-b from-white via-gray-50/60 to-white">
            <div className="max-w-7xl mx-auto px-4 md:px-6 py-10 md:py-12">
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4 md:gap-6">
                    {STATS.map((stat, i) => (
                        <div
                            key={stat.label}
                            className={`relative text-center px-4 py-3 rounded-2xl transition-all duration-300 hover:-translate-y-0.5 ${
                                i > 0 ? 'md:before:absolute md:before:left-0 md:before:top-1/2 md:before:-translate-y-1/2 md:before:w-px md:before:h-12 md:before:bg-gradient-to-b md:before:from-transparent md:before:via-gray-200 md:before:to-transparent' : ''
                            }`}
                            style={{ animation: 'fade-in-up 0.6s cubic-bezier(0.16, 1, 0.3, 1) both', animationDelay: `${i * 80}ms` }}
                        >
                            <div className="text-2xl md:text-4xl font-black tracking-[-0.02em] text-slate-900 tabular-nums">
                                {stat.number}<span className="bg-gradient-to-r from-red-500 to-orange-500 bg-clip-text text-transparent">{stat.suffix}</span>
                            </div>
                            <div className="mt-1 text-xs md:text-sm font-semibold text-slate-500 uppercase tracking-wider">{stat.label}</div>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

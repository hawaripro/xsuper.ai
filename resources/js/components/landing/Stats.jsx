import React from 'react';

const stats = [
    { number: '183', suffix: '+', label: 'AI Models' },
    { number: '3', suffix: '+', label: 'Layanan' },
    { number: '99.9', suffix: '%', label: 'Uptime' },
    { number: '24', suffix: '/7', label: 'Support' },
];

export default function Stats() {
    return (
        <section className="border-t border-gray-100 bg-gradient-to-b from-gray-50 to-white">
            <div className="max-w-7xl mx-auto px-4 md:px-6 py-12">
                <div className="grid grid-cols-2 md:grid-cols-4 gap-8">
                    {stats.map((stat, i) => (
                        <div key={i} className={`text-center relative ${i > 0 ? 'md:before:absolute md:before:left-0 md:before:top-1/2 md:before:-translate-y-1/2 md:before:w-px md:before:h-10 md:before:bg-gray-200' : ''}`}>
                            <div className="text-2xl md:text-3xl font-extrabold text-gray-900 tracking-tight">
                                {stat.number}<span className="text-red-500">{stat.suffix}</span>
                            </div>
                            <div className="mt-1 text-sm font-medium text-gray-400">{stat.label}</div>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

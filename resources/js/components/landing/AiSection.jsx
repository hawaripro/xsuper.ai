import React from 'react';
import { Link } from 'react-router-dom';
// No external icon deps

export default function AiSection() {
    return (
        <section id="ai" className="py-24 md:py-32">
            <div className="max-w-7xl mx-auto px-4 md:px-6">
                <div className="relative p-10 md:p-16 rounded-3xl bg-gradient-to-br from-gray-900 to-gray-950 overflow-hidden">
                    {/* Glows */}
                    <div className="absolute top-[-20%] right-[-10%] w-[500px] h-[500px] bg-red-500/15 rounded-full blur-[80px]" />
                    <div className="absolute bottom-[-20%] left-[-10%] w-[400px] h-[400px] bg-red-500/[0.08] rounded-full blur-[60px]" />

                    <div className="relative z-10 flex flex-col lg:flex-row items-center gap-12 lg:gap-16">
                        {/* Text */}
                        <div className="flex-1">
                            <span className="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-red-500/15 text-red-400 text-xs font-semibold uppercase tracking-widest mb-6">
                                Self-Hosted AI Proxy
                            </span>
                            <h2 className="text-3xl md:text-4xl font-extrabold text-white tracking-tight leading-tight mb-4">
                                183+ AI Models.<br />Satu API Endpoint.
                            </h2>
                            <p className="text-base md:text-lg text-gray-400 leading-relaxed mb-8 max-w-lg">
                                Akses GPT-4o, Claude Sonnet, Gemini Pro, DeepSeek, dan ratusan model AI lainnya melalui satu endpoint API yang aman dan cepat.
                            </p>
                            <div className="flex gap-8 md:gap-10 mb-8">
                                {[['183+', 'Models'], ['<100ms', 'Latency'], ['SSL', 'Encrypted']].map(([num, label], i) => (
                                    <div key={i}>
                                        <div className="text-2xl md:text-3xl font-extrabold text-white">{num}</div>
                                        <div className="text-xs text-gray-500 mt-0.5">{label}</div>
                                    </div>
                                ))}
                            </div>
                            <Link
                                to="/chat"
                                className="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-red-500 hover:bg-red-600 text-white font-semibold text-sm transition-all shadow-lg shadow-red-500/25"
                            >
                                Coba AI Chat
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                            </Link>
                        </div>

                        {/* Terminal */}
                        <div className="w-full max-w-sm lg:w-[380px] flex-shrink-0">
                            <div className="bg-black/40 border border-white/[0.08] rounded-2xl overflow-hidden backdrop-blur-xl">
                                <div className="flex items-center gap-2 px-4 py-3 border-b border-white/[0.06]">
                                    <span className="w-2.5 h-2.5 rounded-full bg-red-500" />
                                    <span className="w-2.5 h-2.5 rounded-full bg-yellow-500" />
                                    <span className="w-2.5 h-2.5 rounded-full bg-green-500" />
                                    <span className="ml-2 text-xs text-gray-500 font-mono">api.ultrai.id</span>
                                </div>
                                <div className="p-5 font-mono text-[13px] leading-7 text-gray-400">
                                    <div><span className="text-gray-600">// Chat with AI</span></div>
                                    <div><span className="text-red-400">curl</span> <span className="text-green-400">https://api.ultrai.id</span></div>
                                    <div>&nbsp;&nbsp;<span className="text-blue-400">/v1/chat/completions</span></div>
                                    <div>&nbsp;</div>
                                    <div>{'{'}</div>
                                    <div>&nbsp;&nbsp;<span className="text-blue-400">"model"</span>: <span className="text-green-400">"auto"</span>,</div>
                                    <div>&nbsp;&nbsp;<span className="text-blue-400">"messages"</span>: [{'{'}</div>
                                    <div>&nbsp;&nbsp;&nbsp;&nbsp;<span className="text-blue-400">"role"</span>: <span className="text-green-400">"user"</span>,</div>
                                    <div>&nbsp;&nbsp;&nbsp;&nbsp;<span className="text-blue-400">"content"</span>: <span className="text-green-400">"Halo!"</span></div>
                                    <div>&nbsp;&nbsp;{'}]'}</div>
                                    <div>{'}'}</div>
                                    <div>&nbsp;</div>
                                    <div><span className="text-green-400">// Response: 200 OK</span></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    );
}

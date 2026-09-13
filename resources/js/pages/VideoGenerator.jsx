import { useState, useEffect, useCallback } from "react";
import { useTheme } from "../contexts/ThemeContext";

const QUICK_PROMPTS = {
    Kosmetik:
        "A young woman applying a luxurious skincare serum on her face, soft golden lighting, close-up shot, clean beauty aesthetic, 4K cinematic quality, smooth skin texture visible",
    Makanan:
        "Delicious gourmet burger being assembled in slow motion, melting cheese, fresh vegetables, steam rising, professional food photography lighting, 4K photorealistic",
    Fashion:
        "A confident model walking down a sunlit street wearing trendy streetwear, dynamic camera following, urban backdrop, golden hour lighting, cinematic 4K quality",
    Elektronik:
        "Sleek smartphone rotating on a reflective surface, holographic UI elements floating around it, dark studio lighting with blue accents, premium product showcase, 4K",
    Alam: "Breathtaking aerial drone shot of a tropical waterfall surrounded by lush green jungle, mist rising, golden sunlight filtering through trees, 4K cinematic",
    Minuman:
        "Refreshing iced coffee being poured into a glass with ice cubes, condensation droplets, warm cafe background bokeh, slow motion splash, 4K photorealistic",
};

const ANGLE_TEMPLATES = [
    {
        id: "closeup",
        name: "Close-up Detail",
        prompt: "Extreme close-up shot focusing on product details, texture, and craftsmanship. Macro lens feel, shallow depth of field.",
    },
    {
        id: "lifestyle",
        name: "Lifestyle / In-Use",
        prompt: "Product being used naturally in everyday life setting. Authentic, relatable, warm lighting.",
    },
    {
        id: "360spin",
        name: "360° Product Spin",
        prompt: "Product rotating 360 degrees on a clean surface, showing all angles. Smooth turntable rotation, studio lighting.",
    },
    {
        id: "cinematic",
        name: "Cinematic Hero Shot",
        prompt: "Epic cinematic hero shot of the product, dramatic lighting, slow motion reveal, premium feel.",
    },
    {
        id: "beforeafter",
        name: "Before & After",
        prompt: "Split screen or transition showing before and after using the product. Clear transformation visible.",
    },
];

const UGC_TEMPLATES = [
    {
        id: "problem_solution",
        name: "Problem → Solution",
        prompt: "Talent shows a common problem, then introduces the product as the solution. Genuine reaction, natural setting.",
    },
    {
        id: "first_impression",
        name: "First Impression Jujur",
        prompt: "Talent opens and tries the product for the first time on camera. Honest, unscripted reaction. Selfie-style camera.",
    },
    {
        id: "before_after",
        name: "Before & After Rutinitas",
        prompt: "Talent shows their routine before the product, then after. Clear improvement visible. Day-in-life style.",
    },
    {
        id: "daily_life",
        name: "Bagian dari Hari-hari",
        prompt: "Product seamlessly integrated into talent daily routine. Morning/evening ritual. Cozy, authentic vibe.",
    },
    {
        id: "recommend",
        name: "Rekomendasi ke Teman",
        prompt: "Talent talking directly to camera recommending the product to a friend. Casual, trustworthy, conversational tone.",
    },
];

const MODEL_INFO = {
    "sora-2": {
        name: "Sora 2",
        provider: "OpenAI",
        tokens: 35,
        durations: [10, 15],
        badge: "STABLE",
        color: "emerald",
    },
    "veo-3.1-fast": {
        name: "Veo 3.1 Fast",
        provider: "Google",
        tokens: 60,
        durations: [8],
        badge: "BARU",
        color: "blue",
        note: "Lebih cepat render. Durasi fixed 8 detik.",
    },
    "veo-3.1-quality": {
        name: "Veo 3.1 Quality",
        provider: "Google",
        tokens: 250,
        durations: [8],
        badge: "HD + Audio",
        color: "violet",
        note: "1080p + Audio",
    },
};

export default function VideoGenerator() {
    const { theme } = useTheme();
    const isDark = theme === "dark";

    // Tab state
    const [activeTab, setActiveTab] = useState("prompt");

    // Prompt mode
    const [prompt, setPrompt] = useState("");
    const [ugcMode, setUgcMode] = useState(false);

    // A/B Testing mode
    const [productName, setProductName] = useState("");
    const [productFeatures, setProductFeatures] = useState("");
    const [selectedAngles, setSelectedAngles] = useState([
        "closeup",
        "lifestyle",
        "360spin",
    ]);
    const [selectedUgcTemplate, setSelectedUgcTemplate] = useState("");
    const [cta, setCta] = useState("");
    const [productImage, setProductImage] = useState(null);

    // Video settings
    const [selectedModel, setSelectedModel] = useState("sora-2");
    const [aspectRatio, setAspectRatio] = useState("16:9");
    const [videoCount, setVideoCount] = useState(1);

    // Token & state
    const [tokenBalance, setTokenBalance] = useState(0);
    const [modelInfo, setModelInfo] = useState(MODEL_INFO);
    const [walletBalance, setWalletBalance] = useState(0);
    const [generating, setGenerating] = useState(false);
    const [videoJobs, setVideoJobs] = useState([]);
    const [showHistory, setShowHistory] = useState(false);
    const [showTokenHistory, setShowTokenHistory] = useState(false);
    const [tokenHistory, setTokenHistory] = useState([]);

    const getCsrfToken = () =>
        decodeURIComponent(
            document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || "",
        );

    // Load token balance
    const loadBalance = useCallback(async () => {
        try {
            const res = await fetch("/api/t/balance", {
                credentials: "same-origin",
                headers: { Accept: "application/json" },
            });
            if (res.ok) {
                const d = await res.json();
                setTokenBalance(d.balance || 0);
            }
        } catch {}
    }, []);

    // Load video history
    const loadHistory = useCallback(async () => {
        try {
            const res = await fetch("/api/v/history", {
                credentials: "same-origin",
                headers: { Accept: "application/json" },
            });
            if (res.ok) {
                const d = await res.json();
                setVideoJobs(d.jobs || []);
            }
        } catch {}
    }, []);

    const loadTokenHistory = async () => {
        try {
            const res = await fetch("/api/t/history", {
                credentials: "same-origin",
                headers: { Accept: "application/json" },
            });
            if (res.ok) {
                const d = await res.json();
                setTokenHistory(d.transactions || []);
            }
        } catch {}
    };

    useEffect(() => {
        loadBalance();
        loadHistory();
        fetch("/api/v/models", {
            credentials: "same-origin",
            headers: { Accept: "application/json" },
        })
            .then((response) =>
                response.ok ? response.json() : Promise.reject(),
            )
            .then((data) =>
                setModelInfo(
                    Object.fromEntries(
                        (data.models || []).map((model) => [
                            model.id,
                            { ...MODEL_INFO[model.id], ...model },
                        ]),
                    ),
                ),
            )
            .catch(() => {});
        fetch("/api/pricing/wallet", {
            credentials: "same-origin",
            headers: { Accept: "application/json" },
        })
            .then((response) =>
                response.ok ? response.json() : Promise.reject(),
            )
            .then((data) => setWalletBalance(data.balance_usd || 0))
            .catch(() => {});
    }, [loadBalance, loadHistory]);

    const currentModel = modelInfo[selectedModel] || MODEL_INFO[selectedModel];
    const modelTokens = currentModel?.tokens || 0;
    const totalCost = modelTokens * videoCount;
    const paygCost = Number(currentModel?.price_usd || 0) * videoCount;
    const hasBillingBalance =
        currentModel?.billing_mode === "payg"
            ? walletBalance >= paygCost
            : tokenBalance >= totalCost;
    const canGenerate =
        hasBillingBalance &&
        (activeTab === "prompt"
            ? prompt.trim().length > 0
            : productName.trim().length > 0);
    const balanceLabel =
        currentModel?.billing_mode === "payg"
            ? `$${walletBalance.toFixed(2)}`
            : `${tokenBalance} token`;

    // Build prompt for A/B testing mode
    const buildAbPrompt = () => {
        let p = `Create a product video for "${productName}".`;
        if (productFeatures) p += ` Key features: ${productFeatures}.`;

        const angles = selectedAngles
            .map((id) => ANGLE_TEMPLATES.find((a) => a.id === id)?.prompt)
            .filter(Boolean);
        if (angles.length > 0) p += " " + angles.join(" ");

        const ugc = UGC_TEMPLATES.find((t) => t.id === selectedUgcTemplate);
        if (ugc) p += " UGC Style: " + ugc.prompt;

        if (cta) p += ` Call-to-Action: "${cta}"`;

        return p;
    };

    const handleGenerate = async () => {
        if (generating) return;
        setGenerating(true);

        const finalPrompt = activeTab === "prompt" ? prompt : buildAbPrompt();

        try {
            const res = await fetch("/api/v/gen", {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-XSRF-TOKEN": getCsrfToken(),
                },
                body: JSON.stringify({
                    prompt: finalPrompt,
                    model: selectedModel,
                    aspect_ratio: aspectRatio,
                    count: videoCount,
                    mode: activeTab === "prompt" ? "prompt" : "ab_testing",
                    ugc_variation: ugcMode,
                    cta: cta || null,
                    settings: {},
                }),
            });

            const data = await res.json();
            if (!res.ok) {
                alert(data.message || "Gagal generate video");
                return;
            }

            loadBalance();
            if (data.balance_usd != null) setWalletBalance(data.balance_usd);
            loadHistory();
            alert(`${videoCount} video sedang diproses!`);
        } catch (err) {
            alert("Error: " + err.message);
        } finally {
            setGenerating(false);
        }
    };

    const toggleAngle = (id) => {
        setSelectedAngles((prev) =>
            prev.includes(id) ? prev.filter((a) => a !== id) : [...prev, id],
        );
    };

    // Shared classes
    const cardClass = isDark
        ? "bg-gray-900/60 border-white/[0.06] backdrop-blur-xl"
        : "bg-white border-gray-200/80 shadow-[0_1px_3px_rgba(15,23,42,0.04)]";
    const inputClass = isDark
        ? "bg-white/[0.05] border-white/[0.08] text-white placeholder-gray-600"
        : "bg-white border-gray-300 text-gray-900 placeholder-gray-400";
    const labelClass = isDark ? "text-gray-400" : "text-gray-500";
    const textClass = isDark ? "text-white" : "text-gray-900";
    const subTextClass = isDark ? "text-gray-500" : "text-gray-400";

    return (
        <div className="p-4 lg:p-6 space-y-5 max-w-6xl mx-auto">
            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 animate-fade-in-up">
                <div>
                    <h1
                        className={`text-2xl lg:text-3xl font-extrabold tracking-tight ${textClass}`}
                    >
                        Video{" "}
                        <span className="bg-gradient-to-r from-red-500 via-red-500 to-orange-500 bg-clip-text text-transparent animate-gradient">
                            Generator
                        </span>
                    </h1>
                    <p className={`text-sm mt-1 ${subTextClass}`}>
                        Buat video AI berkualitas tinggi dari prompt atau detail
                        produk.
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <div
                        className={`flex items-center gap-2 px-4 py-2.5 rounded-xl border transition-all ${isDark ? "bg-white/[0.03] border-white/[0.06]" : "bg-white border-gray-200/80 shadow-[0_1px_3px_rgba(15,23,42,0.04)]"}`}
                    >
                        <span className="w-5 h-5 rounded-md bg-gradient-to-br from-amber-400 to-orange-500 text-white flex items-center justify-center shadow-sm">
                            <svg
                                className="w-3 h-3"
                                fill="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <circle cx="12" cy="12" r="10" />
                            </svg>
                        </span>
                        <span
                            className={`text-sm font-bold tabular-nums ${textClass}`}
                        >
                            {balanceLabel}
                        </span>
                        <span className={`text-xs ${subTextClass}`}>
                            {currentModel?.billing_mode === "payg"
                                ? "PAYG Wallet"
                                : "Token"}
                        </span>
                    </div>
                    <button
                        onClick={() => {
                            setShowTokenHistory(true);
                            loadTokenHistory();
                        }}
                        className="ui-btn-ghost px-3 py-2.5 text-xs"
                    >
                        <svg
                            className="w-3.5 h-3.5"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2"
                            viewBox="0 0 24 24"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                        >
                            <circle cx="12" cy="12" r="10" />
                            <polyline points="12 6 12 12 16 14" />
                        </svg>
                        Riwayat
                    </button>
                </div>
            </div>

            {/* Tab Selector */}
            <div
                role="tablist"
                aria-label="Mode generator"
                className={`inline-flex rounded-xl p-1 border w-full ${isDark ? "bg-white/[0.03] border-white/[0.06]" : "bg-gray-100/80 border-gray-200"}`}
            >
                <button
                    onClick={() => setActiveTab("prompt")}
                    role="tab"
                    aria-selected={activeTab === "prompt"}
                    className={`flex-1 inline-flex items-center justify-center gap-2 py-2.5 px-3 rounded-lg text-sm font-semibold transition-all duration-200 ${
                        activeTab === "prompt"
                            ? "bg-gradient-to-r from-red-500 to-red-600 text-white shadow-[0_10px_24px_-4px_rgba(239,68,68,0.35)]"
                            : isDark
                              ? "text-gray-400 hover:text-white"
                              : "text-gray-600 hover:text-slate-900"
                    }`}
                >
                    <svg
                        className="w-4 h-4"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="2"
                        viewBox="0 0 24 24"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                    >
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                    </svg>
                    Prompt Manual
                </button>
                <button
                    onClick={() => setActiveTab("ab_testing")}
                    role="tab"
                    aria-selected={activeTab === "ab_testing"}
                    className={`flex-1 inline-flex items-center justify-center gap-2 py-2.5 px-3 rounded-lg text-sm font-semibold transition-all duration-200 ${
                        activeTab === "ab_testing"
                            ? "bg-gradient-to-r from-red-500 to-red-600 text-white shadow-[0_10px_24px_-4px_rgba(239,68,68,0.35)]"
                            : isDark
                              ? "text-gray-400 hover:text-white"
                              : "text-gray-600 hover:text-slate-900"
                    }`}
                >
                    <svg
                        className="w-4 h-4"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="2"
                        viewBox="0 0 24 24"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                    >
                        <rect x="3" y="3" width="18" height="18" rx="2" />
                        <line x1="12" y1="3" x2="12" y2="21" />
                    </svg>
                    A/B Testing
                    <span
                        className={`px-1.5 py-0.5 rounded-md text-[10px] font-bold tracking-wider ${
                            activeTab === "ab_testing"
                                ? "bg-white/25 text-white"
                                : isDark
                                  ? "bg-amber-500/20 text-amber-300"
                                  : "bg-amber-100 text-amber-700"
                        }`}
                    >
                        NEW
                    </span>
                </button>
            </div>

            <div className="grid lg:grid-cols-3 gap-6">
                {/* Left: Input Area */}
                <div className="lg:col-span-2 space-y-4">
                    {activeTab === "prompt" ? (
                        /* === PROMPT MANUAL === */
                        <div className={`p-5 rounded-2xl border ${cardClass}`}>
                            <label
                                className={`block text-xs font-bold uppercase tracking-[0.1em] mb-2 ${labelClass}`}
                            >
                                Input Prompt
                            </label>
                            <p className={`text-xs mb-3 ${subTextClass}`}>
                                Disarankan menggunakan bahasa Inggris untuk
                                hasil terbaik
                            </p>
                            <textarea
                                value={prompt}
                                onChange={(e) => setPrompt(e.target.value)}
                                rows={4}
                                maxLength={2000}
                                className={`w-full px-4 py-3 rounded-xl border text-sm resize-none focus:outline-none focus:border-red-500/60 focus:ring-4 focus:ring-red-500/15 transition-all ${inputClass}`}
                                placeholder="Describe your video scene in detail..."
                            />
                            <div className="flex justify-between mt-2">
                                <span className={`text-xs ${subTextClass}`}>
                                    {prompt.length}/2000 karakter
                                </span>
                            </div>

                            {/* Quick Prompts */}
                            <div className="mt-4">
                                <span
                                    className={`text-xs font-bold uppercase tracking-[0.1em] ${labelClass}`}
                                >
                                    Contoh Cepat
                                </span>
                                <div className="flex flex-wrap gap-2 mt-2">
                                    {[
                                        ["Kosmetik", "💄"],
                                        ["Makanan", "🍔"],
                                        ["Fashion", "👗"],
                                        ["Elektronik", "📱"],
                                        ["Alam", "🌿"],
                                        ["Minuman", "☕"],
                                    ].map(([cat, icon]) => (
                                        <button
                                            key={cat}
                                            onClick={() =>
                                                setPrompt(QUICK_PROMPTS[cat])
                                            }
                                            className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-all ${isDark ? "bg-white/[0.05] text-gray-400 hover:bg-white/[0.08] hover:text-white" : "bg-gray-100 text-gray-500 hover:bg-gray-200 hover:text-gray-700"}`}
                                        >
                                            <span>{icon}</span>
                                            {cat}
                                        </button>
                                    ))}
                                </div>
                            </div>

                            {/* UGC Toggle */}
                            <div
                                className={`mt-4 p-4 rounded-xl border transition-all ${
                                    ugcMode
                                        ? isDark
                                            ? "bg-violet-500/[0.05] border-violet-500/20"
                                            : "bg-violet-50 border-violet-200"
                                        : isDark
                                          ? "bg-white/[0.02] border-white/[0.06]"
                                          : "bg-gray-50 border-gray-200"
                                }`}
                            >
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-2">
                                        <span
                                            className={`text-sm font-semibold ${textClass}`}
                                        >
                                            UGC Variation Mode
                                        </span>
                                        <span className="text-[9px] bg-violet-100 text-violet-600 px-1.5 py-0.5 rounded-full font-bold border border-violet-200">
                                            AI
                                        </span>
                                    </div>
                                    <button
                                        onClick={() => setUgcMode(!ugcMode)}
                                        className={`w-11 h-6 rounded-full transition-all flex-shrink-0 ${ugcMode ? "bg-violet-500" : isDark ? "bg-white/[0.1]" : "bg-gray-300"}`}
                                    >
                                        <span
                                            className={`block w-5 h-5 rounded-full bg-white shadow transition-transform ${ugcMode ? "translate-x-5.5" : "translate-x-0.5"}`}
                                        />
                                    </button>
                                </div>
                                <p className={`text-xs mt-1.5 ${subTextClass}`}>
                                    Generate beberapa versi berbeda dari 1
                                    prompt
                                </p>
                                {ugcMode && (
                                    <div
                                        className={`mt-3 flex gap-2.5 p-3 rounded-lg ${isDark ? "bg-violet-500/[0.08]" : "bg-violet-50"}`}
                                    >
                                        <span className="text-violet-500 mt-0.5 flex-shrink-0">
                                            <svg
                                                className="w-4 h-4"
                                                fill="none"
                                                stroke="currentColor"
                                                strokeWidth="2"
                                                viewBox="0 0 24 24"
                                            >
                                                <path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                                            </svg>
                                        </span>
                                        <p
                                            className={`text-xs leading-relaxed ${isDark ? "text-violet-300/80" : "text-violet-700"}`}
                                        >
                                            Sistem akan otomatis membuat 1
                                            variasi unik dari prompt-mu —
                                            karakter talent, gaya bicara, dan
                                            sudut penyampaian berbeda, tapi
                                            konsep &amp; produk tetap sama.
                                            Hemat token, konten lebih variatif.
                                        </p>
                                    </div>
                                )}
                            </div>
                        </div>
                    ) : (
                        /* === A/B TESTING === */
                        <div className="space-y-4">
                            <div
                                className={`p-5 rounded-2xl border ${cardClass}`}
                            >
                                <h3
                                    className={`text-sm font-bold mb-4 ${textClass}`}
                                >
                                    Detail Produk
                                </h3>
                                <p className={`text-xs mb-4 ${subTextClass}`}>
                                    Isi info produk → sistem buat prompt
                                    otomatis
                                </p>
                                <div className="space-y-3">
                                    <div>
                                        <label
                                            className={`block text-xs font-bold uppercase tracking-[0.1em] mb-2 ${labelClass}`}
                                        >
                                            Nama Produk *
                                        </label>
                                        <input
                                            type="text"
                                            value={productName}
                                            onChange={(e) =>
                                                setProductName(e.target.value)
                                            }
                                            className={`w-full px-4 py-3 rounded-xl border text-sm focus:outline-none focus:border-red-500/60 focus:ring-4 focus:ring-red-500/15 transition-all ${inputClass}`}
                                            placeholder="Cth: Seger Waras Foot Soak"
                                        />
                                    </div>
                                    <div>
                                        <label
                                            className={`block text-xs font-bold uppercase tracking-[0.1em] mb-2 ${labelClass}`}
                                        >
                                            Keunggulan / Key Feature
                                        </label>
                                        <input
                                            type="text"
                                            value={productFeatures}
                                            onChange={(e) =>
                                                setProductFeatures(
                                                    e.target.value,
                                                )
                                            }
                                            className={`w-full px-4 py-3 rounded-xl border text-sm focus:outline-none focus:border-red-500/60 focus:ring-4 focus:ring-red-500/15 transition-all ${inputClass}`}
                                            placeholder="Cth: meredakan pegal dalam 5 menit, aroma herbal"
                                        />
                                    </div>
                                </div>
                            </div>

                            {/* Angle Templates */}
                            <div
                                className={`p-5 rounded-2xl border ${cardClass}`}
                            >
                                <div className="flex items-center justify-between mb-3">
                                    <h3
                                        className={`text-sm font-bold ${textClass}`}
                                    >
                                        Angle Templates
                                    </h3>
                                    <span className={`text-xs ${subTextClass}`}>
                                        {selectedAngles.length} terpilih
                                    </span>
                                </div>
                                <div className="space-y-2">
                                    {ANGLE_TEMPLATES.map((angle) => (
                                        <button
                                            key={angle.id}
                                            onClick={() =>
                                                toggleAngle(angle.id)
                                            }
                                            className={`w-full flex items-center justify-between px-4 py-3 rounded-xl text-sm font-medium transition-all ${
                                                selectedAngles.includes(
                                                    angle.id,
                                                )
                                                    ? "bg-red-500/15 border border-red-500/20 text-red-400"
                                                    : isDark
                                                      ? "bg-white/[0.03] border border-white/[0.06] text-gray-400"
                                                      : "bg-gray-50 border border-gray-200 text-gray-500"
                                            }`}
                                        >
                                            <span>{angle.name}</span>
                                            <span
                                                className={`text-xs font-bold ${selectedAngles.includes(angle.id) ? "text-red-400" : subTextClass}`}
                                            >
                                                {selectedAngles.includes(
                                                    angle.id,
                                                )
                                                    ? "✓ ON"
                                                    : "OFF"}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            </div>

                            {/* UGC Story Templates */}
                            <div
                                className={`p-5 rounded-2xl border ${cardClass}`}
                            >
                                <h3
                                    className={`text-sm font-bold mb-3 ${textClass}`}
                                >
                                    UGC Story{" "}
                                    <span className="px-1.5 py-0.5 rounded text-[10px] bg-amber-500/20 text-amber-400 ml-1">
                                        NEW
                                    </span>
                                </h3>
                                <p className={`text-xs mb-3 ${subTextClass}`}>
                                    Pilih 1 template cerita
                                </p>
                                <div className="space-y-2">
                                    {UGC_TEMPLATES.map((tmpl) => (
                                        <button
                                            key={tmpl.id}
                                            onClick={() =>
                                                setSelectedUgcTemplate(
                                                    selectedUgcTemplate ===
                                                        tmpl.id
                                                        ? ""
                                                        : tmpl.id,
                                                )
                                            }
                                            className={`w-full text-left px-4 py-3 rounded-xl text-sm font-medium transition-all ${
                                                selectedUgcTemplate === tmpl.id
                                                    ? "bg-red-500/15 border border-red-500/20 text-red-400"
                                                    : isDark
                                                      ? "bg-white/[0.03] border border-white/[0.06] text-gray-400"
                                                      : "bg-gray-50 border border-gray-200 text-gray-500"
                                            }`}
                                        >
                                            {tmpl.name}
                                        </button>
                                    ))}
                                </div>
                            </div>

                            {/* CTA */}
                            <div
                                className={`p-5 rounded-2xl border ${cardClass}`}
                            >
                                <label
                                    className={`block text-xs font-bold uppercase tracking-[0.1em] mb-1 ${labelClass}`}
                                >
                                    Call-to-Action (CTA) — Opsional
                                </label>
                                <p className={`text-xs mb-3 ${subTextClass}`}>
                                    Talent akan mengucapkan CTA ini di akhir
                                    video + muncul sebagai teks overlay
                                </p>
                                <input
                                    type="text"
                                    value={cta}
                                    onChange={(e) => setCta(e.target.value)}
                                    maxLength={500}
                                    className={`w-full px-4 py-3 rounded-xl border text-sm focus:outline-none focus:border-red-500/60 focus:ring-4 focus:ring-red-500/15 transition-all ${inputClass}`}
                                    placeholder="Cth: Pesan sekarang di Shopee, diskon 50%!"
                                />
                                <span
                                    className={`text-xs mt-1 block ${subTextClass}`}
                                >
                                    {cta.length}/500
                                </span>
                            </div>

                            {/* Product Image Upload */}
                            <div
                                className={`p-5 rounded-2xl border ${cardClass}`}
                            >
                                <label
                                    className={`block text-xs font-bold uppercase tracking-[0.1em] mb-1 ${labelClass}`}
                                >
                                    Referensi Gambar Produk — Opsional
                                </label>
                                <p className={`text-xs mb-3 ${subTextClass}`}>
                                    Upload gambar produkmu agar video
                                    menyesuaikan tampilannya
                                </p>
                                <div
                                    className={`border-2 border-dashed rounded-xl p-8 text-center transition-all ${isDark ? "border-white/[0.08] hover:border-white/[0.15]" : "border-gray-300 hover:border-gray-400"}`}
                                >
                                    <input
                                        type="file"
                                        accept="image/png,image/jpeg,image/webp"
                                        className="hidden"
                                        id="productImg"
                                        onChange={(e) =>
                                            setProductImage(e.target.files[0])
                                        }
                                    />
                                    <label
                                        htmlFor="productImg"
                                        className="cursor-pointer"
                                    >
                                        <svg
                                            className={`w-8 h-8 mx-auto mb-2 ${subTextClass}`}
                                            fill="none"
                                            stroke="currentColor"
                                            strokeWidth="1.5"
                                            viewBox="0 0 24 24"
                                        >
                                            <path d="M12 16v-8m-4 4l4-4 4 4M20 21H4a2 2 0 01-2-2V5a2 2 0 012-2h16a2 2 0 012 2v14a2 2 0 01-2 2z" />
                                        </svg>
                                        <p
                                            className={`text-sm font-medium ${subTextClass}`}
                                        >
                                            {productImage
                                                ? productImage.name
                                                : "Klik upload atau drag & drop"}
                                        </p>
                                        <p
                                            className={`text-xs mt-1 ${isDark ? "text-gray-600" : "text-gray-400"}`}
                                        >
                                            PNG, JPG, WEBP • Max 5MB
                                        </p>
                                    </label>
                                </div>
                            </div>
                        </div>
                    )}
                </div>

                {/* Right: Settings & Generate */}
                <div className="space-y-4">
                    {/* Model Selection */}
                    <div className={`p-5 rounded-2xl border ${cardClass}`}>
                        <h3 className={`text-sm font-bold mb-3 ${textClass}`}>
                            Pengaturan Video
                        </h3>
                        <div className="space-y-2">
                            {Object.entries(modelInfo).map(([id, m]) => (
                                <button
                                    key={id}
                                    onClick={() => setSelectedModel(id)}
                                    className={`w-full text-left p-3 rounded-xl transition-all ${
                                        selectedModel === id
                                            ? "bg-red-500/15 border border-red-500/20"
                                            : isDark
                                              ? "bg-white/[0.03] border border-white/[0.06] hover:bg-white/[0.05]"
                                              : "bg-gray-50 border border-gray-200 hover:bg-gray-100"
                                    }`}
                                >
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <span
                                                className={`text-sm font-semibold ${selectedModel === id ? "text-red-400" : textClass}`}
                                            >
                                                {m.name}
                                            </span>
                                            {m.badge && (
                                                <span
                                                    className={`ml-2 px-1.5 py-0.5 rounded text-[10px] font-bold ${
                                                        m.badge === "BARU"
                                                            ? "bg-blue-500/20 text-blue-400"
                                                            : m.badge ===
                                                                "HD + Audio"
                                                              ? "bg-violet-500/20 text-violet-400"
                                                              : "bg-emerald-500/20 text-emerald-400"
                                                    }`}
                                                >
                                                    {m.badge}
                                                </span>
                                            )}
                                        </div>
                                        <span
                                            className={`text-xs font-bold ${selectedModel === id ? "text-red-400" : subTextClass}`}
                                        >
                                            {m.billing_mode === "payg"
                                                ? `$${Number(m.price_usd).toFixed(2)} / ${m.unit || "video"}`
                                                : `${m.tokens} token`}
                                        </span>
                                    </div>
                                    <p
                                        className={`text-xs mt-1 ${subTextClass}`}
                                    >
                                        {m.provider} · {m.durations.join("s/")}s
                                    </p>
                                    {m.note && (
                                        <p
                                            className={`text-[11px] mt-1 ${isDark ? "text-gray-600" : "text-gray-400"}`}
                                        >
                                            {m.note}
                                        </p>
                                    )}
                                </button>
                            ))}
                        </div>
                    </div>

                    {/* Aspect Ratio */}
                    <div className={`p-5 rounded-2xl border ${cardClass}`}>
                        <h3 className={`text-sm font-bold mb-3 ${textClass}`}>
                            Rasio Aspek
                        </h3>
                        <div className="grid grid-cols-2 gap-2">
                            {[
                                ["16:9", "Landscape", "YouTube · Presentasi"],
                                ["9:16", "Portrait", "Reels · TikTok · Shorts"],
                            ].map(([ratio, name, desc]) => (
                                <button
                                    key={ratio}
                                    onClick={() => setAspectRatio(ratio)}
                                    className={`p-3 rounded-xl text-center transition-all ${
                                        aspectRatio === ratio
                                            ? "bg-red-500/15 border border-red-500/20"
                                            : isDark
                                              ? "bg-white/[0.03] border border-white/[0.06]"
                                              : "bg-gray-50 border border-gray-200"
                                    }`}
                                >
                                    <div
                                        className={`text-lg font-bold ${aspectRatio === ratio ? "text-red-400" : textClass}`}
                                    >
                                        {ratio}
                                    </div>
                                    <div
                                        className={`text-xs font-medium ${aspectRatio === ratio ? "text-red-400" : subTextClass}`}
                                    >
                                        {name}
                                    </div>
                                    <div
                                        className={`text-[10px] ${isDark ? "text-gray-600" : "text-gray-400"}`}
                                    >
                                        {desc}
                                    </div>
                                </button>
                            ))}
                        </div>
                    </div>

                    {/* Video Count */}
                    <div className={`p-5 rounded-2xl border ${cardClass}`}>
                        <div className="flex items-center justify-between mb-3">
                            <h3 className={`text-sm font-bold ${textClass}`}>
                                Jumlah Video
                            </h3>
                            <span className={`text-xs ${subTextClass}`}>
                                {videoCount} video
                            </span>
                        </div>
                        <input
                            type="range"
                            min="1"
                            max="10"
                            value={videoCount}
                            onChange={(e) =>
                                setVideoCount(parseInt(e.target.value))
                            }
                            className="w-full accent-red-500"
                        />
                        <div className="flex justify-between mt-1">
                            <span className={`text-[10px] ${subTextClass}`}>
                                1
                            </span>
                            <span className={`text-[10px] ${subTextClass}`}>
                                10
                            </span>
                        </div>
                    </div>

                    {/* Cost Summary */}
                    <div className={`p-5 rounded-2xl border ${cardClass}`}>
                        <div className="space-y-2">
                            <div className="flex justify-between">
                                <span className={`text-xs ${subTextClass}`}>
                                    Token Anda
                                </span>
                                <span
                                    className={`text-sm font-bold ${textClass}`}
                                >
                                    {tokenBalance}
                                </span>
                            </div>
                            <div className="flex justify-between">
                                <span className={`text-xs ${subTextClass}`}>
                                    Total Biaya
                                </span>
                                <span className="text-sm font-bold text-red-400">
                                    {totalCost} token
                                </span>
                            </div>
                            <div
                                className={`h-px ${isDark ? "bg-white/[0.06]" : "bg-gray-200"}`}
                            />
                            <div className="flex justify-between">
                                <span className={`text-xs ${subTextClass}`}>
                                    Sisa
                                </span>
                                <span
                                    className={`text-sm font-bold ${tokenBalance >= totalCost ? "text-emerald-400" : "text-red-400"}`}
                                >
                                    {tokenBalance - totalCost}
                                </span>
                            </div>
                        </div>

                        {tokenBalance < totalCost && (
                            <div className="mt-3 p-3 rounded-xl bg-red-500/10 border border-red-500/20 text-xs text-red-400">
                                Token tidak cukup.{" "}
                                <button className="underline font-bold">
                                    Top up sekarang →
                                </button>
                            </div>
                        )}

                        <button
                            onClick={handleGenerate}
                            disabled={!canGenerate || generating}
                            className="mt-4 w-full py-3.5 rounded-xl bg-gradient-to-r from-red-500 to-red-600 text-white text-sm font-bold hover:brightness-105 hover:-translate-y-0.5 hover:shadow-[0_12px_28px_-6px_rgba(239,68,68,0.45)] disabled:opacity-50 disabled:cursor-not-allowed transition-all shadow-[0_10px_24px_-4px_rgba(239,68,68,0.35)]"
                        >
                            {generating ? (
                                <span className="flex items-center justify-center gap-2">
                                    <svg
                                        className="w-4 h-4 animate-spin"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                    >
                                        <circle
                                            className="opacity-25"
                                            cx="12"
                                            cy="12"
                                            r="10"
                                            stroke="currentColor"
                                            strokeWidth="4"
                                        />
                                        <path
                                            className="opacity-75"
                                            fill="currentColor"
                                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
                                        />
                                    </svg>
                                    Generating...
                                </span>
                            ) : (
                                `Generate ${videoCount} Video`
                            )}
                        </button>
                    </div>

                    {/* Tips */}
                    <div
                        className={`p-4 rounded-2xl border ${isDark ? "bg-amber-500/[0.03] border-amber-500/10" : "bg-amber-50 border-amber-200"}`}
                    >
                        <p className="text-xs text-amber-400 font-medium">
                            💡 Tips Prompt Terbaik
                        </p>
                        <p
                            className={`text-xs mt-1 ${isDark ? "text-gray-500" : "text-gray-400"}`}
                        >
                            Sertakan subjek, aksi, gaya kamera, pencahayaan, dan
                            kualitas (4K/photorealistic).
                        </p>
                        <p
                            className={`text-[10px] mt-2 ${isDark ? "text-gray-600" : "text-gray-400"}`}
                        >
                            Powered by Sora 2 (STABLE)
                        </p>
                    </div>
                </div>
            </div>

            {/* Video Results */}
            <div className={`p-6 rounded-2xl border ${cardClass}`}>
                <div className="flex items-center justify-between mb-4">
                    <h2 className={`text-lg font-bold ${textClass}`}>
                        Hasil Video Anda
                    </h2>
                    <div className="flex gap-2">
                        <button
                            onClick={() => {
                                setShowHistory(true);
                                loadHistory();
                            }}
                            className={`px-3 py-1.5 rounded-lg text-xs font-medium ${isDark ? "bg-white/[0.05] text-gray-400" : "bg-gray-100 text-gray-500"}`}
                        >
                            Riwayat Video
                        </button>
                    </div>
                </div>

                {videoJobs.filter((j) => j.status !== "completed").length >
                0 ? (
                    <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        {videoJobs
                            .filter((j) => j.status !== "completed")
                            .slice(0, 6)
                            .map((job) => (
                                <div
                                    key={job.id}
                                    className={`p-4 rounded-xl border ${isDark ? "bg-white/[0.02] border-white/[0.06]" : "bg-gray-50 border-gray-200"}`}
                                >
                                    <div className="flex items-center gap-2 mb-2">
                                        {job.status === "processing" && (
                                            <div className="w-3 h-3 border-2 border-amber-400 border-t-transparent rounded-full animate-spin" />
                                        )}
                                        {job.status === "pending" && (
                                            <div className="w-3 h-3 rounded-full bg-gray-400" />
                                        )}
                                        {job.status === "failed" && (
                                            <div className="w-3 h-3 rounded-full bg-red-500" />
                                        )}
                                        <span
                                            className={`text-xs font-medium ${job.status === "processing" ? "text-amber-400" : job.status === "failed" ? "text-red-400" : subTextClass}`}
                                        >
                                            {job.status === "processing"
                                                ? "Sedang diproses..."
                                                : job.status === "pending"
                                                  ? "Menunggu..."
                                                  : "Gagal"}
                                        </span>
                                    </div>
                                    <p
                                        className={`text-xs truncate ${subTextClass}`}
                                    >
                                        {job.prompt?.slice(0, 80)}...
                                    </p>
                                    <p
                                        className={`text-[10px] mt-1 ${isDark ? "text-gray-600" : "text-gray-400"}`}
                                    >
                                        {job.model} · {job.tokens_used} token
                                    </p>
                                </div>
                            ))}
                    </div>
                ) : (
                    <div className="text-center py-12">
                        <svg
                            className={`w-16 h-16 mx-auto mb-4 ${isDark ? "text-gray-700" : "text-gray-300"}`}
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="1"
                            viewBox="0 0 24 24"
                        >
                            <rect x="2" y="2" width="20" height="20" rx="2" />
                            <polygon points="10 8 16 12 10 16 10 8" />
                        </svg>
                        <p className={`text-sm font-medium ${subTextClass}`}>
                            Belum ada video dibuat
                        </p>
                        <p
                            className={`text-xs mt-1 ${isDark ? "text-gray-600" : "text-gray-400"}`}
                        >
                            Tulis prompt dan klik Generate untuk mulai
                        </p>
                    </div>
                )}
            </div>

            {/* Token History Modal */}
            {showTokenHistory && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div
                        className="absolute inset-0 bg-black/70 backdrop-blur-sm"
                        onClick={() => setShowTokenHistory(false)}
                    />
                    <div
                        className={`relative w-full max-w-md max-h-[80vh] overflow-y-auto rounded-2xl border p-6 ${isDark ? "bg-gray-900/95 border-white/[0.08] backdrop-blur-2xl" : "bg-white border-gray-200"}`}
                    >
                        <div className="flex items-center justify-between mb-4">
                            <h3 className={`text-lg font-bold ${textClass}`}>
                                Riwayat Token
                            </h3>
                            <button
                                onClick={() => setShowTokenHistory(false)}
                                className={`p-1.5 rounded-lg ${isDark ? "text-gray-500 hover:text-white hover:bg-white/10" : "text-gray-400 hover:text-gray-600 hover:bg-gray-100"}`}
                            >
                                <svg
                                    className="w-5 h-5"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="2"
                                    viewBox="0 0 24 24"
                                >
                                    <line x1="18" y1="6" x2="6" y2="18" />
                                    <line x1="6" y1="6" x2="18" y2="18" />
                                </svg>
                            </button>
                        </div>
                        {tokenHistory.length === 0 ? (
                            <p
                                className={`text-sm text-center py-8 ${subTextClass}`}
                            >
                                Belum ada transaksi
                            </p>
                        ) : (
                            <div className="space-y-2">
                                {tokenHistory.map((tx, i) => (
                                    <div
                                        key={i}
                                        className={`flex items-center justify-between p-3 rounded-xl ${isDark ? "bg-white/[0.03]" : "bg-gray-50"}`}
                                    >
                                        <div>
                                            <span
                                                className={`text-sm font-medium ${textClass}`}
                                            >
                                                {tx.description || tx.type}
                                            </span>
                                            <p
                                                className={`text-[10px] ${subTextClass}`}
                                            >
                                                {new Date(
                                                    tx.created_at,
                                                ).toLocaleString("id-ID")}
                                            </p>
                                        </div>
                                        <span
                                            className={`text-sm font-bold ${tx.type === "topup" || tx.type === "refund" ? "text-emerald-400" : "text-red-400"}`}
                                        >
                                            {tx.type === "topup" ||
                                            tx.type === "refund"
                                                ? "+"
                                                : "-"}
                                            {tx.amount}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* Video History Modal */}
            {showHistory && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div
                        className="absolute inset-0 bg-black/70 backdrop-blur-sm"
                        onClick={() => setShowHistory(false)}
                    />
                    <div
                        className={`relative w-full max-w-2xl max-h-[80vh] overflow-y-auto rounded-2xl border p-6 ${isDark ? "bg-gray-900/95 border-white/[0.08] backdrop-blur-2xl" : "bg-white border-gray-200"}`}
                    >
                        <div className="flex items-center justify-between mb-4">
                            <h3 className={`text-lg font-bold ${textClass}`}>
                                Riwayat Video
                            </h3>
                            <button
                                onClick={() => setShowHistory(false)}
                                className={`p-1.5 rounded-lg ${isDark ? "text-gray-500 hover:text-white hover:bg-white/10" : "text-gray-400 hover:text-gray-600 hover:bg-gray-100"}`}
                            >
                                <svg
                                    className="w-5 h-5"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="2"
                                    viewBox="0 0 24 24"
                                >
                                    <line x1="18" y1="6" x2="6" y2="18" />
                                    <line x1="6" y1="6" x2="18" y2="18" />
                                </svg>
                            </button>
                        </div>
                        {videoJobs.length === 0 ? (
                            <p
                                className={`text-sm text-center py-8 ${subTextClass}`}
                            >
                                Belum ada video
                            </p>
                        ) : (
                            <div className="space-y-3">
                                {videoJobs.map((job) => (
                                    <div
                                        key={job.id}
                                        className={`p-4 rounded-xl border ${isDark ? "bg-white/[0.02] border-white/[0.06]" : "bg-gray-50 border-gray-200"}`}
                                    >
                                        <div className="flex items-center justify-between mb-1">
                                            <span
                                                className={`text-xs font-bold uppercase ${
                                                    job.status === "completed"
                                                        ? "text-emerald-400"
                                                        : job.status ===
                                                            "processing"
                                                          ? "text-amber-400"
                                                          : job.status ===
                                                              "failed"
                                                            ? "text-red-400"
                                                            : subTextClass
                                                }`}
                                            >
                                                {job.status}
                                            </span>
                                            <span
                                                className={`text-[10px] ${subTextClass}`}
                                            >
                                                {new Date(
                                                    job.created_at,
                                                ).toLocaleString("id-ID")}
                                            </span>
                                        </div>
                                        <p className={`text-sm ${textClass}`}>
                                            {job.prompt?.slice(0, 120)}...
                                        </p>
                                        <p
                                            className={`text-[10px] mt-1 ${subTextClass}`}
                                        >
                                            {job.model} · {job.aspect_ratio} ·{" "}
                                            {job.tokens_used} token
                                        </p>
                                        {job.video_url && (
                                            <a
                                                href={job.video_url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="mt-2 inline-flex items-center gap-1 text-xs text-red-400 font-medium hover:underline"
                                            >
                                                Download Video →
                                            </a>
                                        )}
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}

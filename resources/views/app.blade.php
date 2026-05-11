<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UltrAI — AI-Powered Platform for UMKM Indonesia</title>
    <meta name="description" content="Akses 50+ model AI premium (GPT-4o, Claude Sonnet, Gemini Pro, DeepSeek, Qwen) mulai Rp 5.000/hari. Unlimited usage, akun pribadi, support API key untuk VSCode & Cursor. Lebih hemat 82% dari ChatGPT Plus.">
    <meta name="keywords" content="UltrAI, AI murah Indonesia, ChatGPT murah, Claude Indonesia, Gemini Pro, DeepSeek, akses AI premium, API key AI, unlimited AI, alternatif ChatGPT Plus">
    <meta name="author" content="UltrAI">
    <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
    <meta property="og:title" content="UltrAI — Akses 50+ AI Premium Mulai Rp 5.000">
    <meta property="og:description" content="50+ model AI (GPT-4o, Claude, Gemini, DeepSeek) dalam satu akun. Unlimited usage, akun pribadi, garansi refund. Mulai Rp 5.000/hari.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://ultrai.id">
    <meta property="og:image" content="https://ultrai.id/og-image.png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:type" content="image/png">
    <meta property="og:site_name" content="UltrAI">
    <meta property="og:locale" content="id_ID">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="UltrAI — Akses 50+ AI Premium Mulai Rp 5.000">
    <meta name="twitter:description" content="50+ model AI premium dalam satu akun. Unlimited usage, garansi refund. Lebih hemat 82% dari ChatGPT Plus.">
    <meta name="twitter:image" content="https://ultrai.id/og-image.png">
    <link rel="canonical" href="https://ultrai.id">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="/ultr-icons.png">
    <link rel="apple-touch-icon" href="/ultr-icons.png">
    <link rel="shortcut icon" type="image/png" href="/ultr-icons.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])

    {{-- Umami Analytics --}}
    @if(config('services.umami.id'))
    <script defer src="{{ config('services.umami.url', 'https://cloud.umami.is/script.js') }}" data-website-id="{{ config('services.umami.id') }}"></script>
    @endif
</head>
<body class="font-['Inter'] bg-white">
    <div id="app"></div>

    {{-- JSON-LD Structured Data for SEO --}}
    @verbatim
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "SoftwareApplication",
        "name": "UltrAI",
        "url": "https://ultrai.id",
        "applicationCategory": "DeveloperApplication",
        "operatingSystem": "Web",
        "description": "Akses 50+ model AI premium (GPT-4o, Claude Sonnet, Gemini Pro, DeepSeek, Qwen) mulai Rp 5.000/hari. Unlimited usage, akun pribadi, support API key.",
        "offers": {
            "@type": "AggregateOffer",
            "priceCurrency": "IDR",
            "lowPrice": "5000",
            "highPrice": "499000",
            "offerCount": "6",
            "offers": [
                {"@type": "Offer", "name": "1 Hari", "price": "5000", "priceCurrency": "IDR"},
                {"@type": "Offer", "name": "1 Minggu", "price": "20000", "priceCurrency": "IDR"},
                {"@type": "Offer", "name": "1 Bulan", "price": "55000", "priceCurrency": "IDR"},
                {"@type": "Offer", "name": "3 Bulan", "price": "135000", "priceCurrency": "IDR"},
                {"@type": "Offer", "name": "6 Bulan", "price": "299000", "priceCurrency": "IDR"},
                {"@type": "Offer", "name": "12 Bulan", "price": "499000", "priceCurrency": "IDR"}
            ]
        },
        "aggregateRating": {
            "@type": "AggregateRating",
            "ratingValue": "4.9",
            "ratingCount": "1000",
            "bestRating": "5"
        },
        "provider": {
            "@type": "Organization",
            "name": "UltrAI",
            "url": "https://ultrai.id",
            "logo": "https://ultrai.id/ultr-icons.png",
            "contactPoint": {
                "@type": "ContactPoint",
                "telephone": "+6287786866648",
                "contactType": "customer service",
                "availableLanguage": "Indonesian"
            }
        }
    }
    </script>
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "FAQPage",
        "mainEntity": [
            {"@type": "Question", "name": "Apa itu UltrAI dan bedanya sama ChatGPT Plus?", "acceptedAnswer": {"@type": "Answer", "text": "UltrAI adalah layanan akses AI premium dengan 50+ model dalam satu akun pribadi. Dibanding ChatGPT Plus yang Rp 300rb/bulan untuk 1 model, UltrAI cuma Rp 55rb/bulan untuk 50+ model dengan unlimited usage."}},
            {"@type": "Question", "name": "Apakah UltrAI legal dan aman?", "acceptedAnswer": {"@type": "Answer", "text": "Ya, UltrAI legal dan aman. Setiap user dapat akun pribadi, data percakapan tidak bercampur, dilindungi SSL/TLS encryption."}},
            {"@type": "Question", "name": "Bisa dipakai untuk VSCode, Cursor, atau OpenCode?", "acceptedAnswer": {"@type": "Answer", "text": "Bisa! UltrAI menyediakan API key yang compatible dengan format OpenAI API untuk dipakai di VSCode, Cursor, OpenCode, dan tool developer lainnya."}},
            {"@type": "Question", "name": "Pembayaran pakai apa aja?", "acceptedAnswer": {"@type": "Answer", "text": "QRIS, transfer bank (BCA, Mandiri, BRI), e-wallet (Gopay, ShopeePay). Aktivasi otomatis dalam hitungan menit."}},
            {"@type": "Question", "name": "Ada batas penggunaan?", "acceptedAnswer": {"@type": "Answer", "text": "Tidak ada. Unlimited selama masa aktif paket. Tanpa hitungan token, tanpa rate limit harian."}},
            {"@type": "Question", "name": "Bagaimana kalau ada kendala?", "acceptedAnswer": {"@type": "Answer", "text": "Ada garansi refund/replace. Akun habis sebelum waktunya, kena limit, atau token error — langsung refund atau replace akun baru."}}
        ]
    }
    </script>
    @endverbatim
</body>
</html>

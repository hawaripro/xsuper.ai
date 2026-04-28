<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 — UltrAI</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            min-height: 100vh;
            background: #030712;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: #fff;
            overflow: hidden;
        }
        .bg-glow {
            position: fixed;
            width: 500px; height: 500px;
            border-radius: 50%;
            filter: blur(120px);
            opacity: 0.07;
            pointer-events: none;
        }
        .bg-glow-1 { top: -200px; right: -100px; background: #ef4444; }
        .bg-glow-2 { bottom: -200px; left: -100px; background: #ef4444; }
        .container {
            text-align: center;
            position: relative;
            z-index: 1;
            padding: 2rem;
        }
        .error-code {
            font-size: clamp(6rem, 20vw, 12rem);
            font-weight: 900;
            line-height: 1;
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 50%, #991b1b 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.04em;
            margin-bottom: 0.5rem;
            text-shadow: 0 0 80px rgba(239,68,68,0.3);
        }
        .title {
            font-size: 1.5rem;
            font-weight: 800;
            color: #f9fafb;
            margin-bottom: 0.75rem;
            letter-spacing: -0.02em;
        }
        .desc {
            font-size: 0.95rem;
            color: #6b7280;
            max-width: 400px;
            margin: 0 auto 2rem;
            line-height: 1.6;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 2rem;
            border-radius: 0.875rem;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: #fff;
            font-weight: 700;
            font-size: 0.875rem;
            text-decoration: none;
            transition: all 0.2s;
            box-shadow: 0 8px 24px rgba(239,68,68,0.25);
        }
        .btn:hover {
            filter: brightness(1.1);
            box-shadow: 0 12px 32px rgba(239,68,68,0.35);
            transform: translateY(-1px);
        }
        .logo {
            margin-bottom: 2rem;
            font-size: 1.5rem;
            font-weight: 900;
            letter-spacing: -0.03em;
        }
        .logo span:first-child { color: #fff; }
        .logo span:last-child { color: #ef4444; }
        .grid-bg {
            position: fixed;
            inset: 0;
            background-image: radial-gradient(circle, rgba(239,68,68,0.03) 1px, transparent 1px);
            background-size: 32px 32px;
            pointer-events: none;
        }
    </style>
</head>
<body>
    <div class="bg-glow bg-glow-1"></div>
    <div class="bg-glow bg-glow-2"></div>
    <div class="grid-bg"></div>
    <div class="container">
        <div class="logo"><span>Ultr</span><span>AI</span></div>
        <div class="error-code">404</div>
        <h1 class="title">Halaman Tidak Ditemukan</h1>
        <p class="desc">Maaf, halaman yang Anda cari tidak ada atau telah dipindahkan.</p>
        <a href="/" class="btn">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>
            Kembali ke Beranda
        </a>
    </div>
</body>
</html>

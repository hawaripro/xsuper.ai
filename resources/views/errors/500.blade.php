<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>500 — XSuper.ai</title>
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
        .bg-glow-2 { bottom: -200px; left: -100px; background: #7c3aed; }
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
            background: linear-gradient(135deg, #ef4444 0%, #7c3aed 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.04em;
            margin-bottom: 0.5rem;
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
            max-width: 420px;
            margin: 0 auto 2rem;
            line-height: 1.6;
        }
        .btn-group {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 2rem;
            border-radius: 0.875rem;
            font-weight: 700;
            font-size: 0.875rem;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: #fff;
            box-shadow: 0 8px 24px rgba(239,68,68,0.25);
        }
        .btn-primary:hover {
            filter: brightness(1.1);
            box-shadow: 0 12px 32px rgba(239,68,68,0.35);
            transform: translateY(-1px);
        }
        .btn-secondary {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.08);
            color: #9ca3af;
        }
        .btn-secondary:hover {
            background: rgba(255,255,255,0.08);
            color: #fff;
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
        .pulse-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 64px; height: 64px;
            border-radius: 1rem;
            background: rgba(239,68,68,0.1);
            border: 1px solid rgba(239,68,68,0.2);
            margin-bottom: 1.5rem;
            animation: pulse-glow 2s ease-in-out infinite;
        }
        @keyframes pulse-glow {
            0%, 100% { box-shadow: 0 0 0 0 rgba(239,68,68,0.2); }
            50% { box-shadow: 0 0 20px 4px rgba(239,68,68,0.15); }
        }
    </style>
</head>
<body>
    <div class="bg-glow bg-glow-1"></div>
    <div class="bg-glow bg-glow-2"></div>
    <div class="grid-bg"></div>
    <div class="container">
        <div class="logo"><span>XSuper</span><span>.ai</span></div>
        <div class="pulse-icon">
            <svg width="28" height="28" fill="none" stroke="#ef4444" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        </div>
        <div class="error-code">500</div>
        <h1 class="title">Terjadi Kesalahan Server</h1>
        <p class="desc">Maaf, terjadi kesalahan internal pada server kami. Tim teknis sudah diberitahu dan sedang memperbaikinya.</p>
        <div class="btn-group">
            <a href="/" class="btn btn-primary">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>
                Ke Beranda
            </a>
            <a href="javascript:location.reload()" class="btn btn-secondary">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                Coba Lagi
            </a>
        </div>
    </div>
</body>
</html>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 — Halaman Tidak Ditemukan | XSuper.ai</title>
    <link rel="icon" type="image/png" href="/xsuper-icon.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:#fafbfc;font-family:'Inter',-apple-system,sans-serif;overflow:hidden;position:relative}
        .bg-dots{position:absolute;inset:0;background-image:radial-gradient(circle,rgba(99,102,241,0.1) 1px,transparent 1px);background-size:28px 28px;opacity:.7}
        .glow{position:absolute;border-radius:50%;filter:blur(120px);pointer-events:none}
        .glow-1{top:-15%;left:50%;transform:translateX(-50%);width:600px;height:600px;background:rgba(99,102,241,0.15);animation:pulse 4s ease-in-out infinite}
        .glow-2{bottom:-20%;right:-10%;width:400px;height:400px;background:rgba(168,85,247,0.12);animation:pulse 5s ease-in-out infinite 1s}
        .container{position:relative;text-align:center;max-width:520px;padding:2rem}
        .error-num{position:relative;margin-bottom:2rem}
        .error-num .bg-num{font-size:clamp(140px,30vw,240px);font-weight:900;letter-spacing:-0.05em;line-height:1;background:linear-gradient(135deg,#6366f1,#8b5cf6,#a855f7);-webkit-background-clip:text;-webkit-text-fill-color:transparent;opacity:.12;animation:float 6s ease-in-out infinite;user-select:none}
        .error-num .fg-num{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:clamp(80px,18vw,150px);font-weight:900;letter-spacing:-0.04em;background:linear-gradient(135deg,#6366f1 0%,#8b5cf6 50%,#a855f7 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;animation:glow-text 3s ease-in-out infinite}
        .icon-box{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:80px;height:80px;border-radius:24px;background:linear-gradient(135deg,#6366f1,#8b5cf6);display:flex;align-items:center;justify-content:center;box-shadow:0 24px 48px -12px rgba(99,102,241,0.5),0 0 0 1px rgba(255,255,255,0.1) inset;animation:bounce-icon 2.5s ease-in-out infinite}
        .icon-box svg{width:36px;height:36px;color:white;stroke-width:2}
        .particles span{position:absolute;border-radius:50%;animation:particle 4s ease-in-out infinite}
        .particles span:nth-child(1){width:8px;height:8px;background:#6366f1;top:15%;left:20%;animation-delay:0s}
        .particles span:nth-child(2){width:6px;height:6px;background:#a855f7;top:25%;right:15%;animation-delay:.5s}
        .particles span:nth-child(3){width:10px;height:10px;background:#8b5cf6;bottom:20%;left:15%;animation-delay:1s}
        .particles span:nth-child(4){width:5px;height:5px;background:#c084fc;bottom:30%;right:20%;animation-delay:1.5s}
        .particles span:nth-child(5){width:7px;height:7px;background:#6366f1;top:60%;left:8%;animation-delay:2s}
        .particles span:nth-child(6){width:4px;height:4px;background:#a855f7;top:40%;right:8%;animation-delay:2.5s}
        h1{font-size:1.75rem;font-weight:900;color:#0f172a;margin-bottom:10px;animation:fade-up .6s ease-out both .2s}
        p{font-size:1rem;color:#64748b;margin-bottom:2rem;line-height:1.7;animation:fade-up .6s ease-out both .35s}
        .actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;animation:fade-up .6s ease-out both .5s}
        .btn{display:inline-flex;align-items:center;gap:8px;padding:14px 28px;border-radius:14px;font-size:.9rem;font-weight:700;text-decoration:none;transition:all .25s ease}
        .btn-primary{background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;box-shadow:0 12px 28px -6px rgba(239,68,68,0.45)}
        .btn-primary:hover{transform:translateY(-3px);box-shadow:0 18px 36px -6px rgba(239,68,68,0.55)}
        .btn-ghost{background:#fff;color:#334155;border:1.5px solid #e2e8f0;box-shadow:0 4px 12px -2px rgba(15,23,42,0.06)}
        .btn-ghost:hover{transform:translateY(-2px);border-color:#cbd5e1;box-shadow:0 8px 20px -4px rgba(15,23,42,0.1)}
        @keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-16px)}}
        @keyframes glow-text{0%,100%{filter:brightness(1)}50%{filter:brightness(1.3)}}
        @keyframes bounce-icon{0%,100%{transform:translate(-50%,-50%) translateY(0)}50%{transform:translate(-50%,-50%) translateY(-10px)}}
        @keyframes pulse{0%,100%{opacity:.6;transform:translateX(-50%) scale(1)}50%{opacity:1;transform:translateX(-50%) scale(1.08)}}
        @keyframes particle{0%,100%{transform:translate(0,0) scale(1);opacity:.4}25%{transform:translate(10px,-15px) scale(1.3);opacity:.8}50%{transform:translate(-5px,-25px) scale(.8);opacity:.6}75%{transform:translate(8px,-10px) scale(1.1);opacity:.5}}
        @keyframes fade-up{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
        @media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important}}
    </style>
</head>
<body>
    <div class="bg-dots"></div>
    <div class="glow glow-1"></div>
    <div class="glow glow-2"></div>
    <div class="particles"><span></span><span></span><span></span><span></span><span></span><span></span></div>
    <div class="container">
        <div class="error-num">
            <div class="bg-num">404</div>
            <div class="fg-num">404</div>
            <div class="icon-box">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    <line x1="8" y1="11" x2="14" y2="11"></line>
                </svg>
            </div>
        </div>
        <h1>Halaman Tidak Ditemukan</h1>
        <p>Halaman yang kamu cari tidak ada, sudah dipindahkan, atau URL-nya salah ketik.</p>
        <div class="actions">
            <a href="/" class="btn btn-primary">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                Beranda
            </a>
            <a href="javascript:history.back()" class="btn btn-ghost">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                Kembali
            </a>
        </div>
    </div>
</body>
</html>
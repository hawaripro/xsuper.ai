import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(fileURLToPath(import.meta.url));
const port = Number(process.env.PORT || 4177);
const rootFiles = new Set(['/index.html', '/gallery.css', '/gallery.js', '/fonts.css']);
const allowedVariant = /^\/(atlas|studio|signal)\/(index\.html|style\.css|app\.js)$/;
const allowedStudioMotion = /^\/studio\/motion\.(css|js)$/;
const allowedFont = /^\/fonts\/[a-z0-9-]+\.(ttf|txt)$/;
const types = {
  html: 'text/html; charset=utf-8',
  css: 'text/css; charset=utf-8',
  js: 'text/javascript; charset=utf-8',
  ttf: 'font/ttf',
  txt: 'text/plain; charset=utf-8',
};

const server = Bun.serve({
  hostname: '127.0.0.1',
  port,
  async fetch(request) {
    if (request.method !== 'GET' && request.method !== 'HEAD') {
      return new Response('Method not allowed', { status: 405, headers: { Allow: 'GET, HEAD' } });
    }
    let pathname;
    try {
      pathname = decodeURIComponent(new URL(request.url).pathname);
    } catch {
      return new Response('Invalid path', { status: 400 });
    }
    if (pathname === '/favicon.ico') return new Response(null, { status: 204 });
    if (pathname === '/') pathname = '/index.html';
    if (/^\/(atlas|studio|signal)\/?$/.test(pathname)) {
      pathname = `${pathname.replace(/\/$/, '')}/index.html`;
    }
    if (!rootFiles.has(pathname) && !allowedVariant.test(pathname) && !allowedStudioMotion.test(pathname) && !allowedFont.test(pathname)) {
      return new Response('Not found', { status: 404 });
    }
    const file = Bun.file(resolve(root, `.${pathname}`));
    if (!(await file.exists())) return new Response('Preview file not available', { status: 404 });
    const extension = pathname.split('.').pop();
    return new Response(request.method === 'HEAD' ? null : file, {
      headers: {
        'Content-Type': types[extension] || 'application/octet-stream',
        'Cache-Control': extension === 'ttf' ? 'public, max-age=86400' : 'no-store',
        'X-Content-Type-Options': 'nosniff',
        'Referrer-Policy': 'same-origin',
        'Content-Security-Policy': "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; frame-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'",
      },
    });
  },
});
console.log(`UltrAI prototypes ready: http://127.0.0.1:${server.port}`);

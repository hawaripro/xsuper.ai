function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content
        || decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] || '');
}

export class ApiError extends Error {
    constructor(message, status, details = null) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.details = details;
    }
}

export async function apiRequest(path, options = {}) {
    const method = (options.method || 'GET').toUpperCase();
    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(options.body && !(options.body instanceof FormData) ? { 'Content-Type': 'application/json' } : {}),
        ...(!['GET', 'HEAD'].includes(method) ? { 'X-CSRF-TOKEN': csrfToken() } : {}),
        ...(options.headers || {}),
    };
    const body = options.body && !(options.body instanceof FormData) && typeof options.body !== 'string'
        ? JSON.stringify(options.body)
        : options.body;
    const response = await fetch(path, { ...options, method, headers, body, credentials: 'same-origin' });
    const data = await response.json().catch(() => null);

    if (!response.ok) {
        const firstValidation = data?.errors ? Object.values(data.errors).flat()[0] : null;
        throw new ApiError(firstValidation || data?.message || `Permintaan gagal (${response.status}).`, response.status, data);
    }

    return data;
}

export function formatDateTime(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}

export function formatCurrency(value, currency = 'IDR') {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency, maximumFractionDigits: currency === 'IDR' ? 0 : 2 }).format(Number(value || 0));
}

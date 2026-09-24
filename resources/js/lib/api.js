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

const GENERIC_SERVER_MESSAGES = new Set(['Server Error', 'Internal Server Error', 'Service Unavailable', 'Bad Gateway', 'Gateway Timeout']);

/** The member-facing error for a failed response: first validation message, else the server's own message. */
export function responseError(response, data) {
    const firstValidation = data?.errors ? Object.values(data.errors).flat()[0] : null;
    const message = data?.message || data?.error?.message;
    // Framework defaults such as "Server Error" say nothing useful to a member.
    const generic = response.status >= 500 && (!message || GENERIC_SERVER_MESSAGES.has(message));
    return new ApiError(firstValidation || (generic ? 'Terjadi kesalahan pada server. Coba lagi sebentar lagi.' : message) || `Permintaan gagal (${response.status}).`, response.status, data);
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
    let response;
    try {
        response = await fetch(path, { ...options, method, headers, body, credentials: 'same-origin' });
    } catch (error) {
        // An aborted request stays an AbortError; a browser transport failure ("Failed to fetch") explains itself.
        if (error?.name !== 'TypeError') throw error;
        throw new ApiError('Server tidak dapat dihubungi. Periksa koneksi lalu coba lagi.', 0, null);
    }
    const data = await response.json().catch(() => null);

    if (!response.ok) throw responseError(response, data);

    return data;
}

export function formatDateTime(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}

export function formatCurrency(value, currency = 'IDR') {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency, maximumFractionDigits: currency === 'IDR' ? 0 : 2 }).format(Number(value || 0));
}

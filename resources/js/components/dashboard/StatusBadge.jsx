
const GOOD = new Set(['active', 'approved', 'completed', 'online', 'published', 'qualified', 'resolved']);
const WARN = new Set(['pending', 'processing', 'degraded', 'waiting_on_member', 'waiting_on_staff', 'draft']);
const BAD = new Set(['blocked', 'failed', 'offline', 'rejected', 'expired', 'closed']);

export default function StatusBadge({ status }) {
    const normalized = String(status || 'unknown').toLowerCase();
    const tone = GOOD.has(normalized) ? 'good' : WARN.has(normalized) ? 'warn' : BAD.has(normalized) ? 'bad' : 'neutral';
    return <span className={`ui-status ui-status-${tone}`}>{normalized.replaceAll('_', ' ')}</span>;
}

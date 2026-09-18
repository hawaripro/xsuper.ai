import { useCallback, useEffect, useState } from 'react';
import { EmptyState, ErrorState, LoadingState } from './AsyncState';
import DataTable from './DataTable';
import { apiRequest, formatDateTime } from '../../lib/api';
import { useLocale } from '../../contexts/LocaleContext';

const STATUS_FILTERS = [['', 'Semua'], ['flagged', 'Ditinjau'], ['attributed', 'Teratribusi'], ['qualified', 'Terkualifikasi'], ['rejected', 'Ditolak']];
const REASONS = { shared_ip: 'IP sama dengan pengundang', shared_device: 'Perangkat sama dengan pengundang' };

export default function ReferralReviewQueue() {
    const { t } = useLocale();
    const [status, setStatus] = useState('flagged');
    const [page, setPage] = useState(1);
    const [state, setState] = useState({ data: null, loading: true, error: '' });
    const [decision, setDecision] = useState(null);
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const [feedback, setFeedback] = useState({ error: '', success: '' });

    const load = useCallback(async (signal) => {
        setState(current => ({ ...current, loading: true, error: '' }));
        try {
            const query = new URLSearchParams({ page: String(page), ...(status ? { status } : {}) });
            const data = await apiRequest(`/api/admin/referrals/review?${query}`, { signal });
            if (!signal?.aborted) setState({ data, loading: false, error: '' });
        } catch (error) {
            if (error?.name !== 'AbortError' && !signal?.aborted) setState(current => ({ ...current, loading: false, error: error.message || t('Antrean referral tidak dapat dimuat.') }));
        }
    }, [page, status, t]);

    useEffect(() => { const controller = new AbortController(); load(controller.signal); return () => controller.abort(); }, [load]);

    const submitDecision = async () => {
        if (!decision) return;
        setBusy(true);
        setFeedback({ error: '', success: '' });
        try {
            const result = await apiRequest(`/api/admin/referrals/${decision.referral.id}/review`, { method: 'POST', body: { decision: decision.action, note: note.trim() || null } });
            setFeedback({ error: '', success: t(result.referral.status === 'qualified' ? 'Referral dilepas dan bonus diberikan kepada kedua akun.' : result.referral.status === 'attributed' ? 'Referral dilepas. Bonus diberikan saat pembelian pertama disetujui.' : 'Referral ditolak. Tidak ada bonus yang diberikan.') });
            setDecision(null);
            setNote('');
            load();
        } catch (error) {
            setFeedback({ error: error.message || t('Keputusan tidak dapat disimpan.'), success: '' });
        } finally {
            setBusy(false);
        }
    };

    const rows = state.data?.referrals || [];
    const pagination = state.data?.pagination;

    return (
        <section className="ui-card" aria-labelledby="referral-review-title">
            <div className="ui-card-header">
                <div>
                    <h2 id="referral-review-title" className="ui-section-title">{t('Tinjauan referral & anti-akun ganda')}</h2>
                    <p className="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">{t('Pendaftaran dari IP atau perangkat yang sama dengan pengundang ditahan sampai Anda memutuskan. Bonus hanya diberikan setelah dilepas.')}</p>
                </div>
                <span className="rounded-full bg-amber-500/10 px-3 py-1 text-[11px] font-semibold text-amber-700 dark:text-amber-300">{t('Ditinjau')}: {new Intl.NumberFormat('id-ID').format(Number(state.data?.flagged_count || 0))}</span>
            </div>
            <div className="flex flex-wrap gap-2 border-b border-slate-200 px-4 py-3 dark:border-white/10" role="tablist" aria-label={t('Filter status referral')}>
                {STATUS_FILTERS.map(([value, label]) => <button key={value || 'all'} type="button" role="tab" aria-selected={status === value} className={status === value ? 'ui-btn-primary min-h-9 px-3 text-xs' : 'ui-btn-secondary min-h-9 px-3 text-xs'} onClick={() => { setStatus(value); setPage(1); }}>{t(label)}</button>)}
            </div>
            {(feedback.error || feedback.success) && <div className={`m-4 rounded-xl border p-3 text-xs ${feedback.error ? 'border-red-500/25 bg-red-500/5 text-red-700 dark:text-red-300' : 'border-emerald-500/25 bg-emerald-500/5 text-emerald-700 dark:text-emerald-300'}`} role={feedback.error ? 'alert' : 'status'}>{feedback.error || feedback.success}</div>}
            {state.loading && !state.data ? <div className="p-4"><LoadingState label={t('Memuat referral…')} /></div>
                : state.error && !state.data ? <div className="p-4"><ErrorState message={state.error} onRetry={() => load()} /></div>
                    : rows.length === 0 ? <div className="p-4"><EmptyState title={t('Tidak ada referral pada filter ini')} description={t('Referral yang ditahan karena sinyal akun ganda akan muncul di sini.')} /></div>
                        : <DataTable
                            rows={rows}
                            columns={[
                                { key: 'referred', label: t('Pendaftar'), render: row => <div><strong className="block text-slate-900 dark:text-white">{row.referred?.name || '—'}</strong><span className="text-[11px] text-slate-500">{row.referred?.email}</span></div> },
                                { key: 'referrer', label: t('Pengundang'), render: row => <div><strong className="block text-slate-900 dark:text-white">{row.referrer?.name || '—'}</strong><span className="text-[11px] text-slate-500">{row.referrer?.email}</span></div> },
                                { key: 'signals', label: t('Sinyal'), render: row => <div className="space-y-1">{row.risk_reasons?.length ? row.risk_reasons.map(reason => <span key={reason} className="block rounded-md bg-amber-500/10 px-2 py-0.5 text-[11px] font-medium text-amber-800 dark:text-amber-200">{t(REASONS[reason] || reason)}</span>) : <span className="text-[11px] text-slate-500">{t('Tidak ada sinyal')}</span>}<span className="block font-mono text-[10px] text-slate-500">{row.referred_ip || '—'}</span></div> },
                                { key: 'status', label: t('Status'), render: row => <span className={`ui-status ui-status-${row.status === 'flagged' ? 'warn' : row.status === 'qualified' ? 'good' : row.status === 'rejected' ? 'bad' : 'neutral'}`}>{t(row.status === 'flagged' ? 'Ditinjau' : row.status === 'qualified' ? 'Terkualifikasi' : row.status === 'rejected' ? 'Ditolak' : 'Teratribusi')}</span> },
                                { key: 'dates', label: t('Waktu'), render: row => <div className="text-[11px] text-slate-500"><span className="block">{formatDateTime(row.attributed_at)}</span>{row.reviewed_at && <span className="block">{t('Ditinjau')} {formatDateTime(row.reviewed_at)} · {row.reviewed_by}</span>}{row.review_note && <span className="block italic">“{row.review_note}”</span>}</div> },
                                { key: 'actions', label: t('Tindakan'), render: row => row.status === 'qualified' ? <span className="text-[11px] text-slate-500">{t('Selesai')}</span> : <div className="flex flex-wrap gap-2">{row.status !== 'attributed' && <button type="button" className="ui-btn-secondary" onClick={() => { setDecision({ action: 'approve', referral: row }); setNote(''); }}>{t('Lepas')}</button>}{row.status !== 'rejected' && <button type="button" className="ui-btn-secondary text-red-600 dark:text-red-400" onClick={() => { setDecision({ action: 'reject', referral: row }); setNote(''); }}>{t('Tolak')}</button>}</div> },
                            ]}
                        />}
            {pagination && pagination.last_page > 1 && <div className="flex items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 text-[11px] text-slate-500 dark:border-white/10"><button type="button" className="ui-btn-secondary" disabled={pagination.current_page <= 1} onClick={() => setPage(value => value - 1)}>{t('Sebelumnya')}</button><span>{t('Halaman')} {pagination.current_page} / {pagination.last_page}</span><button type="button" className="ui-btn-secondary" disabled={pagination.current_page >= pagination.last_page} onClick={() => setPage(value => value + 1)}>{t('Berikutnya')}</button></div>}
            {decision && (
                <div className="fixed inset-0 z-[80] grid place-items-center bg-slate-950/55 p-4" role="presentation" onMouseDown={event => { if (event.target === event.currentTarget && !busy) setDecision(null); }}>
                    <div className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl dark:border-white/10 dark:bg-slate-900" role="dialog" aria-modal="true" aria-labelledby="referral-decision-title">
                        <h2 id="referral-decision-title" className="text-base font-bold text-slate-900 dark:text-white">{t(decision.action === 'approve' ? 'Lepas referral ini?' : 'Tolak referral ini?')}</h2>
                        <p className="mt-2 text-xs leading-5 text-slate-600 dark:text-slate-300">{t(decision.action === 'approve' ? 'Sinyal akun ganda diabaikan untuk referral ini. Bonus diberikan segera jika pembelian pertama sudah disetujui.' : 'Referral ditandai tidak memenuhi syarat dan tidak akan pernah memberi bonus.')}</p>
                        <label className="mt-4 block text-xs font-semibold text-slate-900 dark:text-white">{t('Catatan (opsional)')}<textarea className="ui-input mt-1 min-h-20 w-full text-xs" maxLength={240} value={note} onChange={event => setNote(event.target.value)} placeholder={t('Alasan keputusan, mis. hasil verifikasi manual')} /></label>
                        <div className="mt-5 flex justify-end gap-2"><button type="button" className="ui-btn-secondary" disabled={busy} onClick={() => setDecision(null)}>{t('Batal')}</button><button type="button" className="ui-btn-primary min-h-10 px-4 text-xs" disabled={busy} onClick={submitDecision}>{busy ? t('Menyimpan…') : t(decision.action === 'approve' ? 'Lepas & beri bonus' : 'Tolak referral')}</button></div>
                    </div>
                </div>
            )}
        </section>
    );
}

import React, { useCallback, useEffect, useMemo, useState } from "react";
import { apiRequest } from "../lib/api";
import {
    Button,
    FormField,
    InlineAlert,
    MemberPage,
    Metric,
    PageHeader,
    Panel,
    SectionHeader,
    Spinner,
    StatePanel,
    StatusBadge,
    controlClass,
    errorMessage,
    formatLocalDate,
    formatUsdMicros,
    textAreaClass,
    validationErrors,
} from "../components/member/MemberUI";

const SIZES = ["256x256", "512x512", "1024x1024", "1024x1792", "1792x1024"];

function modelPrice(model) {
    if (!model) return "Harga belum tersedia";
    if (model.price_idr != null) return new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(Number(model.price_idr));
    if (model.price_usd != null) return new Intl.NumberFormat("id-ID", { style: "currency", currency: "USD", minimumFractionDigits: 2, maximumFractionDigits: 6 }).format(Number(model.price_usd));
    return "Harga belum tersedia";
}

function ImageResults({ job }) {
    const urls = Array.isArray(job?.result_urls) ? job.result_urls : [];
    if (!job) return null;
    if (job.error_message) return <InlineAlert tone="error"><strong>Proses {job.status || "gagal"}.</strong> {job.error_message}</InlineAlert>;
    if (urls.length === 0) return <InlineAlert tone={job.status === "completed" ? "warning" : "info"}>Job {job.job_id} berstatus <strong>{job.status}</strong>. Belum ada URL hasil yang tersedia.</InlineAlert>;
    return (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            {urls.map((url, index) => (
                <figure key={`${url}-${index}`} className="overflow-hidden rounded-xl border border-slate-200 bg-slate-50 dark:border-white/[0.08] dark:bg-white/[0.025]">
                    <img src={url} alt={`Hasil gambar ${index + 1} dari ${job.prompt}`} className="aspect-square w-full object-cover" loading="lazy" />
                    <figcaption className="flex items-center justify-between gap-2 p-2 text-[10px] text-slate-500">
                        <span>Hasil {index + 1}</span>
                        <a href={url} target="_blank" rel="noreferrer" className="font-semibold text-red-600 hover:underline dark:text-red-400">Buka asli</a>
                    </figcaption>
                </figure>
            ))}
        </div>
    );
}

export default function GenerateImage() {
    const [models, setModels] = useState([]);
    const [jobs, setJobs] = useState([]);
    const [form, setForm] = useState({ model: "", prompt: "", size: "1024x1024", n: "1" });
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [submitting, setSubmitting] = useState(false);
    const [submitError, setSubmitError] = useState(null);
    const [errors, setErrors] = useState({});
    const [activeJob, setActiveJob] = useState(null);
    const [statusLoading, setStatusLoading] = useState(false);
    const [statusError, setStatusError] = useState(null);

    const load = useCallback(async () => {
        setLoading(true); setError(null);
        const results = await Promise.allSettled([apiRequest("/api/images/models"), apiRequest("/api/images")]);
        const rejected = results.find((result) => result.status === "rejected");
        if (rejected) { setError(rejected.reason); setLoading(false); return; }
        const loadedModels = Array.isArray(results[0].value?.models) ? results[0].value.models : [];
        const loadedJobs = Array.isArray(results[1].value?.jobs) ? results[1].value.jobs : [];
        setModels(loadedModels); setJobs(loadedJobs);
        setForm((current) => ({ ...current, model: loadedModels.some((model) => model.id === current.model) ? current.model : (loadedModels[0]?.id || "") }));
        setLoading(false);
    }, []);

    useEffect(() => { load(); }, [load]);

    const selectedModel = useMemo(() => models.find((model) => model.id === form.model) || null, [models, form.model]);

    const generate = async (event) => {
        event.preventDefault(); setSubmitting(true); setSubmitError(null); setErrors({});
        try {
            const response = await apiRequest("/api/images", { method: "POST", body: { ...form, n: Number(form.n) } });
            const job = response?.job;
            if (job) { setActiveJob(job); setJobs((current) => [job, ...current.filter((item) => item.job_id !== job.job_id)].slice(0, 50)); }
        } catch (requestError) {
            setSubmitError(requestError); setErrors(validationErrors(requestError));
            if (requestError?.details?.job) {
                setActiveJob(requestError.details.job);
                setJobs((current) => [requestError.details.job, ...current.filter((item) => item.job_id !== requestError.details.job.job_id)].slice(0, 50));
            }
        } finally { setSubmitting(false); }
    };

    const openJob = async (job) => {
        setActiveJob(job); setStatusLoading(true); setStatusError(null);
        try {
            const response = await apiRequest(`/api/images/${encodeURIComponent(job.job_id)}`);
            if (response?.job) {
                setActiveJob(response.job);
                setJobs((current) => current.map((item) => item.job_id === response.job.job_id ? response.job : item));
            }
        } catch (requestError) { setStatusError(requestError); }
        finally { setStatusLoading(false); }
    };

    return (
        <MemberPage>
            <PageHeader eyebrow="Creative tools" title="Generate gambar" description="Pilih model aktif, kirim prompt, lalu pantau hasil dan status upstream yang sebenarnya." actions={<Button variant="secondary" onClick={load} disabled={loading}>{loading ? "Memuat…" : "Muat ulang"}</Button>} />

            {loading ? (
                <Panel className="flex min-h-56 items-center justify-center"><Spinner label="Memuat model dan riwayat gambar" /></Panel>
            ) : error ? (
                <Panel className="p-4"><StatePanel type="error" title="Layanan gambar tidak dapat dimuat" description={errorMessage(error, "Endpoint gambar belum tersedia atau sedang mengalami gangguan operasional.")} action={<Button variant="secondary" onClick={load}>Coba lagi</Button>} /></Panel>
            ) : (
                <div className="grid gap-5 xl:grid-cols-[minmax(340px,.8fr)_minmax(0,1.2fr)]">
                    <div className="space-y-5">
                        <Panel className="p-4">
                            <SectionHeader title="Permintaan baru" description="Harga dan kemampuan berasal dari katalog model aktif." />
                            {models.length === 0 ? (
                                <div className="mt-4"><StatePanel type="error" title="Tidak ada model tersedia" description="Pengelola belum mengaktifkan model gambar yang dapat digunakan. Tidak ada permintaan yang akan dikirim." compact /></div>
                            ) : (
                                <form className="mt-4 space-y-3" onSubmit={generate}>
                                    {submitError && <InlineAlert tone="error">{errorMessage(submitError, "Gambar gagal dibuat oleh layanan upstream.")}</InlineAlert>}
                                    <FormField label="Model" error={errors.model} required><select className={controlClass} value={form.model} onChange={(event) => setForm({ ...form, model: event.target.value })}>{models.map((model) => <option key={model.id} value={model.id}>{model.name}</option>)}</select></FormField>
                                    {selectedModel && <div className="grid grid-cols-2 gap-2"><Metric label="Tier" value={selectedModel.tier || "—"} detail={selectedModel.provider || "Provider tidak dicantumkan"} /><Metric label="Harga / unit" value={modelPrice(selectedModel)} detail={selectedModel.unit || "unit"} /></div>}
                                    {Array.isArray(selectedModel?.capabilities) && selectedModel.capabilities.length > 0 && <div className="flex flex-wrap gap-1.5">{selectedModel.capabilities.map((capability) => <span key={capability} className="rounded-full bg-slate-100 px-2 py-1 text-[10px] font-medium text-slate-600 dark:bg-white/[0.06] dark:text-slate-300">{capability}</span>)}</div>}
                                    <FormField label="Prompt" error={errors.prompt} hint={`${form.prompt.length}/4000 karakter`} required><textarea className={`${textAreaClass} min-h-32`} value={form.prompt} onChange={(event) => setForm({ ...form, prompt: event.target.value })} maxLength={4000} placeholder="Jelaskan subjek, komposisi, pencahayaan, dan gaya visual…" /></FormField>
                                    <div className="grid grid-cols-2 gap-3">
                                        <FormField label="Ukuran" error={errors.size} required><select className={controlClass} value={form.size} onChange={(event) => setForm({ ...form, size: event.target.value })}>{SIZES.map((size) => <option key={size} value={size}>{size}</option>)}</select></FormField>
                                        <FormField label="Jumlah" error={errors.n} required><select className={controlClass} value={form.n} onChange={(event) => setForm({ ...form, n: event.target.value })}>{[1,2,3,4].map((count) => <option key={count} value={count}>{count} gambar</option>)}</select></FormField>
                                    </div>
                                    <Button type="submit" className="w-full" disabled={submitting || !form.model}>{submitting ? "Memproses upstream…" : "Generate gambar"}</Button>
                                </form>
                            )}
                        </Panel>
                    </div>

                    <div className="space-y-5">
                        <Panel className="p-4">
                            <SectionHeader title="Hasil aktif" description={activeJob ? `Job ${activeJob.job_id}` : "Pilih riwayat atau buat permintaan baru."} action={activeJob && <StatusBadge value={activeJob.status} />} />
                            <div className="mt-4">
                                {statusLoading ? <div className="flex min-h-48 items-center justify-center"><Spinner label="Memeriksa status job" /></div> : statusError ? <StatePanel type="error" title="Status job tidak dapat dimuat" description={errorMessage(statusError)} action={<Button variant="secondary" onClick={() => activeJob && openJob(activeJob)}>Coba lagi</Button>} /> : !activeJob ? <StatePanel title="Belum ada hasil dipilih" description="Setelah permintaan dibuat, hasil atau error upstream akan ditampilkan di sini." /> : <><ImageResults job={activeJob} /><div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4"><Metric label="Model" value={activeJob.model || "—"} /><Metric label="Ukuran" value={activeJob.size || "—"} /><Metric label="Billing" value={activeJob.billing_status || "—"} /><Metric label="Biaya" value={formatUsdMicros(activeJob.cost_microusd)} /></div></>}
                            </div>
                        </Panel>

                        <Panel className="overflow-hidden">
                            <div className="border-b border-slate-200 p-4 dark:border-white/[0.08]"><SectionHeader title="Riwayat gambar" description="Maksimal 50 job terbaru pada akun Anda." /></div>
                            {jobs.length === 0 ? <div className="p-4"><StatePanel title="Belum ada riwayat" description="Permintaan gambar yang dikirim akan muncul di sini." compact /></div> : <div className="max-h-80 divide-y divide-slate-100 overflow-y-auto dark:divide-white/[0.06]">{jobs.map((job) => <button key={job.job_id} type="button" onClick={() => openJob(job)} className={`flex w-full items-start justify-between gap-3 p-3 text-left transition ${activeJob?.job_id === job.job_id ? "bg-red-50/70 dark:bg-red-500/[0.08]" : "hover:bg-slate-50 dark:hover:bg-white/[0.025]"}`}><span className="min-w-0"><span className="line-clamp-1 block text-[12px] font-semibold text-slate-900 dark:text-white">{job.prompt || "Prompt tidak tersedia"}</span><span className="mt-0.5 block text-[10px] text-slate-500">{job.model} · {job.size} · {formatLocalDate(job.created_at)}</span>{job.error && <span className="mt-1 line-clamp-1 block text-[10px] text-red-600 dark:text-red-400">{job.error}</span>}</span><StatusBadge value={job.status} /></button>)}</div>}
                        </Panel>
                    </div>
                </div>
            )}
        </MemberPage>
    );
}

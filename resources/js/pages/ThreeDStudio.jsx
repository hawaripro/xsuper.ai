import { Component, Suspense, lazy, useEffect, useMemo, useRef, useState } from "react";
import { useAuth } from "../contexts/AuthContext";
import { useLocale } from "../contexts/LocaleContext";
import { validationErrors } from "../components/member/MemberUI";
import { isPending, tokenPrice, useMediaStudio, useStudioDraft } from "../components/studios/useMediaStudio";
import { capabilityErrors, capabilitySubmission, capabilityValues } from "../components/studios/capability";
import CapabilityForm from "../components/studios/CapabilityForm";
import { StudioButton, StudioCancellation, StudioCatalog, StudioEmpty, StudioHeader, StudioHistory, StudioIcon, StudioJobMeta, StudioNotice, StudioProgress, StudioQuote, mediaError } from "../components/studios/StudioUI";
import "../components/studios/model3d.css";

const Model3dViewer = lazy(() => import("../components/studios/Model3dViewer"));
const defaults = { model: "", values: {} };
const operation = "image_to_3d";

class ViewerBoundary extends Component {
    state = { failed: false };

    static getDerivedStateFromError() {
        return { failed: true };
    }

    render() {
        return this.state.failed ? this.props.fallback : this.props.children;
    }
}

function managedModelUrl(value) {
    if (typeof value !== "string" || !value) return null;
    try {
        const url = new URL(value, window.location.origin);
        return url.origin === window.location.origin && !url.username && !url.password
            && /^\/api\/3d\/[^/]+\/asset$/.test(url.pathname)
            ? `${url.pathname}${url.search}` : null;
    } catch {
        return null;
    }
}

function ThreeDResult({ job, submitting, loading }) {
    const { t } = useLocale();
    const url = managedModelUrl(job?.model_url);
    const completed = job?.status === "completed";
    const previewable = completed && job.previewable === true && job.format === "glb" && url;
    const viewerFailure = <div className="studio-model3d-placeholder" role="alert">
        <StudioEmpty icon="model3d" title="Pratinjau 3D tidak dapat dimuat" description="Peramban tidak dapat membuka pratinjau. Unduh file asli untuk membukanya di aplikasi 3D yang kompatibel." />
    </div>;

    let content;
    if (submitting || isPending(job)) {
        content = <div className="studio-model3d-placeholder"><StudioProgress job={job} submitting={submitting} /></div>;
    } else if (loading && !job) {
        content = <div className="studio-model3d-placeholder" role="status"><span className="studio-spinner" aria-hidden="true" /><p>{t("Memuat hasil 3D…")}</p></div>;
    } else if (previewable) {
        content = <ViewerBoundary key={`${job.job_id}:${url}`} fallback={viewerFailure}>
            <Suspense fallback={<div className="studio-model3d-placeholder" role="status"><span className="studio-spinner" aria-hidden="true" /><p>{t("Memuat penampil 3D…")}</p></div>}>
                <Model3dViewer src={url} alt={`${t("Model 3D")}: ${job.model_label || job.model}`} />
            </Suspense>
        </ViewerBoundary>;
    } else if (completed) {
        content = <div className="studio-model3d-placeholder" role="status">
            <StudioEmpty icon="model3d" title={url ? "Pratinjau tidak tersedia" : "File hasil belum tersedia"}
                description={!url ? "Periksa status untuk memuat kembali file hasil."
                    : job.preview_unavailable_reason || "Format ini belum dapat dipreview di peramban. Unduh file asli untuk membukanya di aplikasi 3D yang kompatibel."} />
        </div>;
    } else if (job) {
        content = <div className="studio-model3d-placeholder">
            <StudioEmpty icon="model3d" title={job.stage === "cancelled" || job.status === "cancelled" ? "Permintaan dibatalkan" : "Model 3D belum berhasil dibuat"}
                description="Lihat status dan informasi tagihan di bawah sebelum membuat permintaan baru." />
        </div>;
    } else {
        content = <div className="studio-model3d-placeholder">
            <StudioEmpty icon="model3d" title="Belum ada model 3D" description="Unggah gambar untuk membuat model, atau pilih hasil dari riwayat." />
        </div>;
    }

    return <>
        <div className="studio-toolbar studio-canvas-toolbar">
            <div><h2>{t("Pratinjau 3D")}</h2><p className="studio-help">{t("Putar, perbesar, dan geser model hasil Anda.")}</p></div>
            {completed && url && <a className="studio-button studio-download" href={url} download>
                <StudioIcon name="download" />{t("Unduh file asli")}{job.format && <span className="studio-model3d-format">{job.format.toUpperCase()}</span>}
            </a>}
        </div>
        {content}
    </>;
}

function ThreeDWorkbench({ userId }) {
    const { t } = useLocale();
    const studio = useMediaStudio("model3d");
    const [draft, setDraft] = useStudioDraft("model3d", userId, defaults);
    const [cancellation, setCancellation] = useState(null);
    const reconciled = useRef("");
    const models = useMemo(() => studio.models.filter((item) => item.capabilities?.[operation]?.output_kind === "model3d"), [studio.models]);
    const selectedId = studio.requestedModel || draft.model;
    const model = models.find((item) => item.id === selectedId) || null;
    const capability = model?.capabilities?.[operation] || null;

    useEffect(() => {
        const chosen = models.find((item) => item.id === (studio.requestedModel || draft.model))
            || (studio.requestedModel ? null : models[0]);
        if (!chosen) return;
        const nextCapability = chosen.capabilities[operation];
        const key = `${chosen.id}:${nextCapability.source_hash || ""}`;
        if (reconciled.current === key && draft.model === chosen.id) return;
        reconciled.current = key;
        setDraft((current) => ({ ...current, model: chosen.id, values: capabilityValues(nextCapability, current.values) }));
    }, [models, studio.requestedModel, draft.model, setDraft]);

    const quotedPrice = capability?.price_tokens ?? tokenPrice(model);
    const unit = Number.isSafeInteger(quotedPrice) && quotedPrice > 0 && quotedPrice <= 2147483647 ? quotedPrice : null;
    const hasHash = typeof capability?.source_hash === "string" && capability.source_hash.length > 0;
    const clientErrors = capabilityErrors(capability, draft.values);
    const errors = { ...clientErrors, ...validationErrors(studio.submitError) };
    const insufficient = unit != null && studio.balance != null && studio.balance < unit;
    const canSubmit = Boolean(capability && unit != null && hasHash && !insufficient
        && Object.keys(clientErrors).length === 0 && !studio.submitting && !studio.modelLoading && !studio.modelError);
    const job = studio.activeJob;

    const generate = (event) => {
        event.preventDefault();
        if (!canSubmit) return;
        studio.submit({
            model: model.id,
            operation,
            ...capabilitySubmission(capability, draft.values),
            expected_price_tokens: unit,
            expected_capability_hash: capability.source_hash,
        });
    };

    return <div className="media-studio studio-model3d">
        <StudioHeader kind="model3d" title="Studio 3D" description="Buat model 3D dari gambar, lalu periksa hasilnya dari setiap sisi."
            balance={studio.balance} onRefresh={studio.refresh} busy={studio.modelLoading || studio.submitting} />
        <div className="studio-image-desk">
            <form className="studio-image-author" onSubmit={generate} aria-busy={studio.submitting}>
                <div className="studio-section-heading"><h2>{t("Gambar ke 3D")}</h2><StudioIcon name="model3d" className="studio-color-model3d" /></div>
                <StudioCatalog studio={studio} models={models} id="model3d-model" value={selectedId} error={errors.model || errors.operation} onChange={studio.selectModel} />
                {!studio.modelLoading && !studio.modelError && !models.length && <StudioNotice>{t("Belum ada model gambar ke 3D yang tersedia. Coba muat ulang nanti.")}</StudioNotice>}
                {!studio.modelLoading && !studio.modelError && studio.requestedModel && !model && models.length > 0 && <StudioNotice error>{t("Model pada tautan ini tidak tersedia. Pilih model lain untuk melanjutkan.")}</StudioNotice>}
                {capability && <>
                    <p className="studio-help">{t("Gunakan gambar dengan objek yang jelas. Model ini menerima gambar, bukan prompt teks.")}</p>
                    <CapabilityForm capability={capability} values={draft.values} errors={errors} disabled={studio.submitting} idPrefix="model3d"
                        onChange={(values) => setDraft((current) => ({ ...current, values }))} />
                </>}
                {model && unit == null && <StudioNotice error>{t("Harga token model belum tersedia. Pilih model lain atau hubungi pengelola.")}</StudioNotice>}
                {capability && !hasHash && <StudioNotice error>{t("Kontrak model belum tersedia. Muat ulang katalog sebelum mengirim permintaan.")}</StudioNotice>}
                {studio.submitError && <StudioNotice error>{t(studio.submitError.status ? mediaError(studio.submitError) : "Respons belum dapat dikonfirmasi. Periksa riwayat sebelum mengirim lagi.")}</StudioNotice>}
                {studio.submitError?.status === 409 && <p className="studio-help">{t("Periksa kembali model, pengaturan, dan harga yang diperbarui sebelum mengirim lagi.")}</p>}
                <StudioQuote unit={unit} total={unit} balance={studio.balance} />
                <p className="studio-help">{t("Pembatalan hanya tersedia sebelum pengiriman ke penyedia dimulai.")}</p>
                <StudioButton type="submit" primary icon="model3d" disabled={!canSubmit}>{t(studio.submitting ? "Mengirim permintaan…" : "Buat model 3D")}<StudioIcon name="arrow" /></StudioButton>
            </form>
            <section className="studio-image-stage" aria-label={t("Hasil 3D")}>
                {studio.statusError && <StudioNotice error action={<StudioButton onClick={() => studio.loadJob(studio.activeId)} disabled={studio.statusLoading}>{t("Coba lagi")}</StudioButton>}>{t(mediaError(studio.statusError))}</StudioNotice>}
                <ThreeDResult job={job} submitting={studio.submitting} loading={studio.statusLoading || (Boolean(studio.requestedJob) && studio.historyLoading)} />
                {job && <StudioJobMeta job={job} checking={studio.statusLoading} onCheck={() => studio.loadJob(job.job_id)} onCancel={setCancellation} />}
            </section>
        </div>
        <StudioHistory studio={studio} kind="model3d" title="Riwayat 3D" />
        {cancellation && <StudioCancellation key={cancellation.job_id} job={studio.jobs.find((item) => item.job_id === cancellation.job_id) || cancellation}
            onCancel={studio.cancel} onClose={() => setCancellation(null)} />}
    </div>;
}

export default function ThreeDStudio() {
    const { user } = useAuth();
    return user ? <ThreeDWorkbench key={user.id} userId={user.id} /> : null;
}

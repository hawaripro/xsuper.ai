import { useEffect, useRef, useState } from "react";
import { useAuth } from "../contexts/AuthContext";
import { useLocale } from "../contexts/LocaleContext";
import MediaActionDialog from "../components/MediaActionDialog";
import { validationErrors } from "../components/member/MemberUI";
import { isPending, tokenPrice, useMediaStudio, useStudioDraft } from "../components/studios/useMediaStudio";
import { capabilityErrors, capabilitySubmission, capabilityValues } from "../components/studios/capability";
import CapabilityForm from "../components/studios/CapabilityForm";
import { StudioButton, StudioCatalog, StudioEmpty, StudioHeader, StudioHistory, StudioIcon, StudioJobMeta, StudioNotice, StudioProgress, StudioQuote, mediaError } from "../components/studios/StudioUI";

const defaults = { model: "", operation: "", values: {} };
const operationLabels = { text_to_video: "Teks ke video", image_to_video: "Gambar ke video" };

function VideoResult({ job }) {
    const { t } = useLocale();
    const player = useRef(null);
    const [failed, setFailed] = useState(false);
    useEffect(() => {
        setFailed(false);
        if (player.current) player.current.load();
    }, [job.video_url]);

    if (!job.video_url) {
        return <StudioEmpty icon="video" title="Ruang untuk video berikutnya" description="Tulis prompt, pilih model, lalu buat video. Hasil akan tampil di kanvas ini." />;
    }
    return <>
        <div className="studio-toolbar studio-canvas-toolbar"><div><h2>{t("Hasil video")}</h2><p className="studio-help">{job.prompt || ""}</p></div></div>
        <div className="studio-video-screen">
            <video ref={player} src={job.video_url} poster={job.thumbnail_url || undefined} controls playsInline preload="metadata"
                aria-label={`${t("Hasil video")}: ${job.prompt || ""}`} onError={() => setFailed(true)} />
        </div>
        {failed && <StudioNotice error>{t("Video tidak dapat diputar di browser ini. Buka atau unduh hasil aslinya.")}</StudioNotice>}
        <div className="studio-toolbar studio-player-links">
            <a className="studio-button studio-download" href={job.video_url} download><StudioIcon name="download" />{t("Unduh video")}</a>
            <a className="studio-text-link" href={job.video_url} target="_blank" rel="noreferrer">{t("Buka asli")}</a>
        </div>
    </>;
}

function VideoStudio({ userId }) {
    const { t } = useLocale();
    const studio = useMediaStudio("video");
    const [draft, setDraft] = useStudioDraft("video", userId, defaults);
    const [dialog, setDialog] = useState(null);
    const reconciled = useRef("");

    const selectedId = studio.requestedModel || draft.model;
    const model = studio.models.find((item) => item.id === selectedId) || null;
    const capabilities = model?.capabilities || {};
    const operationKeys = Object.keys(capabilities);
    const operation = capabilities[draft.operation] ? draft.operation : operationKeys.includes("text_to_video") ? "text_to_video" : operationKeys[0] || "";
    const capability = capabilities[operation] || null;

    // Pick a model when the catalog loads, then reconcile the draft once per model+operation change:
    // compatible values survive, the rest fall back to capability defaults (an incompatible asset clears).
    useEffect(() => {
        if (!studio.models.length) return;
        const chosen = studio.models.find((item) => item.id === (studio.requestedModel || draft.model)) || (studio.requestedModel ? null : studio.models[0]);
        if (!chosen) return;
        const caps = chosen.capabilities || {};
        const opKeys = Object.keys(caps);
        const op = caps[draft.operation] ? draft.operation : opKeys.includes("text_to_video") ? "text_to_video" : opKeys[0] || "";
        const key = `${chosen.id}:${op}`;
        if (reconciled.current === key && draft.model === chosen.id && draft.operation === op) return;
        reconciled.current = key;
        setDraft((current) => ({
            ...current,
            model: chosen.id,
            operation: op,
            values: capabilityValues(caps[op] || null, current.values),
        }));
    }, [studio.models, studio.requestedModel, draft.model, draft.operation, setDraft]);

    // A changed price or capability (409) keeps the draft and refreshes the catalog so the next
    // submit carries the current hash/price — never an automatic paid resubmit.
    useEffect(() => {
        if (studio.submitError?.status === 409) studio.loadModels();
    }, [studio.submitError, studio.loadModels]);

    const unit = capability?.price_tokens ?? tokenPrice(model);
    const total = unit;
    const clientErrors = capabilityErrors(capability, draft.values);
    const errors = { ...clientErrors, ...validationErrors(studio.submitError) };
    const insufficient = total != null && studio.balance != null && studio.balance < total;
    const canSubmit = Boolean(capability && unit != null && total != null && total <= 2147483647 && !insufficient
        && Object.keys(clientErrors).length === 0 && !studio.submitting && !studio.modelLoading && !studio.modelError);
    const warning = t(mediaError(studio.catalog?.cancel_reason || "Pembuatan video tidak dapat dibatalkan setelah dikirim. Menutup halaman tidak menghentikan proses atau mengembalikan token."));
    const job = studio.activeJob;
    const busyCanvas = studio.submitting || isPending(job);

    const generate = () => {
        if (!canSubmit) return;
        setDialog(null);
        studio.submit({
            model: model.id,
            operation,
            idempotency_key: globalThis.crypto?.randomUUID?.() || String(Date.now()) + Math.random().toString(36).slice(2),
            expected_price_tokens: unit,
            ...(capability.source_hash ? { expected_capability_hash: capability.source_hash } : {}),
            ...capabilitySubmission(capability, draft.values),
        });
    };

    return <div className="media-studio studio-video">
        <StudioHeader kind="video" title="Studio video" description="Dari satu gagasan ke video yang siap digunakan." balance={studio.balance} onRefresh={studio.refresh} busy={studio.modelLoading || studio.submitting} />
        <div className="studio-image-desk">
            <form className="studio-image-author" onSubmit={(event) => { event.preventDefault(); if (canSubmit) setDialog("confirm"); }} aria-busy={studio.submitting}>
                <div className="studio-section-heading"><h2>{t("Arahan kreatif")}</h2><StudioIcon name="prompt" className="studio-color-video" /></div>
                <StudioCatalog studio={studio} id="video-model" value={selectedId} error={errors.model} onChange={(id) => studio.selectModel(id)} />
                {operationKeys.length > 1 && <fieldset className="studio-angle-fieldset"><legend>{t("Mode")}</legend><div className="studio-tabs" role="tablist">{operationKeys.map((op) => <button type="button" key={op} role="tab" aria-selected={op === operation} disabled={studio.submitting} onClick={() => setDraft((current) => ({ ...current, operation: op }))}>{t(operationLabels[op] || op)}</button>)}</div></fieldset>}
                {capability
                    ? <CapabilityForm capability={capability} values={draft.values} errors={errors} disabled={studio.submitting} idPrefix="video" onChange={(values) => setDraft((current) => ({ ...current, values }))} />
                    : model ? <StudioNotice error>{t("Model ini belum menyediakan operasi video yang didukung.")}</StudioNotice> : null}
                {model && unit == null && <StudioNotice error>{t("Harga token model belum tersedia. Pilih model lain atau hubungi pengelola.")}</StudioNotice>}
                {studio.submitError && <StudioNotice error>{t(studio.submitError.status ? mediaError(studio.submitError) : "Respons belum dapat dikonfirmasi. Periksa riwayat sebelum mengirim lagi.")}</StudioNotice>}
                <StudioQuote unit={unit} total={total} count={1} balance={studio.balance} />
                <StudioButton type="submit" primary icon="video" disabled={!canSubmit}>{t(studio.submitting ? "Membuat video…" : "Generate video")}<StudioIcon name="arrow" /></StudioButton>
            </form>
            <section className="studio-image-stage" aria-label={t("Hasil video")}>
                {studio.statusError && <StudioNotice error action={<StudioButton onClick={() => studio.loadJob(studio.activeId)} disabled={studio.statusLoading}>{t("Coba lagi")}</StudioButton>}>{t(mediaError(studio.statusError))}</StudioNotice>}
                {busyCanvas ? <div className="studio-image-working"><StudioProgress job={job} submitting={studio.submitting} synchronous /><StudioButton onClick={() => setDialog("unavailable")}>{t("Tidak bisa dibatalkan")}</StudioButton></div> : <VideoResult key={job?.job_id || "empty"} job={job || {}} />}
                {job && <StudioJobMeta job={job} checking={studio.statusLoading} onCheck={() => studio.loadJob(job.job_id)} />}
            </section>
        </div>
        <StudioHistory studio={studio} kind="video" title="Riwayat video" />
        {dialog && <MediaActionDialog title={t(dialog === "confirm" ? "Konfirmasi pembuatan video" : "Pembuatan video tidak bisa dibatalkan")} description={dialog === "unavailable" && job?.cancel_reason ? t(mediaError(job.cancel_reason)) : warning} closeLabel={t(dialog === "confirm" ? "Kembali" : "Mengerti")} confirmLabel={t("Ya, buat video")} confirmDisabled={!canSubmit} onConfirm={dialog === "confirm" ? generate : undefined} onClose={() => setDialog(null)}><dl className="space-y-3 text-sm"><div className="flex flex-wrap justify-between gap-2"><dt>{t("Model")}</dt><dd className="font-semibold">{model?.name || selectedId}</dd></div><div className="flex flex-wrap justify-between gap-2"><dt>{t("Estimasi biaya")}</dt><dd>{total} {t("token")}</dd></div></dl></MediaActionDialog>}
    </div>;
}

export default function VideoGenerator() {
    const { user } = useAuth();
    return user ? <VideoStudio key={user.id} userId={user.id} /> : null;
}

import { Suspense, lazy, useEffect, useMemo, useRef, useState } from "react";
import { useAuth } from "../../contexts/AuthContext";
import { useLocale } from "../../contexts/LocaleContext";
import MediaActionDialog from "../MediaActionDialog";
import { formatLocalDate, formatUsdMicros } from "../member/MemberUI";
import CapabilityForm from "./CapabilityForm";
import MediaOutputPreview, { MediaResultData } from "./MediaOutputPreview";
import { AUDIO_AUTHORED, AudioAuthoring, AvatarRules, ProOption, VideoAuthoring } from "./NativeStudioAuthoring";
import WorkspaceHistory from "./WorkspaceHistory";
import useGlobalMediaWorkspace from "./useGlobalMediaWorkspace";
import { capabilityErrors } from "./capability";
import { schemaEqual } from "./schema";
import { ownedMediaUrl } from "./mediaOutput";
import { VIDEO_DEFAULTS, VIDEO_TABS, composeVideoPrompt, jobSummary, nativeStudio, promptLimit, restoreDraft, videoAuthoringErrors, videoExecution, videoOperation } from "./nativeStudio";
import { mediaJobPending, operationLabel, outputKindLabel, workspaceQuote, workspaceValidation } from "./workspaceMedia";
import { StudioButton, StudioEmpty, StudioField, StudioHeader, StudioIcon, StudioNotice, StudioProgress, StudioQuote, StudioStatus, mediaError } from "./StudioUI";
import "./media-workspace.css";

const RealtimeMediaSession = lazy(() => import("./RealtimeMediaSession"));
const unitLabels = { generation: "generasi", second: "detik", request: "permintaan", invocation: "permintaan", session: "sesi", image: "gambar", video: "video", output: "hasil" };
const billingLabels = { reserved: "Dicadangkan", settled: "Dibebankan", released: "Dikembalikan" };
const message = (value) => Array.isArray(value) ? value[0] : value;
const videoOperations = ["text_to_video", "image_to_video"];
const nativeOperation = (model, operation) => model.operations?.some((entry) => entry.operation === operation && entry.contract_version === 1);
// Kind pages keep the studio wording members knew before the workspace unification.
const resultTitles = { image: "Kanvas gambar", video: "Monitor video", audio: "Dengarkan hasilnya", avatar: "Pratinjau avatar", model3d: "Pratinjau 3D" };
const emptyStates = {
    image: ["Ruang untuk ide berikutnya", "Tulis prompt, pilih model, lalu buat gambar. Hasil asli akan tampil di kanvas ini."],
    video: ["Adegan Anda dimulai di sini", "Hasil video akan dapat diputar, dicari posisinya, dan diunduh setelah proses selesai."],
    audio: ["Belum ada audio untuk diputar", "Buat suara atau musik, atau pilih hasil dari riwayat. Waveform akan dibuat dari file audio hasil yang sebenarnya."],
    avatar: ["Foto Anda, ucapan Anda", "Pilih foto wajah dan audio ucapan. Video hasilnya akan tampil di sini."],
    model3d: ["Belum ada model 3D", "Buat model 3D, atau pilih hasil dari riwayat."],
};
const submitLabels = { image: "Generate gambar", video: "Generate video", avatar: "Generate avatar", model3d: "Buat model 3D" };
const cancellationNotes = {
    video: "Pembatalan hanya tersedia sebelum pengiriman video dimulai. Token dikembalikan jika permintaan ditolak atau proses gagal.",
    audio: "Pembatalan hanya tersedia sebelum pengiriman ke penyedia dimulai. Hasil dan tagihan tersimpan pada riwayat akun Anda.",
    model3d: "Pembatalan hanya tersedia sebelum pengiriman ke penyedia dimulai.",
};

function JobBilling({ job }) {
    const { t, locale } = useLocale();
    const details = job.details || {};
    if (details.billing_mode === "admin") return <p className="studio-help">{t("Gratis admin (riwayat lama)")}</p>;
    const tokens = details.billing_mode === "tokens" && details.tokens_reserved != null ? details.tokens_reserved : null;
    const cost = tokens != null ? `${new Intl.NumberFormat(locale).format(Number(tokens))} ${t("token")}`
        : details.cost_microusd != null && details.billing_mode !== "tokens" && job.price_tokens == null ? formatUsdMicros(details.cost_microusd)
            : job.price_tokens != null ? `${new Intl.NumberFormat(locale).format(Number(job.price_tokens))} ${t("token")}` : null;
    if (!cost) return null;
    return <p className="studio-billing"><StudioIcon name="tokens" />{cost}<span>·</span>{t(billingLabels[job.billing_status] || "Status tagihan belum tersedia")}</p>;
}

function MediaOutputs({ job, initialOutput = "" }) {
    const { t } = useLocale();
    const [selected, setSelected] = useState(initialOutput);
    const [filter, setFilter] = useState("");
    const [comparing, setComparing] = useState(false);
    const [other, setOther] = useState("");
    const [split, setSplit] = useState(50);
    const outputs = job.outputs || [];
    const kinds = [...new Set(outputs.map((output) => output.kind))];
    const visible = filter ? outputs.filter((output) => output.kind === filter) : outputs;
    const output = visible.find((entry) => entry.id === selected) || visible[0];
    const images = outputs.filter((entry) => entry.kind === "image" && entry.previewable && /^image\/(png|jpeg|webp|gif|avif)$/.test(entry.mime || "") && ownedMediaUrl(entry.url));
    // A batch of images reads as variations (image studio) and several audio files as tracks of one request.
    const variations = kinds.length === 1 && kinds[0] === "image" && images.length === outputs.length;
    const tracks = kinds.length === 1 && kinds[0] === "audio" && outputs.length > 1;
    const label = (entry) => {
        const index = outputs.indexOf(entry);
        return variations ? `${t("Variasi")} ${index + 1}` : tracks ? `${t("Track")} ${index + 1}` : entry.name || `${t("Hasil")} ${index + 1}`;
    };
    const secondary = images.find((entry) => entry.id === other && entry.id !== output?.id) || images.find((entry) => entry.id !== output?.id);
    return <>
        {outputs.length > 0 && <>
            <div className="media-output-toolbar">
                {!variations && <StudioField id="media-output-select" label={tracks ? "Pilih track" : `${t("Hasil")} (${outputs.length})`}><select id="media-output-select" value={output?.id || ""} onChange={(event) => setSelected(event.target.value)}>
                    {visible.map((entry) => <option key={entry.id} value={entry.id}>{label(entry)}{tracks ? "" : ` · ${t(outputKindLabel(entry.kind))}`}</option>)}
                </select></StudioField>}
                {kinds.length > 1 && <StudioField id="media-output-filter" label="Jenis hasil"><select id="media-output-filter" value={filter} onChange={(event) => { setFilter(event.target.value); setComparing(false); }}><option value="">{t("Semua file")}</option>{kinds.map((kind) => <option value={kind} key={kind}>{t(outputKindLabel(kind))}</option>)}</select></StudioField>}
                {images.length > 1 && images.some((entry) => entry.id === output?.id) && <StudioButton icon="compare" aria-pressed={comparing} onClick={() => setComparing((current) => !current)}>{t("Bandingkan")}</StudioButton>}
            </div>
            {tracks && <p className="studio-help media-output-note">{t("Semua track berasal dari satu permintaan. Pilihan track tidak menambah tagihan.")}</p>}
            {comparing && secondary && images.some((entry) => entry.id === output?.id) ? <>
                <div className="studio-compare-controls"><StudioField id="media-compare-other" label="Bandingkan dengan"><select id="media-compare-other" value={secondary.id} onChange={(event) => setOther(event.target.value)}>{images.filter((entry) => entry.id !== output.id).map((entry) => <option key={entry.id} value={entry.id}>{label(entry)}</option>)}</select></StudioField>
                    <StudioField id="media-compare-split" label="Posisi pembanding"><input id="media-compare-split" type="range" min="0" max="100" value={split} aria-valuetext={`${split}%`} onChange={(event) => setSplit(Number(event.target.value))} /></StudioField></div>
                <div className="media-image-comparison"><img src={ownedMediaUrl(output.url)} alt={label(output)} /><img className="media-image-comparison-overlay" src={ownedMediaUrl(secondary.url)} alt={`${t("Perbandingan variasi")}: ${label(secondary)}`} style={{ clipPath: `inset(0 ${100 - split}% 0 0)` }} /><span className="studio-compare-divider" style={{ left: `${split}%` }} /><span className="studio-compare-label">{label(output)}</span></div>
                <div className="media-comparison-downloads">{[output, secondary].map((entry) => { const url = ownedMediaUrl(entry.download_url); return url ? <a key={entry.id} className="studio-button" href={url} download><StudioIcon name="download" />{label(entry)}</a> : null; })}</div>
            </> : <MediaOutputPreview key={output?.id} output={output && ["text_to_speech", "speech_to_speech"].includes(job.operation) ? { ...output, mode: "speech" } : output} />}
            {variations && images.length > 1 ? <div className="studio-variations"><div className="studio-section-heading"><h3>{t("Variasi hasil")}</h3><span className="studio-help">{outputs.indexOf(output) + 1} / {outputs.length}</span></div>
                <div className="studio-variation-list">{images.map((entry) => <button type="button" key={entry.id} className="studio-variation" aria-pressed={entry.id === output?.id} onClick={() => setSelected(entry.id)}>
                    <img src={ownedMediaUrl(entry.url)} alt={`${t("Pilih variasi")} ${outputs.indexOf(entry) + 1}`} loading="lazy" /><span>{label(entry)}</span></button>)}</div>
            </div> : !variations && visible.length > 1 && <div className="media-output-list" aria-label={t("Semua hasil tersimpan")}>{visible.map((entry) => {
                const preview = entry.kind === "image" && entry.previewable && ownedMediaUrl(entry.url);
                return <button type="button" key={entry.id} aria-pressed={entry.id === output?.id} onClick={() => setSelected(entry.id)}>{preview ? <img src={preview} loading="lazy" alt="" /> : <StudioIcon name={entry.kind === "model3d" ? "model3d" : ["video", "audio"].includes(entry.kind) ? entry.kind : "download"} />}<span>{label(entry)}<small>{t(outputKindLabel(entry.kind))} · {entry.mime}</small></span></button>;
            })}</div>}
        </>}
        {job.result_data !== undefined && job.result_data !== null && <div className="media-data-panel"><MediaResultData data={job.result_data} /></div>}
        {!outputs.length && job.result_data == null && !mediaJobPending(job) && <StudioEmpty icon="download" title={job.status === "completed" ? "Belum ada hasil tersimpan" : "Permintaan belum menghasilkan file"}
            description={job.can_retry_save ? "Hasil belum tersimpan. Coba simpan kembali tanpa membuat permintaan baru." : "Periksa status dan pesan pekerjaan. Tidak ada hasil simulasi yang ditampilkan."} />}
    </>;
}

// Request facts the studios showed: the saved reference, prompt, recorded settings and billing.
function SavedReferences({ job }) {
    const { t } = useLocale();
    const image = ownedMediaUrl(job.details?.reference_url);
    const speech = ownedMediaUrl(job.details?.speech_audio_url);
    if (!image && !speech) return null;
    const avatar = job.operation === "talking_avatar";
    return <details className="studio-saved-reference"><summary>{t(avatar ? "Referensi yang digunakan" : "Gambar referensi permintaan ini")}</summary>
        {image && <a href={image} target="_blank" rel="noreferrer"><img src={image} alt={t(avatar ? "Foto wajah" : "Gambar referensi video tersimpan")} loading="lazy" /></a>}
        {speech && <audio src={speech} controls preload="none" aria-label={t("Audio ucapan")} />}
    </details>;
}

function JobDetails({ job, studio, dialogOpen, onDialog }) {
    const { t, locale } = useLocale();
    const details = job.details || {};
    const summary = jobSummary(job).map((part) => typeof part === "object" ? `${part.seconds} ${t("detik")}` : part);
    return <div className="studio-job-meta">
        <div className="studio-toolbar"><strong>{t(operationLabel(job.operation))}</strong>{job.created_at && <time dateTime={job.created_at}>{formatLocalDate(job.created_at, { locale })}</time>}</div>
        {details.prompt && <p className="studio-result-prompt">{details.prompt}</p>}
        {summary.length > 0 && <p className="studio-help">{summary.join(" · ")}</p>}
        <code>{job.id}</code>
        <JobBilling job={job} />
        {job.error && <StudioNotice error={job.status !== "cancelled"}>{t(mediaError(job.error))}</StudioNotice>}
        {job.cancel_reason && mediaJobPending(job) && <p className="studio-help">{t(mediaError(job.cancel_reason))}</p>}
        {job.output_kind === "model3d" && job.status === "completed" && details.preview_unavailable_reason && <StudioNotice>{t(mediaError(details.preview_unavailable_reason))}</StudioNotice>}
        {studio.actionError && !dialogOpen && <StudioNotice error>{t(mediaError(studio.actionError))}</StudioNotice>}
        <div className="studio-toolbar-actions"><StudioButton icon="refresh" disabled={studio.detailLoading} onClick={() => { void studio.loadJob(job.id); }}>{t(studio.detailLoading ? "Memeriksa…" : "Periksa status")}</StudioButton>
            {mediaJobPending(job) && <StudioButton disabled={Boolean(studio.actionBusy)} onClick={() => onDialog({ id: job.id, action: "cancel" })}>{t(job.can_cancel ? "Batalkan permintaan" : "Tidak bisa dibatalkan")}</StudioButton>}
            {job.can_retry_save && <StudioButton icon="refresh" disabled={Boolean(studio.actionBusy)} onClick={() => { void studio.jobAction(job.id, "retry-save").catch(() => {}); }}>{t(studio.actionBusy.endsWith(":retry-save") ? "Meminta simpan ulang…" : "Coba simpan hasil lagi")}</StudioButton>}
            {job.can_delete && <StudioButton icon="close" className="studio-danger" disabled={Boolean(studio.actionBusy)} onClick={() => onDialog({ id: job.id, action: "delete" })}>{t("Hapus dari riwayat")}</StudioButton>}
        </div>{job.can_retry_save && <p className="studio-help">{t("Simpan ulang memakai hasil yang sudah dibuat, bukan permintaan generasi baru.")}</p>}
        {details.improved_prompt && details.improved_prompt !== details.prompt && <details><summary>{t("Lihat prompt hasil peninjauan lama")}</summary><p className="studio-result-prompt">{details.improved_prompt}</p></details>}
    </div>;
}

function MediaWorkbench({ kind, title, description, userId }) {
    const { t, locale } = useLocale();
    const studio = useGlobalMediaWorkspace({ kind, userId });
    const [uploads, setUploads] = useState({ busy: false, failed: false });
    const [dialog, setDialog] = useState(null);
    const [confirming, setConfirming] = useState(false);
    const [realtimeActive, setRealtimeActive] = useState(false);
    const [realtimeErrors, setRealtimeErrors] = useState({});
    // Client checks stay quiet until a field is edited or a submit reveals everything still missing.
    const [feedback, setFeedback] = useState({ key: "", revealed: false, touched: [] });
    const form = useRef(null);
    const capability = studio.capability;
    const realtime = capability?.execution?.transport === "realtime";
    const native = Boolean(capability) && capability.contract_version !== 2;
    const studioKind = nativeStudio(capability);
    const facts = studio.model?.native || null;
    const billing = capability?.billing || {};
    const format = (value) => new Intl.NumberFormat(locale).format(value);
    // Video studio: one authoring draft spans the model's text and image operations.
    const video = restoreDraft(studio.authoring, VIDEO_DEFAULTS);
    const tab = VIDEO_TABS.some((entry) => entry.id === video.tab) ? video.tab : "prompt";
    const videoOps = Object.entries(studio.capabilities).filter(([operation, definition]) => definition.contract_version === 1 && videoOperations.includes(operation)).map(([operation]) => operation);
    const onlyVideoOps = videoOps.length > 0 && videoOps.length === Object.keys(studio.capabilities).length;
    const referenceRequired = facts?.reference_image?.required === true;
    const videoTarget = studioKind === "video" ? videoOperation(tab, video.withReference, videoOps, referenceRequired) : null;
    const referenceModels = studio.catalog.models.filter((entry) => nativeOperation(entry, "image_to_video"));
    const finalPrompt = studioKind === "video" ? composeVideoPrompt({ ...video, tab }) : null;
    const effectiveValues = useMemo(() => studioKind === "video" ? { ...studio.values, prompt: finalPrompt } : studio.values, [studioKind, studio.values, finalPrompt]);
    const quote = workspaceQuote(capability, studio.values, studio.controls);
    const operation = studio.operation;
    const clientErrors = useMemo(() => {
        const errors = capabilityErrors(capability, effectiveValues);
        if (studioKind === "video") {
            const draft = restoreDraft(studio.authoring, VIDEO_DEFAULTS);
            const draftTab = VIDEO_TABS.some((entry) => entry.id === draft.tab) ? draft.tab : "prompt";
            // The image tab never sends a text-to-video request instead of the image one it shows.
            return { ...errors, ...videoAuthoringErrors({ ...draft, tab: draftTab }),
                ...(draftTab === "reference" && operation !== "image_to_video" ? { reference: "Pilih model image-to-video untuk membuat video dari gambar." } : {}) };
        }
        if (studioKind === "audio" && typeof effectiveValues.prompt === "string" && effectiveValues.prompt.length > promptLimit(facts)) return { ...errors, prompt: "Teks terlalu panjang." };
        return errors;
    }, [capability, effectiveValues, studioKind, studio.authoring, operation, facts]);
    const seen = feedback.key === studio.draftKey ? feedback : { key: studio.draftKey, revealed: false, touched: [] };
    const shown = seen.revealed ? clientErrors : Object.fromEntries(Object.entries(clientErrors).filter(([path]) => path === "" || seen.touched.includes(path.split(".")[0])));
    const errors = { ...shown, ...workspaceValidation(studio.submitError), ...realtimeErrors };
    const withFeedback = (change) => setFeedback((state) => change(state.key === studio.draftKey ? state : { key: studio.draftKey, revealed: false, touched: [] }));
    const touchKeys = (keys) => { if (keys.length) withFeedback((state) => ({ ...state, touched: [...new Set([...state.touched, ...keys])] })); };
    const touch = (next) => {
        const previous = studio.values || {};
        touchKeys([...new Set([...Object.keys(previous), ...Object.keys(next || {})])].filter((key) => !schemaEqual(previous[key], next?.[key])));
    };
    const reveal = () => {
        withFeedback((state) => ({ ...state, revealed: true }));
        requestAnimationFrame(() => form.current?.querySelector('[aria-invalid="true"], .studio-field-error')?.closest(".studio-field, fieldset")?.querySelector("input, select, textarea, button")?.focus());
    };
    // The video studio's tabs choose the operation; the image tab moves to a model that has one.
    useEffect(() => {
        if (studioKind === "video" && videoTarget && videoTarget !== studio.operation) studio.selectOperation(videoTarget, { carry: true });
    }, [studioKind, videoTarget, studio]);
    const editVideo = (patch, touched) => { studio.setAuthoring(patch); if (touched) touchKeys([touched]); };
    const selectVideoTab = (next) => {
        if (next === tab) return;
        editVideo({ tab: next });
        if (next === "reference" && !videoOps.includes("image_to_video")) {
            // Prefer a dedicated image-to-video model, as the video studio did, over one that merely accepts a reference.
            const target = referenceModels.find((entry) => !nativeOperation(entry, "text_to_video")) || referenceModels[0];
            if (target) studio.selectModel(target.model_id, "image_to_video");
        }
    };
    const selectAudioMode = (next) => {
        const operation = next === "speech" ? "text_to_speech" : "music";
        if (studio.capabilities[operation]?.contract_version === 1) { studio.selectOperation(operation); return; }
        const target = studio.catalog.models.find((entry) => nativeOperation(entry, operation));
        if (target) studio.selectModel(target.model_id, operation);
    };
    const audioModes = ["speech", "music"].filter((mode) => {
        const operation = mode === "speech" ? "text_to_speech" : "music";
        return studio.capabilities[operation]?.contract_version === 1 || studio.catalog.models.some((entry) => nativeOperation(entry, operation));
    });
    // Native quantity and Pro are execution controls below, not duplicated as form params.
    const hiddenParams = native ? [billing.count_field, billing.pro_field, ...(studioKind === "audio" ? AUDIO_AUTHORED : [])].filter(Boolean) : [];
    const hiddenInputs = studioKind === "video" || studioKind === "audio" ? ["prompt"] : [];
    const consentMissing = studio.consentRequired && studio.controls.rights_confirmed !== true;
    const ready = Boolean(capability?.source_hash && quote.admission != null && studio.balance != null && quote.total <= studio.balance
        && !Object.keys(clientErrors).length && !uploads.busy && !uploads.failed && !consentMissing);
    const canSubmit = ready && !studio.submitting && !studio.capabilityLoading && !studio.capabilityError && !studio.uncertain;
    const catalogModels = studio.catalog.models;
    // A limited pilot excluding this member is an access state, not an empty catalog.
    const availability = studio.catalog.availability;
    const restricted = availability?.state === "restricted";
    const selectedOutsideSearch = studio.modelId && !catalogModels.some((model) => model.model_id === studio.modelId);
    const job = studio.job;
    const activeDialogJob = dialog && studio.jobs.find((entry) => entry.id === dialog.id);
    const setActive = (active) => { studio.freeze(active); setRealtimeActive(active); };
    const priceLine = quote.unit == null ? t("Harga belum tersedia.") : [
        `${format(quote.unit)} ${t("token")} / ${t(unitLabels[billing.price_unit || capability?.price_unit] || billing.price_unit || capability?.price_unit || "permintaan")}`,
        quote.seconds ? `× ${format(quote.seconds)} ${t("detik")}` : null,
        quote.pro ? `× ${billing.pro_multiplier} (Pro)` : null,
    ].filter(Boolean).join(" ");
    const blocker = !capability || studio.submitting ? null : quote.reason ? null : consentMissing ? "Konfirmasikan izin penggunaan wajah dan suara untuk melanjutkan."
        : uploads.busy ? null : Object.keys(clientErrors).length ? "Lengkapi input wajib dan perbaiki isian yang ditandai untuk melanjutkan."
            : studio.balance == null || quote.admission == null ? "Periksa harga dan saldo sebelum melanjutkan." : null;
    const send = () => {
        setConfirming(false);
        void studio.submit(quote.admission, false, studioKind === "video"
            ? { inputs: { prompt: finalPrompt }, execution: videoExecution({ ...video, tab }, quote.count) } : undefined);
    };
    const confirmAction = async () => {
        try {
            const response = await studio.jobAction(dialog.id, dialog.action);
            if (dialog.action === "delete" || response?.job?.status === "cancelled" || response?.job?.stage === "cancelled") setDialog(null);
        } catch { /* The persisted action error remains visible in the dialog. */ }
    };
    const durationParam = capability?.params?.find((param) => param.name === "duration");
    const submitLabel = studio.submitting ? (kind === "image" ? "Membuat gambar…" : "Mengirim permintaan…")
        : submitLabels[kind] || (studioKind === "audio" ? (studio.operation === "text_to_speech" ? "Generate suara" : "Generate musik") : "Kirim permintaan");
    const resultHelp = kind === "video" ? (job ? [job.model, job.id.startsWith("video:") ? (job.details?.pro_mode ? "Pro" : "Standard") : null].filter(Boolean).join(" · ") : t("Hasil asli dari model"))
        : kind === "avatar" ? t("Foto + audio → video") : kind === "model3d" ? t("Putar, perbesar, dan geser model hasil Anda.") : kind === "audio" && job ? job.model : null;
    const [emptyTitle, emptyDescription] = emptyStates[kind] || ["Ruang untuk semua hasil Anda", "Input mengikuti model yang dipilih. Gambar, video, audio, mesh, tekstur, arsip, dan data hasil tetap tersimpan sebagai keluaran terpisah."];
    const cancelDialogUnavailable = dialog?.action === "cancel" && activeDialogJob && activeDialogJob.can_cancel !== true;
    return <div className={`media-studio studio-global studio-${kind || "all"}`}>
        <StudioHeader kind={kind || "image"} title={title || "Studio media"} description={description || "Pilih model, lengkapi input, dan kelola semua hasil di satu ruang kerja."}
            balance={studio.balance} onRefresh={studio.refresh} busy={studio.submitting || studio.catalogLoading || studio.capabilityLoading || realtimeActive} />
        <div className="media-workspace-desk">
            <form ref={form} className="media-workspace-form" noValidate onSubmit={(event) => { event.preventDefault(); if (realtime) return; if (!canSubmit) reveal(); else if (kind === "image") setConfirming(true); else send(); }} aria-busy={studio.submitting}>
                <fieldset className="media-input-group" disabled={realtimeActive} aria-label={t("Input dan pengaturan model")}>
                <div className="studio-section-heading"><h2>{t("Model & input")}</h2><StudioIcon name="settings" /></div>
                {restricted ? <StudioNotice>{t(availability.reason || "Studio media belum tersedia untuk akun Anda.")}</StudioNotice> : <>
                <StudioField id="media-model-search" label="Cari model" hint={t("Pencarian mencakup seluruh model yang tersedia untuk akun Anda.")}><input type="search" id="media-model-search" value={studio.query}
                    disabled={studio.submitting} onChange={(event) => studio.setQuery(event.target.value)} placeholder={t("Nama model atau penyedia")} /></StudioField>
                <StudioField id="media-model" label="Model" error={errors.model}><select id="media-model" value={studio.modelId} disabled={studio.submitting || !catalogModels.length && !studio.modelId} onChange={(event) => studio.selectModel(event.target.value)}>
                    <option value="" disabled>{t(studio.catalogLoading ? "Memuat model…" : "Pilih model")}</option>
                    {selectedOutsideSearch && <option value={studio.modelId}>{studio.model?.name || studio.modelId} · {t("Dipilih")}</option>}
                    {catalogModels.map((model) => <option value={model.model_id} key={model.model_id}>{model.name} {model.provider_name ? `· ${model.provider_name}` : ""}</option>)}
                </select>{studio.modelId && <p className="studio-help media-model-id">{studio.modelId}</p>}</StudioField>
                <div className="media-catalog-pagination"><span className="studio-help" role="status">{studio.catalogLoading ? t("Mencari model…")
                    : studio.catalog.total != null ? `${format(catalogModels.length)} / ${format(studio.catalog.total)} ${t("model")}` : `${format(catalogModels.length)} ${t("model dimuat")}`}</span>
                    {studio.catalog.next_cursor && <StudioButton disabled={studio.catalogLoading || studio.submitting} onClick={() => { void studio.loadModels(studio.catalog.next_cursor); }}>{t("Muat model berikutnya")}</StudioButton>}</div>
                {studio.catalogError && <StudioNotice error action={<StudioButton onClick={() => { void studio.loadModels(); }}>{t("Coba lagi")}</StudioButton>}>{t(mediaError(studio.catalogError))}</StudioNotice>}
                {!studio.catalogLoading && !studio.catalogError && !catalogModels.length && <StudioNotice>{t(studio.query ? "Tidak ada model yang cocok. Coba kata pencarian lain." : "Belum ada model yang tersedia. Model yang belum dipublikasikan, belum diberi harga, atau di luar izin akun tidak ditawarkan.")}</StudioNotice>}
                {studio.capabilityLoading && <p role="status" className="studio-loading">{t("Memuat operasi dan pengaturan model…")}</p>}
                {studio.capabilityError && <StudioNotice error action={<StudioButton onClick={studio.reloadCapabilities}>{t("Muat ulang model")}</StudioButton>}>{t(mediaError(studio.capabilityError))}</StudioNotice>}
                {Object.keys(studio.capabilities).length > 0 && !(studioKind === "video" && onlyVideoOps) && !(studioKind === "audio" && Object.keys(studio.capabilities).every((operation) => ["text_to_speech", "music"].includes(operation)))
                    && <StudioField id="media-operation" label="Operasi" error={errors.operation}><select id="media-operation" value={studio.operation} disabled={studio.submitting || studio.capabilityLoading} onChange={(event) => {
                        const next = event.target.value;
                        if (next === "image_to_video" && studioKind === "video") editVideo({ tab: "reference" });
                        else if (next === "text_to_video" && tab === "reference") editVideo({ tab: "prompt" });
                        studio.selectOperation(next);
                    }}>
                    {Object.entries(studio.capabilities).map(([operation, definition]) => <option value={operation} key={operation}>{t(operationLabel(operation))} · {t(outputKindLabel(definition.output_kind))}</option>)}
                </select></StudioField>}
                {capability && <>
                    {studioKind === "video" && <VideoAuthoring draft={video} tab={tab} errors={errors} disabled={studio.submitting || studio.capabilityLoading}
                        imageToVideo={videoOps.includes("image_to_video")} referenceRequired={referenceRequired} referenceModels={referenceModels.length > 0} onChange={editVideo} onSelectTab={selectVideoTab} />}
                    {studioKind === "audio" && <AudioAuthoring capability={capability} native={facts} values={studio.values} errors={errors} disabled={studio.submitting || studio.capabilityLoading}
                        available={audioModes} onValues={(next) => { touch(next); studio.setValues(next); }} onSelectMode={selectAudioMode} />}
                    {studioKind === "avatar" && facts?.avatar_audio_mode === "soundtrack" && <p className="studio-help">{t("Audio Fal menggantikan soundtrack video; sinkronisasi bibir tidak dijamin. Audio minimal 2 detik, maksimal 15 MB.")}</p>}
                    {studioKind === "model3d" && studio.operation === "image_to_3d" && <p className="studio-help">{t("Gunakan gambar dengan objek yang jelas. Model ini menerima gambar, bukan prompt teks.")}</p>}
                    <CapabilityForm key={`${studio.draftKey}:${capability.source_hash}`} capability={capability} values={studio.values} errors={errors} hiddenInputs={hiddenInputs} hiddenParams={hiddenParams}
                        disabled={studio.submitting || studio.capabilityLoading || realtimeActive} onChange={(next) => { setRealtimeErrors({}); touch(next); studio.setValues(next); }} onUploadStateChange={setUploads} />
                    {studioKind === "video" && studio.operation === "image_to_video" && !capability.params?.some((param) => param.name === "aspect_ratio") && facts?.reference_image?.aspect_ratio_from_image
                        && <StudioField id="media-video-ratio" label="Rasio aspek" hint={t("Komposisi mengikuti rasio gambar referensi.")}><select id="media-video-ratio" value="reference" disabled><option value="reference">{t("Dari gambar")}</option></select></StudioField>}
                    {native && billing.count_field && <StudioField id="media-count" label={kind === "video" ? "Jumlah video" : kind === "image" ? "Jumlah" : "Jumlah hasil"} error={errors.count}>
                        <select id="media-count" value={studio.controls.count} disabled={studio.submitting || (billing.max_count || 1) < 2} onChange={(event) => studio.setControls({ count: Number(event.target.value) })}>
                            {Array.from({ length: billing.max_count || 1 }, (_, index) => <option key={index + 1} value={index + 1}>{index + 1}{kind === "image" ? ` ${t("gambar")}` : ""}</option>)}
                        </select>
                    </StudioField>}
                    {studioKind === "video" && quote.count > 1 && <label className="studio-checkbox"><input type="checkbox" checked={video.variations} disabled={studio.submitting} onChange={(event) => editVideo({ variations: event.target.checked })} />
                        <span>{t("Variasikan komposisi tiap video")}<small>{t("Arahan tambahan dikirim ke model untuk hasil berikutnya.")}</small></span></label>}
                    {native && (billing.pro_field || studioKind === "video") && <ProOption supported={Boolean(billing.pro_field)} active={studio.controls.pro === true} unit={quote.unit}
                        multiplier={Number(billing.pro_multiplier) || 2} disabled={studio.submitting} error={errors.pro || errors.pro_mode} perUnit={studioKind === "video" ? "token / video" : "token"}
                        description={studioKind === "video" ? facts?.pro?.description || "16 langkah inferensi dan encoding maksimum; Standard menggunakan 12 langkah dan encoding tinggi." : "Pro memakai pengaturan kualitas lebih tinggi yang didukung model ini."}
                        onToggle={() => studio.setControls({ pro: studio.controls.pro !== true })} />}
                    {billing.duration_field === "billing_seconds" && <StudioField id="media-billing-seconds" label="Durasi yang ditagihkan" error={errors.billing_seconds}
                        hint={t("Tarif per detik memakai durasi yang ditinjau pengelola. Parameter model tetap terpisah.")}>
                        <select id="media-billing-seconds" value={studio.controls.billing_seconds ?? ""} disabled={studio.submitting || !(billing.durations || []).length} onChange={(event) => studio.setControls({ billing_seconds: Number(event.target.value) })}>
                            <option value="" disabled>{t("Pilih durasi")}</option>{(billing.durations || []).map((seconds) => <option key={seconds} value={seconds}>{seconds} {t("detik")}</option>)}
                        </select>
                    </StudioField>}
                    {studio.consentRequired && <label className="studio-checkbox"><input type="checkbox" checked={studio.controls.rights_confirmed === true} disabled={studio.submitting} aria-invalid={Boolean(errors.rights_confirmed)}
                        onChange={(event) => studio.setControls({ rights_confirmed: event.target.checked })} /><span>{t("Saya memiliki hak atau izin untuk menggunakan wajah dan suara ini.")}<small>{t("Persetujuan diperlukan sebelum token dicadangkan.")}</small></span></label>}
                    {studio.consentRequired && <AvatarRules />}
                    {errors.rights_confirmed && <StudioNotice error>{t(mediaError(message(errors.rights_confirmed)))}</StudioNotice>}
                    {errors[""] && <StudioNotice error>{t(mediaError(message(errors[""])))}</StudioNotice>}
                    {quote.reason && <StudioNotice error>{t(quote.reason)}</StudioNotice>}
                    {studioKind === "avatar" && quote.unit != null && durationParam && <p className="studio-help">{format(quote.unit)} {t("token per detik")} · 480p · {durationParam.min}–{durationParam.max} {t("detik")}</p>}
                    <StudioQuote total={quote.total} unit={quote.admission} count={quote.count} balance={studio.balance} />
                    <p className="studio-help">{priceLine}{capability.contract_version === 2 && ` · ${t("Harga mengikuti tarif yang ditinjau pengelola. Perubahan konfigurasi tidak menunjukkan biaya penyedia.")}`}</p>
                </>}
                </>}
                {uploads.busy && <p className="studio-help" role="status">{t("Tunggu unggahan selesai sebelum mengirim.")}</p>}
                {uploads.failed && <StudioNotice error>{t("Coba lagi atau hapus unggahan yang gagal sebelum mengirim.")}</StudioNotice>}
                {studio.submitError && <StudioNotice error>{t(mediaError(studio.submitError))}{studio.submitError.status === 409 && <p>{t("Definisi atau harga mungkin berubah. Tinjau ulang pengaturan sebelum mengirim kembali.")}</p>}</StudioNotice>}
                {studio.uncertain && <StudioNotice error action={<StudioButton disabled={studio.submitting} icon="refresh" onClick={() => { void studio.submit(null, true); }}>{t("Periksa permintaan yang sama")}</StudioButton>}>
                    <p>{t("Penerimaan belum terkonfirmasi. Jangan buat permintaan baru. Tombol ini memakai kunci yang sama agar tidak membuat duplikat berbayar.")}</p><p className="studio-help">{studio.uncertain.body?.model}</p>
                </StudioNotice>}
                {!restricted && <>
                    {!realtime && <StudioButton primary icon={capability?.output_kind || kind || "image"} type="submit"
                        disabled={!capability || studio.submitting || studio.capabilityLoading || Boolean(studio.capabilityError) || Boolean(studio.uncertain)}>{t(submitLabel)}<StudioIcon name="arrow" /></StudioButton>}
                    {!realtime && cancellationNotes[kind] && <p className="studio-help">{t(cancellationNotes[kind])}</p>}
                    {realtime && !realtimeActive && <p className="studio-help">{t("Operasi ini berjalan sebagai sesi langsung. Mulai sesi dari panel sesi setelah input lengkap.")}</p>}
                    {realtimeActive && <p className="studio-help" role="status">{t("Pengaturan awal terkunci selama sesi aktif. Gunakan kontrol pembaruan sesi.")}</p>}
                    {blocker && <p className="studio-help">{t(blocker)}</p>}
                    {realtime && !realtimeActive && !seen.revealed && Object.keys(clientErrors).length > 0 && <StudioButton onClick={reveal}>{t("Tandai isian yang perlu dilengkapi")}</StudioButton>}
                </>}
                </fieldset>
            </form>
            <section className="media-workspace-results" aria-label={t("Hasil media")}>
                <div className="studio-toolbar media-result-heading"><h2><StudioIcon name={job?.output_kind || kind || "image"} />{t(resultTitles[kind] || "Hasil")}</h2>{resultHelp && <span className="studio-help">{resultHelp}</span>}{job && <StudioStatus job={job} />}</div>
                {realtime && <Suspense fallback={<p role="status" className="studio-loading">{t("Memuat sesi realtime…")}</p>}>
                    <RealtimeMediaSession key={`${studio.modelId}:${studio.operation}:${capability.source_hash}`} model={studio.modelId} capability={capability} inputs={studio.values}
                        disabled={!canSubmit} onValidationErrors={(next) => setRealtimeErrors(workspaceValidation({ details: { errors: next || {} } }))}
                        onActiveChange={setActive} onSaved={studio.refreshAfterSession} />
                </Suspense>}
                {studio.detailError && <StudioNotice error action={<StudioButton disabled={studio.detailLoading} onClick={() => { void studio.loadJob(studio.activeId); }}>{t("Periksa status")}</StudioButton>}>{t(mediaError(studio.detailError))}</StudioNotice>}
                {studio.detailLoading && !job && <p role="status" className="studio-loading">{t("Memuat hasil…")}</p>}
                {studio.submitting && !job && <StudioProgress submitting synchronous={kind === "image"} />}
                {job ? <>
                    {mediaJobPending(job) && <StudioProgress job={job} synchronous={job.output_kind === "image" && job.id.startsWith("image:")} />}
                    {/* Several images from one request are separate jobs; they are shown and compared as one set. */}
                    {studio.batch && <p className="studio-help media-output-note" role="status">
                        {t("Variasi siap")}: {studio.batch.done} {t("dari")} {studio.batch.size}
                        {studio.batch.failed > 0 && ` · ${studio.batch.failed} ${t("berakhir tanpa gambar")}`}
                        {studio.batch.pending.length > 0 && ` · ${t("lainnya masih diproses")}`}
                    </p>}
                    <MediaOutputs key={job.id} job={studio.batch ? { ...job, outputs: studio.batch.outputs } : job}
                        initialOutput={studio.batch ? (job.outputs?.[0] ? `${job.id}#${job.outputs[0].id}` : "")
                            : studio.linked.jobs.includes(job.id) ? studio.linked.track : ""} />
                    <SavedReferences job={job} />
                    <JobDetails job={job} studio={studio} dialogOpen={Boolean(dialog)} onDialog={setDialog} />
                </> : !realtime && !studio.detailLoading && !studio.submitting && <StudioEmpty icon={kind || "image"} title={emptyTitle} description={emptyDescription} />}
            </section>
        </div>
        <WorkspaceHistory studio={studio} kind={kind} />
        {confirming && <MediaActionDialog title={t("Konfirmasi pembuatan gambar")} closeLabel={t("Kembali")} confirmLabel={t("Ya, buat gambar")} confirmDisabled={!canSubmit}
            description={t("Pembuatan gambar tidak dapat dibatalkan setelah dikirim. Menutup halaman tidak menghentikan proses atau mengembalikan token.")}
            onConfirm={send} onClose={() => setConfirming(false)}>
            <dl className="space-y-3 text-sm"><div className="flex flex-wrap justify-between gap-2"><dt>{t("Model")}</dt><dd className="font-semibold">{studio.model?.name || studio.modelId}</dd></div>
                <div className="flex flex-wrap justify-between gap-2"><dt>{t("Jumlah")}</dt><dd>{quote.count} {t("gambar")}</dd></div>
                <div className="flex flex-wrap justify-between gap-2"><dt>{t("Estimasi total")}</dt><dd>{quote.total == null ? "—" : `${format(quote.total)} ${t("token")}`}</dd></div></dl>
        </MediaActionDialog>}
        {dialog && (cancelDialogUnavailable
            ? <MediaActionDialog title={t("Permintaan tidak bisa dibatalkan")} closeLabel={t("Mengerti")} onClose={() => setDialog(null)}
                description={t(mediaError(activeDialogJob.cancel_reason || "Pengiriman sudah dimulai. Menutup halaman tidak membatalkan proses atau mengembalikan token."))}><StudioStatus job={activeDialogJob} /></MediaActionDialog>
            : <MediaActionDialog title={t(dialog.action === "delete" ? "Hapus dari riwayat?" : "Batalkan permintaan ini?")}
                description={t(dialog.action === "delete" ? "Pekerjaan yang selesai beserta file hasilnya akan dihapus permanen. Tindakan ini tidak dapat dibatalkan." : "Pembatalan hanya selesai setelah dikonfirmasi server. Menutup halaman tidak membatalkan proses atau menjamin pengembalian token.")}
                closeLabel={t("Tutup")} confirmLabel={t(dialog.action === "delete" ? "Hapus permanen" : "Ajukan pembatalan")} busyLabel={t("Memproses…")} busy={Boolean(studio.actionBusy)}
                error={studio.actionError ? t(mediaError(studio.actionError)) : null}
                confirmDisabled={dialog.action === "cancel" && activeDialogJob?.can_cancel !== true} onConfirm={confirmAction} onClose={() => setDialog(null)} />)}
    </div>;
}

export default function GlobalMediaWorkspace({ kind = "", title, description }) {
    const { user } = useAuth();
    return user ? <MediaWorkbench key={`${user.id}:${kind}`} userId={user.id} kind={kind} title={title} description={description} /> : null;
}

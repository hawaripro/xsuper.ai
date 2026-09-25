import { useEffect, useMemo, useRef, useState } from "react";
import { useLocale } from "../../contexts/LocaleContext";
import MediaActionDialog from "../MediaActionDialog";
import RequestPanel from "./RequestPanel";
import StudioWorkbench from "./StudioWorkbench";
import { AUDIO_AUTHORED } from "./NativeStudioAuthoring";
import { capabilityErrors } from "./capability";
import { schemaEqual } from "./schema";
import { VIDEO_DEFAULTS, VIDEO_TABS, composeVideoPrompt, nativeStudio, promptLimit, restoreDraft, videoAuthoringErrors, videoExecution, videoOperation } from "./nativeStudio";
import { capabilitySchema, mergeJsonDraft, parseJsonDraft } from "./studioForm";
import { jobDraft } from "./studioJobs";
import { capabilityKind } from "./studioPalette";
import { workspaceQuote, workspaceValidation } from "./workspaceMedia";

const videoOperations = ["text_to_video", "image_to_video"];
const nativeOperation = (model, operation) => model.operations?.some((entry) => entry.operation === operation && entry.contract_version === 1);
const submitLabels = { image: "Generate gambar", video: "Generate video", avatar: "Generate avatar", model3d: "Buat model 3D", audio: "Generate audio" };
const narrow = () => typeof window !== "undefined" && window.matchMedia?.("(max-width: 1023.98px)").matches;
const reducedMotion = () => typeof window !== "undefined" && window.matchMedia?.("(prefers-reduced-motion: reduce)").matches;
export const scrollIntoStudioView = (element) => { if (element && narrow()) element.scrollIntoView({ block: "start", behavior: reducedMotion() ? "auto" : "smooth" }); };

// The studio's request state: the Request panel edits it, the Workbench prices, documents and runs it.
export default function MediaStudio({ studio, lists, onOpenPalette, realtimeActive, onRealtimeActive }) {
    const { t, locale } = useLocale();
    const [uploads, setUploads] = useState({ busy: false, failed: false });
    const [confirming, setConfirming] = useState(false);
    const [realtimeErrors, setRealtimeErrors] = useState({});
    // Client checks stay quiet until a field is edited or a submit reveals everything still missing.
    const [feedback, setFeedback] = useState({ key: "", revealed: false, touched: [] });
    const [json, setJson] = useState({ open: false, key: "", text: "", dirty: false });
    const [toast, setToast] = useState(null);
    const form = useRef(null);
    const workbench = useRef(null);
    const capability = studio.capability;
    const realtime = capability?.execution?.transport === "realtime";
    const native = Boolean(capability) && capability.contract_version !== 2;
    const studioKind = nativeStudio(capability);
    const outputKind = capabilityKind(studio.model, capability);
    const facts = studio.model?.native || null;
    const billing = capability?.billing || {};
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
    // Native quantity and Pro are execution controls, not duplicated as form params or JSON keys.
    const hiddenParams = native ? [billing.count_field, billing.pro_field, ...(studioKind === "audio" ? AUDIO_AUTHORED : [])].filter(Boolean) : [];
    const hiddenInputs = studioKind === "video" || studioKind === "audio" ? ["prompt"] : [];
    const hidden = useMemo(() => [...new Set([...hiddenParams, ...hiddenInputs])], [hiddenParams.join(","), hiddenInputs.join(",")]);
    const docsSchema = useMemo(() => capabilitySchema(capability, hidden), [capability, hidden]);
    const jsonDirty = json.dirty && json.key === studio.draftKey;
    const reveal = () => {
        setJson((current) => ({ ...current, open: current.dirty && current.key === studio.draftKey }));
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
            if (target) studio.selectModel(target.model_id, "image_to_video", target);
        }
    };
    const selectAudioMode = (next) => {
        const target = next === "speech" ? "text_to_speech" : "music";
        if (studio.capabilities[target]?.contract_version === 1) { studio.selectOperation(target); return; }
        const model = studio.catalog.models.find((entry) => nativeOperation(entry, target));
        if (model) studio.selectModel(model.model_id, target, model);
    };
    const audioModes = ["speech", "music"].filter((mode) => {
        const target = mode === "speech" ? "text_to_speech" : "music";
        return studio.capabilities[target]?.contract_version === 1 || studio.catalog.models.some((entry) => nativeOperation(entry, target));
    });
    const selectOperation = (next) => {
        if (next === "image_to_video" && studioKind === "video") editVideo({ tab: "reference" });
        else if (next === "text_to_video" && tab === "reference") editVideo({ tab: "prompt" });
        studio.selectOperation(next);
    };
    const consentMissing = studio.consentRequired && studio.controls.rights_confirmed !== true;
    const ready = Boolean(capability?.source_hash && quote.admission != null && studio.balance != null && quote.total <= studio.balance
        && !Object.keys(clientErrors).length && !uploads.busy && !uploads.failed && !consentMissing);
    const canSubmit = ready && !studio.submitting && !studio.capabilityLoading && !studio.capabilityError && !studio.uncertain && !jsonDirty;
    const availability = studio.catalog.availability;
    const restricted = availability?.state === "restricted";
    const fieldsPending = Object.keys(clientErrors).length > 0 || consentMissing;
    // Why Generate cannot run yet, in the order a member can act on it.
    const reason = restricted ? availability.reason || "Studio media belum tersedia untuk akun Anda."
        : !studio.modelId ? (studio.catalogLoading ? "Memuat model…" : "Pilih model untuk mulai.")
            : studio.capabilityError ? "Model tidak dapat dimuat. Muat ulang model atau pilih model lain."
                : !capability || studio.capabilityLoading ? "Memuat pengaturan model…"
                    : studio.submitting ? null
                        : studio.uncertain ? "Periksa permintaan sebelumnya sebelum membuat yang baru."
                            : realtimeActive ? "Pengaturan awal terkunci selama sesi aktif. Gunakan kontrol pembaruan sesi."
                                : realtime ? "Operasi ini berjalan sebagai sesi langsung. Mulai sesi dari panel sesi setelah input lengkap."
                                    : jsonDirty ? "Terapkan atau buang perubahan JSON sebelum mengirim."
                                        : quote.reason ? quote.reason
                                            : consentMissing ? "Konfirmasikan izin penggunaan wajah dan suara untuk melanjutkan."
                                                : uploads.busy ? "Tunggu unggahan selesai sebelum mengirim."
                                                    : uploads.failed ? "Coba lagi atau hapus unggahan yang gagal sebelum mengirim."
                                                        : Object.keys(clientErrors).length ? "Lengkapi input wajib dan perbaiki isian yang ditandai untuk melanjutkan."
                                                            : studio.balance == null || quote.admission == null ? "Periksa harga dan saldo sebelum melanjutkan."
                                                                : quote.total > studio.balance ? "Saldo token tidak cukup untuk jumlah ini." : null;
    const submitLabel = studio.submitting ? (outputKind === "image" ? "Membuat gambar…" : "Mengirim permintaan…")
        : studioKind === "audio" ? (operation === "text_to_speech" ? "Generate suara" : "Generate musik") : submitLabels[outputKind] || "Kirim permintaan";
    const notify = (message, action = null) => setToast({ id: Date.now(), message, action });
    useEffect(() => {
        if (!toast) return undefined;
        const timer = setTimeout(() => setToast(null), toast.action ? 9000 : 5000);
        return () => clearTimeout(timer);
    }, [toast]);
    const send = async () => {
        setConfirming(false);
        const data = await studio.submit(quote.admission, false, studioKind === "video"
            ? { inputs: { prompt: finalPrompt }, execution: videoExecution({ ...video, tab }, quote.count) } : undefined);
        if (data) scrollIntoStudioView(workbench.current);
    };
    const submit = () => {
        if (realtime || studio.submitting) return;
        if (!canSubmit) { if (fieldsPending) reveal(); return; }
        // Images cannot be cancelled once sent, so the studio confirms model, quantity and total first.
        if (outputKind === "image") setConfirming(true); else void send();
    };
    const reset = () => {
        const snapshot = studio.resetDraft(studioKind === "video" ? { ...VIDEO_DEFAULTS, tab } : null);
        if (!snapshot) return;
        setJson((current) => ({ ...current, dirty: false }));
        setFeedback({ key: studio.draftKey, revealed: false, touched: [] });
        setRealtimeErrors({});
        notify("Pengaturan dikembalikan ke bawaan model.", { label: "Urungkan", run: () => studio.restoreSnapshot(snapshot) });
    };
    const applyDraft = (draft, summary, message) => {
        if (!draft || !studio.loadDraft(draft, summary)) { notify("Pengaturan sedang terkunci. Coba lagi setelah proses selesai."); return false; }
        setJson((current) => ({ ...current, open: false, dirty: false }));
        setFeedback({ key: "", revealed: false, touched: [] });
        setRealtimeErrors({});
        notify(message);
        requestAnimationFrame(() => scrollIntoStudioView(form.current));
        return true;
    };
    const loadJob = (job) => {
        const draft = jobDraft(job);
        const summary = [studio.summary, ...studio.catalog.models, ...lists.recent, ...lists.favorites].find((entry) => entry?.model_id === draft?.model) || null;
        return applyDraft(draft, summary, "Pengaturan hasil dimuat ke form.");
    };
    const useExample = (example) => applyDraft({ model: studio.modelId, operation, values: { ...studio.values, ...example.values } }, null, "Contoh diterapkan ke form.");
    const jsonHandlers = {
        toggle: (open) => setJson((current) => ({ ...current, open })),
        edit: (text) => setJson({ open: true, key: studio.draftKey, text, dirty: true }),
        discard: () => setJson((current) => ({ ...current, dirty: false })),
        apply: () => {
            const parsed = parseJsonDraft(json.text, capability, { hidden, values: studio.values });
            if (!parsed.ok) return false;
            const next = mergeJsonDraft(studio.values, parsed.value, hidden);
            touch(next);
            studio.setValues(next);
            setJson((current) => ({ ...current, dirty: false }));
            return true;
        },
    };
    const setActive = (active) => { studio.freeze(active); onRealtimeActive(active); };
    const format = (value) => new Intl.NumberFormat(locale).format(value);
    // Realtime never submits the form: flag missing inputs, then bring the session panel into view.
    const showSession = () => {
        if (fieldsPending) reveal();
        document.getElementById("sw-tab-results")?.click();
        requestAnimationFrame(() => {
            const session = document.getElementById("sw-realtime");
            session?.scrollIntoView({ block: "nearest" });
            session?.focus({ preventScroll: true });
        });
    };
    const request = {
        capability, native, realtime, studioKind, outputKind, facts, billing, quote, errors, clientErrors, revealed: seen.revealed,
        video: { draft: video, tab, videoOps, onlyVideoOps, referenceRequired, referenceModels: referenceModels.length > 0 },
        audioModes, hiddenParams, hiddenInputs, hidden, consentMissing, restricted, availability, reason, submitLabel, canSubmit,
        fieldsPending, uploads, realtimeActive, json: { ...json, dirty: jsonDirty, text: jsonDirty ? json.text : null },
    };
    const actions = {
        onValues: (next) => { setRealtimeErrors({}); touch(next); studio.setValues(next); },
        editVideo, selectVideoTab, selectAudioMode, selectOperation, setUploads, submit, reset, reveal, showSession, onOpenPalette, json: jsonHandlers,
        toggleFavorite: () => { if (studio.summary) lists.toggleFavorite(studio.summary); },
        isFavorite: lists.isFavorite(studio.modelId),
        notify,
    };
    return <main className="sw-body" id="studio-main">
        <h1 className="studio-visually-hidden">{t("Studio Media")}</h1>
        <RequestPanel ref={form} studio={studio} request={request} actions={actions} />
        <StudioWorkbench ref={workbench} studio={studio} capability={capability} quote={quote} docsSchema={docsSchema} realtime={realtime} canSubmit={canSubmit}
            realtimeProps={{ onValidationErrors: (next) => setRealtimeErrors(workspaceValidation({ details: { errors: next || {} } })), onActiveChange: setActive }}
            onLoadJob={loadJob} onUseExample={useExample} onNotify={notify} />
        {confirming && <MediaActionDialog title={t("Konfirmasi pembuatan gambar")} closeLabel={t("Kembali")} confirmLabel={t("Ya, buat gambar")} confirmDisabled={!canSubmit}
            description={t("Pembuatan gambar tidak dapat dibatalkan setelah dikirim. Menutup halaman tidak menghentikan proses atau mengembalikan token.")}
            onConfirm={() => { void send(); }} onClose={() => setConfirming(false)}>
            <dl className="space-y-3 text-sm"><div className="flex flex-wrap justify-between gap-2"><dt>{t("Model")}</dt><dd className="font-semibold">{studio.model?.name || studio.modelId}</dd></div>
                <div className="flex flex-wrap justify-between gap-2"><dt>{t("Jumlah")}</dt><dd>{quote.count * quote.quantity} {t("gambar")}</dd></div>
                <div className="flex flex-wrap justify-between gap-2"><dt>{t("Estimasi total")}</dt><dd>{quote.total == null ? "—" : `${format(quote.total)} ${t("token")}`}</dd></div></dl>
        </MediaActionDialog>}
        <div className="sw-toast-region" role="status" aria-live="polite">{toast && <div className="sw-toast" key={toast.id}>
            <span>{t(toast.message)}</span>
            {toast.action && <button type="button" onClick={() => { toast.action.run(); setToast(null); }}>{t(toast.action.label)}</button>}
            <button type="button" className="sw-toast-close" aria-label={t("Tutup pemberitahuan")} onClick={() => setToast(null)}>×</button>
        </div>}</div>
    </main>;
}

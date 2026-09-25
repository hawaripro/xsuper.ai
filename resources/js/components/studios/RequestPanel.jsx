import { useLayoutEffect, useRef } from "react";
import { Link } from "react-router-dom";
import { useLocale } from "../../contexts/LocaleContext";
import CapabilityForm from "./CapabilityForm";
import JsonEditor from "./JsonEditor";
import { AudioAuthoring, AvatarRules, ProOption, VideoAuthoring } from "./NativeStudioAuthoring";
import { ModelMark, RollingNumber, StudioButton, StudioField, StudioIcon, StudioNotice, mediaError } from "./StudioUI";
import { operationLabel, outputKindLabel } from "./workspaceMedia";

export const UNIT_LABELS = { generation: "generasi", second: "detik", request: "permintaan", invocation: "permintaan", session: "sesi", image: "gambar", video: "video", output: "hasil" };
const cancellationNotes = {
    video: "Pembatalan hanya tersedia sebelum pengiriman video dimulai. Token dikembalikan jika permintaan ditolak atau proses gagal.",
    audio: "Pembatalan hanya tersedia sebelum pengiriman ke penyedia dimulai. Hasil dan tagihan tersimpan pada riwayat akun Anda.",
    model3d: "Pembatalan hanya tersedia sebelum pengiriman ke penyedia dimulai.",
};
const operationIcon = { image: "image", video: "video", audio: "audio", model3d: "model3d" };
const first = (value) => Array.isArray(value) ? value[0] : value;
const radioKeys = ["ArrowLeft", "ArrowRight", "ArrowUp", "ArrowDown", "Home", "End"];

// The quote as a member reads it: unit price times every multiplier the request carries.
export function quoteBreakdown(quote, billing, capability, t, format) {
    if (quote.unit == null) return t("Harga belum tersedia.");
    const unit = billing.price_unit || capability?.price_unit;
    return [
        `${format(quote.unit)} ${t("token")} / ${t(UNIT_LABELS[unit] || unit || "permintaan")}`,
        quote.seconds ? `× ${format(quote.seconds)} ${t("detik")}` : null,
        quote.quantityField && quote.quantity > 1 ? `× ${format(quote.quantity)} ${t("hasil")}` : null,
        quote.count > 1 ? `× ${format(quote.count)}` : null,
        quote.pro ? `× ${billing.pro_multiplier} (Pro)` : null,
    ].filter(Boolean).join(" ");
}

function ModelHeader({ studio, request, actions }) {
    const { t } = useLocale();
    const summary = studio.summary;
    const locked = studio.submitting || request.realtimeActive;
    const copyId = async () => {
        try { await navigator.clipboard.writeText(studio.modelId); actions.notify("ID model disalin."); } catch { actions.notify("Browser tidak mengizinkan menyalin. Salin ID secara manual."); }
    };
    return <section className="sw-model" aria-label={t("Model terpilih")}>
        <button type="button" className="sw-model-pick" onClick={actions.onOpenPalette} disabled={locked} aria-haspopup="dialog" aria-keyshortcuts="Control+/ Meta+/"
            title={t(locked ? "Model terkunci selama permintaan atau sesi berjalan." : "Pilih model (Ctrl+/)")}>
            <ModelMark model={summary} className="is-large" />
            <span className="sw-model-copy">
                <small>{summary?.provider_name || t(studio.modelId ? "Model" : "Belum ada model")}</small>
                <strong>{summary?.name || studio.modelId || t("Pilih model")}</strong>
            </span>
            <span className="sw-model-change">{t("Ganti")}<StudioIcon name="chevron" /></span>
        </button>
        {studio.modelId && <div className="sw-model-meta">
            <code dir="ltr" title={studio.modelId}>{studio.modelId}</code>
            <button type="button" className="sw-icon-btn" onClick={() => { void copyId(); }} aria-label={t("Salin ID model")} title={t("Salin ID model")}><StudioIcon name="copy" /></button>
            <button type="button" className={`sw-icon-btn sw-favorite${actions.isFavorite ? " is-on" : ""}`} aria-pressed={actions.isFavorite} disabled={!summary}
                onClick={actions.toggleFavorite} aria-label={t("Tandai favorit")} title={t(actions.isFavorite ? "Hapus dari favorit" : "Tambahkan ke favorit")}><StudioIcon name="star" /></button>
        </div>}
        {summary?.description && <p className="sw-model-description">{summary.description}</p>}
    </section>;
}

function Operations({ studio, request, actions }) {
    const { t } = useLocale();
    const entries = Object.entries(studio.capabilities);
    if (!entries.length) return null;
    const { studioKind, video } = request;
    // Native video and audio pick their operation through their own tabs.
    if ((studioKind === "video" && video.onlyVideoOps) || (studioKind === "audio" && entries.every(([operation]) => ["text_to_speech", "music"].includes(operation)))) return null;
    if (entries.length === 1) {
        const [[operation, definition]] = entries;
        return <p className="sw-operation-single"><StudioIcon name={operationIcon[definition.output_kind] || "settings"} />{t(operationLabel(operation))}<span>{t(outputKindLabel(definition.output_kind))}</span></p>;
    }
    const disabled = studio.submitting || studio.capabilityLoading || request.realtimeActive;
    const move = (event, index) => {
        if (!radioKeys.includes(event.key)) return;
        event.preventDefault();
        const next = event.key === "Home" ? 0 : event.key === "End" ? entries.length - 1
            : (index + (["ArrowRight", "ArrowDown"].includes(event.key) ? 1 : -1) + entries.length) % entries.length;
        actions.selectOperation(entries[next][0]);
        event.currentTarget.parentElement?.children[next]?.focus();
    };
    return <div className="sw-field-block">
        <span className="sw-label" id="sw-operation-label">{t("Operasi")}</span>
        <div className="sw-operations" role="radiogroup" aria-labelledby="sw-operation-label">{entries.map(([operation, definition], index) => {
            const selected = operation === studio.operation;
            return <button type="button" role="radio" key={operation} aria-checked={selected} tabIndex={selected ? 0 : -1} disabled={disabled}
                data-kind={definition.output_kind === "video" && studio.summary?.category === "avatar" ? "avatar" : ["image", "video", "audio", "model3d"].includes(definition.output_kind) ? definition.output_kind : "other"}
                onClick={() => { if (!selected) actions.selectOperation(operation); }} onKeyDown={(event) => move(event, index)}>
                <StudioIcon name={operationIcon[definition.output_kind] || "settings"} /><span>{t(operationLabel(operation))}</span></button>;
        })}</div>
        {request.errors.operation && <p className="studio-field-error" role="alert">{t(mediaError(first(request.errors.operation)))}</p>}
    </div>;
}

function FormBody({ studio, request, actions }) {
    const { t, locale } = useLocale();
    const { capability, native, studioKind, facts, billing, quote, errors, video } = request;
    const format = (value) => new Intl.NumberFormat(locale).format(value);
    const durationParam = capability.params?.find((param) => param.name === "duration");
    const busy = studio.submitting || studio.capabilityLoading;
    return <>
        {studioKind === "video" && <VideoAuthoring draft={video.draft} tab={video.tab} errors={errors} disabled={busy}
            imageToVideo={video.videoOps.includes("image_to_video")} referenceRequired={video.referenceRequired} referenceModels={video.referenceModels}
            onChange={actions.editVideo} onSelectTab={actions.selectVideoTab} />}
        {studioKind === "audio" && <AudioAuthoring capability={capability} native={facts} values={studio.values} errors={errors} disabled={busy}
            available={request.audioModes} onValues={actions.onValues} onSelectMode={actions.selectAudioMode} />}
        {studioKind === "avatar" && facts?.avatar_audio_mode === "soundtrack" && <p className="studio-help">{t("Audio Fal menggantikan soundtrack video; sinkronisasi bibir tidak dijamin. Audio minimal 2 detik, maksimal 15 MB.")}</p>}
        {studioKind === "model3d" && studio.operation === "image_to_3d" && <p className="studio-help">{t("Gunakan gambar dengan objek yang jelas. Model ini menerima gambar, bukan prompt teks.")}</p>}
        <CapabilityForm key={`${studio.draftKey}:${capability.source_hash}`} grouped quantityInput={billing.quantity_input || null} capability={capability} values={studio.values}
            errors={errors} hiddenInputs={request.hiddenInputs} hiddenParams={request.hiddenParams} disabled={busy || request.realtimeActive}
            onChange={actions.onValues} onUploadStateChange={actions.setUploads} />
        {studioKind === "video" && studio.operation === "image_to_video" && !capability.params?.some((param) => param.name === "aspect_ratio") && facts?.reference_image?.aspect_ratio_from_image
            && <StudioField id="media-video-ratio" label="Rasio aspek" hint={t("Komposisi mengikuti rasio gambar referensi.")}><select id="media-video-ratio" value="reference" disabled><option value="reference">{t("Dari gambar")}</option></select></StudioField>}
        {(native && (billing.count_field || billing.pro_field || studioKind === "video")) || billing.duration_field === "billing_seconds" || studio.consentRequired ? <section className="sw-run-options" aria-label={t("Opsi eksekusi")}>
            {native && billing.count_field && <StudioField id="media-count" label={studioKind === "video" ? "Jumlah video" : request.outputKind === "image" ? "Jumlah" : "Jumlah hasil"} error={errors.count}>
                <select id="media-count" value={studio.controls.count} disabled={studio.submitting || (billing.max_count || 1) < 2} onChange={(event) => studio.setControls({ count: Number(event.target.value) })}>
                    {Array.from({ length: billing.max_count || 1 }, (_, index) => <option key={index + 1} value={index + 1}>{index + 1}{request.outputKind === "image" ? ` ${t("gambar")}` : ""}</option>)}
                </select>
            </StudioField>}
            {studioKind === "video" && quote.count > 1 && <label className="studio-checkbox"><input type="checkbox" checked={video.draft.variations} disabled={studio.submitting} onChange={(event) => actions.editVideo({ variations: event.target.checked })} />
                <span>{t("Variasikan komposisi tiap video")}<small>{t("Arahan tambahan dikirim ke model untuk hasil berikutnya.")}</small></span></label>}
            {native && (billing.pro_field || studioKind === "video") && <ProOption supported={Boolean(billing.pro_field)} active={studio.controls.pro === true} unit={quote.unit}
                multiplier={Number(billing.pro_multiplier) || 2} disabled={studio.submitting} error={errors.pro || errors.pro_mode} perUnit={billing.mode === "per_second" ? "token / detik" : studioKind === "video" ? "token / video" : "token"}
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
        </section> : null}
        {errors.rights_confirmed && <StudioNotice error>{t(mediaError(first(errors.rights_confirmed)))}</StudioNotice>}
        {errors[""] && <StudioNotice error>{t(mediaError(first(errors[""])))}</StudioNotice>}
        {quote.reason && <StudioNotice error>{t(quote.reason)}</StudioNotice>}
        {studioKind === "avatar" && quote.unit != null && durationParam && <p className="studio-help">{format(quote.unit)} {t("token per detik")} · 480p · {durationParam.min}–{durationParam.max} {t("detik")}</p>}
    </>;
}

// Left pane: model, operation, inputs (form or JSON) and a footer that always shows the price and
// why Generate cannot run yet.
export default function RequestPanel({ ref, studio, request, actions }) {
    const { t, locale, localizedPath } = useLocale();
    const { capability, quote, billing, json, reason } = request;
    const format = (value) => new Intl.NumberFormat(locale).format(value);
    const insufficient = quote.total != null && studio.balance != null && quote.total > studio.balance;
    const footer = useRef(null);
    // Below 1024px the footer is a fixed action bar; the page reserves its height so nothing hides under it.
    useLayoutEffect(() => {
        const element = footer.current;
        const root = element?.closest(".sw-root");
        if (!element || !root) return undefined;
        const publish = () => root.style.setProperty("--sw-footer-space", `${Math.ceil(element.getBoundingClientRect().height)}px`);
        publish();
        const observer = new ResizeObserver(publish);
        observer.observe(element);
        return () => { observer.disconnect(); root.style.removeProperty("--sw-footer-space"); };
    }, []);
    const locked = !capability || studio.capabilityLoading || Boolean(studio.capabilityError) || Boolean(studio.uncertain) || request.restricted;
    return <form ref={ref} className="sw-request" noValidate aria-labelledby="sw-request-title" aria-busy={studio.submitting}
        onSubmit={(event) => { event.preventDefault(); actions.submit(); }}>
        <div className="sw-request-scroll">
            <div className="sw-panel-head">
                <h2 id="sw-request-title"><StudioIcon name="sliders" />{t("Pengaturan permintaan")}</h2>
                {capability && !request.restricted && <div className="sw-mode" role="group" aria-label={t("Mode input")}>
                    <button type="button" aria-pressed={!json.open} onClick={() => actions.json.toggle(false)}>{t("Form")}</button>
                    <button type="button" aria-pressed={json.open} onClick={() => actions.json.toggle(true)}><StudioIcon name="code" />JSON</button>
                </div>}
            </div>
            <ModelHeader studio={studio} request={request} actions={actions} />
            {request.restricted ? <StudioNotice>{t(request.availability.reason || "Studio media belum tersedia untuk akun Anda.")}</StudioNotice> : <>
                {studio.catalogError && !studio.modelId && <StudioNotice error action={<StudioButton onClick={() => { void studio.loadModels(); }}>{t("Coba lagi")}</StudioButton>}>{t(mediaError(studio.catalogError))}</StudioNotice>}
                {!studio.modelId && !studio.catalogLoading && !studio.catalogError && <div className="sw-request-empty">
                    <StudioIcon name="spark" />
                    <p>{t(studio.catalog.models.length ? "Pilih model untuk mulai membuat." : "Belum ada model untuk jenis ini. Pilih model lain dari katalog.")}</p>
                    <StudioButton primary icon="search" onClick={actions.onOpenPalette}>{t("Pilih model")}</StudioButton>
                </div>}
                {studio.capabilityLoading && <p role="status" className="studio-loading">{t("Memuat operasi dan pengaturan model…")}</p>}
                {studio.capabilityError && <StudioNotice error action={<div className="studio-toolbar-actions"><StudioButton onClick={studio.reloadCapabilities}>{t("Muat ulang model")}</StudioButton>
                    <StudioButton onClick={actions.onOpenPalette}>{t("Pilih model lain")}</StudioButton></div>}>{t(mediaError(studio.capabilityError))}</StudioNotice>}
                {capability && <>
                    <Operations studio={studio} request={request} actions={actions} />
                    <fieldset className="sw-request-fields" disabled={request.realtimeActive} aria-label={t("Input dan pengaturan model")}>
                        {json.open ? <JsonEditor capability={capability} hidden={request.hidden} values={studio.values} text={json.text} dirty={json.dirty}
                            disabled={studio.submitting || studio.capabilityLoading} onEdit={actions.json.edit} onApply={actions.json.apply} onDiscard={actions.json.discard} onCopied={actions.notify} />
                            : <>
                                {json.dirty && <StudioNotice action={<div className="studio-toolbar-actions"><StudioButton onClick={() => actions.json.toggle(true)}>{t("Kembali ke JSON")}</StudioButton>
                                    <StudioButton onClick={actions.json.discard}>{t("Buang perubahan")}</StudioButton></div>}>{t("Perubahan JSON belum diterapkan ke form.")}</StudioNotice>}
                                <FormBody studio={studio} request={request} actions={actions} />
                            </>}
                    </fieldset>
                </>}
                {request.uploads.busy && <p className="studio-help" role="status">{t("Tunggu unggahan selesai sebelum mengirim.")}</p>}
                {request.uploads.failed && <StudioNotice error>{t("Coba lagi atau hapus unggahan yang gagal sebelum mengirim.")}</StudioNotice>}
                {studio.submitError && <StudioNotice error>{t(mediaError(studio.submitError))}{studio.submitError.status === 409 && <p>{t("Definisi atau harga mungkin berubah. Tinjau ulang pengaturan sebelum mengirim kembali.")}</p>}</StudioNotice>}
                {studio.uncertain && <StudioNotice error action={<StudioButton disabled={studio.submitting} icon="refresh" onClick={() => { void studio.submit(null, true); }}>{t("Periksa permintaan yang sama")}</StudioButton>}>
                    <p>{t("Penerimaan belum terkonfirmasi. Jangan buat permintaan baru. Tombol ini memakai kunci yang sama agar tidak membuat duplikat berbayar.")}</p><p className="studio-help">{studio.uncertain.body?.model}</p>
                </StudioNotice>}
                {capability && !request.realtime && cancellationNotes[request.outputKind === "avatar" ? "video" : request.outputKind] && <p className="studio-help sw-note">{t(cancellationNotes[request.outputKind === "avatar" ? "video" : request.outputKind])}</p>}
            </>}
        </div>
        <div className="sw-request-footer" ref={footer}>
            <div className="sw-estimate">
                <div className="sw-estimate-total"><span><StudioIcon name="tokens" />{t("Estimasi")}</span><strong><RollingNumber value={quote.total == null ? null : format(quote.total)} /> <small>{t("token")}</small></strong></div>
                {capability && <p className="sw-estimate-line">{quoteBreakdown(quote, billing, capability, t, format)}</p>}
                {insufficient && <p className="sw-estimate-warning">{t("Saldo token tidak cukup untuk jumlah ini.")} <Link to={localizedPath("/deposit")}>{t("Isi saldo")}</Link></p>}
            </div>
            {reason && <p className="sw-reason" id="sw-submit-reason" aria-live="polite"><StudioIcon name="info" />{t(reason)}</p>}
            <div className="sw-footer-actions">
                <StudioButton className="sw-reset" disabled={!capability || studio.submitting || request.realtimeActive} onClick={actions.reset}>{t("Reset")}</StudioButton>
                {request.realtime
                    ? <StudioButton primary className="sw-generate" disabled={!capability} onClick={actions.showSession}>{t("Ke sesi langsung")}<StudioIcon name="arrow" /></StudioButton>
                    : <button type="submit" className={`studio-button studio-button-primary sw-generate${request.canSubmit ? "" : " is-waiting"}`} disabled={studio.submitting || locked}
                        aria-disabled={!request.canSubmit} aria-describedby={reason ? "sw-submit-reason" : undefined}>
                        {studio.submitting && <span className="studio-spinner sw-inline-spinner" aria-hidden="true" />}{t(request.submitLabel)}{!studio.submitting && <StudioIcon name="arrow" />}</button>}
            </div>
        </div>
    </form>;
}

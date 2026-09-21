import { useEffect, useRef, useState } from "react";
import { useAuth } from "../contexts/AuthContext";
import { useLocale } from "../contexts/LocaleContext";
import MediaActionDialog from "../components/MediaActionDialog";
import { validationErrors } from "../components/member/MemberUI";
import { isPending, maxQuantity, tokenPrice, useMediaStudio, useStudioDraft } from "../components/studios/useMediaStudio";
import { capabilityErrors, capabilitySubmission, capabilityValues } from "../components/studios/capability";
import CapabilityForm from "../components/studios/CapabilityForm";
import { StudioButton, StudioCatalog, StudioField, StudioHeader, StudioHistory, StudioIcon, StudioJobMeta, StudioNotice, StudioProgress, StudioQuote, mediaError } from "../components/studios/StudioUI";

const defaults = { model: "", operation: "", values: {}, count: "1" };
const operationLabels = { text_to_image: "Teks ke gambar", image_edit: "Edit dengan referensi", image_to_image: "Gambar ke gambar" };

function ImageCanvas({ job }) {
    const { t } = useLocale();
    const urls = Array.isArray(job?.result_urls) ? job.result_urls : [];
    const [selected, setSelected] = useState(0);
    const [zoom, setZoom] = useState("fit");
    const [dimensions, setDimensions] = useState(null);
    const [failed, setFailed] = useState(false);
    const [compare, setCompare] = useState(false);
    const [other, setOther] = useState(1);
    const [split, setSplit] = useState(50);
    const url = urls[selected] || urls[0];
    const compareIndex = other === selected ? (selected === 0 ? 1 : 0) : other;
    return <>
        <div className="studio-toolbar studio-canvas-toolbar"><div><h2>{t("Kanvas gambar")}</h2><p className="studio-help">{dimensions ? `${dimensions.width} × ${dimensions.height} px` : t("Pilih hasil untuk melihat detail")}</p></div><div className="studio-toolbar-actions"><label className="studio-visually-hidden" htmlFor="image-zoom">{t("Zoom kanvas")}</label><select id="image-zoom" className="studio-zoom" value={zoom} disabled={!url || !dimensions} onChange={(event) => setZoom(event.target.value)}><option value="fit">{t("Sesuaikan")}</option><option value="50">50%</option><option value="100">100%</option><option value="150">150%</option></select><StudioButton icon="compare" disabled={urls.length < 2} aria-pressed={compare} onClick={() => setCompare(!compare)}>{t("Bandingkan")}</StudioButton>{url && <a className="studio-button studio-download" href={url} download><StudioIcon name="download" />{t("Unduh asli")}</a>}</div></div>
        {compare && urls.length > 1 && <div className="studio-compare-controls"><StudioField id="image-compare-target" label="Bandingkan dengan"><select id="image-compare-target" value={compareIndex} onChange={(event) => setOther(Number(event.target.value))}>{urls.map((_, index) => index !== selected && <option value={index} key={index}>{t("Variasi")} {index + 1}</option>)}</select></StudioField><StudioField id="image-compare-split" label="Posisi perbandingan"><input id="image-compare-split" type="range" min="0" max="100" value={split} onChange={(event) => setSplit(Number(event.target.value))} aria-valuetext={`${split}%`} /></StudioField></div>}
        <div className="studio-image-viewport" tabIndex={url ? 0 : undefined} role="region" aria-label={t("Kanvas gambar; gunakan tombol panah untuk menggulir saat diperbesar")}>
            {url ? <div className={`studio-image-space ${zoom === "fit" ? "is-fit" : ""}`}><div className="studio-image-artboard" style={zoom !== "fit" && dimensions ? { width: dimensions.width * Number(zoom) / 100 } : undefined}><img key={url} src={url} alt={`${t("Hasil gambar")} ${selected + 1}: ${job.prompt || ""}`} onLoad={(event) => { setDimensions({ width: event.currentTarget.naturalWidth, height: event.currentTarget.naturalHeight }); setFailed(false); }} onError={() => setFailed(true)} />{compare && urls[compareIndex] && <><img className="studio-image-comparison" src={urls[compareIndex]} alt={`${t("Perbandingan variasi")} ${compareIndex + 1}`} style={{ clipPath: `inset(0 ${100 - split}% 0 0)` }} /><span className="studio-compare-divider" style={{ left: `${split}%` }} /><span className="studio-compare-label">{t("Variasi")} {selected + 1}</span></>}</div></div> : <div className="studio-empty"><StudioIcon name="image" /><h3>{t("Ruang untuk ide berikutnya")}</h3><p>{t("Tulis prompt, pilih model, lalu buat gambar. Hasil asli akan tampil di kanvas ini.")}</p></div>}
        </div>
        {failed && <StudioNotice error>{t("Gambar tidak dapat ditampilkan. Periksa status atau unduh hasil aslinya.")}</StudioNotice>}
        {urls.length > 0 && <div className="studio-variations"><div className="studio-section-heading"><h3>{t("Variasi hasil")}</h3><span className="studio-help">{selected + 1} / {urls.length}</span></div><div className="studio-variation-list">{urls.map((image, index) => <button type="button" key={image} aria-pressed={selected === index} onClick={() => { if (index !== selected) { setSelected(index); setFailed(false); setDimensions(null); } }} className="studio-variation"><img src={image} alt={`${t("Pilih variasi")} ${index + 1}`} loading="lazy" /><span>{t("Variasi")} {index + 1}</span></button>)}</div></div>}
    </>;
}

function ImageStudio({ userId }) {
    const { t, locale } = useLocale();
    const studio = useMediaStudio("image");
    const [draft, setDraft] = useStudioDraft("image", userId, defaults);
    const [dialog, setDialog] = useState(null);
    const reconciled = useRef("");

    const selectedId = studio.requestedModel || draft.model;
    const model = studio.models.find((item) => item.id === selectedId) || null;
    const capabilities = model?.capabilities || {};
    const operationKeys = Object.keys(capabilities);
    const operation = capabilities[draft.operation] ? draft.operation : operationKeys.includes("text_to_image") ? "text_to_image" : operationKeys[0] || "";
    const capability = capabilities[operation] || null;

    // Pick a model when the catalog loads, then reconcile the draft once per model+operation
    // change: compatible values survive, the rest fall back to capability defaults.
    useEffect(() => {
        if (!studio.models.length) return;
        const chosen = studio.models.find((item) => item.id === (studio.requestedModel || draft.model)) || (studio.requestedModel ? null : studio.models[0]);
        if (!chosen) return;
        const caps = chosen.capabilities || {};
        const opKeys = Object.keys(caps);
        const op = caps[draft.operation] ? draft.operation : opKeys.includes("text_to_image") ? "text_to_image" : opKeys[0] || "";
        const key = `${chosen.id}:${op}`;
        if (reconciled.current === key && draft.model === chosen.id && draft.operation === op) return;
        reconciled.current = key;
        setDraft((current) => ({
            ...current,
            model: chosen.id,
            operation: op,
            values: capabilityValues(caps[op] || null, current.values),
            count: String(Math.min(Math.max(1, Number(current.count) || 1), maxQuantity(chosen))),
        }));
    }, [studio.models, studio.requestedModel, draft.model, draft.operation, setDraft]);

    // A changed price or capability (409) keeps the draft and refreshes the catalog so the next
    // submit carries the current hash/price — never an automatic paid resubmit.
    useEffect(() => {
        if (studio.submitError?.status === 409) studio.loadModels();
    }, [studio.submitError, studio.loadModels]);

    const unit = capability?.price_tokens ?? tokenPrice(model);
    const count = Math.max(1, Math.min(Number(draft.count) || 1, maxQuantity(model)));
    const total = unit == null ? null : unit * count;
    const clientErrors = capabilityErrors(capability, draft.values);
    const errors = { ...clientErrors, ...validationErrors(studio.submitError) };
    const insufficient = total != null && studio.balance != null && studio.balance < total;
    const canSubmit = Boolean(capability && unit != null && total != null && total <= 2147483647 && !insufficient
        && Object.keys(clientErrors).length === 0 && !studio.submitting && !studio.modelLoading && !studio.modelError);
    const warning = t(mediaError(studio.catalog?.cancel_reason || "Pembuatan gambar tidak dapat dibatalkan setelah dikirim. Menutup halaman tidak menghentikan proses atau mengembalikan token."));
    const job = studio.activeJob;
    const busyCanvas = studio.submitting || isPending(job);

    const generate = () => {
        if (!canSubmit) return;
        setDialog(null);
        studio.submit({
            model: model.id,
            operation,
            n: count,
            idempotency_key: globalThis.crypto?.randomUUID?.() || String(Date.now()) + Math.random().toString(36).slice(2),
            expected_price_tokens: unit,
            ...(capability.source_hash ? { expected_capability_hash: capability.source_hash } : {}),
            ...capabilitySubmission(capability, draft.values),
        });
    };

    return <div className="media-studio studio-image">
        <StudioHeader kind="image" title="Studio gambar" description="Dari satu gagasan ke gambar yang siap digunakan." balance={studio.balance} onRefresh={studio.refresh} busy={studio.modelLoading || studio.submitting} />
        <div className="studio-image-desk">
            <form className="studio-image-author" onSubmit={(event) => { event.preventDefault(); if (canSubmit) setDialog("confirm"); }} aria-busy={studio.submitting}>
                <div className="studio-section-heading"><h2>{t("Arahan kreatif")}</h2><StudioIcon name="prompt" className="studio-color-image" /></div>
                <StudioCatalog studio={studio} id="image-model" value={selectedId} error={errors.model} onChange={(id) => studio.selectModel(id)} />
                {operationKeys.length > 1 && <fieldset className="studio-angle-fieldset"><legend>{t("Mode")}</legend><div className="studio-tabs" role="tablist">{operationKeys.map((op) => <button type="button" key={op} role="tab" aria-selected={op === operation} disabled={studio.submitting} onClick={() => setDraft((current) => ({ ...current, operation: op }))}>{t(operationLabels[op] || op)}</button>)}</div></fieldset>}
                {capability
                    ? <CapabilityForm capability={capability} values={draft.values} errors={errors} disabled={studio.submitting} idPrefix="image" onChange={(values) => setDraft((current) => ({ ...current, values }))} />
                    : model ? <StudioNotice error>{t("Model ini belum menyediakan operasi gambar yang didukung.")}</StudioNotice> : null}
                <StudioField id="image-count" label="Jumlah" error={errors.n} className="studio-count-field">
                    <select id="image-count" value={count} disabled={studio.submitting || !model || maxQuantity(model) === 1} onChange={(event) => setDraft((current) => ({ ...current, count: event.target.value }))}>
                        {Array.from({ length: maxQuantity(model) }, (_, index) => index + 1).map((value) => <option key={value} value={value}>{value} {t("gambar")}</option>)}
                    </select>
                </StudioField>
                {model && unit == null && <StudioNotice error>{t("Harga token model belum tersedia. Pilih model lain atau hubungi pengelola.")}</StudioNotice>}
                {studio.submitError && <StudioNotice error>{t(studio.submitError.status ? mediaError(studio.submitError) : "Respons belum dapat dikonfirmasi. Periksa riwayat sebelum mengirim lagi.")}</StudioNotice>}
                <StudioQuote unit={unit} total={total} count={count} balance={studio.balance} />
                <StudioButton type="submit" primary icon="image" disabled={!canSubmit}>{t(studio.submitting ? "Membuat gambar…" : "Generate gambar")}<StudioIcon name="arrow" /></StudioButton>
            </form>
            <section className="studio-image-stage" aria-label={t("Hasil gambar")}>
                {studio.statusError && <StudioNotice error action={<StudioButton onClick={() => studio.loadJob(studio.activeId)} disabled={studio.statusLoading}>{t("Coba lagi")}</StudioButton>}>{t(mediaError(studio.statusError))}</StudioNotice>}
                {busyCanvas ? <div className="studio-image-working"><StudioProgress job={job} submitting={studio.submitting} synchronous /><StudioButton onClick={() => setDialog("unavailable")}>{t("Tidak bisa dibatalkan")}</StudioButton></div> : <ImageCanvas key={job?.job_id || "empty"} job={job} />}
                {job && <StudioJobMeta job={job} checking={studio.statusLoading} onCheck={() => studio.loadJob(job.job_id)} />}
            </section>
        </div>
        <StudioHistory studio={studio} kind="image" title="Riwayat gambar" />
        {dialog && <MediaActionDialog title={t(dialog === "confirm" ? "Konfirmasi pembuatan gambar" : "Pembuatan gambar tidak bisa dibatalkan")} description={dialog === "unavailable" && job?.cancel_reason ? t(mediaError(job.cancel_reason)) : warning} closeLabel={t(dialog === "confirm" ? "Kembali" : "Mengerti")} confirmLabel={t("Ya, buat gambar")} confirmDisabled={!canSubmit} onConfirm={dialog === "confirm" ? generate : undefined} onClose={() => setDialog(null)}>{dialog === "confirm" ? <dl className="space-y-3 text-sm"><div className="flex flex-wrap justify-between gap-2"><dt>{t("Model")}</dt><dd className="font-semibold">{model?.name || selectedId}</dd></div><div className="flex flex-wrap justify-between gap-2"><dt>{t("Jumlah")}</dt><dd>{count} {t("gambar")}</dd></div><div className="flex flex-wrap justify-between gap-2"><dt>{t("Estimasi total")}</dt><dd>{total == null ? "—" : `${new Intl.NumberFormat(locale).format(total)} ${t("token")}`}</dd></div></dl> : null}</MediaActionDialog>}
    </div>;
}

export default function GenerateImage() {
    const { user } = useAuth();
    return user ? <ImageStudio key={user.id} userId={user.id} /> : null;
}

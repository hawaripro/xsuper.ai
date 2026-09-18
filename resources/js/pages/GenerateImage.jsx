import { useEffect, useState } from "react";
import { useAuth } from "../contexts/AuthContext";
import { useLocale } from "../contexts/LocaleContext";
import MediaActionDialog from "../components/MediaActionDialog";
import { validationErrors } from "../components/member/MemberUI";
import { isPending, maxQuantity, modelOptions, tokenPrice, useMediaStudio, useStudioDraft } from "../components/studios/useMediaStudio";
import { StudioBilling, StudioButton, StudioCatalog, StudioEmpty, StudioField, StudioHeader, StudioHistory, StudioIcon, StudioJobMeta, StudioNotice, StudioProgress, StudioQuote, mediaError } from "../components/studios/StudioUI";

const defaults = { model: "", prompt: "", size: "", count: "1" };

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
            {url ? <div className={`studio-image-space ${zoom === "fit" ? "is-fit" : ""}`}><div className="studio-image-artboard" style={zoom !== "fit" && dimensions ? { width: dimensions.width * Number(zoom) / 100 } : undefined}><img key={url} src={url} alt={`${t("Hasil gambar")} ${selected + 1}: ${job.prompt || ""}`} onLoad={(event) => { setDimensions({ width: event.currentTarget.naturalWidth, height: event.currentTarget.naturalHeight }); setFailed(false); }} onError={() => setFailed(true)} />{compare && urls[compareIndex] && <><img className="studio-image-comparison" src={urls[compareIndex]} alt={`${t("Perbandingan variasi")} ${compareIndex + 1}`} style={{ clipPath: `inset(0 ${100 - split}% 0 0)` }} /><span className="studio-compare-divider" style={{ left: `${split}%` }} /><span className="studio-compare-label">{compareIndex + 1} / {selected + 1}</span></>}</div></div> : <StudioEmpty icon="image" title="Ruang untuk ide berikutnya" description="Tulis prompt, pilih model, lalu buat gambar. Hasil asli akan tampil di kanvas ini." />}
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
    const selectedId = studio.requestedModel || draft.model;
    const model = studio.models.find((item) => item.id === selectedId) || null;
    useEffect(() => {
        if (!studio.models.length) return;
        const next = studio.models.find((item) => item.id === (studio.requestedModel || draft.model));
        if (studio.requestedModel && !next) return;
        const chosen = next || studio.models[0];
        setDraft((current) => ({ ...current, model: chosen.id, size: modelOptions(chosen, "sizes").includes(current.size) ? current.size : modelOptions(chosen, "sizes")[0] || "", count: String(Math.min(Number(current.count) || 1, maxQuantity(chosen))) }));
    }, [studio.models, studio.requestedModel, draft.model, setDraft]);
    const unit = tokenPrice(model);
    const count = Math.max(1, Math.min(Number(draft.count) || 1, maxQuantity(model)));
    const total = unit == null ? null : unit * count;
    const sizes = modelOptions(model, "sizes");
    const errors = validationErrors(studio.submitError);
    const canSubmit = Boolean(model && total != null && total <= 2147483647 && studio.balance != null && studio.balance >= total && draft.prompt.trim() && draft.prompt.trim().length <= 4000 && !studio.submitting && !studio.modelLoading && !studio.modelError);
    const warning = t(mediaError(studio.catalog?.cancel_reason || "Pembuatan gambar tidak dapat dibatalkan setelah dikirim. Menutup halaman tidak menghentikan proses atau mengembalikan token."));
    const job = studio.activeJob;
    const busyCanvas = studio.submitting || isPending(job);
    const formatTokens = (value) => `${new Intl.NumberFormat(locale).format(value)} ${t("token")}`;
    const generate = () => {
        if (!canSubmit) return;
        setDialog(null);
        studio.submit({ model: model.id, prompt: draft.prompt.trim(), n: count, ...(draft.size ? { size: draft.size } : {}) });
    };
    return <div className="media-studio studio-image">
        <StudioHeader kind="image" title="Studio gambar" description="Dari satu gagasan ke gambar yang siap digunakan." balance={studio.balance} onRefresh={studio.refresh} busy={studio.modelLoading || studio.submitting} />
        <div className="studio-image-desk">
            <form className="studio-image-author" onSubmit={(event) => { event.preventDefault(); if (canSubmit) setDialog("confirm"); }} aria-busy={studio.submitting}>
                <div className="studio-section-heading"><h2>{t("Arahan kreatif")}</h2><StudioIcon name="prompt" className="studio-color-image" /></div>
                <StudioField id="image-prompt" label="Prompt" error={errors.prompt} hint={`${draft.prompt.length}/4000 ${t("karakter")}`}><textarea id="image-prompt" value={draft.prompt} maxLength={4000} rows={7} required disabled={studio.submitting} aria-invalid={Boolean(errors.prompt)} aria-describedby={`image-prompt-hint${errors.prompt ? " image-prompt-error" : ""}`} placeholder={t("Jelaskan subjek, komposisi, pencahayaan, dan gaya visual…")} onChange={(event) => setDraft({ ...draft, prompt: event.target.value })} /></StudioField>
                <div className="studio-divider" />
                <StudioCatalog studio={studio} id="image-model" value={selectedId} error={errors.model} onChange={(id) => studio.selectModel(id)} />
                <div className="studio-field-pair"><StudioField id="image-size" label="Ukuran" error={errors.size}><select id="image-size" value={draft.size} onChange={(event) => setDraft({ ...draft, size: event.target.value })} disabled={studio.submitting || sizes.length < 2}>{!sizes.length ? <option value="">{t("Otomatis oleh model")}</option> : sizes.map((size) => <option key={size} value={size}>{size === "auto" ? t("Otomatis oleh model") : size}</option>)}</select></StudioField><StudioField id="image-count" label="Jumlah" error={errors.n}><select id="image-count" value={count} disabled={studio.submitting || !model || maxQuantity(model) === 1} onChange={(event) => setDraft({ ...draft, count: event.target.value })}>{Array.from({ length: maxQuantity(model) }, (_, index) => <option key={index + 1} value={index + 1}>{index + 1} {t("gambar")}</option>)}</select></StudioField></div>
                {model && unit == null && <StudioNotice error>{t("Harga token model belum tersedia. Pilih model lain atau hubungi pengelola.")}</StudioNotice>}
                {studio.submitError && <StudioNotice error>{t(studio.submitError.status ? mediaError(studio.submitError) : "Respons belum dapat dikonfirmasi. Periksa riwayat sebelum mengirim lagi.")}</StudioNotice>}
                <StudioQuote unit={unit} total={total} count={count} balance={studio.balance} />
                <StudioButton type="submit" primary icon="image" disabled={!canSubmit}>{t(studio.submitting ? "Membuat gambar…" : "Generate gambar")}<StudioIcon name="arrow" /></StudioButton>
                <p className="studio-help">{t("Gambar dibuat dari prompt. Pengeditan gambar referensi belum didukung.")}</p>
            </form>
            <section className="studio-image-stage" aria-label={t("Hasil gambar")}>
                {studio.statusError && <StudioNotice error action={<StudioButton onClick={() => studio.loadJob(studio.activeId)} disabled={studio.statusLoading}>{t("Coba lagi")}</StudioButton>}>{t(mediaError(studio.statusError))}</StudioNotice>}
                {busyCanvas ? <div className="studio-image-working"><StudioProgress job={job} submitting={studio.submitting} synchronous /><StudioButton onClick={() => setDialog("unavailable")}>{t("Tidak bisa dibatalkan")}</StudioButton></div> : <ImageCanvas key={job?.job_id || "empty"} job={job} />}
                {job && <StudioJobMeta job={job} checking={studio.statusLoading} onCheck={() => studio.loadJob(job.job_id)} />}
            </section>
        </div>
        <StudioHistory studio={studio} kind="image" title="Riwayat gambar" />
        {dialog && <MediaActionDialog title={t(dialog === "confirm" ? "Konfirmasi pembuatan gambar" : "Pembuatan gambar tidak bisa dibatalkan")} description={dialog === "unavailable" && job?.cancel_reason ? t(mediaError(job.cancel_reason)) : warning} closeLabel={t(dialog === "confirm" ? "Kembali" : "Mengerti")} confirmLabel={t("Ya, buat gambar")} confirmDisabled={!canSubmit} onConfirm={dialog === "confirm" ? generate : undefined} onClose={() => setDialog(null)}>{dialog === "confirm" ? <><dl className="space-y-3 text-sm"><div className="flex flex-wrap justify-between gap-2"><dt>{t("Model")}</dt><dd className="font-semibold">{model?.name || selectedId}</dd></div><div className="flex flex-wrap justify-between gap-2"><dt>{t("Jumlah")}</dt><dd>{count} {t("gambar")}</dd></div><div className="flex flex-wrap justify-between gap-2"><dt>{t("Estimasi total")}</dt><dd className="font-semibold">{total == null ? "—" : formatTokens(total)}</dd></div></dl><p className="mt-4 text-sm leading-6 text-slate-600 dark:text-slate-300">{t("Prompt dikirim langsung ke penyedia dan model yang dipilih. Pemeriksaan keamanan penyedia tetap berlaku.")}</p><p className="mt-3 text-sm leading-6 text-slate-600 dark:text-slate-300">{t("Token dicadangkan saat dikirim. Permintaan yang gagal dikembalikan sesuai status tagihan.")}</p></> : <><p className="text-sm leading-6">{t("Tunggu hasil atau periksa riwayat. Jangan kirim ulang permintaan yang sama.")}</p>{job && <StudioBilling job={job} />}</>}</MediaActionDialog>}
    </div>;
}

export default function GenerateImage() {
    const { user } = useAuth();
    return user ? <ImageStudio key={user.id} userId={user.id} /> : null;
}

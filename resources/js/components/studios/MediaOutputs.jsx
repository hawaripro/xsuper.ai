import { useId, useState } from "react";
import { useLocale } from "../../contexts/LocaleContext";
import { formatUsdMicros } from "../member/MemberUI";
import MediaOutputPreview, { MediaResultData } from "./MediaOutputPreview";
import { ownedMediaUrl } from "./mediaOutput";
import { BILLING_LABELS, jobTokens } from "./studioJobs";
import { mediaJobPending, outputKindLabel } from "./workspaceMedia";
import { StudioButton, StudioEmpty, StudioField, StudioIcon } from "./StudioUI";

export function JobBilling({ job }) {
    const { t, locale } = useLocale();
    const details = job.details || {};
    if (details.billing_mode === "admin") return <p className="studio-help">{t("Gratis admin (riwayat lama)")}</p>;
    const tokens = jobTokens(job);
    // This is the legacy member charge, never the provider's generation cost.
    const cost = tokens != null ? `${new Intl.NumberFormat(locale).format(tokens)} ${t("token")}`
        : details.cost_microusd != null && details.billing_mode !== "tokens" && job.price_tokens == null ? formatUsdMicros(details.cost_microusd) : null;
    if (!cost) return null;
    return <p className="studio-billing"><StudioIcon name="tokens" />{cost}<span>·</span>{t(BILLING_LABELS[job.billing_status] || "Status tagihan belum tersedia")}</p>;
}

export function MediaOutputs({ job, selected = "", onSelect }) {
    const { t } = useLocale();
    const id = useId();
    const [filter, setFilter] = useState("");
    const [comparing, setComparing] = useState(false);
    const [other, setOther] = useState("");
    const [split, setSplit] = useState(50);
    const outputs = job.outputs || [];
    const output = outputs.find((entry) => entry.id === selected) || outputs[0];
    const kinds = [...new Set(outputs.map((entry) => entry.kind))];
    // External selection or a refreshed output list must not leave the viewer and aside on different files.
    const activeFilter = filter === output?.kind ? filter : "";
    const visible = activeFilter ? outputs.filter((entry) => entry.kind === activeFilter) : outputs;
    const images = outputs.filter((entry) => entry.kind === "image" && entry.previewable && /^image\/(png|jpeg|webp|gif|avif)$/.test(entry.mime || "") && ownedMediaUrl(entry.url));
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
                {!variations && <StudioField id={`${id}-output`} label={tracks ? "Pilih track" : `${t("Hasil")} (${outputs.length})`}><select id={`${id}-output`} value={output?.id || ""} onChange={(event) => onSelect(event.target.value)}>
                    {visible.map((entry) => <option key={entry.id} value={entry.id}>{label(entry)}{tracks ? "" : ` · ${t(outputKindLabel(entry.kind))}`}</option>)}
                </select></StudioField>}
                {kinds.length > 1 && <StudioField id={`${id}-filter`} label="Jenis hasil"><select id={`${id}-filter`} value={activeFilter} onChange={(event) => {
                    const next = event.target.value;
                    setFilter(next);
                    setComparing(false);
                    if (next && output?.kind !== next) onSelect(outputs.find((entry) => entry.kind === next).id);
                }}><option value="">{t("Semua file")}</option>{kinds.map((kind) => <option value={kind} key={kind}>{t(outputKindLabel(kind))}</option>)}</select></StudioField>}
                {images.length > 1 && images.some((entry) => entry.id === output?.id) && <StudioButton icon="compare" aria-pressed={comparing} onClick={() => setComparing((current) => !current)}>{t("Bandingkan")}</StudioButton>}
            </div>
            {tracks && <p className="studio-help media-output-note">{t("Semua track berasal dari satu permintaan. Pilihan track tidak menambah tagihan.")}</p>}
            {comparing && secondary && images.some((entry) => entry.id === output?.id) ? <>
                <div className="studio-compare-controls"><StudioField id={`${id}-other`} label="Bandingkan dengan"><select id={`${id}-other`} value={secondary.id} onChange={(event) => setOther(event.target.value)}>{images.filter((entry) => entry.id !== output.id).map((entry) => <option key={entry.id} value={entry.id}>{label(entry)}</option>)}</select></StudioField>
                    <StudioField id={`${id}-split`} label="Posisi pembanding"><input id={`${id}-split`} type="range" min="0" max="100" value={split} aria-valuetext={`${split}%`} onChange={(event) => setSplit(Number(event.target.value))} /></StudioField></div>
                <div className="media-image-comparison"><img src={ownedMediaUrl(output.url)} alt={label(output)} /><img className="media-image-comparison-overlay" src={ownedMediaUrl(secondary.url)} alt={`${t("Perbandingan variasi")}: ${label(secondary)}`} style={{ clipPath: `inset(0 ${100 - split}% 0 0)` }} /><span className="studio-compare-divider" style={{ left: `${split}%` }} /><span className="studio-compare-label">{label(output)}</span></div>
                <div className="media-comparison-downloads">{[output, secondary].map((entry) => { const url = ownedMediaUrl(entry.download_url); return url ? <a key={entry.id} className="studio-button" href={url} download><StudioIcon name="download" />{label(entry)}</a> : null; })}</div>
            </> : <MediaOutputPreview key={output?.id} actions={false} output={output && ["text_to_speech", "speech_to_speech"].includes(job.operation) ? { ...output, mode: "speech" } : output} />}
            {variations && images.length > 1 ? <div className="studio-variations"><div className="studio-section-heading"><h3>{t("Variasi hasil")}</h3><span className="studio-help">{outputs.indexOf(output) + 1} / {outputs.length}</span></div>
                <div className="studio-variation-list">{images.map((entry) => <button type="button" key={entry.id} className="studio-variation" aria-pressed={entry.id === output?.id} onClick={() => onSelect(entry.id)}>
                    <img src={ownedMediaUrl(entry.url)} alt={`${t("Pilih variasi")} ${outputs.indexOf(entry) + 1}`} loading="lazy" /><span>{label(entry)}</span></button>)}</div>
            </div> : !variations && visible.length > 1 && <div className="media-output-list" aria-label={t("Semua hasil tersimpan")}>{visible.map((entry) => {
                const preview = entry.kind === "image" && entry.previewable && ownedMediaUrl(entry.url);
                return <button type="button" key={entry.id} aria-pressed={entry.id === output?.id} onClick={() => onSelect(entry.id)}>{preview ? <img src={preview} loading="lazy" alt="" /> : <StudioIcon name={entry.kind === "model3d" ? "model3d" : ["video", "audio"].includes(entry.kind) ? entry.kind : "download"} />}<span>{label(entry)}<small>{t(outputKindLabel(entry.kind))} · {entry.mime}</small></span></button>;
            })}</div>}
        </>}
        {job.result_data !== undefined && job.result_data !== null && <div className="media-data-panel"><MediaResultData data={job.result_data} /></div>}
        {!outputs.length && job.result_data == null && !mediaJobPending(job) && <StudioEmpty icon="download" title={job.status === "completed" ? "Belum ada hasil tersimpan" : "Permintaan belum menghasilkan file"}
            description={job.can_retry_save ? "Hasil belum tersimpan. Coba simpan kembali tanpa membuat permintaan baru." : "Periksa status dan pesan pekerjaan. Tidak ada hasil simulasi yang ditampilkan."} />}
    </>;
}

export function SavedReferences({ job }) {
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

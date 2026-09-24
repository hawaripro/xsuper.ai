import { Component, Suspense, lazy, useEffect, useState } from "react";
import { useLocale } from "../../contexts/LocaleContext";
import { StudioButton, StudioEmpty, StudioIcon, StudioNotice } from "./StudioUI";
import AudioPlayer from "./AudioPlayer";
import { ownedMediaUrl, readOwnedMedia } from "./mediaOutput";
import "./media-workspace.css";

const Model3dViewer = lazy(() => import("./Model3dViewer"));
class PreviewBoundary extends Component {
    state = { failed: false };
    static getDerivedStateFromError() { return { failed: true }; }
    render() { return this.state.failed ? this.props.fallback : this.props.children; }
}

export function MediaResultData({ data, label = "Data hasil" }) {
    const { t } = useLocale();
    const [limit, setLimit] = useState(40);
    if (data === null || typeof data !== "object") return <span className="media-data-value" dir="auto">{data === null ? "null" : String(data)}</span>;
    const entries = Object.entries(data);
    return <details className="media-result-data"><summary>{t(label)} <span>({entries.length})</span></summary>
        <dl>{entries.slice(0, limit).map(([key, value]) => <div key={key}><dt>{key}</dt><dd><MediaResultData data={value} label={Array.isArray(value) ? "Daftar" : "Objek"} /></dd></div>)}</dl>
        {entries.length > limit && <StudioButton onClick={() => setLimit((current) => current + 100)}>{t("Tampilkan berikutnya")}</StudioButton>}
    </details>;
}

function TextOutput({ src, mime }) {
    const { t } = useLocale();
    const [result, setResult] = useState({ loading: true });
    useEffect(() => {
        const controller = new AbortController();
        setResult({ loading: true });
        readOwnedMedia(src, 1024 * 1024, controller.signal).then((bytes) => {
            const text = new TextDecoder("utf-8", { fatal: true }).decode(bytes);
            let data;
            if (mime === "application/json") { try { data = JSON.parse(text); } catch { /* Preserve invalid JSON as inert original text. */ } }
            if (!controller.signal.aborted) setResult({ text, data, loading: false });
        }).catch((error) => { if (!controller.signal.aborted) setResult({ loading: false, error: error.message }); });
        return () => controller.abort();
    }, [src, mime]);
    if (result.loading) return <p role="status" className="studio-loading">{t("Memuat pratinjau…")}</p>;
    if (result.error) return <StudioNotice error>{t("Pratinjau teks tidak tersedia atau terlalu besar. Unduh file asli.")}</StudioNotice>;
    return result.data !== undefined ? <MediaResultData data={result.data} /> : <pre className="media-text-output" dir="auto">{result.text}</pre>;
}

function ImageOutput({ src, name }) {
    const { t } = useLocale();
    const [zoom, setZoom] = useState("fit");
    const [dimensions, setDimensions] = useState(null);
    const [failed, setFailed] = useState(false);
    return <>
        <div className="studio-toolbar media-image-toolbar"><label>{t("Zoom")} <select aria-label={t("Zoom kanvas")} value={zoom} onChange={(event) => setZoom(event.target.value)}><option value="fit">{t("Sesuaikan")}</option>{["50", "100", "150", "200"].map((level) => <option key={level} value={level}>{level}%</option>)}</select></label>{dimensions && <span className="studio-help">{dimensions.width} × {dimensions.height} px</span>}</div>
        {failed ? <StudioNotice error>{t("Pratinjau gambar gagal dimuat. Coba muat ulang atau unduh file asli.")}</StudioNotice>
            : <div className={`media-image-viewport ${zoom === "fit" ? "is-fit" : ""}`} tabIndex={0} role="region" aria-label={t("Kanvas gambar; gunakan tombol panah untuk menggulir saat diperbesar")}><img src={src} alt={name || t("Hasil gambar")}
                style={zoom !== "fit" && dimensions ? { width: dimensions.width * Number(zoom) / 100, maxWidth: "none" } : undefined}
                onLoad={(event) => setDimensions({ width: event.currentTarget.naturalWidth, height: event.currentTarget.naturalHeight })} onError={() => setFailed(true)} /></div>}
    </>;
}

function NativePlayback({ kind, src, name }) {
    const { t } = useLocale();
    const [failed, setFailed] = useState(false);
    return <><div className={`media-${kind}-preview`}>{kind === "video" ? <video controls playsInline preload="metadata" src={src} aria-label={name || t("Hasil video")} onError={() => setFailed(true)} />
        : <audio controls preload="metadata" src={src} aria-label={name || t("Hasil audio")} onError={() => setFailed(true)} />}</div>
        {failed && <StudioNotice error>{t("Browser tidak dapat memutar format ini. Unduh file asli untuk membukanya di aplikasi yang sesuai.")}</StudioNotice>}
    </>;
}

// Deliberately acyclic: artifact viewers can import this component without pulling in
// workspace state or ArtifactPanel. No returned text, SVG or HTML is executed as markup.
export default function MediaOutputPreview({ output }) {
    const { t, locale } = useLocale();
    if (!output) return null;
    const src = ownedMediaUrl(output.url || output.preview_url);
    const download = ownedMediaUrl(output.download_url);
    const mime = (output.mime || "").split(";")[0].toLowerCase();
    const kind = output.kind;
    const preview = output.previewable === true && src;
    let content;
    const unavailable = <StudioEmpty icon={kind === "model3d" ? "model3d" : "download"} title="Unduh untuk membuka hasil"
        description="Format ini disimpan sebagai file asli. Pratinjau aman belum tersedia di browser; tidak ada file yang dibuang." />;
    if (preview && kind === "image" && /^image\/(png|jpeg|webp|gif|avif)$/.test(mime)) content = <ImageOutput key={src} src={src} name={output.name} />;
    else if (preview && kind === "video" && mime.startsWith("video/")) content = <NativePlayback key={src} kind="video" src={src} name={output.name} />;
    else if (preview && kind === "audio" && mime.startsWith("audio/")) content = output.bytes != null && output.bytes <= 64 * 1024 * 1024
        ? <AudioPlayer key={src} job={{ mode: output.mode }} output={{ ...output, url: src }} />
        : <NativePlayback key={src} kind="audio" src={src} name={output.name} />;
    else if (preview && kind === "model3d" && (mime === "model/gltf-binary" || /\.glb$/i.test(output.name || ""))) content = <PreviewBoundary key={src} fallback={unavailable}>
        <Suspense fallback={<p role="status" className="studio-loading">{t("Memuat penampil 3D…")}</p>}><Model3dViewer src={src} alt={output.name} /></Suspense>
    </PreviewBoundary>;
    else if (preview && ["application/json", "text/plain", "text/markdown", "text/csv"].includes(mime)) content = <TextOutput key={src} src={src} mime={mime} />;
    else if (output.data !== undefined) content = <MediaResultData data={output.data} />;
    else content = unavailable;
    return <div className="media-studio media-output-preview">
        {content}
        <div className="studio-toolbar media-output-download"><div><strong>{output.name || t("Hasil")}</strong><p className="studio-help">{[kind, mime, output.bytes != null ? `${new Intl.NumberFormat(locale).format(output.bytes)} bytes` : null].filter(Boolean).join(" · ")}</p></div>
            <div className="studio-toolbar-actions">{preview && kind === "video" && <a className="studio-text-link" href={src} target="_blank" rel="noreferrer">{t("Buka asli")}</a>}
                {download ? <a className="studio-button studio-download" href={download} download><StudioIcon name="download" />{t("Unduh asli")}</a>
                    : <p className="studio-help">{t("Tautan unduhan belum tersedia. Muat ulang status hasil.")}</p>}</div>
        </div>
    </div>;
}

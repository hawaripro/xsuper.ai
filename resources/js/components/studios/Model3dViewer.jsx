import { useEffect, useId, useMemo, useRef, useState } from "react";
import { ModelViewerElement } from "@google/model-viewer";
import { useLocale } from "../../contexts/LocaleContext";
import { StudioButton, StudioIcon } from "./StudioUI";
import "./model3d.css";

// This component is imported only for a selected, server-approved self-contained GLB.
// The documented URL hook also covers Three's optional Draco/KTX2 file loaders;
// no decoder scripts, remote textures, or arbitrary same-origin paths are allowed.
ModelViewerElement.mapURLs((value) => {
    const url = new URL(value, window.location.origin);
    const ownedModel = url.origin === window.location.origin && !url.username && !url.password
        && /^\/api\/3d\/[^/]+\/asset$/.test(url.pathname);
    const embeddedBlob = url.protocol === "blob:" && url.origin === window.location.origin;
    const embeddedData = /^data:(?:image\/(?:png|jpeg|webp)|application\/(?:octet-stream|gltf-buffer));base64,/i.test(value);
    if (ownedModel || embeddedBlob || embeddedData) return value;
    throw new Error("The 3D preview cannot load external resources or optional decoders.");
});
// Do not keep previously selected private models resident in the shared viewer cache.
ModelViewerElement.modelCacheSize = 0;

const initialOrbit = "0deg 75deg 105%";

export default function Model3dViewer({ src, alt }) {
    const { t } = useLocale();
    const viewerRef = useRef(null);
    const [state, setState] = useState("loading");
    const helpId = useId();
    const a11y = useMemo(() => ({
        "interaction-prompt": t("Gunakan tombol panah untuk memutar, Shift dan panah untuk menggeser, serta Page Up atau Page Down untuk zoom."),
        front: t("Tampak depan"),
        back: t("Tampak belakang"),
        left: t("Tampak kiri"),
        right: t("Tampak kanan"),
        "upper-front": t("Tampak depan atas"),
        "upper-back": t("Tampak belakang atas"),
        "upper-left": t("Tampak kiri atas"),
        "upper-right": t("Tampak kanan atas"),
        "lower-front": t("Tampak depan bawah"),
        "lower-back": t("Tampak belakang bawah"),
        "lower-left": t("Tampak kiri bawah"),
        "lower-right": t("Tampak kanan bawah"),
    }), [t]);

    useEffect(() => {
        const viewer = viewerRef.current;
        if (!viewer) return;
        const loaded = () => setState("ready");
        const failed = () => setState("error");
        const motion = window.matchMedia("(prefers-reduced-motion: reduce)");
        const updateMotion = () => { viewer.interpolationDecay = motion.matches ? 0 : 50; };
        setState("loading");
        viewer.addEventListener("load", loaded);
        viewer.addEventListener("error", failed);
        motion.addEventListener("change", updateMotion);
        updateMotion();
        // Configure after construction (model-viewer sets CDN defaults in its constructor).
        // Empty decoder locations are blocked by mapURLs; Meshopt remains unconfigured,
        // because setting its location would eagerly inject a script. Lottie is not used.
        ModelViewerElement.dracoDecoderLocation = "";
        ModelViewerElement.ktx2TranscoderLocation = "";
        ModelViewerElement.lottieLoaderLocation = "";
        viewer.src = src;
        return () => {
            viewer.removeEventListener("load", loaded);
            viewer.removeEventListener("error", failed);
            motion.removeEventListener("change", updateMotion);
            viewer.src = null;
        };
    }, [src]);

    const resetView = () => {
        const viewer = viewerRef.current;
        if (!viewer || state !== "ready") return;
        viewer.cameraOrbit = initialOrbit;
        viewer.cameraTarget = "auto auto auto";
        viewer.fieldOfView = "auto";
        viewer.jumpCameraToGoal();
    };

    return <div className="model3d-viewer">
        <div className="model3d-viewer-viewport" aria-busy={state === "loading"}>
            <model-viewer ref={viewerRef} alt={alt || t("Pratinjau model 3D")} a11y={a11y}
                camera-controls="" camera-orbit={initialOrbit} camera-target="auto auto auto"
                touch-action="pan-y" interaction-prompt="none" environment-image="neutral" loading="eager"
                aria-describedby={helpId} aria-hidden={state !== "ready" ? true : undefined} hidden={state === "error"}>
                <span slot="progress-bar" aria-hidden="true" />
            </model-viewer>
            {state === "loading" && <div className="model3d-viewer-message" role="status">
                <span className="studio-spinner" aria-hidden="true" /><p>{t("Memuat model 3D…")}</p>
            </div>}
            {state === "error" && <div className="model3d-viewer-message" role="alert">
                <StudioIcon name="model3d" /><h3>{t("Pratinjau 3D tidak dapat dimuat")}</h3>
                <p>{t("File atau perangkat ini tidak dapat menampilkan pratinjau. Unduh file asli untuk membukanya di aplikasi 3D yang kompatibel.")}</p>
            </div>}
        </div>
        <div className="model3d-viewer-controls">
            <div className="studio-toolbar-actions" role="group" aria-label={t("Kontrol pratinjau 3D")}>
                <StudioButton onClick={() => viewerRef.current?.zoom(1)} disabled={state !== "ready"}>{t("Perbesar")}</StudioButton>
                <StudioButton onClick={() => viewerRef.current?.zoom(-1)} disabled={state !== "ready"}>{t("Perkecil")}</StudioButton>
                <StudioButton icon="refresh" onClick={resetView} disabled={state !== "ready"}>{t("Atur ulang tampilan")}</StudioButton>
            </div>
            <div id={helpId} className="model3d-viewer-help">
                <p>{t("Seret untuk memutar, gulir atau cubit untuk zoom. Geser dengan klik kanan atau dua jari.")}</p>
                <p>{a11y["interaction-prompt"]}</p>
            </div>
        </div>
    </div>;
}

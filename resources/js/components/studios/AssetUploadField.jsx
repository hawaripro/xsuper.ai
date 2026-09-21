import { useEffect, useRef, useState } from "react";
import { StudioButton, StudioField, StudioIcon } from "./StudioUI";
import { useLocale } from "../../contexts/LocaleContext";
import { apiRequest } from "../../lib/api";

const IMAGE_MIME = ["image/jpeg", "image/png", "image/webp"];
const MAX_BYTES = 15 * 1024 * 1024;

// Capability-driven reference uploader. Uploads the file to the controlled asset endpoint and
// returns only the internal asset id to the studio (never a raw URL). Preview is the local
// object URL; limits/errors are actionable. The backend re-validates size/type/signature/role.
export default function AssetUploadField({ input, meta = {}, value, error, disabled = false, idPrefix = "cap", onChange }) {
    const { t } = useLocale();
    const id = `${idPrefix}-${input.key}`;
    const [uploading, setUploading] = useState(false);
    const [localError, setLocalError] = useState(null);
    const [preview, setPreview] = useState(null);
    const fileRef = useRef(null);

    useEffect(() => () => { if (preview) URL.revokeObjectURL(preview); }, [preview]);
    // A cleared value (e.g. model/mode change dropped an incompatible reference) resets the preview.
    useEffect(() => {
        if (!value && preview) { URL.revokeObjectURL(preview); setPreview(null); }
    }, [value, preview]);

    const pick = async (event) => {
        const file = event.target.files?.[0];
        event.target.value = "";
        if (!file) return;
        setLocalError(null);
        if (!IMAGE_MIME.includes(file.type)) { setLocalError("Gunakan berkas JPG, PNG, atau WebP."); return; }
        if (file.size > MAX_BYTES) { setLocalError("Ukuran berkas melebihi 15 MB."); return; }
        const objectUrl = URL.createObjectURL(file);
        setPreview((prev) => { if (prev) URL.revokeObjectURL(prev); return objectUrl; });
        setUploading(true);
        try {
            const form = new FormData();
            form.append("file", file);
            form.append("role", input.role);
            const data = await apiRequest("/api/media/assets", { method: "POST", body: form });
            const assetId = data?.asset?.id;
            if (!assetId) throw new Error("Unggahan gagal. Coba lagi.");
            onChange(assetId);
        } catch (uploadError) {
            setLocalError(uploadError?.message || "Unggahan gagal. Coba lagi.");
            setPreview((prev) => { if (prev) URL.revokeObjectURL(prev); return null; });
            onChange("");
        } finally {
            setUploading(false);
        }
    };

    const clear = () => {
        setLocalError(null);
        setPreview((prev) => { if (prev) URL.revokeObjectURL(prev); return null; });
        onChange("");
    };

    const staged = Boolean(value) || Boolean(preview);
    return <StudioField id={id} label={meta.label || input.key} error={localError || error} hint={meta.help ? t(meta.help) : t("JPG, PNG, atau WebP hingga 15 MB.")}>
        {staged
            ? <div className="studio-reference-preview">
                {preview
                    ? <img src={preview} alt={t("Pratinjau referensi")} />
                    : <span className="studio-history-thumb"><StudioIcon name="image" /></span>}
                <div>
                    <strong>{uploading ? t("Mengunggah…") : t("Referensi siap")}</strong>
                    <small>{t("Gambar acuan")}</small>
                </div>
                <StudioButton type="button" icon="close" disabled={disabled || uploading} onClick={clear}>{t("Ganti")}</StudioButton>
              </div>
            : <>
                <input ref={fileRef} id={id} type="file" accept={IMAGE_MIME.join(",")} disabled={disabled || uploading} aria-invalid={Boolean(error || localError)} onChange={pick} />
                {uploading && <p className="studio-help">{t("Mengunggah…")}</p>}
              </>}
    </StudioField>;
}

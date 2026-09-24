import { createContext, useContext, useEffect, useId, useRef, useState } from "react";
import { StudioButton, StudioField } from "./StudioUI";
import { useLocale } from "../../contexts/LocaleContext";
import { apiRequest, ApiError } from "../../lib/api";
import { ownedMediaUrl } from "./mediaOutput";
import { publicUrlError, uuidPattern } from "./schema";

export const UploadActivityContext = createContext(null);
let policies;
function loadPolicies() {
    if (!policies) policies = apiRequest("/api/media/assets/policy").catch((error) => { policies = null; throw error; });
    return policies;
}
// Mirrors AssetUploadPolicy::ROLE_KINDS for legacy inputs that only declare a role.
const roleKinds = { image_ref: "image", init_frame: "image", end_frame: "image", avatar_photo: "image", mask_image: "image", speech_audio: "audio",
    audio_reference: "audio", reference_video: "video", document: "document", file: "file", model_reference: "model3d" };
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] || "");
const megabytes = (bytes, locale) => (bytes / 1048576).toLocaleString(locale, { maximumFractionDigits: 1 });

function upload(file, role, progress, register) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        register(xhr);
        xhr.open("POST", "/api/media/assets");
        xhr.setRequestHeader("Accept", "application/json");
        xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
        xhr.setRequestHeader("X-CSRF-TOKEN", csrf());
        xhr.upload.onprogress = (event) => { if (event.lengthComputable) progress(Math.round(event.loaded / event.total * 100)); };
        xhr.onerror = () => reject(new Error("Unggahan terputus. Coba unggah kembali."));
        xhr.onabort = () => reject(new DOMException("Upload cancelled", "AbortError"));
        xhr.onload = () => {
            let data;
            try { data = JSON.parse(xhr.responseText); } catch { reject(new Error("Respons unggahan tidak valid.")); return; }
            if (xhr.status < 200 || xhr.status >= 300) reject(new ApiError(Object.values(data.errors || {}).flat()[0] || data.message || `Unggahan gagal (${xhr.status}).`, xhr.status, data));
            else if (!data.asset?.id) reject(new Error("Respons unggahan tidak memuat berkas tersimpan."));
            else resolve(data.asset);
        };
        const form = new FormData();
        form.append("file", file);
        form.append("role", role);
        xhr.send(form);
    });
}

// Decodes the local file only to pre-check a model's pixel limit; the server re-checks every upload.
function imagePixels(file) {
    return new Promise((resolve) => {
        const url = URL.createObjectURL(file);
        const image = new Image();
        const finish = (pixels) => { URL.revokeObjectURL(url); resolve(pixels); };
        image.onload = () => finish(image.naturalWidth * image.naturalHeight);
        image.onerror = () => finish(null);
        image.src = url;
    });
}

// Values are owned asset UUIDs or, when the model accepts links, public HTTPS URLs that the
// provider fetches. The browser never requests a pasted link. Local File/XHR state never escapes
// this mounted field; changing the capability unmounts it so callbacks cannot write another draft.
export default function AssetUploadField({ input, meta = {}, value, error, invalid = false, itemErrors = [], disabled = false, idPrefix = "cap", onChange }) {
    const { t, locale } = useLocale();
    const instance = useId();
    const id = `${idPrefix}-${instance}`;
    const activity = useContext(UploadActivityContext);
    const kind = input.kind || roleKinds[input.role] || "file";
    const multiple = input.single === false;
    const acceptsUrl = input.acceptsUrl === true;
    const [policy, setPolicy] = useState(null);
    const [policyError, setPolicyError] = useState("");
    const [retryPolicy, setRetryPolicy] = useState(0);
    const [rows, setRows] = useState([]);
    const [assets, setAssets] = useState({});
    const [broken, setBroken] = useState({});
    const [localError, setLocalError] = useState("");
    const [checking, setChecking] = useState(false);
    const [library, setLibrary] = useState(null);
    const [loading, setLoading] = useState(false);
    const [source, setSource] = useState("");
    const [link, setLink] = useState("");
    const [linkError, setLinkError] = useState("");
    const mounted = useRef(true);
    const requests = useRef(new Map());
    const values = useRef(value);
    values.current = value;
    const changed = useRef(onChange);
    changed.current = onChange;
    const librarySequence = useRef(0);
    const busy = checking || rows.some((row) => row.status === "uploading");
    const failed = rows.some((row) => row.status === "failed");
    const entries = multiple ? Array.isArray(value) ? value : [] : typeof value === "string" && value !== "" ? [value] : [];
    const max = multiple ? input.max ?? Infinity : 1;
    const full = multiple && entries.length + rows.length >= max;
    const maxBytes = Math.min(Number(policy?.max_bytes) || Infinity, Number(input.maxFileSize) > 0 ? Number(input.maxFileSize) : Infinity);
    const maxPixels = kind === "image" && Number(input.maxPixels) > 0 ? Number(input.maxPixels) : null;

    useEffect(() => {
        mounted.current = true;
        return () => { mounted.current = false; librarySequence.current += 1; requests.current.forEach((xhr) => xhr.abort()); requests.current.clear(); };
    }, []);
    useEffect(() => {
        let live = true;
        setPolicy(null);
        setPolicyError("");
        loadPolicies().then((data) => {
            if (!live) return;
            const next = data.roles?.[input.role];
            if (!next) { setPolicyError(t("Kebijakan unggahan untuk peran ini tidak tersedia.")); return; }
            setPolicy(next);
        }).catch((failure) => { if (live) setPolicyError(failure.message); });
        return () => { live = false; };
    }, [input.role, retryPolicy, t]);
    useEffect(() => {
        activity?.(instance, { busy: busy || loading, failed });
        return () => activity?.(instance, null);
    }, [activity, instance, busy, failed, loading]);

    const current = () => multiple ? Array.isArray(values.current) ? values.current : [] : typeof values.current === "string" && values.current !== "" ? [values.current] : [];
    const emit = (next) => { values.current = next; changed.current(next); };
    const add = (entry) => emit(multiple ? [...current(), entry] : entry);
    const remove = (index) => emit(multiple ? current().filter((_, at) => at !== index) : "");
    const updateRow = (key, changes) => { if (mounted.current) setRows((list) => list.map((row) => row.key === key ? { ...row, ...changes } : row)); };
    const start = async (row) => {
        updateRow(row.key, { status: "uploading", progress: 0, error: "" });
        try {
            const asset = await upload(row.file, input.role, (progress) => updateRow(row.key, { progress }), (xhr) => requests.current.set(row.key, xhr));
            if (!mounted.current || !requests.current.has(row.key)) return;
            setAssets((known) => ({ ...known, [asset.id]: asset }));
            // A single field keeps its previous value until the replacement is actually stored.
            add(asset.id);
            setRows((list) => list.filter((item) => item.key !== row.key));
        } catch (failure) {
            if (mounted.current && failure.name !== "AbortError" && requests.current.has(row.key)) updateRow(row.key, { status: "failed", error: failure.message });
        } finally { requests.current.delete(row.key); }
    };
    const pick = async (files) => {
        if (!policy || disabled || busy || !files?.length) return;
        setLocalError("");
        const selected = Array.from(files);
        if ((!multiple && selected.length > 1) || (multiple && entries.length + rows.length + selected.length > max)) { setLocalError(`${t("Maksimal berkas")}: ${max}`); return; }
        const extensions = (policy.accepted_extensions || []).map((entry) => entry.startsWith(".") ? entry.toLowerCase() : `.${entry.toLowerCase()}`);
        const invalidFile = selected.find((file) => file.size === 0 || file.size > maxBytes
            || !(policy.accepted_mimes?.includes(file.type) || extensions.includes(`.${file.name.split(".").pop().toLowerCase()}`)));
        if (invalidFile) { setLocalError(`${invalidFile.name}: ${t("Format atau ukuran berkas tidak sesuai kebijakan.")}`); return; }
        if (maxPixels) {
            setChecking(true);
            const sizes = await Promise.all(selected.map(imagePixels));
            if (!mounted.current) return;
            setChecking(false);
            const index = sizes.findIndex((pixels) => pixels != null && pixels > maxPixels);
            if (index >= 0) { setLocalError(`${selected[index].name}: ${t("Resolusi gambar melebihi batas model ini.")} (${new Intl.NumberFormat(locale).format(maxPixels)} px)`); return; }
        }
        if (!multiple) {
            requests.current.forEach((xhr) => xhr.abort());
            requests.current.clear();
        }
        const pending = selected.map((file) => ({ key: crypto.randomUUID(), file, status: "uploading", progress: 0, error: "" }));
        setRows((list) => multiple ? [...list, ...pending] : pending);
        pending.forEach((row) => { void start(row); });
    };
    const removePending = (key) => {
        const xhr = requests.current.get(key);
        requests.current.delete(key);
        xhr?.abort();
        setRows((list) => list.filter((row) => row.key !== key));
    };
    const applyLink = () => {
        const candidate = link.trim();
        const problem = publicUrlError(candidate);
        if (problem) { setLinkError(problem); return; }
        if (multiple && current().includes(candidate)) { setLinkError("Tautan ini sudah ditambahkan."); return; }
        if (full) { setLinkError(`${t("Maksimal berkas")}: ${max}`); return; }
        add(candidate);
        setLink("");
        setLinkError("");
    };
    const loadLibrary = async (page = 1) => {
        const request = ++librarySequence.current;
        setLoading(true);
        setLocalError("");
        try {
            // Generic file roles accept every stored kind, so only the role narrows that list.
            const query = new URLSearchParams({ role: input.role, page: String(page) });
            if (kind !== "file") query.set("media_type", kind);
            const [uploads, audio] = await Promise.all([
                apiRequest(`/api/media/assets?${query}`),
                kind === "audio" ? apiRequest("/api/audio") : Promise.resolve(null),
            ]);
            if (mounted.current && request === librarySequence.current) setLibrary({ ...uploads, generated: (audio?.jobs || []).filter((job) => job.status === "completed" && job.mode === "speech") });
        } catch (failure) { if (mounted.current && request === librarySequence.current) setLocalError(failure.message); }
        finally { if (mounted.current && request === librarySequence.current) setLoading(false); }
    };
    const selectOwned = async () => {
        setLocalError("");
        const request = ++librarySequence.current;
        setLoading(true);
        try {
            let asset;
            if (source.startsWith("asset:")) asset = library.assets.find((entry) => entry.id === source.slice(6));
            else if (source.startsWith("audio:")) {
                const [, jobId, index] = source.split(":");
                const data = await apiRequest(`/api/audio/${encodeURIComponent(jobId)}/reference`, { method: "POST", body: { index: Number(index) } });
                asset = data.asset;
            }
            if (!mounted.current || request !== librarySequence.current) return;
            if (!asset?.id) throw new Error(t("Referensi tidak tersedia. Pilih atau unggah ulang."));
            if (multiple && current().includes(asset.id)) throw new Error(t("Berkas ini sudah dipilih."));
            setAssets((known) => ({ ...known, [asset.id]: asset }));
            add(asset.id);
            setLibrary(null);
            setSource("");
        } catch (failure) { if (mounted.current && request === librarySequence.current) setLocalError(failure.message); }
        finally { if (mounted.current && request === librarySequence.current) setLoading(false); }
    };
    const accepted = [...(policy?.accepted_mimes || []), ...(policy?.accepted_extensions || []).map((extension) => extension.startsWith(".") ? extension : `.${extension}`)].join(",");
    const hint = policy ? [accepted, Number.isFinite(maxBytes) ? `${t("Maksimal")} ${megabytes(maxBytes, locale)} MB` : null,
        maxPixels ? `${t("Maksimal")} ${new Intl.NumberFormat(locale).format(maxPixels)} px` : null,
        multiple && Number.isFinite(max) ? `${max} ${t("berkas")}` : null].filter(Boolean).join(" · ") : t("Memuat kebijakan unggahan…");
    return <StudioField id={id} label={meta.label || input.key} error={policyError || localError || error} hint={meta.help ? `${t(meta.help)} ${hint}` : hint}>
        <div className="studio-asset-drop" onDragOver={(event) => { if (!disabled && event.dataTransfer.types.includes("Files")) event.preventDefault(); }}
            onDrop={(event) => { if (event.dataTransfer.files.length) { event.preventDefault(); void pick(event.dataTransfer.files); } }}
            onPaste={(event) => { if (event.clipboardData.files.length) { event.preventDefault(); void pick(event.clipboardData.files); } }}>
            <input id={id} type="file" multiple={multiple} accept={accepted} disabled={disabled || !policy || busy || full}
                aria-describedby={`${id}-hint`} aria-invalid={Boolean(invalid || error || localError)} onChange={(event) => { void pick(event.target.files); event.target.value = ""; }} />
            <p className="studio-help">{t(checking ? "Memeriksa resolusi gambar…" : "Pilih, seret, atau tempel berkas. Berkas hanya tersedia untuk akun Anda.")}</p>
        </div>
        {policyError && <StudioButton icon="refresh" onClick={() => setRetryPolicy((count) => count + 1)}>{t("Muat ulang kebijakan")}</StudioButton>}
        {entries.map((entry, index) => {
            const problem = itemErrors[index];
            if (typeof entry !== "string" || !uuidPattern.test(entry)) return <div className="studio-reference-preview studio-asset-link" key={`${entry}-${index}`}>
                <div><strong>{t("Tautan publik")}</strong><small><code dir="ltr">{String(entry)}</code></small>{problem && <p className="studio-field-error" role="alert">{t(problem)}</p>}</div>
                <StudioButton icon="close" disabled={disabled} aria-label={`${t("Hapus tautan")} ${index + 1}`} onClick={() => remove(index)}>{t("Hapus")}</StudioButton>
            </div>;
            const asset = assets[entry];
            const preview = !broken[entry] && ["image", "audio", "video"].includes(kind) && (asset ? asset.previewable === true && ownedMediaUrl(asset.preview_url) : `/api/media/assets/${encodeURIComponent(entry)}`);
            const hide = () => setBroken((known) => ({ ...known, [entry]: true }));
            return <div className={`studio-reference-preview studio-asset-${kind}`} key={`${entry}-${index}`}>
                {preview && kind === "image" && <img src={preview} alt={asset?.original_name || t("Pratinjau referensi")} loading="lazy" onError={hide} />}
                {preview && kind === "audio" && <audio controls src={preview} preload="none" aria-label={asset?.original_name || t("Referensi audio")} onError={hide} />}
                {preview && kind === "video" && <video controls playsInline src={preview} preload="none" aria-label={asset?.original_name || t("Referensi video")} onError={hide} />}
                <div><strong>{asset?.original_name || `${t("Berkas tersimpan")} ${index + 1}`}</strong><small>{asset?.mime || kind} · {t("Siap")}</small>{problem && <p className="studio-field-error" role="alert">{t(problem)}</p>}</div>
                <StudioButton icon="close" disabled={disabled} aria-label={`${t("Hapus referensi")} ${index + 1}`} onClick={() => remove(index)}>{t("Hapus")}</StudioButton>
            </div>;
        })}
        {rows.map((row) => <div key={row.key} className="studio-upload-row" role="status">
            <strong>{row.file.name}</strong>
            {row.status === "uploading" ? <><progress value={row.progress} max={100} aria-label={`${t("Mengunggah")} ${row.file.name}`} /><span>{row.progress}% · {t(row.progress === 100 ? "Memeriksa dan menyimpan…" : "Mengunggah…")}</span></>
                : <p className="studio-field-error" role="alert">{t(row.error)}</p>}
            <div className="studio-toolbar-actions">{row.status === "failed" && <StudioButton disabled={disabled} icon="refresh" onClick={() => { void start(row); }}>{t("Coba unggah lagi")}</StudioButton>}
                <StudioButton disabled={disabled} icon="close" onClick={() => removePending(row.key)}>{t(row.status === "uploading" ? "Batalkan unggahan" : "Hapus")}</StudioButton></div>
        </div>)}
        <StudioButton icon="history" disabled={disabled || busy || loading || !policy || full} onClick={() => { if (library) { setLibrary(null); librarySequence.current += 1; } else void loadLibrary(); }}>
            {t(loading ? "Memuat…" : library ? "Tutup pilihan" : "Pilih dari koleksi")}
        </StudioButton>
        {library && <div className="studio-asset-library"><label htmlFor={`${id}-owned`}>{t("Referensi milik Anda")}</label>
            <select id={`${id}-owned`} value={source} disabled={disabled || loading || busy} onChange={(event) => setSource(event.target.value)}>
                <option value="">{t("Pilih referensi")}</option>
                <optgroup label={t("Unggahan")}>{(library.assets || []).map((asset) => <option key={asset.id} value={`asset:${asset.id}`}>{asset.original_name || asset.mime} · {megabytes(asset.size_bytes || 0, locale)} MB</option>)}</optgroup>
                {kind === "audio" && <optgroup label={t("Hasil Studio audio")}>{library.generated.flatMap((job) => (job.outputs || []).map((output, index) => <option key={`${job.job_id}:${index}`} value={`audio:${job.job_id}:${index}`}>{job.model} · {t("Track")} {index + 1}</option>))}</optgroup>}
            </select>
            <div className="studio-toolbar-actions"><StudioButton disabled={!source || disabled || loading || busy} onClick={() => { void selectOwned(); }}>{t("Gunakan referensi")}</StudioButton>
                {library.page > 1 && <StudioButton disabled={loading} onClick={() => { void loadLibrary(library.page - 1); }}>{t("Sebelumnya")}</StudioButton>}
                {library.page < library.last_page && <StudioButton disabled={loading} onClick={() => { void loadLibrary(library.page + 1); }}>{t("Berikutnya")}</StudioButton>}
            </div>{!library.assets?.length && !library.generated.length && <p className="studio-help">{t("Belum ada referensi tersimpan. Unggah berkas terlebih dahulu.")}</p>}
        </div>}
        {acceptsUrl && <details className="studio-asset-link-entry">
            <summary>{t("Gunakan tautan publik sebagai gantinya")}</summary>
            <label htmlFor={`${id}-link`}>{t("Tautan HTTPS publik")}</label>
            <div className="studio-asset-link-row">
                <input id={`${id}-link`} type="text" inputMode="url" dir="ltr" spellCheck={false} autoComplete="off" placeholder="https://" maxLength={2048} value={link} disabled={disabled || full}
                    aria-invalid={Boolean(linkError)} aria-describedby={`${id}-link-help${linkError ? ` ${id}-link-error` : ""}`}
                    onChange={(event) => { setLink(event.target.value); setLinkError(""); }}
                    onKeyDown={(event) => { if (event.key === "Enter" && !event.nativeEvent.isComposing) { event.preventDefault(); applyLink(); } }} />
                <StudioButton disabled={disabled || full || !link.trim()} onClick={applyLink}>{t("Gunakan tautan")}</StudioButton>
            </div>
            {linkError && <p id={`${id}-link-error`} className="studio-field-error" role="alert">{t(linkError)}</p>}
            <p id={`${id}-link-help`} className="studio-help">{t("Penyedia model mengambil tautan ini secara langsung. Browser tidak membukanya; gunakan hanya berkas yang boleh diakses publik.")}</p>
        </details>}
    </StudioField>;
}

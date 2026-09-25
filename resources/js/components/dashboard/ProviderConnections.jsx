import { useEffect, useRef, useState } from "react";
import { useLocale } from "../../contexts/LocaleContext";
import { apiRequest, formatDateTime } from "../../lib/api";
import MediaActionDialog from "../MediaActionDialog";
import { LoadingState } from "./AsyncState";

const defaultEndpoints = {
    openai: "https://api.openai.com/v1",
    anthropic: "https://api.anthropic.com/v1",
    fal: "https://fal.run",
    kinovi: "https://kinovi.ai/api/v1",
    runware: "https://api.runware.ai/v1",
};
const healthStates = {
    discovered: ["neutral", "Katalog terdokumentasi"],
    online: ["good", "Terhubung"],
    healthy: ["good", "Terhubung"],
    active: ["good", "Terhubung"],
    degraded: ["warn", "Bermasalah"],
    offline: ["bad", "Gagal terhubung"],
    failed: ["bad", "Gagal terhubung"],
    error: ["bad", "Gagal terhubung"],
    unavailable: ["bad", "Gagal terhubung"],
};
const idleForm = { busy: false, error: "", fields: {} };

export default function ProviderConnections({ providers, focusId = null, loading, error, hasData, onRefresh, autoOpenNew = false, onClose }) {
    const { t, locale } = useLocale();
    const [draft, setDraft] = useState(null);
    const [formState, setFormState] = useState(idleForm);
    const [operations, setOperations] = useState({});
    const [notice, setNotice] = useState("");
    const [deletion, setDeletion] = useState(null);
    const deletionInFlight = useRef(false);
    const deletionCompleted = useRef(false);
    const providerHeading = useRef(null);
    const nameInput = useRef(null);
    const editorTrigger = useRef(null);
    const environment = draft?.configuration_source === "environment";
    const connectionChanged = draft?.id && !environment && (
        draft.protocol !== draft.original_protocol ||
        draft.base_url.trim() !== draft.original_base_url
    );
    const keyRequired = !environment && (!draft?.has_api_key || !!connectionChanged);
    const editorId = draft ? (draft.id ?? "new") : null;

    useEffect(() => {
        if (editorId !== null) nameInput.current?.focus();
        else editorTrigger.current?.focus();
    }, [editorId]);

    useEffect(() => {
        if (!deletion && deletionCompleted.current) {
            deletionCompleted.current = false;
            providerHeading.current?.focus({ preventScroll: true });
        }
    }, [deletion]);

    // The list page's "Tambah penyedia" lands here with the new-provider form
    // already open — one click, not two.
    useEffect(() => {
        if (autoOpenNew) openEditor(null);
    }, [autoOpenNew]);

    const openEditor = (provider, event) => {
        editorTrigger.current = event?.currentTarget ?? null;
        setFormState(idleForm);
        setNotice("");
        if (provider) setOperations((current) => ({ ...current, [provider.id]: {} }));
        setDraft({
            id: provider?.id ?? null,
            name: provider?.name || "",
            protocol: provider?.protocol || "openai",
            base_url: provider ? (provider.base_url || "") : defaultEndpoints.openai,
            api_key: "",
            api_version: provider?.api_version || "2023-06-01",
            is_enabled: provider ? !!provider.is_enabled : true,
            has_api_key: !!provider?.has_api_key,
            configuration_source: provider?.configuration_source || "admin",
            original_protocol: provider?.protocol || "openai",
            original_base_url: provider?.base_url || "",
        });
    };

    const closeEditor = () => {
        setDraft(null);
        setFormState(idleForm);
        onClose?.();
    };

    const changeField = (field, value) => {
        setDraft((current) => ({
            ...current,
            [field]: value,
            // fal always uses its fixed endpoint; Runware fills its official
            // endpoint unless the admin already typed a custom URL.
            ...(field === "protocol" && (value === "fal" || current.base_url === defaultEndpoints[current.protocol]
                || (value === "runware" && (!current.base_url.trim() || Object.values(defaultEndpoints).includes(current.base_url.trim()))))
                ? { base_url: defaultEndpoints[value] }
                : {}),
        }));
        setFormState((current) => ({
            ...current,
            error: "",
            fields: { ...current.fields, [field]: undefined, ...(field === "protocol" ? { base_url: undefined } : {}) },
        }));
    };

    const fieldError = (field) => {
        const message = formState.fields[field];
        return message ? (
            <p id={`provider-${field}-error`} className="mt-1 text-xs text-red-700 dark:text-red-300">
                {t(Array.isArray(message) ? message[0] : message)}
            </p>
        ) : null;
    };

    const saveProvider = async (event) => {
        event.preventDefault();
        if (formState.busy) return;
        const fields = {};
        if (!draft.name.trim()) fields.name = t("Nama provider wajib diisi.");
        if (keyRequired && !draft.api_key.trim()) {
            fields.api_key = t(connectionChanged
                ? "Masukkan API key baru saat mengganti URL atau protokol."
                : "Masukkan API key untuk koneksi ini.");
        }
        if (Object.keys(fields).length) {
            setFormState({ busy: false, error: t("Periksa kolom yang ditandai."), fields });
            return;
        }
        const body = { name: draft.name.trim(), is_enabled: draft.is_enabled };
        if (!environment) {
            body.protocol = draft.protocol;
            body.base_url = draft.base_url.trim();
            if (draft.protocol === "anthropic") body.api_version = draft.api_version.trim();
            if (draft.api_key.trim()) body.api_key = draft.api_key;
        }
        setFormState({ busy: true, error: "", fields: {} });
        try {
            await apiRequest(draft.id ? `/api/admin/ai/providers/${draft.id}` : "/api/admin/ai/providers", {
                method: draft.id ? "PATCH" : "POST",
                body,
            });
            setDraft((current) => current ? { ...current, api_key: "" } : null);
            await onRefresh();
            closeEditor();
            setNotice(t("Provider disimpan. Periksa koneksi sebelum menyinkronkan model."));
        } catch (requestError) {
            setFormState({
                busy: false,
                error: t(requestError.message || "Provider tidak dapat disimpan. Periksa isian lalu coba lagi."),
                fields: requestError.details?.errors || {},
            });
        }
    };

    const runOperation = async (provider, action) => {
        if (operations[provider.id]?.action) return;
        setOperations((current) => ({ ...current, [provider.id]: { action, error: "", success: "" } }));
        try {
            const result = await apiRequest(`/api/admin/ai/providers/${provider.id}${action === "toggle" ? "" : `/${action}`}`, {
                method: action === "toggle" ? "PATCH" : "POST",
                ...(action === "toggle" ? { body: { is_enabled: !provider.is_enabled } } : action === "sync" ? { body: { publish: false } } : {}),
            });
            let success;
            if (action === "check") {
                success = `${t(result.message || "Pemeriksaan selesai.")} ${t("Pemeriksaan koneksi tidak mengimpor atau mempublikasikan model.")}`;
            } else if (action === "sync") {
                success = `${t("Metadata provider disinkronkan tanpa publikasi otomatis.")} ${Number(result.synced_models).toLocaleString(locale)} ${t("model dilaporkan.")}`;
            } else {
                success = t(provider.is_enabled ? "Koneksi dinonaktifkan." : "Koneksi diaktifkan.");
            }
            await onRefresh();
            setOperations((current) => ({ ...current, [provider.id]: { action: "", error: "", success } }));
        } catch (requestError) {
            await onRefresh();
            setOperations((current) => ({
                ...current,
                [provider.id]: {
                    action: "",
                    success: "",
                    error: t(requestError.message || "Operasi provider gagal."),
                },
            }));
        }
    };

    const prepareDeletion = (provider) => {
        if (deletionInFlight.current || operations[provider.id]?.action || draft?.id === provider.id) return;
        const modelCount = Number.isInteger(provider.models_count) && provider.models_count >= 0 ? provider.models_count : null;
        setNotice("");
        setDeletion({
            id: provider.id,
            name: provider.name || provider.slug,
            modelCount,
            environment: provider.configuration_source === "environment",
            busy: false,
            needsReview: modelCount === null,
            error: modelCount === null ? t("Jumlah model belum tersedia. Tutup dialog, muat ulang katalog, lalu konfirmasi ulang.") : "",
        });
    };

    const deleteProvider = async () => {
        if (!deletion || deletionInFlight.current || deletion.needsReview) return;
        const target = deletion;
        deletionInFlight.current = true;
        setDeletion((current) => ({ ...current, busy: true, error: "" }));
        try {
            await apiRequest(`/api/admin/ai/providers/${target.id}`, {
                method: "DELETE",
                body: { delete_models: true, expected_model_count: target.modelCount },
            });
        } catch (requestError) {
            const code = requestError.details?.code;
            const needsReview = code === "provider_models_changed" || requestError.status === 404;
            const message = code === "provider_models_changed"
                ? "Jumlah model berubah. Tutup dialog, muat ulang katalog, lalu periksa dan konfirmasikan jumlah terbaru."
                : code === "provider_media_busy"
                  ? "Provider masih memiliki pekerjaan media aktif atau kredit yang dicadangkan. Selesaikan pekerjaan atau rekonsiliasi tagihannya sebelum menghapus."
                  : requestError.status === 404
                    ? "Provider tidak lagi tersedia. Tutup dialog dan muat ulang katalog."
                    : [401, 419].includes(requestError.status)
                      ? "Sesi Anda telah berakhir. Masuk kembali sebelum menghapus."
                      : requestError.status === 403
                        ? "Anda tidak memiliki izin untuk menghapus provider."
                        : "Penghapusan provider belum dikonfirmasi. Muat ulang katalog sebelum mencoba lagi.";
            setDeletion((current) => ({ ...current, busy: false, error: t(message), needsReview }));
            deletionInFlight.current = false;
            return;
        }
        setNotice(`${t("Provider dihapus.")} ${target.name}. ${t("Riwayat penggunaan, tagihan, dan hasil generasi tetap disimpan.")}`);
        try {
            await onRefresh();
        } catch {
            setNotice(t("Provider dihapus. Muat ulang katalog untuk memperbarui daftar."));
        } finally {
            deletionInFlight.current = false;
            deletionCompleted.current = true;
            setDeletion(null);
        }
    };

    return (
        <section className="ui-card" aria-labelledby="provider-title" data-provider-connections>
            {!autoOpenNew && (<div className="ui-card-header flex-wrap">
                <div className="min-w-0 flex-1 basis-64">
                    <h2 ref={providerHeading} id="provider-title" tabIndex={-1} className="ui-section-title">{t("Koneksi provider AI")}</h2>
                    <p className="mt-1 max-w-prose text-xs leading-5 text-slate-600 dark:text-slate-400">
                        {t("Hubungkan beberapa provider sekaligus. Setiap model menggunakan koneksi yang Anda pilih; API key tersimpan tidak pernah ditampilkan kembali.")}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <button type="button" className="ui-btn-secondary min-h-11 disabled:cursor-not-allowed disabled:opacity-60" onClick={() => onRefresh()} disabled={loading}>
                        {loading ? t("Memuat ulang…") : t("Refresh")}
                    </button>
                    <button type="button" className="ui-btn-primary min-h-11 px-4 text-xs" onClick={(event) => openEditor(null, event)} disabled={!!draft || (!hasData && loading)}>
                        {t("Provider baru")}
                    </button>
                </div>
            </div>)}

            {!autoOpenNew && notice && <p role="status" className="border-b border-emerald-500/20 bg-emerald-500/5 px-4 py-3 text-xs text-emerald-800 dark:text-emerald-300">{notice}</p>}
            {!autoOpenNew && error && <p role="alert" className="px-4 py-3 text-xs text-red-700 dark:text-red-300">{t(error)} {t("Muat ulang untuk mencoba mengambil katalog lagi.")}</p>}

            {draft && (
                <form key={draft.id ?? "new"} data-provider-editor aria-labelledby="provider-editor-title" onSubmit={saveProvider} className="space-y-4 border-b border-slate-200 bg-slate-50/60 p-4 dark:border-white/10 dark:bg-white/[0.02]">
                    <h3 id="provider-editor-title" className="ui-section-title">{draft.id ? t("Edit koneksi provider") : t("Provider baru")}</h3>
                    {environment && <p className="max-w-prose text-xs leading-5 text-slate-600 dark:text-slate-400">{t("Koneksi ini dikelola melalui konfigurasi server. URL dan API key tidak ditampilkan atau diubah di sini. Tambahkan provider baru untuk koneksi terpisah.")}</p>}
                    <fieldset disabled={formState.busy} className="grid min-w-0 gap-4 sm:grid-cols-2">
                        <div className={environment ? "sm:col-span-2" : ""}>
                            <label htmlFor="provider-name" className="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-200">{t("Nama provider")}</label>
                            <input ref={nameInput} id="provider-name" name="provider_name" className="ui-input min-h-11" value={draft.name} onChange={(event) => changeField("name", event.target.value)} maxLength={120} required aria-invalid={!!formState.fields.name} aria-describedby={formState.fields.name ? "provider-name-error" : undefined} />
                            {fieldError("name")}
                        </div>
                        {!environment && (
                            <>
                                <div>
                                    <label htmlFor="provider-protocol" className="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-200">{t("Protokol")}</label>
                                    <select id="provider-protocol" name="protocol" className="ui-input min-h-11" value={draft.protocol} onChange={(event) => changeField("protocol", event.target.value)} aria-invalid={!!formState.fields.protocol} aria-describedby={formState.fields.protocol ? "provider-protocol-error" : undefined}>
                                        <option value="openai">{t("Kompatibel OpenAI")}</option>
                                        <option value="anthropic">{t("Kompatibel Anthropic")}</option>
                                        <option value="fal">{t("fal (gambar, video, audio & teks)")}</option>
                                        <option value="kinovi">{t("Kinovi (gambar, video, audio & avatar)")}</option>
                                        <option value="runware">{t("Runware (gambar, video, audio & 3D)")}</option>
                                    </select>
                                    {fieldError("protocol")}
                                </div>
                                <div className="sm:col-span-2">
                                    <label htmlFor="provider-base-url" className="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-200">{t("URL dasar HTTPS")}</label>
                                    <input id="provider-base-url" name="base_url" type="url" inputMode="url" autoComplete="off" autoCapitalize="none" spellCheck={false} className="ui-input min-h-11" value={draft.base_url} onChange={(event) => changeField("base_url", event.target.value)} readOnly={draft.protocol === "fal"} maxLength={2048} required aria-invalid={!!formState.fields.base_url} aria-describedby={`provider-url-help${formState.fields.base_url ? " provider-base_url-error" : ""}`} />
                                    <p id="provider-url-help" className="mt-1 text-xs leading-5 text-slate-600 dark:text-slate-400">{t(draft.protocol === "fal" ? "Endpoint resmi fal adalah https://fal.run. Gunakan endpoint ini tanpa prefiks tambahan; API fal berbeda dari API kompatibel OpenAI." : draft.protocol === "runware" ? "Endpoint resmi Runware adalah https://api.runware.ai/v1. Host dikunci ke api.runware.ai; mock loopback hanya diizinkan di lingkungan pengembangan lokal." : "Gunakan URL HTTPS publik tanpa kredensial, query, atau fragmen. URL root menggunakan /v1; sertakan prefiks API jika berbeda.")}</p>
                                    {draft.protocol === "fal" && <p className="mt-1 text-xs leading-5 text-slate-600 dark:text-slate-400">{t("Model fal yang didukung: FLUX Schnell, FLUX.2 Pro, LongCat 480p, dan Gemini 2.5 Flash Lite. Opsi generasi mengikuti skema masing-masing model.")}</p>}
                                    {fieldError("base_url")}
                                </div>
                                <div className="sm:col-span-2">
                                    <label htmlFor="provider-api-key" className="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-200">{t("API key provider")}</label>
                                    <input id="provider-api-key" name="provider_api_key" type="password" autoComplete="new-password" autoCapitalize="none" spellCheck={false} className="ui-input min-h-11" value={draft.api_key} onChange={(event) => changeField("api_key", event.target.value)} required={keyRequired} aria-invalid={!!formState.fields.api_key} aria-describedby={`provider-key-help${draft.protocol === "fal" ? " provider-fal-key-help" : draft.protocol === "runware" ? " provider-runware-key-help" : ""}${connectionChanged ? " provider-rotation-help" : ""}${formState.fields.api_key ? " provider-api_key-error" : ""}`} />
                                    <p id="provider-key-help" className="mt-1 text-xs leading-5 text-slate-600 dark:text-slate-400">{t(draft.has_api_key ? "API key sudah tersimpan. Kosongkan untuk mempertahankannya, atau masukkan key baru untuk menggantinya." : "Masukkan API key untuk koneksi ini. Key hanya dikirim saat disimpan dan tidak dapat dibaca kembali.")}</p>
                                    {draft.protocol === "fal" && <p id="provider-fal-key-help" className="mt-1 text-xs leading-5 text-slate-600 dark:text-slate-400">{t("Gunakan key fal dengan scope API. Scope ADMIN juga dapat digunakan, tetapi tidak diperlukan.")}</p>}
                                    {draft.protocol === "runware" && <p id="provider-runware-key-help" className="mt-1 text-xs leading-5 text-slate-600 dark:text-slate-400">{t("Gunakan API key dari dashboard Runware. Key hanya dikirim ke api.runware.ai dan tidak pernah ditampilkan kembali.")}</p>}
                                    {connectionChanged && <p id="provider-rotation-help" className="mt-1 text-xs font-semibold text-amber-800 dark:text-amber-300">{t("Masukkan API key baru saat mengganti URL atau protokol.")}</p>}
                                    {fieldError("api_key")}
                                </div>
                                {draft.protocol === "anthropic" && (
                                    <div>
                                        <label htmlFor="provider-api-version" className="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-200">{t("Versi API Anthropic")}</label>
                                        <input id="provider-api-version" name="api_version" className="ui-input min-h-11" value={draft.api_version} onChange={(event) => changeField("api_version", event.target.value)} maxLength={32} required aria-invalid={!!formState.fields.api_version} aria-describedby={formState.fields.api_version ? "provider-api_version-error" : undefined} />
                                        {fieldError("api_version")}
                                    </div>
                                )}
                            </>
                        )}
                        <div className="sm:col-span-2">
                            <label className="inline-flex min-h-11 cursor-pointer items-center gap-2 text-xs font-semibold text-slate-700 dark:text-slate-200">
                                <input type="checkbox" role="switch" className="h-4 w-4 rounded border-slate-300 accent-red-600" checked={draft.is_enabled} onChange={(event) => changeField("is_enabled", event.target.checked)} aria-describedby="provider-enabled-help" />
                                {t("Koneksi aktif")}
                            </label>
                            <p id="provider-enabled-help" className="text-xs leading-5 text-slate-600 dark:text-slate-400">{t("Menonaktifkan koneksi langsung menghentikan akses ke semua model yang menggunakannya.")}</p>
                            {fieldError("is_enabled")}
                        </div>
                    </fieldset>
                    {formState.error && <p role="alert" className="text-xs text-red-700 dark:text-red-300">{formState.error}</p>}
                    <div className="flex flex-wrap justify-end gap-2">
                        <button type="button" className="ui-btn-secondary min-h-11 disabled:opacity-60" disabled={formState.busy} onClick={closeEditor}>{t("Batal")}</button>
                        <button type="submit" className="ui-btn-primary min-h-11 px-4 text-xs" disabled={formState.busy}>{formState.busy ? t("Menyimpan…") : t("Simpan provider")}</button>
                    </div>
                </form>
            )}

            {!autoOpenNew && (loading && !hasData ? (
                <div className="p-4"><LoadingState label={t("Memuat kesehatan penyedia…")} /></div>
            ) : !providers.length && !error ? (
                <div className="px-4 py-8 text-center">
                    <h3 className="ui-section-title">{t("Belum ada koneksi provider")}</h3>
                    <p className="mx-auto mt-2 max-w-prose text-xs leading-5 text-slate-600 dark:text-slate-400">{t("Tambahkan koneksi provider, periksa aksesnya, lalu impor dan tinjau model dari halaman detail.")}</p>
                </div>
            ) : (
                <ul className="divide-y divide-slate-200 dark:divide-white/10">
                    {(focusId === null ? providers : providers.filter((provider) => provider.id === focusId)).map((provider) => {
                        const operation = operations[provider.id] || {};
                        const busy = !!operation.action || (deletion?.id === provider.id && deletion.busy);
                        const editing = draft?.id === provider.id;
                        const [tone, health] = provider.is_enabled
                            ? (healthStates[provider.status] || ["neutral", "Belum diperiksa"])
                            : ["neutral", "Nonaktif"];
                        const capabilities = Array.isArray(provider.capabilities)
                            ? provider.capabilities.join(", ")
                            : Object.keys(provider.capabilities || {}).join(", ");
                        return (
                            <li key={provider.id} data-provider-id={provider.id} aria-busy={busy} className="min-w-0 space-y-3 p-4">
                                <div className="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <h3 className="min-w-0 max-w-full break-all text-sm font-semibold text-slate-900 dark:text-white">{provider.name || provider.slug}</h3>
                                            <span className={`ui-status ui-status-${tone}`}>{t(health)}</span>
                                        </div>
                                        <p className="mt-1 text-xs text-slate-600 dark:text-slate-400">{t(provider.protocol === "fal" ? "fal (gambar, video, audio & teks)" : provider.protocol === "kinovi" ? "Kinovi (gambar, video, audio & avatar)" : provider.protocol === "runware" ? "Runware (gambar, video, audio & 3D)" : provider.protocol === "anthropic" ? "Kompatibel Anthropic" : "Kompatibel OpenAI")} <span aria-hidden="true">/</span> <span className="break-all">{provider.slug}</span></p>
                                        <p className="mt-1 break-all text-xs leading-5 text-slate-600 dark:text-slate-400">{provider.configuration_source === "environment" ? t("Dikelola server — URL dan key tetap di konfigurasi server.") : provider.base_url}</p>
                                        <p className="mt-1 text-xs text-slate-600 dark:text-slate-400">{t(provider.configuration_source === "environment" ? "Kredensial dikelola server" : provider.has_api_key ? "API key tersimpan" : "API key belum tersimpan")}</p>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <button type="button" className="ui-btn-secondary min-h-11 disabled:cursor-not-allowed disabled:opacity-60" disabled={busy || !!draft} onClick={(event) => openEditor(provider, event)}>{t("Edit")}</button>
                                        <button type="button" className="ui-btn-secondary min-h-11 disabled:cursor-not-allowed disabled:opacity-60" disabled={busy || editing || !provider.is_enabled} onClick={() => runOperation(provider, "check")}>{operation.action === "check" ? t("Memeriksa…") : t(provider.protocol === "kinovi" ? "Periksa katalog terdokumentasi" : provider.protocol === "runware" ? "Periksa akun & saldo" : "Periksa koneksi")}</button>
                                        {!["fal", "runware"].includes(provider.protocol) && <button type="button" className="ui-btn-secondary min-h-11 disabled:cursor-not-allowed disabled:opacity-60" disabled={busy || editing || !provider.is_enabled} onClick={() => runOperation(provider, "sync")}>{operation.action === "sync" ? t("Menyinkronkan…") : t("Sinkronkan metadata (draf)")}</button>}
                                        <button type="button" className="ui-btn-secondary min-h-11 disabled:cursor-not-allowed disabled:opacity-60" disabled={busy || editing} onClick={() => runOperation(provider, "toggle")}>{operation.action === "toggle" ? t("Menyimpan…") : t(provider.is_enabled ? "Nonaktifkan koneksi" : "Aktifkan koneksi")}</button>
                                        <button type="button" className="ui-btn-secondary min-h-11 text-red-700 disabled:cursor-not-allowed disabled:opacity-60 dark:text-red-300" disabled={busy || editing} onClick={() => prepareDeletion(provider)} aria-label={`${t("Hapus provider")} ${provider.name || provider.slug}`}>{t("Hapus provider")}</button>
                                    </div>
                                </div>
                                <dl className="grid gap-2 text-xs sm:grid-cols-2">
                                    <div><dt className="text-slate-500 dark:text-slate-400">{t("Pemeriksaan terakhir")}</dt><dd className="mt-1 text-slate-700 dark:text-slate-200">{provider.last_checked_at ? formatDateTime(provider.last_checked_at) : t("Belum diperiksa")}</dd></div>
                                    <div><dt className="text-slate-500 dark:text-slate-400">{t("Kemampuan")}</dt><dd className="mt-1 break-words text-slate-700 dark:text-slate-200">{capabilities || t("Belum dilaporkan")}</dd></div>
                                    <div><dt className="text-slate-500 dark:text-slate-400">{t("Jumlah model")}</dt><dd className="mt-1 tabular-nums text-slate-700 dark:text-slate-200">{Number.isInteger(provider.models_count) ? provider.models_count.toLocaleString(locale) : t("Belum tersedia")}</dd></div>
                                </dl>
                                {provider.verification?.catalog_source === "static_documentation" && <p className="text-xs leading-5 text-slate-600 dark:text-slate-400">{t("Katalog dari dokumentasi; autentikasi dan generasi belum diverifikasi.")}</p>}
                                {provider.verification?.authenticated && !provider.verification?.generation_verified && <p className="text-xs leading-5 text-slate-600 dark:text-slate-400">{t("Kredensial terverifikasi. Pemeriksaan ini bukan pengujian generasi model.")}</p>}
                                {!provider.is_enabled && <p className="text-xs leading-5 text-slate-600 dark:text-slate-400">{t("Aktifkan koneksi untuk memeriksa atau menyinkronkan model. Model provider ini tidak dapat digunakan selama koneksi nonaktif.")}</p>}
                                {provider.protocol === "fal" && <p className="text-xs leading-5 text-slate-600 dark:text-slate-400">{t("Gunakan impor bertahap pada tab Model & Harga untuk mengambil schema fal. Publikasi selalu memerlukan tinjauan terpisah.")}</p>}
                                {provider.protocol === "runware" && <p className="text-xs leading-5 text-slate-600 dark:text-slate-400">{t("Impor model per halaman dari katalog publik Runware pada tab Model & Harga. Publikasi selalu memerlukan tinjauan harga terpisah; saldo akun hanya terlihat oleh admin.")}</p>}
                                {provider.last_error && !operation.error && <p role="alert" className="break-words text-xs leading-5 text-red-700 dark:text-red-300">{provider.last_error}</p>}
                                {operation.error && <div role="alert" className="rounded-lg border border-red-500/20 bg-red-500/5 p-3 text-xs leading-5 text-red-700 dark:text-red-300"><p>{operation.error}</p><p>{t(provider.configuration_source === "environment" ? "Periksa konfigurasi koneksi di server, lalu coba lagi. Tidak ada permintaan generasi yang dikirim." : "Periksa URL, protokol, dan API key melalui Edit, lalu coba lagi. Tidak ada permintaan generasi yang dikirim.")}</p></div>}
                                {operation.success && <p role="status" className="text-xs text-emerald-800 dark:text-emerald-300">{operation.success}</p>}
                            </li>
                        );
                    })}
                </ul>
            ))}
            {!autoOpenNew && deletion && <MediaActionDialog
                title={t("Hapus provider?")}
                description={t("Provider ini, seluruh modelnya, dan tarif PAYG terkait akan dihapus. Riwayat penggunaan, tagihan, dan hasil generasi tetap disimpan. Tindakan ini tidak dapat dibatalkan.")}
                closeLabel={t("Batal")}
                confirmLabel={`${t("Hapus provider")} · ${deletion.modelCount?.toLocaleString(locale) ?? "—"} ${t("model")}`}
                busyLabel={t("Menghapus…")}
                busy={deletion.busy}
                confirmDisabled={deletion.needsReview}
                error={deletion.error}
                onConfirm={deleteProvider}
                onClose={() => { if (!deletionInFlight.current) setDeletion(null); }}
            >
                <dl className="space-y-3 text-sm">
                    <div><dt className="text-slate-500 dark:text-slate-400">{t("Nama provider")}</dt><dd className="mt-1 break-words font-semibold">{deletion.name}</dd></div>
                    <div><dt className="text-slate-500 dark:text-slate-400">{t("ID provider")}</dt><dd className="mt-1 font-mono">#{deletion.id}</dd></div>
                    <div><dt className="text-slate-500 dark:text-slate-400">{t("Jumlah model yang dihapus")}</dt><dd className="mt-1 font-semibold tabular-nums">{deletion.modelCount?.toLocaleString(locale) ?? t("Belum tersedia")}</dd></div>
                </dl>
                <p className="mt-4 text-sm leading-6 text-slate-600 dark:text-slate-300">{t("Pekerjaan media aktif atau kredit yang masih dicadangkan akan membatalkan seluruh penghapusan.")}</p>
                {deletion.environment && <p className="mt-3 text-sm leading-6 text-slate-600 dark:text-slate-300">{t("Hanya koneksi tersimpan yang dihapus. Konfigurasi dan kredensial server tidak diubah.")}</p>}
            </MediaActionDialog>}
        </section>
    );
}

import { useCallback, useEffect, useId, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { useLocation, useNavigate } from "react-router-dom";
import { useAuth } from "../../contexts/AuthContext";
import { useLocale } from "../../contexts/LocaleContext";
import { apiRequest } from "../../lib/api";
import "./dashboard-search.css";

// Legacy studio paths stay valid: they redirect into /studio with their query.
const DESTINATIONS = new Set([
    "/dashboard", "/chat", "/library", "/templates", "/studio", "/generate-image", "/video", "/audio", "/avatar", "/3d", "/media",
    "/downloads", "/converter", "/notifications", "/token-usage", "/deposit", "/referral", "/bantuan", "/profile",
    "/admin/overview", "/admin/users", "/admin/token-usage", "/admin/operations", "/admin/content", "/admin/ai", "/admin/system", "/admin/settings",
]);
const RESULT_TYPES = new Set(["destination", "model", "conversation", "image", "video", "audio", "avatar", "model3d", "download", "convert", "template"]);
const RESULT_ICONS = { avatar: "video", model3d: "model" };

function SearchIcon({ kind = "search" }) {
    const paths = {
        search: <><circle cx="10.5" cy="10.5" r="6.5" /><path d="m16 16 4.5 4.5" /></>,
        close: <path d="m6 6 12 12M6 18 18 6" />,
        destination: <><rect x="3.5" y="3.5" width="17" height="17" rx="3" /><path d="M3.5 9h17M9 9v11.5" /></>,
        model: <><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z" /><path d="m4 7.5 8 4.5 8-4.5M12 12v9" /></>,
        conversation: <path d="M20 11.5a8 8 0 0 1-8 8H5l-3 2 1.6-5A8 8 0 1 1 20 11.5Z" />,
        image: <><rect x="3" y="3" width="18" height="18" rx="3" /><circle cx="8.5" cy="8.5" r="1.5" /><path d="m3 16 5-5 5 5 3-3 5 5" /></>,
        video: <><rect x="3" y="5" width="18" height="14" rx="3" /><path d="m10 9 5 3-5 3V9Z" /></>,
        audio: <><path d="M5 9v6M9 5v14M13 8v8M17 3v18M21 9v6" /></>,
        download: <><path d="M12 3v12m-5-5 5 5 5-5M4 16v4h16v-4" /></>,
        convert: <><path d="M4 8h15m-4-4 4 4-4 4M20 16H5m4-4-4 4 4 4" /></>,
        template: <><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9l-6-6Z" /><path d="M14 3v6h6M8 13h8M8 17h5" /></>,
        enter: <path d="M20 5v8H5m5-5-5 5 5 5" />,
    };
    return <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">{paths[kind] || paths.search}</svg>;
}

function safeDestination(value, isAdmin) {
    if (typeof value !== "string" || !value.startsWith("/") || value.startsWith("//") || /[\\\u0000-\u001f\u007f]/.test(value)) return null;
    try {
        const parsed = new URL(value, window.location.origin);
        const path = parsed.pathname.startsWith("/en/") ? parsed.pathname.slice(3) : parsed.pathname;
        if (parsed.origin !== window.location.origin || !DESTINATIONS.has(path) || (!isAdmin && path.startsWith("/admin/"))) return null;
        return `${path}${parsed.search}${parsed.hash}`;
    } catch {
        return null;
    }
}

function searchGroups(data, isAdmin) {
    if (!Array.isArray(data?.groups)) throw new Error("Invalid search response");
    let count = 0;
    const seen = new Set();
    return data.groups.slice(0, 40).flatMap((group) => {
        if (typeof group?.id !== "string" || typeof group.label !== "string" || !Array.isArray(group.results)) return [];
        const results = group.results.slice(0, 6).flatMap((result) => {
            const url = safeDestination(result?.url, isAdmin);
            if (!url || !RESULT_TYPES.has(result.type) || typeof result.id !== "string" || typeof result.title !== "string" || typeof result.description !== "string" || seen.has(result.id) || count >= 40) return [];
            seen.add(result.id);
            return [{ ...result, url, index: count++ }];
        });
        return results.length ? [{ id: group.id, label: group.label, results }] : [];
    });
}

function searchError(error) {
    if (error?.status === 401 || error?.status === 419) return "Sesi Anda telah berakhir. Masuk kembali untuk melanjutkan.";
    if (error?.status === 403) return "Pencarian tidak tersedia untuk akses akun ini. Periksa akses akun atau coba lagi.";
    if (error?.status === 422) return "Gunakan maksimal 120 karakter untuk mencari.";
    if (error?.status === 429) return "Terlalu banyak pencarian. Tunggu sebentar, lalu coba lagi.";
    return "Pencarian belum dapat dimuat. Periksa koneksi Anda dan coba lagi.";
}

function SearchDialog({ onClose, triggerRef, isAdmin }) {
    const { t, localizedPath } = useLocale();
    const navigate = useNavigate();
    const dialogRef = useRef(null);
    const inputRef = useRef(null);
    const requestId = useRef(0);
    const prefix = useId();
    const [search, setSearch] = useState("");
    const [revision, setRevision] = useState(0);
    const [activeIndex, setActiveIndex] = useState(0);
    const [snapshot, setSnapshot] = useState({ query: null, groups: [], loading: true, error: null });
    const query = search.trim();
    const busy = snapshot.loading || snapshot.query !== query;
    const groups = busy || snapshot.error ? [] : snapshot.groups;
    const results = useMemo(() => groups.flatMap((group) => group.results), [groups]);
    const activeResult = results[activeIndex];
    const loginRequired = snapshot.error?.status === 401 || snapshot.error?.status === 419;

    useEffect(() => {
        const dialog = dialogRef.current;
        const previousFocus = document.activeElement;
        const previousOverflow = document.body.style.overflow;
        dialog.showModal();
        document.body.style.overflow = "hidden";
        inputRef.current?.focus({ preventScroll: true });
        return () => {
            dialog.close();
            document.body.style.overflow = previousOverflow;
            const restore = previousFocus instanceof HTMLElement && previousFocus.isConnected ? previousFocus : triggerRef.current;
            restore?.focus({ preventScroll: true });
        };
    }, [triggerRef]);

    useEffect(() => {
        const controller = new AbortController();
        const currentRequest = ++requestId.current;
        setSnapshot({ query, groups: [], loading: true, error: null });
        setActiveIndex(0);
        const timer = window.setTimeout(async () => {
            try {
                const params = new URLSearchParams({ q: query });
                const data = await apiRequest(`/api/dashboard/search?${params}`, { signal: controller.signal });
                if (controller.signal.aborted || currentRequest !== requestId.current) return;
                if (data?.query !== query) throw new Error("Mismatched search response");
                setSnapshot({ query, groups: searchGroups(data, isAdmin), loading: false, error: null });
            } catch (error) {
                if (!controller.signal.aborted && currentRequest === requestId.current) {
                    setSnapshot({ query, groups: [], loading: false, error });
                }
            }
        }, query ? 250 : 0);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [query, revision, isAdmin]);

    useEffect(() => {
        if (activeResult) document.getElementById(`${prefix}-result-${activeResult.index}`)?.scrollIntoView({ block: "nearest" });
    }, [activeResult, prefix]);

    const activate = (result) => {
        const destination = safeDestination(result?.url, isAdmin);
        if (!destination) return;
        onClose();
        navigate(localizedPath(destination));
    };
    const changeSearch = (value) => {
        setSearch(value);
        setActiveIndex(0);
    };
    const clearSearch = () => {
        changeSearch("");
        inputRef.current?.focus();
    };
    const handleKeys = (event) => {
        if (event.nativeEvent.isComposing) return;
        if ((event.key === "ArrowDown" || event.key === "ArrowUp") && results.length) {
            event.preventDefault();
            setActiveIndex((current) => (current + (event.key === "ArrowDown" ? 1 : -1) + results.length) % results.length);
        } else if (event.key === "Enter" && activeResult) {
            event.preventDefault();
            activate(activeResult);
        }
    };
    const status = busy ? t("Mencari…") : snapshot.error ? t("Pencarian belum dapat ditampilkan.")
        : t(":count hasil tersedia.").replace(":count", String(results.length));

    return createPortal(
        <dialog
            ref={dialogRef}
            className="dashboard-search-dialog"
            data-dashboard-search="true"
            aria-labelledby={`${prefix}-title`}
            aria-describedby={`${prefix}-help`}
            onCancel={(event) => { event.preventDefault(); event.stopPropagation(); onClose(); }}
            onClick={(event) => { if (event.target === event.currentTarget) onClose(); }}
        >
            <div className="dashboard-search-surface">
                <div className="dashboard-search-heading">
                    <h2 id={`${prefix}-title`}>{t("Cari di workspace")}</h2>
                    <button type="button" className="dashboard-search-close" aria-label={t("Tutup pencarian")} onClick={onClose}><SearchIcon kind="close" /></button>
                </div>
                <div className="dashboard-search-field">
                    <SearchIcon />
                    <input
                        ref={inputRef}
                        type="text"
                        role="combobox"
                        aria-label={t("Cari halaman, model, dan karya Anda")}
                        aria-autocomplete="list"
                        aria-expanded="true"
                        aria-controls={`${prefix}-results`}
                        aria-activedescendant={activeResult ? `${prefix}-result-${activeResult.index}` : undefined}
                        aria-describedby={`${prefix}-help`}
                        autoComplete="off"
                        spellCheck={false}
                        maxLength={120}
                        value={search}
                        placeholder={t("Cari halaman, model, dan karya Anda")}
                        onChange={(event) => changeSearch(event.target.value)}
                        onKeyDown={handleKeys}
                    />
                    {search && <button type="button" className="dashboard-search-clear" aria-label={t("Hapus pencarian")} onClick={clearSearch}><SearchIcon kind="close" /></button>}
                </div>
                <p id={`${prefix}-help`} className="dashboard-search-help">{t("Hanya halaman yang dapat Anda akses dan karya milik Anda.")}</p>
                <p className="dashboard-search-sr-only" role="status" aria-live="polite" aria-atomic="true">{status}</p>
                <div className="dashboard-search-results" id={`${prefix}-results`} role="listbox" aria-label={t("Hasil pencarian")} aria-busy={busy}>
                    {groups.map((group, groupIndex) => (
                        <div key={`${group.id}-${groupIndex}`} role="group" aria-labelledby={`${prefix}-group-${groupIndex}`} className="dashboard-search-group">
                            <h3 id={`${prefix}-group-${groupIndex}`}>{t(group.label)}</h3>
                            {group.results.map((result) => (
                                <button
                                    type="button"
                                    role="option"
                                    key={result.id}
                                    id={`${prefix}-result-${result.index}`}
                                    aria-selected={activeIndex === result.index}
                                    tabIndex={-1}
                                    className="dashboard-search-result"
                                    onMouseMove={() => setActiveIndex(result.index)}
                                    onMouseDown={(event) => event.preventDefault()}
                                    onClick={() => activate(result)}
                                >
                                    <span className="dashboard-search-kind" data-kind={result.type}><SearchIcon kind={RESULT_ICONS[result.type] || result.type} /></span>
                                    <span className="dashboard-search-result-copy">
                                        <strong>{result.type === "destination" ? t(result.title) : result.title}</strong>
                                        <span>{result.type === "destination" || result.type === "template" || result.type === "conversation" ? t(result.description) : result.description}</span>
                                    </span>
                                    <span className="dashboard-search-enter"><SearchIcon kind="enter" /></span>
                                </button>
                            ))}
                        </div>
                    ))}
                </div>
                {busy ? (
                    <div className="dashboard-search-state" aria-hidden="true">
                        <p>{t("Mencari…")}</p>
                        <div className="dashboard-search-skeleton" /><div className="dashboard-search-skeleton" /><div className="dashboard-search-skeleton" />
                    </div>
                ) : snapshot.error ? (
                    <div className="dashboard-search-state" role="alert">
                        <h3>{t("Pencarian belum dapat ditampilkan.")}</h3>
                        <p>{t(searchError(snapshot.error))}</p>
                        <button type="button" className="dashboard-search-recovery" onClick={() => {
                            if (loginRequired) { onClose(); window.location.assign(localizedPath("/login")); }
                            else setRevision((value) => value + 1);
                        }}>{loginRequired ? t("Masuk kembali") : t("Coba lagi")}</button>
                    </div>
                ) : results.length === 0 ? (
                    <div className="dashboard-search-state">
                        <h3>{query ? t("Tidak ada hasil untuk “:query”.").replace(":query", query) : t("Belum ada tujuan yang tersedia.")}</h3>
                        <p>{t("Coba nama halaman, model, judul, atau kata dari prompt Anda.")}</p>
                        {query && <button type="button" className="dashboard-search-recovery" onClick={clearSearch}>{t("Tampilkan halaman")}</button>}
                    </div>
                ) : null}
                <div className="dashboard-search-footer" aria-hidden="true">
                    <span>{t("Gunakan panah untuk memilih")}</span>
                    <span><kbd>Enter</kbd> {t("Buka")}</span>
                    <span><kbd>Esc</kbd> {t("Tutup")}</span>
                </div>
            </div>
        </dialog>,
        document.body,
    );
}

export default function DashboardSearch() {
    const { user } = useAuth();
    const { t } = useLocale();
    const location = useLocation();
    const triggerRef = useRef(null);
    const [open, setOpen] = useState(false);
    const close = useCallback(() => setOpen(false), []);
    const scope = JSON.stringify([user?.id, user?.role, user?.permissions, user?.expires_at, user?.is_expired]);
    const shortcut = /Mac|iPhone|iPad/.test(navigator.platform) ? "Cmd K" : "Ctrl K";

    useEffect(() => { setOpen(false); }, [location.pathname, location.search, user?.id]);
    useEffect(() => {
        const handleShortcut = (event) => {
            if (!user || event.defaultPrevented || event.isComposing || event.altKey || !(event.ctrlKey || event.metaKey) || event.key.toLowerCase() !== "k") return;
            const modal = document.querySelector("dialog[open]");
            if (modal && !modal.hasAttribute("data-dashboard-search")) return;
            event.preventDefault();
            setOpen((current) => !current);
        };
        window.addEventListener("keydown", handleShortcut);
        return () => window.removeEventListener("keydown", handleShortcut);
    }, [user]);

    return <>
        <button
            ref={triggerRef}
            type="button"
            className="dashboard-search-trigger"
            aria-label={t("Cari di workspace")}
            aria-haspopup="dialog"
            aria-expanded={open && Boolean(user)}
            aria-keyshortcuts="Control+k Meta+k"
            title={`${t("Cari di workspace")} (${shortcut})`}
            disabled={!user}
            onClick={() => setOpen(true)}
        >
            <SearchIcon />
            <span>{t("Cari di workspace")}</span>
            <kbd aria-hidden="true">{shortcut}</kbd>
        </button>
        {open && user && <SearchDialog key={scope} onClose={close} triggerRef={triggerRef} isAdmin={user.role === "admin"} />}
    </>;
}

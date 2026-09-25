import { useCallback, useEffect, useId, useMemo, useRef, useState } from "react";
import { useLocale } from "../../contexts/LocaleContext";
import { apiRequest } from "../../lib/api";
import { StudioButton, StudioIcon, StudioNotice, mediaError } from "./StudioUI";
import { LOCAL_CATEGORIES, PALETTE_CATEGORIES, categoryKind, defaultCategory, localModels, safeLogoUrl, startingPrice } from "./studioPalette";
import { operationLabel } from "./workspaceMedia";
import "./studio-palette.css";

function ModelLogo({ model }) {
    const [failedUrl, setFailedUrl] = useState(null);
    const url = safeLogoUrl(model.logo_url);
    const kind = model.category === "avatar" ? "avatar" : model.operations?.[0]?.output_kind;
    return <span className="sw-palette-logo" data-kind={kind} aria-hidden="true">
        {url && url !== failedUrl
            ? <img src={url} alt="" loading="lazy" referrerPolicy="no-referrer" onError={() => setFailedUrl(url)} />
            : <span>{Array.from((model.name || model.model_id).trim())[0]?.toUpperCase()}</span>}
    </span>;
}

export default function ModelPalette({ kind = "", selectedId = "", recent = [], favorites = [], onSelect, onClose }) {
    const { t, locale } = useLocale();
    const dialogRef = useRef(null);
    const inputRef = useRef(null);
    const scrollRef = useRef(null);
    const requestRef = useRef({ sequence: 0, controller: null, pending: false });
    const id = useId();
    const titleId = `${id}-title`;
    const listId = `${id}-list`;
    const [category, setCategory] = useState(() => defaultCategory(kind, recent));
    const [query, setQuery] = useState("");
    const [activeIndex, setActiveIndex] = useState(0);
    const [catalog, setCatalog] = useState({ key: null, models: [], next_cursor: null, total: null, availability: null, loading: false, loadingMore: false, error: null, errorCursor: null });
    const isLocal = LOCAL_CATEGORIES.includes(category);
    const searchKey = JSON.stringify([category, query, locale]);
    const number = useMemo(() => new Intl.NumberFormat(locale), [locale]);
    const favoriteIds = useMemo(() => new Set(favorites.map((model) => model.model_id)), [favorites]);
    const models = useMemo(() => isLocal ? localModels(category === "recent" ? recent : favorites, query) : catalog.models, [isLocal, category, recent, favorites, query, catalog.models]);
    const refreshing = !isLocal && (catalog.key !== searchKey || catalog.loading);
    const paging = !isLocal && catalog.key === searchKey && catalog.loadingMore;
    const active = models.length ? Math.min(activeIndex, models.length - 1) : -1;
    const activeId = active >= 0 && !refreshing ? `${listId}-${active}` : undefined;
    const total = isLocal ? models.length : catalog.total;
    const availability = !isLocal && catalog.key === searchKey ? catalog.availability : null;
    const error = !isLocal && catalog.key === searchKey ? catalog.error : null;
    const hasMore = !isLocal && catalog.key === searchKey && Boolean(catalog.next_cursor);

    useEffect(() => {
        const element = dialogRef.current;
        const previousFocus = document.activeElement;
        element.showModal();
        inputRef.current?.focus({ preventScroll: true });
        return () => {
            element.close();
            if (previousFocus instanceof HTMLElement && previousFocus.isConnected) previousFocus.focus({ preventScroll: true });
        };
    }, []);

    const loadPage = useCallback(async (cursor = null) => {
        const request = requestRef.current;
        if (cursor && request.pending) return;
        request.controller?.abort();
        const controller = new AbortController();
        const sequence = ++request.sequence;
        request.controller = controller;
        request.pending = true;
        setCatalog((current) => ({ ...current, key: searchKey, loading: !cursor, loadingMore: Boolean(cursor), error: null, errorCursor: null }));
        try {
            const params = new URLSearchParams({ kind: categoryKind(category), q: query, locale });
            if (cursor) params.set("cursor", cursor);
            const data = await apiRequest(`/api/media/workspace/models?${params}`, { signal: controller.signal });
            if (controller.signal.aborted || sequence !== request.sequence) return;
            setCatalog((current) => ({
                key: searchKey,
                models: cursor ? [...new Map([...current.models, ...(data.models || [])].map((model) => [model.model_id, model])).values()] : data.models || [],
                next_cursor: data.next_cursor || null,
                total: data.total ?? (cursor ? current.total : null),
                availability: data.availability || null,
                loading: false, loadingMore: false, error: null, errorCursor: null,
            }));
            if (!cursor) setActiveIndex(0);
        } catch (failure) {
            if (controller.signal.aborted || sequence !== request.sequence) return;
            setCatalog((current) => ({ ...current, ...(cursor ? {} : { models: [], next_cursor: null, total: null, availability: null }), loading: false, loadingMore: false, error: failure, errorCursor: cursor }));
        } finally {
            if (sequence === request.sequence) request.pending = false;
        }
    }, [category, query, locale, searchKey]);

    useEffect(() => {
        const request = requestRef.current;
        if (!isLocal) {
            setCatalog((current) => ({ ...current, key: searchKey, next_cursor: null, total: null, availability: null, loading: true, loadingMore: false, error: null, errorCursor: null }));
        }
        const timer = !isLocal ? setTimeout(() => { void loadPage(); }, 250) : null;
        return () => {
            clearTimeout(timer);
            request.sequence += 1;
            request.controller?.abort();
            request.pending = false;
        };
    }, [isLocal, loadPage, searchKey]);

    const resetActive = () => {
        setActiveIndex(0);
        if (scrollRef.current) scrollRef.current.scrollTop = 0;
    };
    const loadMore = () => {
        if (hasMore && !refreshing && !paging) void loadPage(catalog.next_cursor);
    };
    const onInputKeyDown = (event) => {
        if (event.nativeEvent.isComposing) return;
        if (event.key === "Escape") {
            event.preventDefault();
            event.stopPropagation();
            onClose();
            return;
        }
        if (event.key === "Enter") {
            event.preventDefault();
            if (active >= 0 && !refreshing) onSelect(models[active]);
            return;
        }
        let next;
        if (event.key === "ArrowDown") next = active + 1;
        else if (event.key === "ArrowUp") next = active - 1;
        else if (event.key === "PageDown") next = active + 5;
        else if (event.key === "PageUp") next = active - 5;
        else if ((event.ctrlKey || event.metaKey) && event.key === "Home") next = 0;
        else if ((event.ctrlKey || event.metaKey) && event.key === "End") next = models.length - 1;
        else return;
        event.preventDefault();
        event.stopPropagation();
        if (active < 0 || refreshing) return;
        next = Math.max(0, Math.min(models.length - 1, next));
        setActiveIndex(next);
        document.getElementById(`${listId}-${next}`)?.scrollIntoView({ block: "nearest" });
        if (event.key === "ArrowDown" && next === models.length - 1) loadMore();
    };
    const emptyMessage = query.trim() ? "Tidak ada model yang cocok. Coba kata lain atau kategori Semua."
        : category === "recent" ? "Belum ada model yang dipakai."
            : category === "favorites" ? "Belum ada favorit. Tandai model dengan bintang di panel Request."
                : "Tidak ada model yang cocok. Coba kata lain atau kategori Semua.";

    return <dialog
        ref={dialogRef}
        className="media-studio-palette sw-palette"
        aria-labelledby={titleId}
        onCancel={(event) => { event.preventDefault(); event.stopPropagation(); onClose(); }}
    >
        <header className="sw-palette-header">
            <h2 id={titleId}>{t("Pilih model")}</h2>
            <kbd>{t("Ctrl /")}</kbd>
        </header>
        <div className="sw-palette-search">
            <label htmlFor={`${id}-search`} className="studio-visually-hidden">{t("Cari model")}</label>
            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round"><circle cx="10.5" cy="10.5" r="6.5" /><path d="m16 16 4 4" /></svg>
            <input
                ref={inputRef}
                id={`${id}-search`}
                type="search"
                role="combobox"
                aria-expanded="true"
                aria-controls={listId}
                aria-activedescendant={activeId}
                aria-autocomplete="list"
                autoComplete="off"
                spellCheck={false}
                maxLength={200}
                placeholder={t("Nama model atau penyedia")}
                value={query}
                onChange={(event) => { setQuery(event.target.value); resetActive(); }}
                onKeyDown={onInputKeyDown}
            />
        </div>
        <div className="sw-palette-body">
            <nav className="sw-palette-rail" aria-label={t("Kategori model")}>
                {PALETTE_CATEGORIES.map((entry) => <button
                    key={entry.id}
                    type="button"
                    aria-pressed={category === entry.id}
                    onClick={() => { setCategory(entry.id); resetActive(); }}
                >
                    <span>{t(entry.label)}</span>
                    {LOCAL_CATEGORIES.includes(entry.id) && <span className="sw-palette-category-count">{number.format(entry.id === "recent" ? recent.length : favorites.length)}</span>}
                </button>)}
            </nav>
            <section className="sw-palette-results" aria-label={t("Model tersedia")}>
                <div className="sw-palette-resultbar">
                    <span aria-live="polite" aria-atomic="true">{number.format(total ?? models.length)} {t(total == null ? "model dimuat" : "model")}</span>
                    {(refreshing || paging) && <span className="sw-palette-loading" role="status"><span className="studio-spinner" aria-hidden="true" />{t("Mencari model…")}</span>}
                </div>
                <div ref={scrollRef} className="sw-palette-scroll">
                    {availability?.state === "restricted" && <StudioNotice>{t(availability.reason || "Studio media belum tersedia untuk akun Anda.")}</StudioNotice>}
                    {error && <StudioNotice error action={<StudioButton onClick={() => { void loadPage(catalog.errorCursor); }}>{t("Coba lagi")}</StudioButton>}>{t(mediaError(error))}</StudioNotice>}
                    <div id={listId} role="listbox" aria-label={t("Pilih model")} aria-busy={refreshing || paging} className={`sw-palette-list${refreshing ? " is-refreshing" : ""}`}>
                        {models.map((model, index) => {
                            const price = startingPrice(model);
                            const operations = model.operations || [];
                            return <div
                                key={model.model_id}
                                id={`${listId}-${index}`}
                                role="option"
                                aria-selected={model.model_id === selectedId}
                                aria-disabled={refreshing || undefined}
                                className={`sw-palette-option${active === index ? " is-active" : ""}`}
                                onMouseEnter={() => { if (!refreshing) setActiveIndex(index); }}
                                onMouseDown={(event) => event.preventDefault()}
                                onClick={() => { if (!refreshing) onSelect(model); }}
                            >
                                <ModelLogo model={model} />
                                <div className="sw-palette-model">
                                    <div className="sw-palette-name">
                                        {model.provider_name && <><span className="sw-palette-provider">{model.provider_name}</span><span className="sw-palette-separator" aria-hidden="true">/</span></>}
                                        <strong>{model.name || model.model_id}</strong>
                                        {favoriteIds.has(model.model_id) && <span className="sw-palette-favorite"><StudioIcon name="pro" /><span className="studio-visually-hidden">{t("Favorit")}</span></span>}
                                        {model.model_id === selectedId && <StudioIcon name="check" className="sw-palette-selected" />}
                                    </div>
                                    {model.description && <p className="sw-palette-description">{model.description}</p>}
                                    <div className="sw-palette-meta">
                                        <div className="sw-palette-operations">
                                            {operations.slice(0, 3).map((operation, operationIndex) => <span key={`${operation.operation}-${operationIndex}`}>{t(operationLabel(operation.operation))}</span>)}
                                            {operations.length > 3 && <span aria-label={t("{count} operasi lainnya").replace("{count}", number.format(operations.length - 3))}>+{number.format(operations.length - 3)}</span>}
                                        </div>
                                        <span className="sw-palette-price">{price == null ? t("Harga belum tersedia") : t("mulai {count} token").replace("{count}", number.format(price))}</span>
                                    </div>
                                </div>
                            </div>;
                        })}
                    </div>
                    {!refreshing && !error && availability?.state !== "restricted" && !models.length && <p className="sw-palette-empty" role="status">{t(emptyMessage)}</p>}
                    {hasMore && <div className="sw-palette-more"><StudioButton disabled={refreshing || paging} onClick={loadMore}>{t("Muat lebih banyak")}</StudioButton></div>}
                </div>
            </section>
        </div>
        <footer className="sw-palette-footer">{t("↑↓ pilih · Enter gunakan · Esc tutup")}</footer>
        {/* Last in DOM order so keyboard navigation follows search, categories, paging, then close. */}
        <StudioButton icon="close" className="sw-palette-close" aria-label={t("Tutup pemilih model")} onClick={onClose} />
    </dialog>;
}

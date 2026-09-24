import React, { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react';
import { useAuth } from '../../contexts/AuthContext';
import { useLocale } from '../../contexts/LocaleContext';
import { apiRequest } from '../../lib/api';
import SafeMarkdown from './SafeMarkdown';
import MediaOutputPreview from '../studios/MediaOutputPreview';
import './artifact-panel.css';

const MAX_TEXT_BYTES = 2 * 1024 * 1024;
const TEXT_KINDS = ['text', 'code', 'html', 'svg', 'markdown', 'json', 'table'];
const EXTENSIONS = { text: 'txt', markdown: 'md', html: 'html', svg: 'svg', json: 'json', table: 'csv', code: 'txt' };

function useCopy() {
    const { locale } = useLocale();
    return useCallback((en, id) => locale === 'en' ? en : id, [locale]);
}

function ArtifactIcon({ type = 'file' }) {
    const paths = {
        file: <><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" /><path d="M14 2v6h6M8 13h8M8 17h5" /></>,
        copy: <><rect x="8" y="8" width="12" height="12" rx="2" /><path d="M16 8V4a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h4" /></>,
        download: <><path d="M12 3v12m-5-5 5 5 5-5M4 16v4h16v-4" /></>,
        close: <path d="m6 6 12 12M6 18 18 6" />,
        back: <path d="m14 6-6 6 6 6" />,
    };
    return <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{paths[type]}</svg>;
}

function errorMessage(error, fallback) {
    return typeof error?.message === 'string' && error.message ? error.message : fallback;
}

function safeOwnedUrl(value) {
    if (typeof value !== 'string' || !value.startsWith('/api/c/artifacts/') || value.includes('\\')) return null;
    try {
        const url = new URL(value, window.location.origin);
        const uuid = '[a-f\\d]{8}-[a-f\\d]{4}-[a-f\\d]{4}-[a-f\\d]{4}-[a-f\\d]{12}';
        const path = new RegExp(`^/api/c/artifacts/${uuid}/(?:preview|download)$`, 'i');
        const revision = url.searchParams.get('revision');
        if (url.origin !== window.location.origin || url.username || url.password || !path.test(url.pathname)
            || [...url.searchParams.keys()].some(key => key !== 'revision') || (revision && !new RegExp(`^${uuid}$`, 'i').test(revision))) return null;
        return url.href;
    } catch { return null; }
}

function saveBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.append(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function sandboxDocument(content) {
    const policy = "default-src 'none'; script-src 'none'; style-src 'unsafe-inline'; img-src data: blob:; connect-src 'none'; form-action 'none'; base-uri 'none'";
    // The original is never rewritten in storage. Only the isolated preview disables navigation/network surfaces.
    if (typeof DOMParser === 'undefined') return `<meta http-equiv="Content-Security-Policy" content="${policy}">${content}`;
    const doc = new DOMParser().parseFromString(content, 'text/html');
    doc.querySelectorAll('script,iframe,frame,frameset,object,embed,base,meta,link').forEach(node => node.remove());
    doc.querySelectorAll('*').forEach(node => {
        for (const attribute of [...node.attributes]) {
            const name = attribute.name.toLowerCase();
            if (name.startsWith('on') || ['action', 'formaction', 'srcdoc', 'srcset', 'ping', 'target'].includes(name)) node.removeAttribute(attribute.name);
            if (['href', 'xlink:href', 'src', 'poster', 'data'].includes(name)) {
                const value = attribute.value.trim();
                const raster = name === 'src' && node.tagName === 'IMG' && /^data:image\/(?:png|jpeg|gif|webp|avif);base64,[a-z\d+/=\s]+$/i.test(value);
                if (!value.startsWith('#') && !raster) node.removeAttribute(attribute.name);
            }
        }
    });
    return `<!doctype html><meta http-equiv="Content-Security-Policy" content="${policy}">${doc.documentElement.outerHTML}`;
}

function SvgPreview({ content, filename }) {
    const copy = useCopy();
    const [url, setUrl] = useState(null);
    const [failed, setFailed] = useState(false);
    useEffect(() => {
        const next = URL.createObjectURL(new Blob([content], { type: 'image/svg+xml' }));
        setUrl(next);
        setFailed(false);
        return () => URL.revokeObjectURL(next);
    }, [content]);
    return failed ? <p className="artifact-notice" role="status">{copy('This SVG cannot be previewed. Open Source to inspect it, or download the original.', 'SVG ini tidak dapat dipratinjau. Buka Sumber untuk memeriksanya, atau unduh berkas asli.')}</p>
        : url && <img className="artifact-svg-preview" src={url} alt={filename} onError={() => setFailed(true)} />;
}

function tableData(content, mime) {
    const limit = 200;
    if (mime === 'application/json') {
        const values = JSON.parse(content);
        if (!Array.isArray(values)) throw new Error('A table must be an array of rows.');
        if (values.every(row => row && typeof row === 'object' && !Array.isArray(row))) {
            const keys = [...new Set(values.slice(0, limit).flatMap(row => Object.keys(row)))].slice(0, 50);
            return { headers: keys, rows: values.slice(0, limit).map(row => keys.map(key => typeof row[key] === 'object' ? JSON.stringify(row[key]) : String(row[key] ?? ''))), limited: values.length > limit || values.some(row => Object.keys(row).length > 50) };
        }
        if (!values.every(Array.isArray)) throw new Error('Each table row must be an object or an array.');
        const columns = Math.min(50, Math.max(0, ...values.slice(0, limit).map(row => row.length)));
        return { headers: Array.from({ length: columns }, (_, index) => String(index + 1)), rows: values.slice(0, limit).map(row => row.slice(0, columns).map(cell => typeof cell === 'object' ? JSON.stringify(cell) : String(cell ?? ''))), limited: values.length > limit || values.some(row => row.length > 50) };
    }
    const delimiter = mime === 'text/tab-separated-values' ? '\t' : ',';
    const rows = [];
    let row = [], cell = '', quoted = false, limited = false;
    for (let index = 0; index < content.length; index++) {
        const char = content[index];
        if (char === '"') {
            if (quoted && content[index + 1] === '"') { cell += '"'; index++; }
            else quoted = !quoted;
        } else if (!quoted && char === delimiter) { row.push(cell); cell = ''; }
        else if (!quoted && (char === '\n' || char === '\r')) {
            row.push(cell); rows.push(row); row = []; cell = '';
            if (char === '\r' && content[index + 1] === '\n') index++;
            if (rows.length > limit) { limited = index < content.length - 1; break; }
        } else cell += char;
    }
    if (quoted) throw new Error('A quoted cell is not closed.');
    if (!limited && (cell || row.length)) { row.push(cell); rows.push(row); }
    return { headers: (rows[0] || []).slice(0, 50), rows: rows.slice(1, limit + 1).map(value => value.slice(0, 50)), limited: limited || rows.some(value => value.length > 50) };
}

function TablePreview({ content, mime }) {
    const copy = useCopy();
    const table = useMemo(() => {
        try { return tableData(content, mime); } catch (error) { return { error: error.message }; }
    }, [content, mime]);
    if (table.error) return <p className="artifact-notice" role="status">{copy('Table preview unavailable. Check the source format.', 'Pratinjau tabel tidak tersedia. Periksa format sumber.')}</p>;
    if (!table.headers.length) return <p className="artifact-muted">{copy('The table is empty.', 'Tabel kosong.')}</p>;
    return <><div className="artifact-table-scroll" role="region" aria-label={copy('Artifact table', 'Tabel artefak')} tabIndex={0}><table><thead><tr>{table.headers.map((header, index) => <th key={index} scope="col">{header}</th>)}</tr></thead><tbody>{table.rows.map((row, index) => <tr key={index}>{row.map((cell, column) => <td key={column}>{cell}</td>)}</tr>)}</tbody></table></div>{table.limited && <p className="artifact-muted">{copy('Preview shows up to 200 rows and 50 columns. Download includes the complete table.', 'Pratinjau menampilkan hingga 200 baris dan 50 kolom. Unduhan berisi tabel lengkap.')}</p>}</>;
}

export function ArtifactPreview({ artifact }) {
    const copy = useCopy();
    const html = useMemo(() => artifact?.kind === 'html' ? sandboxDocument(artifact.content ?? '') : '', [artifact?.kind, artifact?.content]);
    if (!artifact) return null;
    if (typeof artifact.content !== 'string') {
        const url = safeOwnedUrl(artifact.preview_url);
        return <MediaOutputPreview output={{ kind: artifact.kind, mime: artifact.mime, name: artifact.filename, url, previewable: Boolean(url && artifact.previewable), download_url: safeOwnedUrl(artifact.download_url) }} />;
    }
    if (artifact.kind === 'html') return <><p className="artifact-preview-safety">{copy('Isolated preview · scripts, forms and external resources are disabled.', 'Pratinjau terisolasi · skrip, formulir, dan sumber eksternal dinonaktifkan.')}</p><iframe className="artifact-html-preview" title={copy('Isolated HTML preview', 'Pratinjau HTML terisolasi')} sandbox="" referrerPolicy="no-referrer" srcDoc={html} /></>;
    // Like HTML, SVG is isolated: rendered as a static image, so its scripts, links and external images never load.
    if (artifact.kind === 'svg') return <><p className="artifact-preview-safety">{copy('Isolated preview · shown as a static image; scripts, links and external images do not load.', 'Pratinjau terisolasi · ditampilkan sebagai gambar statis; skrip, tautan, dan gambar eksternal tidak dimuat.')}</p><SvgPreview content={artifact.content} filename={artifact.filename} /></>;
    if (artifact.kind === 'markdown') return <div className="artifact-markdown"><SafeMarkdown content={artifact.content} /></div>;
    if (artifact.kind === 'table') return <TablePreview content={artifact.content} mime={artifact.mime} />;
    let content = artifact.content;
    if (artifact.kind === 'json') {
        try { content = JSON.stringify(JSON.parse(content), null, 2); } catch { /* Unsaved JSON remains visible as source. */ }
    }
    return <pre className={`artifact-source${artifact.kind === 'text' ? ' artifact-source-wrap' : ''}`} dir="auto"><code>{content}</code></pre>;
}

function useUnsavedGuard(dirty, onDirtyChange) {
    useEffect(() => {
        onDirtyChange?.(dirty);
        return () => onDirtyChange?.(false);
    }, [dirty, onDirtyChange]);
    useEffect(() => {
        if (!dirty) return undefined;
        const prevent = event => { event.preventDefault(); event.returnValue = ''; };
        window.addEventListener('beforeunload', prevent);
        return () => window.removeEventListener('beforeunload', prevent);
    }, [dirty]);
}

function draftKey(userId, artifactId) { return `xsuper:artifact-draft:${userId}:${artifactId}`; }
function readDraft(userId, artifactId) {
    try { return JSON.parse(sessionStorage.getItem(draftKey(userId, artifactId)) || 'null'); } catch { return null; }
}
function removeDraft(userId, artifactId) {
    try { sessionStorage.removeItem(draftKey(userId, artifactId)); } catch { /* In-memory editing still works when browser storage is unavailable. */ }
}

export default function ArtifactPanel({ conversationId, activeArtifactId, onSelectArtifact, onDirtyChange, onRequestSaveFromMessage, discardRef, refreshKey = 0 }) {
    const copy = useCopy();
    const { locale } = useLocale();
    const { user } = useAuth();
    const userId = user?.id;
    const uid = useId();
    const [artifacts, setArtifacts] = useState([]);
    const [localId, setLocalId] = useState(null);
    const [artifact, setArtifact] = useState(null);
    const [revisionSelection, setRevisionSelection] = useState(null);
    const [draft, setDraft] = useState(null);
    const [mode, setMode] = useState('preview');
    const [loading, setLoading] = useState(false);
    const [loadingList, setLoadingList] = useState(false);
    const [saving, setSaving] = useState(false);
    const [exporting, setExporting] = useState(false);
    const [error, setError] = useState('');
    const [listError, setListError] = useState('');
    const [notice, setNotice] = useState('');
    const [conflict, setConflict] = useState(null);
    const [reload, setReload] = useState(0);
    const requestScope = useRef(0);
    const editorRef = useRef(null);
    const selectedId = activeArtifactId ?? (localId?.conversationId === conversationId ? localId.id : null);
    const revisionId = revisionSelection?.artifactId === selectedId && revisionSelection?.conversationId === conversationId ? revisionSelection.id : null;
    const visibleArtifact = userId && artifact?.conversation_id === conversationId && artifact.id === selectedId ? artifact : null;
    const dirty = Boolean(visibleArtifact && draft && (draft.content !== visibleArtifact.content || draft.filename !== visibleArtifact.filename || draft.base_version !== visibleArtifact.version));
    // "Leave without saving" in the page guards is an explicit discard, so the tab-recovery copy must not come back.
    // The panel owns the open artifact id (it can differ from activeArtifactId after a conversation round trip).
    const discardId = visibleArtifact?.id;
    useEffect(() => {
        if (!discardRef) return undefined;
        discardRef.current = () => { if (discardId) removeDraft(userId, discardId); };
        return () => { discardRef.current = null; };
    }, [discardRef, discardId, userId]);
    useUnsavedGuard(dirty || saving, onDirtyChange);

    useEffect(() => {
        const controller = new AbortController();
        setLoadingList(Boolean(conversationId));
        setListError('');
        setArtifacts([]);
        if (!conversationId || !userId) { setArtifacts([]); setLocalId(null); return () => controller.abort(); }
        apiRequest(`/api/c/h/${encodeURIComponent(conversationId)}/artifacts`, { signal: controller.signal })
            .then(data => { if (!controller.signal.aborted) setArtifacts(data.artifacts || []); })
            .catch(err => { if (!controller.signal.aborted) setListError(errorMessage(err, copy('Could not load artifacts.', 'Artefak gagal dimuat.'))); })
            .finally(() => { if (!controller.signal.aborted) setLoadingList(false); });
        return () => controller.abort();
    }, [conversationId, refreshKey, reload, copy, userId]);

    useEffect(() => {
        const controller = new AbortController();
        const scope = ++requestScope.current;
        setArtifact(null); setDraft(null); setError(''); setNotice(''); setConflict(null); setMode('preview');
        setSaving(false); setExporting(false);
        if (!conversationId || !selectedId || !userId) { setLoading(false); return () => controller.abort(); }
        setLoading(true);
        const path = `/api/c/artifacts/${encodeURIComponent(selectedId)}${revisionId ? `/revisions/${encodeURIComponent(revisionId)}` : ''}`;
        apiRequest(path, { signal: controller.signal }).then(data => {
            if (controller.signal.aborted || scope !== requestScope.current) return;
            if (data.artifact?.conversation_id !== conversationId) throw new Error(copy('This artifact belongs to another conversation.', 'Artefak ini milik percakapan lain.'));
            const saved = data.artifact;
            setArtifact(saved);
            const previous = !revisionId && saved.editable ? readDraft(userId, saved.id) : null;
            if (previous && typeof previous.content === 'string' && typeof previous.filename === 'string' && Number.isInteger(previous.base_version)) {
                setDraft(previous); setMode('edit');
                setNotice(copy('Recovered an unsaved draft from this browser tab.', 'Draf yang belum disimpan dipulihkan dari tab browser ini.'));
            }
        }).catch(err => { if (!controller.signal.aborted) setError(errorMessage(err, copy('Could not open the artifact.', 'Artefak gagal dibuka.'))); })
            .finally(() => { if (!controller.signal.aborted) setLoading(false); });
        return () => { controller.abort(); requestScope.current++; };
    }, [conversationId, selectedId, revisionId, reload, copy, userId]);

    useEffect(() => {
        if (!visibleArtifact || !draft) return;
        if (!dirty) { removeDraft(userId, visibleArtifact.id); return; }
        try { sessionStorage.setItem(draftKey(userId, visibleArtifact.id), JSON.stringify(draft)); }
        catch { setNotice(copy('The draft is kept only while this panel stays open. Browser storage is unavailable; save or export before leaving.', 'Draf hanya tersimpan selama panel terbuka. Penyimpanan browser tidak tersedia; simpan atau ekspor sebelum keluar.')); }
    }, [draft, dirty, visibleArtifact, userId, copy]);

    const discard = () => {
        if (saving) return false;
        if (dirty && !window.confirm(copy('Discard your unsaved artifact changes?', 'Buang perubahan artefak yang belum disimpan?'))) return false;
        if (visibleArtifact) removeDraft(userId, visibleArtifact.id);
        setDraft(null); setConflict(null); setNotice(''); setError('');
        return true;
    };
    const select = id => {
        if (!discard()) return;
        setRevisionSelection(null); setLocalId({ conversationId, id }); onSelectArtifact?.(id);
    };
    const switchRevision = id => {
        if (!discard()) return;
        setRevisionSelection({ artifactId: selectedId, conversationId, id: id || null });
    };
    const edit = () => {
        if (!visibleArtifact?.editable) return;
        setDraft(current => current || { content: visibleArtifact.content, filename: visibleArtifact.filename, base_version: visibleArtifact.version });
        setMode('edit');
        window.requestAnimationFrame(() => editorRef.current?.focus());
    };
    const persist = async (baseVersion = draft?.base_version) => {
        if (!visibleArtifact || !draft || saving) return;
        if (new TextEncoder().encode(draft.content).byteLength > MAX_TEXT_BYTES) { setError(copy('Text artifacts can be at most 2 MiB.', 'Ukuran artefak teks maksimal 2 MiB.')); return; }
        const scope = requestScope.current;
        setSaving(true); setError(''); setNotice('');
        try {
            const data = await apiRequest(`/api/c/artifacts/${visibleArtifact.id}/revisions`, { method: 'POST', body: { base_version: baseVersion, content: draft.content, filename: draft.filename } });
            removeDraft(userId, visibleArtifact.id);
            if (scope !== requestScope.current) return;
            setArtifact(data.artifact); setDraft(null); setConflict(null); setMode('preview');
            if (revisionId) setRevisionSelection(null);
            setArtifacts(current => current.map(item => item.id === data.artifact.id ? data.artifact : item));
            setNotice(copy(`Saved as version ${data.artifact.version}.`, `Disimpan sebagai versi ${data.artifact.version}.`));
        } catch (err) {
            if (scope !== requestScope.current) return;
            if (err.status === 409) setConflict({ version: err.details?.current_version });
            setError(errorMessage(err, copy('Save failed. Your draft is still here.', 'Gagal menyimpan. Draf Anda masih tersedia.')));
        } finally { if (scope === requestScope.current) setSaving(false); }
    };
    const compareLatest = async () => {
        const scope = requestScope.current;
        setLoading(true);
        try {
            const data = await apiRequest(`/api/c/artifacts/${visibleArtifact.id}`);
            if (scope === requestScope.current) setConflict({ version: data.artifact.version, latest: data.artifact });
        } catch (err) { if (scope === requestScope.current) setError(errorMessage(err, copy('Could not load the latest version.', 'Versi terbaru gagal dimuat.'))); }
        finally { if (scope === requestScope.current) setLoading(false); }
    };
    const currentContent = draft?.content ?? visibleArtifact?.content;
    const currentFilename = draft?.filename ?? visibleArtifact?.filename;
    const copyContent = async () => {
        const scope = requestScope.current;
        try {
            if (!navigator.clipboard?.writeText) throw new Error(copy('Clipboard access is unavailable. Select text in Source, or export the file.', 'Akses papan klip tidak tersedia. Pilih teks di Sumber, atau ekspor berkas.'));
            await navigator.clipboard.writeText(currentContent);
            if (scope === requestScope.current) setNotice(copy(dirty ? 'Unsaved draft copied.' : 'Viewed content copied.', dirty ? 'Draf yang belum disimpan telah disalin.' : 'Konten yang ditampilkan telah disalin.'));
        } catch (err) { if (scope === requestScope.current) setError(errorMessage(err, copy('Copy failed. Use Source to select the text.', 'Gagal menyalin. Gunakan Sumber untuk memilih teks.'))); }
    };
    const exportContent = async () => {
        const scope = requestScope.current;
        setExporting(true); setError('');
        try {
            if (dirty) saveBlob(new Blob([currentContent], { type: `${visibleArtifact.mime};charset=utf-8` }), currentFilename);
            else {
                const url = safeOwnedUrl(visibleArtifact.download_url);
                if (!url) throw new Error(copy('A private download URL is unavailable.', 'URL unduhan privat tidak tersedia.'));
                // Check access without buffering a potentially large binary artifact in JavaScript.
                const response = await fetch(url, { method: 'HEAD', credentials: 'same-origin', headers: { Accept: '*/*' } });
                if (!response.ok) throw new Error(copy(`Download unavailable (${response.status}). The file may no longer be retained.`, `Unduhan tidak tersedia (${response.status}). Berkas mungkin sudah melewati masa penyimpanan.`));
                if (scope !== requestScope.current) return;
                const link = document.createElement('a');
                link.href = url; link.download = currentFilename;
                document.body.append(link); link.click(); link.remove();
            }
            if (scope === requestScope.current) setNotice(copy(dirty ? 'Draft exported. It has not been saved to the workspace.' : 'Original file download started.', dirty ? 'Draf diekspor. Draf belum disimpan ke ruang kerja.' : 'Unduhan berkas asli dimulai.'));
        } catch (err) { if (scope === requestScope.current) setError(errorMessage(err, copy('Download failed. Try again.', 'Unduhan gagal. Coba lagi.'))); }
        finally { if (scope === requestScope.current) setExporting(false); }
    };
    const modes = visibleArtifact?.editable ? ['preview', 'source', 'edit'] : ['preview'];
    const modeName = value => ({ preview: copy('Preview', 'Pratinjau'), source: copy('Source', 'Sumber'), edit: copy('Edit', 'Edit') })[value];
    const setView = value => value === 'edit' ? edit() : setMode(value);

    if (!userId) return null;
    return <section className="artifact-panel" aria-label={copy('Saved artifacts', 'Artefak tersimpan')} onKeyDown={event => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's' && dirty) { event.preventDefault(); persist(); }
        if (event.key === 'Escape' && draft) { event.stopPropagation(); if (discard()) setMode('preview'); }
    }}>
        {!selectedId && <><div className="artifact-heading"><h3>{copy('Saved artifacts', 'Artefak tersimpan')}</h3>{onRequestSaveFromMessage && <button type="button" className="artifact-button" onClick={onRequestSaveFromMessage}>{copy('Save from chat', 'Simpan dari chat')}</button>}</div>
            <p className="artifact-muted">{copy('Save a response or code block explicitly. Files are never created automatically.', 'Simpan jawaban atau blok kode secara eksplisit. Berkas tidak dibuat otomatis.')}</p>
            {loadingList && <p role="status" className="artifact-muted">{copy('Loading artifacts…', 'Memuat artefak…')}</p>}
            {listError && <div role="alert" className="artifact-error">{listError}<button type="button" className="artifact-button" onClick={() => setReload(value => value + 1)}>{copy('Try again', 'Coba lagi')}</button></div>}
            {!conversationId && <p className="artifact-empty">{copy('Choose a conversation to see its saved files.', 'Pilih percakapan untuk melihat berkas tersimpannya.')}</p>}
            {conversationId && !loadingList && !listError && !artifacts.length && <div className="artifact-empty"><ArtifactIcon /><h4>{copy('No saved artifacts yet', 'Belum ada artefak tersimpan')}</h4><p>{copy('Use “Save artifact” on a response you want to keep.', 'Gunakan “Simpan artefak” pada jawaban yang ingin Anda simpan.')}</p></div>}
            <ul className="artifact-list">{artifacts.map(item => <li key={item.id}><button type="button" onClick={() => select(item.id)}><ArtifactIcon /><span><strong>{item.title}</strong><small>{item.filename} · {copy('Version', 'Versi')} {item.version}</small></span></button></li>)}</ul>
        </>}
        {selectedId && <><button type="button" className="artifact-back" disabled={saving} onClick={() => select(null)}><ArtifactIcon type="back" />{copy('All artifacts', 'Semua artefak')}</button>
            {loading && <p role="status" className="artifact-muted">{copy('Loading artifact…', 'Memuat artefak…')}</p>}
            {visibleArtifact && <><div className="artifact-heading"><h3>{visibleArtifact.title}</h3><span className="artifact-version">v{visibleArtifact.version}</span></div>
                <p className="artifact-filename" title={visibleArtifact.filename}>{visibleArtifact.filename}</p>
                <label className="artifact-field" htmlFor={`${uid}-revision`}>{copy('Revision history', 'Riwayat revisi')}<select id={`${uid}-revision`} value={revisionId || ''} disabled={saving} onChange={event => switchRevision(event.target.value)}><option value="">{copy('Latest saved version', 'Versi tersimpan terbaru')} · v{visibleArtifact.latest_version}</option>{visibleArtifact.revisions?.map(revision => <option key={revision.id} value={revision.id}>v{revision.version} · {new Date(revision.created_at).toLocaleString(locale === 'en' ? 'en-US' : 'id-ID')}</option>)}</select></label>
                {/* The message produced version 1 only; later revisions are the member's own edits. */}
                {visibleArtifact.source_message_id && <p className="artifact-muted">{visibleArtifact.version > 1 ? copy(`Version 1 from message #${visibleArtifact.source_message_id}`, `Versi 1 dari pesan #${visibleArtifact.source_message_id}`) : `${copy('Source message', 'Pesan sumber')} #${visibleArtifact.source_message_id}`}</p>}
                {!visibleArtifact.editable && <p className="artifact-muted">{copy('Generated media · original file, read-only.', 'Media hasil generasi · berkas asli, hanya baca.')}</p>}
                <div className="artifact-view-tabs" role="tablist" aria-label={copy('Artifact view', 'Tampilan artefak')}>{modes.map(value => <button key={value} id={`${uid}-${value}`} type="button" role="tab" aria-selected={mode === value} aria-controls={`${uid}-view`} tabIndex={mode === value ? 0 : -1} disabled={saving} onClick={() => setView(value)} onKeyDown={event => {
                    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
                    event.preventDefault();
                    const index = modes.indexOf(value);
                    const next = event.key === 'Home' ? modes[0] : event.key === 'End' ? modes.at(-1) : modes[(index + (event.key === 'ArrowRight' ? 1 : -1) + modes.length) % modes.length];
                    setView(next); document.getElementById(`${uid}-${next}`)?.focus();
                }}>{modeName(value)}</button>)}</div>
                <div className="artifact-view" id={`${uid}-view`} role="tabpanel" aria-labelledby={`${uid}-${mode}`}>
                    {mode === 'edit' && draft ? <><label className="artifact-field" htmlFor={`${uid}-filename`}>{copy('Filename', 'Nama berkas')}<input id={`${uid}-filename`} value={draft.filename} maxLength={180} disabled={saving} onChange={event => setDraft(current => ({ ...current, filename: event.target.value }))} /></label><div className="artifact-field"><label htmlFor={`${uid}-content`}>{copy('Content', 'Konten')}</label><textarea ref={editorRef} id={`${uid}-content`} className="artifact-editor" value={draft.content} spellCheck={false} dir="auto" disabled={saving} onChange={event => setDraft(current => ({ ...current, content: event.target.value }))} /></div></>
                        : mode === 'source' ? <pre className="artifact-source" tabIndex={0} dir="auto"><code>{currentContent}</code></pre>
                            : <ArtifactPreview artifact={{ ...visibleArtifact, content: currentContent, filename: currentFilename }} />}
                </div>
                <p className={`artifact-save-status${dirty ? ' artifact-unsaved' : ''}`} role="status">{saving ? copy('Saving…', 'Menyimpan…') : dirty ? copy('Unsaved changes', 'Perubahan belum disimpan') : copy(`Saved version ${visibleArtifact.version}`, `Versi ${visibleArtifact.version} tersimpan`)}</p>
                <div className="artifact-actions">{typeof currentContent === 'string' && <button type="button" className="artifact-button" onClick={copyContent}><ArtifactIcon type="copy" />{copy(dirty ? 'Copy draft' : 'Copy', dirty ? 'Salin draf' : 'Salin')}</button>}<button type="button" className="artifact-button" onClick={exportContent} disabled={exporting}><ArtifactIcon type="download" />{exporting ? copy('Downloading…', 'Mengunduh…') : copy(dirty ? 'Export draft' : 'Download', dirty ? 'Ekspor draf' : 'Unduh')}</button></div>
                {draft && <div className="artifact-actions"><button type="button" className="artifact-button artifact-primary" disabled={!dirty || saving || Boolean(conflict)} onClick={() => persist()}>{copy('Save revision', 'Simpan revisi')}</button><button type="button" className="artifact-button" disabled={saving} onClick={() => { if (discard()) setMode('preview'); }}>{copy('Discard changes', 'Buang perubahan')}</button></div>}
            </>}
        </>}
        {error && <div className="artifact-error" role="alert"><p>{error}</p>{!visibleArtifact && selectedId && <button type="button" className="artifact-button" onClick={() => setReload(value => value + 1)}>{copy('Try again', 'Coba lagi')}</button>}</div>}
        {conflict && <div className="artifact-conflict"><h4>{copy('A newer revision was saved', 'Revisi yang lebih baru telah disimpan')}</h4><p>{copy('Your draft is intact. Compare it with the latest version before saving a new revision.', 'Draf Anda tetap utuh. Bandingkan dengan versi terbaru sebelum menyimpan revisi baru.')}</p>{!conflict.latest ? <button type="button" className="artifact-button" disabled={loading} onClick={compareLatest}>{copy('Compare latest', 'Bandingkan versi terbaru')}</button> : <><details open><summary>{copy('Latest saved content', 'Konten tersimpan terbaru')} · v{conflict.latest.version}</summary><pre className="artifact-source"><code>{conflict.latest.content}</code></pre></details><div className="artifact-actions"><button type="button" className="artifact-button artifact-primary" disabled={saving} onClick={() => persist(conflict.latest.version)}>{copy('Save my draft as a new revision', 'Simpan draf saya sebagai revisi baru')}</button><button type="button" className="artifact-button" disabled={saving} onClick={() => { const latest = conflict.latest; if (discard()) { setArtifact(latest); setRevisionSelection(null); setMode('preview'); } }}>{copy('Use latest; discard draft', 'Gunakan terbaru; buang draf')}</button></div></>}</div>}
        {notice && <p className="artifact-notice" role="status">{notice}</p>}
    </section>;
}

function formatForLanguage(language) {
    const normalized = String(language || '').toLowerCase();
    const mapping = { html: ['html', 'html'], svg: ['svg', 'svg'], markdown: ['markdown', 'md'], md: ['markdown', 'md'], json: ['json', 'json'], csv: ['table', 'csv'], tsv: ['table', 'tsv'], text: ['text', 'txt'], plaintext: ['text', 'txt'], javascript: ['code', 'js'], typescript: ['code', 'ts'], python: ['code', 'py'], bash: ['code', 'sh'], shell: ['code', 'sh'] };
    return mapping[normalized] || (normalized ? ['code', /^[a-z\d]{1,10}$/.test(normalized) ? normalized : 'txt'] : ['text', 'txt']);
}

export function SaveArtifactDialog({ conversationId, source, onSaved, onClose, onDirtyChange }) {
    const copy = useCopy();
    const uid = useId();
    const dialogRef = useRef(null);
    const initial = useMemo(() => formatForLanguage(source?.language), [source?.language]);
    const generated = source?.generatedOutput;
    // A descriptive default: saved results are told apart by their source message (or save time), never all "artifact".
    const stamp = useMemo(() => new Date().toISOString().slice(0, 16).replace(/\D/g, ''), []);
    const initialFilename = generated?.name || `${copy('result', 'hasil')}-${source?.sourceMessageId ?? stamp}.${initial[1]}`;
    const [kind, setKind] = useState(initial[0]);
    const [filename, setFilename] = useState(initialFilename);
    const [title, setTitle] = useState('');
    const [content, setContent] = useState(source?.content ?? '');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const mounted = useRef(true);
    const dirty = content !== (source?.content ?? '') || filename !== initialFilename || kind !== initial[0] || title !== '';
    useUnsavedGuard(dirty || saving, onDirtyChange);
    useEffect(() => {
        mounted.current = true;
        const previous = document.activeElement;
        dialogRef.current?.showModal();
        return () => { mounted.current = false; previous?.focus?.(); };
    }, []);
    const close = () => {
        if (saving) return;
        if (dirty && !window.confirm(copy('Discard this unsaved artifact?', 'Buang artefak yang belum disimpan ini?'))) return;
        onClose?.();
    };
    const save = async event => {
        event.preventDefault();
        if (saving) return;
        if (!conversationId) { setError(copy('Save the conversation before creating an artifact.', 'Simpan percakapan sebelum membuat artefak.')); return; }
        if (new TextEncoder().encode(content).byteLength > MAX_TEXT_BYTES) { setError(copy('Text artifacts can be at most 2 MiB.', 'Ukuran artefak teks maksimal 2 MiB.')); return; }
        setSaving(true); setError('');
        try {
            const body = {
                title: title || undefined, filename,
                ...(generated ? { generated_output: { job_id: generated.job_id, output_id: String(generated.output_id) } } : { kind, content }),
                // Content edited before saving is the member's own text: it is not claimed as that message's output.
                ...(source?.sourceMessageId && (generated || content === (source?.content ?? '')) ? { source_message_id: source.sourceMessageId } : {}),
            };
            const data = await apiRequest(`/api/c/h/${encodeURIComponent(conversationId)}/artifacts`, { method: 'POST', body });
            if (mounted.current) onSaved?.(data.artifact);
        } catch (err) { if (mounted.current) setError(errorMessage(err, copy('Save failed. Your content is still here.', 'Gagal menyimpan. Konten Anda masih tersedia.'))); }
        finally { if (mounted.current) setSaving(false); }
    };
    return <dialog ref={dialogRef} className="artifact-save-dialog" aria-labelledby={`${uid}-title`} onCancel={event => { event.preventDefault(); close(); }} onClick={event => { if (event.target === event.currentTarget) { const box = event.currentTarget.getBoundingClientRect(); if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) close(); } }}>
        <form onSubmit={save}><div className="artifact-heading"><h2 id={`${uid}-title`}>{copy('Save artifact', 'Simpan artefak')}</h2><button type="button" className="artifact-icon-button" aria-label={copy('Close save dialog', 'Tutup dialog simpan')} disabled={saving} onClick={close}><ArtifactIcon type="close" /></button></div>
            <p className="artifact-muted">{generated ? copy('An owned media reference will be saved in this conversation. The original file stays read-only.', 'Referensi media milik Anda akan disimpan di percakapan ini. Berkas asli tetap hanya baca.') : copy('A private file will be saved in this conversation. You can edit and keep revisions later.', 'Berkas privat akan disimpan di percakapan ini. Anda dapat mengedit dan menyimpan revisinya nanti.')}</p>
            <label className="artifact-field" htmlFor={`${uid}-name`}>{copy('Filename', 'Nama berkas')}<input autoFocus id={`${uid}-name`} value={filename} onChange={event => setFilename(event.target.value)} required maxLength={180} disabled={saving} /></label>
            {!generated && <label className="artifact-field" htmlFor={`${uid}-format`}>{copy('Format', 'Format')}<select id={`${uid}-format`} value={kind} disabled={saving} onChange={event => { const next = event.target.value; setKind(next); setFilename(current => `${current.replace(/\.[^.]*$/, '') || 'artifact'}.${EXTENSIONS[next]}`); }}>{TEXT_KINDS.map(value => <option key={value} value={value}>{({ text: copy('Plain text', 'Teks biasa'), code: copy('Code', 'Kode'), html: 'HTML', svg: 'SVG', markdown: 'Markdown', json: 'JSON', table: copy('Table', 'Tabel') })[value]}</option>)}</select></label>}
            <label className="artifact-field" htmlFor={`${uid}-label`}>{copy('Title (optional)', 'Judul (opsional)')}<input id={`${uid}-label`} value={title} onChange={event => setTitle(event.target.value)} maxLength={200} disabled={saving} /></label>
            {generated ? <p className="artifact-notice">{copy('The owned original media is linked without copying its bytes. Binary artifacts are read-only.', 'Media asli milik Anda ditautkan tanpa menyalin berkasnya. Artefak biner hanya dapat dibaca.')}</p> : <div className="artifact-field"><label htmlFor={`${uid}-text`}>{copy('Content', 'Konten')}</label><textarea id={`${uid}-text`} className="artifact-editor" value={content} onChange={event => setContent(event.target.value)} spellCheck={false} dir="auto" disabled={saving} /></div>}
            {!generated && source?.sourceMessageId && content !== (source?.content ?? '') && <p className="artifact-muted" role="status">{copy('Edited content is saved as your own artifact, without a link to the source message.', 'Isi yang diubah disimpan sebagai artefak Anda sendiri, tanpa tautan ke pesan sumber.')}</p>}
            {error && <p className="artifact-error" role="alert">{error}</p>}
            <div className="artifact-actions"><button type="submit" className="artifact-button artifact-primary" disabled={saving || !conversationId}>{saving ? copy('Saving…', 'Menyimpan…') : copy('Save artifact', 'Simpan artefak')}</button><button type="button" className="artifact-button" disabled={saving} onClick={close}>{copy('Cancel', 'Batal')}</button></div>
        </form>
    </dialog>;
}

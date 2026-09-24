import React, { useEffect, useId, useRef, useState } from 'react';
import { useLocale } from '../../contexts/LocaleContext';
import ArtifactPanel from './ArtifactPanel';
import ChatIcon from './ChatIcon';
import './chat-workspace-panels.css';

const TABS = ['context', 'tools', 'artifacts'];
const sizeLabel = (bytes) => bytes < 1024 ? `${bytes} B` : bytes < 1048576 ? `${(bytes / 1024).toFixed(1)} KB` : `${(bytes / 1048576).toFixed(1)} MB`;

function ownedUrl(value) {
    if (!value || /[\u0000-\u0020\\]/.test(value)) return null;
    try {
        const url = new URL(value, window.location.origin);
        return url.origin === window.location.origin && url.pathname.startsWith('/api/') && !url.username && !url.password ? `${url.pathname}${url.search}` : null;
    } catch { return null; }
}

function WorkspaceNotes({ workspace, onWorkspaceChange, onWorkspaceReload, onDirtyChange, en }) {
    const { t } = useLocale();
    const [draft, setDraft] = useState(workspace.notes || '');
    const [base, setBase] = useState({ notes: workspace.notes || '', version: workspace.version });
    const [status, setStatus] = useState('idle');
    const [error, setError] = useState('');
    const [conflict, setConflict] = useState(null);
    const noteId = useId();
    const mounted = useRef(true);
    const dirty = draft !== base.notes;
    const callback = useRef(onDirtyChange);
    callback.current = onDirtyChange;
    useEffect(() => { mounted.current = true; return () => { mounted.current = false; callback.current?.(false); }; }, []);
    useEffect(() => { callback.current?.(dirty); }, [dirty]);
    useEffect(() => {
        if (!dirty && workspace.version !== base.version) {
            setBase({ notes: workspace.notes || '', version: workspace.version });
            setDraft(workspace.notes || '');
        }
    }, [workspace.notes, workspace.version, base.version, dirty]);
    const save = async () => {
        setStatus('saving'); setError('');
        try {
            const saved = await onWorkspaceChange(draft, base.version);
            if (!saved || saved.version == null) throw new Error(en ? 'The server did not confirm that the notes were saved.' : 'Server belum mengonfirmasi catatan tersimpan.');
            if (!mounted.current) return;
            setBase({ notes: saved.notes || '', version: saved.version }); setDraft(saved.notes || ''); setConflict(null); setStatus('saved');
        } catch (failure) {
            if (!mounted.current) return;
            if (failure.status === 409) { setConflict({ latest: null }); setStatus('conflict'); return; }
            setStatus('failed');
            setError(failure.message);
        }
    };
    const loadLatest = async () => {
        setStatus('loading'); setError('');
        try {
            const latest = await onWorkspaceReload();
            if (!latest || latest.version == null) throw new Error(en ? 'The latest notes could not be loaded.' : 'Catatan terbaru tidak dapat dimuat.');
            if (!mounted.current) return;
            setConflict({ latest: { notes: latest.notes || '', version: latest.version } });
        } catch (failure) { if (mounted.current) setError(failure.message); }
        finally { if (mounted.current) setStatus('conflict'); }
    };
    // Keeping the draft rebases it on the newest saved version; the next save is an explicit overwrite.
    const keepDraft = () => { setBase(conflict.latest); setConflict(null); setStatus('idle'); };
    const useLatest = () => { setBase(conflict.latest); setDraft(conflict.latest.notes); setConflict(null); setStatus('idle'); };
    const busy = status === 'saving' || status === 'loading';
    return <section className="cwp-context-section cwp-notes">
        <div className="cwp-section-heading"><h3><label htmlFor={noteId}>{en ? 'Workspace notes' : 'Catatan workspace'}</label></h3><span>v{base.version}</span></div>
        <p className="cwp-help">{en ? 'Shared user context for conversations in this workspace, not system instructions.' : 'Konteks pengguna untuk percakapan di workspace ini, bukan instruksi sistem.'}</p>
        <textarea id={noteId} value={draft} rows={7} maxLength={20000} disabled={busy} dir="auto" onChange={(event) => { setDraft(event.target.value); if (!conflict) setStatus('idle'); setError(''); }} placeholder={en ? 'Goals, preferences, or working context…' : 'Tujuan, preferensi, atau konteks pekerjaan…'} aria-describedby={`${noteId}-status`} />
        <div className="cwp-note-actions">
            <span id={`${noteId}-status`} className="cwp-note-status" role="status">{status === 'saving' ? (en ? 'Saving…' : 'Menyimpan…') : status === 'loading' ? (en ? 'Loading latest notes…' : 'Memuat catatan terbaru…') : conflict ? (en ? 'Not saved: newer notes exist' : 'Belum tersimpan: ada catatan yang lebih baru') : dirty ? (en ? 'Unsaved changes' : 'Belum tersimpan') : status === 'saved' ? (en ? 'Saved to workspace' : 'Tersimpan di workspace') : (en ? 'No unsaved changes' : 'Tidak ada perubahan')}</span>
            <button type="button" className="cwp-button" disabled={!dirty || busy || Boolean(conflict) || !onWorkspaceChange} onClick={save}><ChatIcon name="save" />{en ? 'Save notes' : 'Simpan catatan'}</button>
        </div>
        {conflict && <div className="cwp-note-conflict" role="alert">
            <p>{en ? 'These notes changed elsewhere, for example by a rename or another tab. Your draft is still here.' : 'Catatan ini berubah di tempat lain, misalnya karena ganti nama atau tab lain. Draft Anda tetap ada.'}</p>
            {!conflict.latest ? <button type="button" className="cwp-button" disabled={busy || !onWorkspaceReload} onClick={loadLatest}><ChatIcon name="refresh" />{en ? 'Load latest notes' : 'Muat catatan terbaru'}</button> : <>
                <details open><summary>{en ? `Latest saved notes · v${conflict.latest.version}` : `Catatan tersimpan terbaru · v${conflict.latest.version}`}</summary><pre dir="auto">{conflict.latest.notes || (en ? '(empty)' : '(kosong)')}</pre></details>
                <div className="cwp-note-conflict-actions">
                    <button type="button" className="cwp-button" onClick={keepDraft}>{en ? 'Keep my draft' : 'Pakai draft saya'}</button>
                    <button type="button" className="cwp-button" onClick={useLatest}>{en ? 'Use latest notes' : 'Pakai catatan terbaru'}</button>
                </div>
            </>}
        </div>}
        {error && <p className="cwp-error" role="alert">{t(error)}</p>}
    </section>;
}

function Attachments({ attachments, capabilities, onChange, en }) {
    const { t } = useLocale();
    const input = useRef(null);
    const [dragging, setDragging] = useState(false);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(new Set());
    const policy = capabilities?.input;
    const canUpload = Boolean(onChange?.upload && policy?.max_files && capabilities?.tools?.file_analysis?.available);
    const run = async (key, action) => {
        setBusy((current) => new Set(current).add(key)); setError('');
        try { await action(); }
        catch (failure) { setError(failure.message); }
        finally { setBusy((current) => { const next = new Set(current); next.delete(key); return next; }); }
    };
    const upload = (files) => { if (files?.length) run('upload', () => onChange.upload(files)); };
    const statusText = (file) => file.removing ? (en ? 'Removing when upload finishes…' : 'Dihapus setelah upload selesai…')
        : file.status === 'ready' ? (en ? 'Attached' : 'Terlampir')
            : file.status === 'processing' ? (en ? 'Waiting for server confirmation…' : 'Menunggu konfirmasi server…')
                : file.status === 'failed' ? (en ? 'Upload failed' : 'Upload gagal')
                    : file.status === 'uploading' ? (file.progress == null ? (en ? 'Uploading…' : 'Mengunggah…') : (en ? `Uploading ${file.progress}%` : `Mengunggah ${file.progress}%`))
                        : file.status === 'error' ? (en ? 'Unavailable — remove it to continue' : 'Tidak tersedia — hapus untuk melanjutkan')
                            : file.status;
    return <section className="cwp-context-section">
        <div className="cwp-section-heading"><h3>{en ? 'Files in this conversation' : 'File dalam percakapan'}</h3><span>{attachments.length}</span></div>
        <p className="cwp-help">{en ? 'Attached files are sent only when File analysis is enabled. Attached does not mean cited or already analyzed.' : 'File dikirim hanya saat Analisis file aktif. Terlampir bukan berarti sudah dianalisis atau dikutip.'}</p>
        <div className={`cwp-file-drop${dragging ? ' is-dragging' : ''}`} onDragOver={(event) => {
            if (canUpload && event.dataTransfer.types.includes('Files')) { event.preventDefault(); setDragging(true); }
        }} onDragLeave={(event) => { if (!event.currentTarget.contains(event.relatedTarget)) setDragging(false); }} onDrop={(event) => {
            setDragging(false);
            if (event.dataTransfer.files.length) { event.preventDefault(); if (canUpload) upload(Array.from(event.dataTransfer.files)); }
        }} onPaste={(event) => {
            if (!canUpload || !event.clipboardData.files.length) return;
            if (!event.clipboardData.getData('text/plain')) event.preventDefault();
            upload(Array.from(event.clipboardData.files));
        }}>
            <input ref={input} type="file" hidden multiple accept={[...(policy?.accepted_mimes || []), ...(policy?.accepted_extensions || []).map((extension) => `.${String(extension).replace(/^\./, '')}`)].join(',') || undefined} onChange={(event) => { upload(Array.from(event.target.files || [])); event.target.value = ''; }} />
            <button type="button" className="cwp-file-add" disabled={!canUpload || busy.has('upload')} onClick={() => input.current?.click()}><ChatIcon name="upload" />{en ? 'Add files' : 'Tambah file'}</button>
            <p>{canUpload ? (en ? 'Drop files here, or choose Add files.' : 'Letakkan file di sini, atau pilih Tambah file.') : !capabilities ? (en ? 'Model capabilities are not loaded yet.' : 'Capability model belum dimuat.') : capabilities.tools?.file_analysis?.available === false ? (t(capabilities.tools.file_analysis.reason) || (en ? 'File upload is unavailable for this model.' : 'Upload file tidak tersedia untuk model ini.')) : (en ? 'File upload is unavailable for this model.' : 'Upload file tidak tersedia untuk model ini.')}</p>
            {canUpload && <p className="cwp-help">{en ? `Up to ${policy.max_files} files` : `Maksimal ${policy.max_files} file`}{policy.max_file_bytes ? ` · ${sizeLabel(policy.max_file_bytes)} ${en ? 'per file' : 'per file'}` : ''}</p>}
            {canUpload && policy.max_text_bytes && <p className="cwp-help">{en ? 'Combined text files' : 'Gabungan file teks'}: {sizeLabel(policy.max_text_bytes)}</p>}
            {canUpload && policy.max_total_file_bytes && <p className="cwp-help">{en ? 'All files combined' : 'Gabungan seluruh file'}: {sizeLabel(policy.max_total_file_bytes)}</p>}
        </div>
        {attachments.length > 0 ? <ul className="cwp-file-list">{attachments.map((file) => {
            const id = file.id || file.local_id;
            const download = ownedUrl(file.download_url);
            return <li key={file.local_id || file.id} className="cwp-file-row">
                <ChatIcon name={file.kind === 'image' ? 'image' : 'file'} />
                <div className="cwp-file-info">
                    {download ? <a href={download} className="cwp-file-name" title={file.name} download>{file.name}</a> : <span className="cwp-file-name" title={file.name}>{file.name}</span>}
                    <span className="cwp-file-meta">{sizeLabel(file.size || 0)} · {statusText(file)}</span>
                    {file.status === 'uploading' && <progress max={100} value={file.progress ?? undefined} aria-label={en ? `Uploading ${file.name}` : `Mengunggah ${file.name}`} />}
                    {file.error && <p className="cwp-error">{t(file.error)}</p>}
                    {file.status === 'failed' && file.file && onChange?.retry && <button type="button" className="cwp-text-button" disabled={busy.has(id)} onClick={() => run(id, () => onChange.retry(file.local_id))}><ChatIcon name="refresh" />{en ? 'Retry upload' : 'Ulangi upload'}</button>}
                </div>
                {onChange?.remove && <button type="button" className="cwp-icon-button" disabled={busy.has(id) || file.removing} onClick={() => run(id, () => onChange.remove(id))} aria-label={en ? `Remove ${file.name}` : `Hapus ${file.name}`} title={en ? 'Remove attachment' : 'Hapus lampiran'}><ChatIcon name="close" /></button>}
            </li>;
        })}</ul> : <p className="cwp-empty">{en ? 'No files attached to this conversation.' : 'Belum ada file terlampir di percakapan ini.'}</p>}
        {error && <p className="cwp-error" role="alert">{t(error)}</p>}
    </section>;
}

function Tools({ capabilities, tools, onToolsChange, en }) {
    const { localizedPath, t } = useLocale();
    const [error, setError] = useState('');
    const labels = {
        web_search: [en ? 'Web search' : 'Pencarian web', 'landing'],
        image_generation: [en ? 'Image generation' : 'Pembuatan gambar', 'image'],
        code_interpreter: [en ? 'Code interpreter' : 'Eksekusi kode', 'api'],
        file_analysis: [en ? 'File analysis' : 'Analisis file', 'file'],
    };
    if (!capabilities) return <p className="cwp-empty">{en ? 'Capabilities have not been loaded. Tools stay unavailable until the server confirms support.' : 'Capability belum dimuat. Alat tetap nonaktif sampai server mengonfirmasi dukungan.'}</p>;
    return <div className="cwp-tools-content">
        <p className="cwp-help">{en ? 'Selections apply to your next message. Availability comes from this model, your access, and implemented server tools.' : 'Pilihan berlaku untuk pesan berikutnya. Ketersediaan mengikuti model, hak akses, dan alat server yang benar-benar tersedia.'}</p>
        <ul className="cwp-tools-list">{Object.entries(capabilities.tools || {}).map(([id, capability]) => {
            const [label, icon] = labels[id] || [capability.name || id, 'settings'];
            // Studio routes follow the active UI locale (/en/...), like the rest of the workspace navigation.
            const href = typeof capability.href === 'string' && /^\/(?!\/)/.test(capability.href) && !capability.href.includes('\\') ? localizedPath(capability.href) : null;
            return <li key={id} className="cwp-tool-row">
                <ChatIcon name={icon} />
                <div className="cwp-tool-description"><strong id={`cwp-tool-${id}`}>{label}</strong>
                    <p id={`cwp-tool-${id}-reason`}>{(capability.reason && t(capability.reason)) || (capability.available ? (href ? (en ? 'Open the studio to create media.' : 'Buka studio untuk membuat media.') : (en ? 'Available for the next message.' : 'Tersedia untuk pesan berikutnya.')) : (en ? 'Not available for this model or account.' : 'Tidak tersedia untuk model atau akun ini.'))}</p>
                </div>
                {href ? <a href={href} className="cwp-studio-link" aria-label={en ? `Open ${label}` : `Buka ${label}`} title={en ? 'Open studio' : 'Buka studio'}><ChatIcon name="external" /></a>
                    : <button type="button" className="cwp-tool-switch" role="switch" aria-checked={Boolean(capability.available && tools[id])} aria-labelledby={`cwp-tool-${id}`} aria-describedby={`cwp-tool-${id}-reason`} disabled={!capability.available || !onToolsChange} onClick={async () => {
                        try { await onToolsChange({ ...tools, [id]: !tools[id] }); setError(''); } catch (failure) { setError(failure.message); }
                    }}><span /></button>}
            </li>;
        })}</ul>
        {/* The state label is short; the server's reason carries the detail, so nothing is said twice. */}
        {capabilities.provider_status && <section className="cwp-provider-status"><h3>{en ? 'Provider status' : 'Status penyedia'}</h3><p>{({
            configured: en ? 'Configured.' : 'Terkonfigurasi.',
            unverified: en ? 'Not verified yet.' : 'Belum terverifikasi.',
            unavailable: en ? 'Unavailable.' : 'Tidak tersedia.',
        })[capabilities.provider_status.state] || capabilities.provider_status.state}</p>{capabilities.provider_status.reason && <p className="cwp-help">{t(capabilities.provider_status.reason)}</p>}</section>}
        {error && <p className="cwp-error" role="alert">{t(error)}</p>}
    </div>;
}

export default function WorkspaceContextPanel({ tab, onTabChange, conversation, workspace, capabilities, attachments = [], tools = {}, onToolsChange, onAttachmentsChange, onWorkspaceChange, onWorkspaceReload, onDirtyChange, onClose, artifactsProps }) {
    const { locale } = useLocale();
    const en = locale === 'en';
    const [drawer, setDrawer] = useState(() => typeof window !== 'undefined' && window.matchMedia('(max-width: 1180px)').matches);
    const panel = useRef(null);
    const tabRefs = useRef({});
    const closeRef = useRef(onClose);
    closeRef.current = onClose;
    const id = useId();
    const active = TABS.includes(tab) ? tab : 'context';
    const labels = { context: en ? 'Context' : 'Konteks', tools: en ? 'Tools' : 'Alat', artifacts: en ? 'Artifacts' : 'Hasil' };
    useEffect(() => {
        const media = window.matchMedia('(max-width: 1180px)');
        const changed = () => setDrawer(media.matches);
        media.addEventListener('change', changed);
        const previous = document.activeElement;
        tabRefs.current[active]?.focus({ preventScroll: true });
        return () => { media.removeEventListener('change', changed); if (previous instanceof HTMLElement && previous.isConnected) previous.focus({ preventScroll: true }); };
    }, []);
    const handleKeys = (event) => {
        if (document.querySelector('dialog[open]')) return;
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); closeRef.current?.(); return; }
        if (!drawer || event.key !== 'Tab') return;
        const controls = [...panel.current.querySelectorAll('button:not(:disabled),a[href],textarea:not(:disabled),input:not(:disabled),select:not(:disabled),[tabindex="0"]')].filter((element) => element.getClientRects().length > 0);
        const first = controls[0]; const last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    };
    return <>
        {drawer && <button type="button" className="cwp-panel-backdrop" aria-label={en ? 'Close workspace panel' : 'Tutup panel workspace'} tabIndex={-1} onClick={onClose} />}
        <aside ref={panel} className={`cwp-panel${drawer ? ' is-drawer' : ''}`} role={drawer ? 'dialog' : 'complementary'} aria-modal={drawer ? true : undefined} aria-label={en ? 'Conversation workspace panel' : 'Panel workspace percakapan'} onKeyDown={handleKeys}>
            <div className="cwp-panel-header">
                <div className="cwp-tabs" role="tablist" aria-label={en ? 'Workspace sections' : 'Bagian workspace'}>{TABS.map((value, index) => <button key={value} type="button" id={`${id}-${value}-tab`} role="tab" aria-selected={active === value} aria-controls={`${id}-${value}-panel`} tabIndex={active === value ? 0 : -1} ref={(element) => { tabRefs.current[value] = element; }} onClick={() => onTabChange(value)} onKeyDown={(event) => {
                    const next = event.key === 'ArrowRight' ? (index + 1) % TABS.length : event.key === 'ArrowLeft' ? (index + TABS.length - 1) % TABS.length : event.key === 'Home' ? 0 : event.key === 'End' ? TABS.length - 1 : null;
                    if (next != null) { event.preventDefault(); onTabChange(TABS[next]); tabRefs.current[TABS[next]]?.focus(); }
                }}>{labels[value]}</button>)}</div>
                <button type="button" className="cwp-icon-button" onClick={onClose} title={en ? 'Close panel' : 'Tutup panel'} aria-label={en ? 'Close panel' : 'Tutup panel'}><ChatIcon name="close" /></button>
            </div>
            <div className="cwp-panel-body" id={`${id}-context-panel`} role="tabpanel" aria-labelledby={`${id}-context-tab`} hidden={active !== 'context'} tabIndex={0}>
                {conversation?.title && <p className="cwp-context-title" title={conversation.title}>{conversation.title}</p>}
                <Attachments key={conversation?.conversation_id || 'new'} attachments={attachments} capabilities={capabilities} onChange={onAttachmentsChange} en={en} />
                {workspace ? <WorkspaceNotes key={workspace.id} workspace={workspace} onWorkspaceChange={onWorkspaceChange} onWorkspaceReload={onWorkspaceReload} onDirtyChange={onDirtyChange} en={en} /> : <p className="cwp-empty">{en ? 'Choose a workspace to edit its notes.' : 'Pilih workspace untuk mengedit catatannya.'}</p>}
                <section className="cwp-context-section"><div className="cwp-section-heading"><h3>{en ? 'Selected tools' : 'Alat yang dipilih'}</h3><button type="button" className="cwp-text-button" onClick={() => onTabChange('tools')}>{en ? 'Manage' : 'Atur'}</button></div>
                    <p className="cwp-help">{Object.entries(tools).filter(([name, selected]) => selected && capabilities?.tools?.[name]?.available && !capabilities.tools[name].href).map(([name]) => ({ file_analysis: en ? 'File analysis' : 'Analisis file', web_search: en ? 'Web search' : 'Pencarian web', code_interpreter: en ? 'Code interpreter' : 'Eksekusi kode' })[name] || name).join(', ') || (en ? 'No tools selected for the next message.' : 'Tidak ada alat dipilih untuk pesan berikutnya.')}</p>
                </section>
                <button type="button" className="cwp-artifacts-link" onClick={() => onTabChange('artifacts')}><ChatIcon name="template" /><span>{en ? 'View saved artifacts' : 'Lihat hasil tersimpan'}</span><ChatIcon name="chevron" /></button>
            </div>
            <div className="cwp-panel-body" id={`${id}-tools-panel`} role="tabpanel" aria-labelledby={`${id}-tools-tab`} hidden={active !== 'tools'} tabIndex={0}><Tools capabilities={capabilities} tools={tools} onToolsChange={onToolsChange} en={en} /></div>
            <div className="cwp-panel-body" id={`${id}-artifacts-panel`} role="tabpanel" aria-labelledby={`${id}-artifacts-tab`} hidden={active !== 'artifacts'} tabIndex={0}><ArtifactPanel {...artifactsProps} /></div>
        </aside>
    </>;
}

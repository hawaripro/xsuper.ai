import React, { useEffect, useId, useRef, useState } from 'react';
import { useLocale } from '../../contexts/LocaleContext';
import MediaActionDialog from '../MediaActionDialog';
import ChatIcon from './ChatIcon';
import './chat-workspace-panels.css';

export default function WorkspaceNavigation({ state, actions, collapsed = false, onCollapse, modelLabel = (model) => model }) {
    const { locale, t } = useLocale();
    const en = locale === 'en';
    const searchRef = useRef(null);
    const nameRef = useRef(null);
    const [dialog, setDialog] = useState(null);
    const [name, setName] = useState('');
    const [busy, setBusy] = useState('');
    const [error, setError] = useState('');
    const searchId = useId();
    const workspaceId = useId();
    const nameId = useId();
    const workspace = state.workspaces.find((item) => String(item.id) === String(state.workspaceId));
    const pinned = state.conversations.filter((conversation) => conversation.pinned);
    const recent = state.conversations.filter((conversation) => !conversation.pinned);

    useEffect(() => {
        const search = (event) => {
            if (!(event.ctrlKey || event.metaKey) || event.key.toLowerCase() !== 'k' || event.altKey || document.querySelector('dialog[open]')) return;
            event.preventDefault();
            if (collapsed) onCollapse?.();
            requestAnimationFrame(() => searchRef.current?.focus());
        };
        window.addEventListener('keydown', search);
        return () => window.removeEventListener('keydown', search);
    }, [collapsed, onCollapse]);

    useEffect(() => { if (dialog && dialog.type !== 'delete') nameRef.current?.focus(); }, [dialog]);

    const perform = async (id, action) => {
        setBusy(id); setError('');
        try { return await action(); }
        catch (failure) { setError(failure.message); return null; }
        finally { setBusy(''); }
    };
    const openDialog = (type, item = null) => {
        setDialog({ type, item }); setName(item?.name || item?.title || ''); setError('');
    };
    const confirm = async () => {
        setBusy('dialog'); setError('');
        try {
            if (dialog.type === 'workspace-new') await actions.createWorkspace(name.trim());
            if (dialog.type === 'workspace-rename') await actions.renameWorkspace(dialog.item.id, name.trim());
            if (dialog.type === 'conversation-rename') await actions.renameConversation(dialog.item.conversation_id, name.trim());
            if (dialog.type === 'delete') await actions.deleteConversation(dialog.item.conversation_id);
            setDialog(null);
        } catch (failure) { setError(failure.message); }
        finally { setBusy(''); }
    };
    const title = dialog?.type === 'workspace-new' ? (en ? 'Create workspace' : 'Buat workspace')
        : dialog?.type === 'workspace-rename' ? (en ? 'Rename workspace' : 'Ganti nama workspace')
            : dialog?.type === 'delete' ? (en ? 'Delete conversation?' : 'Hapus percakapan?')
                : (en ? 'Rename conversation' : 'Ganti judul percakapan');
    const conversationRow = (conversation) => {
        const id = String(conversation.conversation_id);
        const current = id === String(state.conversationId);
        const label = conversation.title || (en ? 'Untitled conversation' : 'Percakapan tanpa judul');
        return <div key={id} className={`cwp-history-row${current ? ' is-current' : ''}`}>
            <button type="button" className="cwp-conversation" aria-current={current ? 'page' : undefined} title={label}
                disabled={busy === id} onClick={() => perform(id, () => actions.selectConversation(id))}>
                <ChatIcon name={conversation.pinned ? 'pin' : 'chat'} />
                <span><span className="cwp-conversation-title">{label}</span>{conversation.model && <span className="cwp-conversation-model">{modelLabel(conversation.model)}</span>}</span>
            </button>
            <details className="cwp-conversation-menu" onKeyDown={(event) => {
                if (event.key === 'Escape') { event.currentTarget.open = false; event.currentTarget.querySelector('summary')?.focus(); event.stopPropagation(); }
            }} onBlur={(event) => { if (!event.currentTarget.contains(event.relatedTarget)) event.currentTarget.open = false; }}>
                <summary aria-label={en ? `Actions for ${label}` : `Tindakan untuk ${label}`} title={en ? 'Conversation actions' : 'Tindakan percakapan'}><ChatIcon name="more" /></summary>
                <div className="cwp-conversation-menu-items">
                    <button type="button" onClick={(event) => { event.currentTarget.closest('details').open = false; openDialog('conversation-rename', conversation); }}><ChatIcon name="edit" />{en ? 'Rename' : 'Ganti judul'}</button>
                    <button type="button" disabled={busy === id} onClick={(event) => { event.currentTarget.closest('details').open = false; perform(id, () => actions.pinConversation(id, !conversation.pinned)); }}><ChatIcon name="pin" />{conversation.pinned ? (en ? 'Unpin' : 'Lepas sematan') : (en ? 'Pin' : 'Sematkan')}</button>
                    <button type="button" className="cwp-danger" onClick={(event) => { event.currentTarget.closest('details').open = false; openDialog('delete', conversation); }}><ChatIcon name="trash" />{en ? 'Delete' : 'Hapus'}</button>
                </div>
            </details>
        </div>;
    };

    return <div className={`cwp-navigation${collapsed ? ' is-collapsed' : ''}`}>
        <div className="cwp-nav-actions">
            <button type="button" className="cwp-new-chat" disabled={Boolean(busy || state.loading.workspaces || state.loading.creating || !state.workspaceId)}
                title={en ? 'New conversation' : 'Chat baru'} aria-label={en ? 'New conversation' : 'Chat baru'}
                onClick={() => perform('new', () => actions.newConversation())}>
                <ChatIcon name="plus" />{!collapsed && <span>{en ? 'New conversation' : 'Chat baru'}</span>}
            </button>
            {collapsed && onCollapse && <button type="button" className="cwp-icon-button" onClick={onCollapse} title={en ? 'Show workspaces and history' : 'Tampilkan workspace dan riwayat'} aria-label={en ? 'Show workspaces and history' : 'Tampilkan workspace dan riwayat'}><ChatIcon name="search" /></button>}
        </div>
        <div className="cwp-nav-expanded" hidden={collapsed}>
            <div className="cwp-workspace-picker">
                <label htmlFor={workspaceId}>{en ? 'Workspace' : 'Workspace'}</label>
                <div className="cwp-workspace-select-row">
                    <select id={workspaceId} value={state.workspaceId ?? ''} disabled={state.loading.workspaces || busy === 'workspace'}
                        title={workspace?.name} onChange={(event) => perform('workspace', () => actions.setWorkspace(event.target.value))}>
                        {!state.workspaces.length && <option value="">{state.loading.workspaces ? (en ? 'Loading…' : 'Memuat…') : (en ? 'No workspace' : 'Belum ada workspace')}</option>}
                        {state.workspaces.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                    </select>
                    <button type="button" className="cwp-icon-button" disabled={!workspace} title={en ? 'Rename workspace' : 'Ganti nama workspace'} aria-label={en ? 'Rename workspace' : 'Ganti nama workspace'} onClick={() => openDialog('workspace-rename', workspace)}><ChatIcon name="edit" /></button>
                </div>
                <button type="button" className="cwp-text-button" onClick={() => openDialog('workspace-new')}><ChatIcon name="plus" />{en ? 'New workspace' : 'Workspace baru'}</button>
            </div>
            <div className="cwp-history-search">
                <label htmlFor={searchId} className="cwp-sr-only">{en ? 'Search this workspace’s history' : 'Cari riwayat workspace ini'}</label>
                <ChatIcon name="search" />
                <input ref={searchRef} id={searchId} type="search" maxLength={120} placeholder={en ? 'Search conversations' : 'Cari percakapan'} value={state.search} onChange={(event) => actions.setSearch(event.target.value)} aria-describedby={`${searchId}-scope`} />
            </div>
            <p id={`${searchId}-scope`} className="cwp-search-scope">{en ? 'All conversations in this workspace' : 'Seluruh percakapan di workspace ini'}</p>
            {(error && !dialog) || state.errors.history || state.errors.workspace ? <div className="cwp-nav-error" role="alert">
                <p>{t((error && !dialog ? error : '') || state.errors.history || state.errors.workspace)}</p>
                <button type="button" className="cwp-text-button" disabled={state.loading.history || state.loading.workspaces} onClick={() => perform('reload', () => state.errors.workspace ? actions.initialize() : actions.refreshHistory())}><ChatIcon name="refresh" />{en ? 'Try again' : 'Coba lagi'}</button>
            </div> : null}
            <nav className="cwp-history-list" aria-label={en ? 'Conversation history' : 'Riwayat percakapan'} aria-busy={state.loading.history}>
                {pinned.length > 0 && <section><h2>{en ? 'Pinned' : 'Disematkan'}</h2>{pinned.map(conversationRow)}</section>}
                {recent.length > 0 && <section><h2>{state.search ? (en ? 'Search results' : 'Hasil pencarian') : (en ? 'Recent' : 'Terbaru')}</h2>{recent.map(conversationRow)}</section>}
                {!state.conversations.length && !state.loading.history && !state.loading.workspaces && !state.errors.history && !state.errors.workspace && <p className="cwp-empty">{state.search ? (en ? 'No conversations match this search.' : 'Tidak ada percakapan yang sesuai pencarian.') : (en ? 'No conversations yet. Start a new chat in this workspace.' : 'Belum ada percakapan. Mulai chat baru di workspace ini.')}</p>}
                {(state.loading.history || state.loading.workspaces) && <p className="cwp-loading" role="status">{en ? 'Loading conversations…' : 'Memuat percakapan…'}</p>}
                {state.nextCursor && <button type="button" className="cwp-load-more" disabled={state.loading.history} onClick={() => perform('more', () => actions.loadMoreHistory())}>{en ? 'Load more' : 'Muat lainnya'}</button>}
            </nav>
        </div>
        {dialog && <MediaActionDialog title={title}
            description={dialog.type === 'delete' ? (en ? `“${dialog.item.title || 'Untitled conversation'}” and its conversation resources will be deleted. Any running response will be stopped. This cannot be undone.` : `“${dialog.item.title || 'Percakapan tanpa judul'}” beserta sumber daya percakapan akan dihapus. Jawaban yang berjalan akan dihentikan. Tindakan ini tidak dapat dibatalkan.`) : (en ? 'Use a name that makes this work easy to find.' : 'Gunakan nama yang mudah ditemukan kembali.')}
            closeLabel={en ? 'Cancel' : 'Batal'} confirmLabel={dialog.type === 'delete' ? (en ? 'Delete conversation' : 'Hapus percakapan') : (en ? 'Save' : 'Simpan')}
            busyLabel={en ? 'Saving…' : 'Menyimpan…'} busy={busy === 'dialog'} confirmDisabled={dialog.type !== 'delete' && !name.trim()}
            error={error} onConfirm={confirm} onClose={() => { setDialog(null); setError(''); }}>
            {dialog.type !== 'delete' && <label htmlFor={nameId} className="cwp-dialog-label">{en ? 'Name' : 'Nama'}<input ref={nameRef} id={nameId} className="cwp-dialog-input" value={name} maxLength={200} onChange={(event) => setName(event.target.value)} onKeyDown={(event) => { if (event.key === 'Enter' && !event.nativeEvent.isComposing && name.trim() && !busy) { event.preventDefault(); confirm(); } }} /></label>}
        </MediaActionDialog>}
    </div>;
}

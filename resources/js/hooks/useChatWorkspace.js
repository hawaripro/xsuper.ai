import { useEffect, useMemo, useReducer, useRef } from 'react';
import { apiRequest, ApiError, responseError } from '../lib/api';
import { useLocale } from '../contexts/LocaleContext';

const ACTIVE = new Set(['sending', 'queued', 'pending', 'running', 'streaming', 'stopping', 'stop_requested']);
const TERMINAL = new Set(['completed', 'complete', 'stopped', 'failed', 'cancelled']);
const keyOf = (conversation) => String(conversation?.conversation_id ?? conversation?.conversation_key ?? '');
const pathFor = (key) => `/api/c/h/${encodeURIComponent(key)}`;
const uuid = () => crypto.randomUUID();
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content
    || decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] || '');
const storageKey = (user, key) => `xsuper:chat-draft:v2:${encodeURIComponent(user)}:${encodeURIComponent(key)}`;
const isDefaultTools = (tools) => Object.entries(tools || {}).every(([name, value]) => name === 'file_analysis' ? value === true : !value);

function readDraft(user, key) {
    if (!user) return {};
    try {
        const value = JSON.parse(localStorage.getItem(storageKey(user, key)) || '{}');
        return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
    } catch { return {}; }
}

function makeEntry(user, key, workspaceId) {
    const saved = readDraft(user, key);
    const attempt = saved.attempt?.payload?.conversation_id === key && typeof saved.attempt?.payload?.client_request_id === 'string' ? saved.attempt : null;
    const operationId = attempt?.operationId || null;
    return {
        conversation: null, messages: [], draft: typeof saved.draft === 'string' ? saved.draft : '',
        attachments: [], tools: saved.tools && typeof saved.tools === 'object' && !Array.isArray(saved.tools) ? saved.tools : { file_analysis: true },
        operation: attempt ? {
            id: operationId, operation_id: operationId, status: 'uncertain', model: attempt.payload.model, recoverable: true,
            client_request_id: attempt.payload.client_request_id, started_at: attempt.startedAt || null,
        } : null,
        attempt, uploadPolicy: null, loaded: false, loading: false,
        workspaceId, errors: { conversation: '', stream: '', upload: '' },
    };
}

function normalizeMessage(message) {
    return { ...message, id: String(message.id), content: message.content ?? '', attachments: message.attachments || [], status: message.status || 'completed' };
}

function acceptedMime(file, mimes = [], extensions = []) {
    if (extensions.some((extension) => file.name.toLowerCase().endsWith(`.${String(extension).replace(/^\./, '').toLowerCase()}`))) return true;
    return mimes.some((mime) => mime === file.type || (mime.endsWith('/*') && file.type.startsWith(mime.slice(0, -1))));
}

export default function useChatWorkspace({ user, selectedModel, onModelChange }) {
    const { locale, t } = useLocale();
    const latest = useRef({ selectedModel, onModelChange, locale, t });
    latest.current = { selectedModel, onModelChange, locale, t };
    const userKey = user?.id == null ? '' : String(user.id);
    const [, redraw] = useReducer((value) => value + 1, 0);
    const session = useMemo(() => ({
        userKey, disposed: false, entries: new Map(), requests: new Set(), uploads: new Map(), creating: new Map(),
        streams: new Map(), polls: new Map(), deleted: new Set(), overrides: new Map(), mutations: 0,
        viewVersion: 0, historyVersion: 0, capabilityVersion: 0,
        data: {
            workspaces: [], workspaceId: null, conversations: [], nextCursor: null, search: '', activeKey: 'new:',
            capabilities: null, capabilityModel: '', loading: { history: false, capabilities: false, workspaces: false },
            errors: { history: '', capabilities: '', workspace: '', persistence: '' },
        },
    }), [userKey]);

    const actions = useMemo(() => {
        const text = (id, en) => latest.current.locale === 'en' ? en : id;
        const notify = () => { if (!session.disposed) redraw(); };
        const entry = (key = session.data.activeKey) => {
            if (!session.entries.has(key)) session.entries.set(key, makeEntry(session.userKey, key, session.data.workspaceId));
            return session.entries.get(key);
        };
        const patch = (key, update) => {
            if (session.disposed || session.deleted.has(key)) return;
            const current = entry(key);
            const next = typeof update === 'function' ? update(current) : { ...current, ...update };
            if (next.messages !== current.messages) next.messageRevision = (current.messageRevision || 0) + 1;
            if (next.attachments !== current.attachments) next.filesRevision = (current.filesRevision || 0) + 1;
            session.entries.set(key, next);
            notify();
        };
        const errorAt = (key, type, error) => patch(key, (current) => ({ ...current, errors: { ...current.errors, [type]: error ? (error.message || String(error)) : '' } }));
        const persist = (key) => {
            if (!session.userKey || session.deleted.has(key)) return;
            const current = entry(key);
            const status = current.operation?.status;
            const attempt = current.attempt?.payload && (ACTIVE.has(status) || status === 'uncertain') ? {
                payload: current.attempt.payload, text: current.attempt.text, draftAtSend: current.attempt.draftAtSend,
                attachments: current.attempt.attachments, operationId: current.attempt.operationId, accepted: current.attempt.accepted,
                kind: current.attempt.kind, startedAt: current.attempt.startedAt,
            } : null;
            try {
                if (!current.draft && !attempt && isDefaultTools(current.tools)) localStorage.removeItem(storageKey(session.userKey, key));
                else localStorage.setItem(storageKey(session.userKey, key), JSON.stringify({ draft: current.draft, tools: current.tools, attempt }));
                session.data.errors.persistence = '';
            } catch {
                session.data.errors.persistence = text('Draft hanya tersimpan di tab ini; penyimpanan browser tidak tersedia.', 'Draft is only kept in this tab; browser storage is unavailable.');
            }
        };
        const request = async (path, options = {}) => {
            const controller = new AbortController();
            session.requests.add(controller);
            try { return await apiRequest(path, { ...options, signal: controller.signal }); }
            finally { session.requests.delete(controller); }
        };
        const valid = (key) => !session.disposed && !session.deleted.has(key);
        const updateWorkspace = (workspace) => {
            if (session.disposed) return;
            const index = session.data.workspaces.findIndex((item) => String(item.id) === String(workspace.id));
            session.data.workspaces = index < 0 ? [...session.data.workspaces, workspace]
                : session.data.workspaces.map((item, i) => i === index ? workspace : item);
            notify();
        };
        // Local renames/pins survive a history page that was requested before they were saved.
        const updateConversation = (conversation) => {
            const key = keyOf(conversation);
            if (!key || !valid(key)) return;
            session.overrides.set(key, { conversation, mark: ++session.mutations });
            patch(key, { conversation, workspaceId: conversation.workspace_id });
            const inList = session.data.conversations.some((item) => keyOf(item) === key);
            if (inList) session.data.conversations = session.data.conversations.map((item) => keyOf(item) === key ? conversation : item);
            else if (!session.data.search.trim() && String(conversation.workspace_id) === String(session.data.workspaceId)) {
                session.data.conversations = [conversation, ...session.data.conversations];
            }
            notify();
        };
        const loadHistory = async (append = false) => {
            if (!session.userKey || session.data.workspaceId == null) return null;
            if (append && (!session.data.nextCursor || session.data.loading.history)) return null;
            session.historyController?.abort();
            const controller = new AbortController();
            session.historyController = controller;
            const version = ++session.historyVersion;
            const mark = session.mutations;
            const query = session.data.search.trim();
            const params = new URLSearchParams({ workspace_id: String(session.data.workspaceId) });
            if (query) params.set('q', query);
            if (append) params.set('cursor', session.data.nextCursor);
            session.data.loading.history = true;
            session.data.errors.history = '';
            notify();
            try {
                const data = await apiRequest(`/api/c/h?${params}`, { signal: controller.signal });
                if (session.disposed || version !== session.historyVersion) return null;
                if (!Array.isArray(data?.conversations)) throw new Error(text('Respons riwayat tidak valid.', 'Invalid conversation history response.'));
                const page = data.conversations.filter((item) => !session.deleted.has(keyOf(item))).map((item) => {
                    const override = session.overrides.get(keyOf(item));
                    return override && override.mark > mark ? override.conversation : item;
                });
                // Titles derive from the first saved turn, so refreshed history also refreshes open conversations.
                for (const item of page) {
                    const existing = session.entries.get(keyOf(item));
                    if (existing?.conversation && existing.conversation !== item) session.entries.set(keyOf(item), { ...existing, conversation: item });
                }
                const rows = append ? [...session.data.conversations, ...page] : page;
                session.data.conversations = [...new Map(rows.map((item) => [keyOf(item), item])).values()];
                session.data.nextCursor = data.next_cursor || null;
                return data;
            } catch (error) {
                if (error.name === 'AbortError' || version !== session.historyVersion || session.disposed) return null;
                session.data.errors.history = error.message;
                throw error;
            } finally {
                if (!session.disposed && version === session.historyVersion) { session.data.loading.history = false; notify(); }
            }
        };
        const loadAttachments = async (key) => {
            const revision = (entry(key).filesRevision || 0) + 1;
            patch(key, { filesRevision: revision });
            const data = await request(`${pathFor(key)}/attachments`);
            if (!valid(key)) return data;
            if (entry(key).filesRevision !== revision) { patch(key, { uploadPolicy: data?.upload_policy || null }); return data; }
            if (!Array.isArray(data?.attachments)) throw new Error(text('Respons lampiran tidak valid.', 'Invalid attachment response.'));
            patch(key, (current) => {
                const pending = current.attachments.filter((item) => !item.id || session.uploads.has(item.local_id));
                const pendingIds = new Set(pending.map((item) => item.id).filter(Boolean));
                const stored = [...data.attachments, ...(Array.isArray(data.unavailable_attachments) ? data.unavailable_attachments : [])];
                return { ...current, uploadPolicy: data.upload_policy || null, attachments: [...stored.filter((item) => !pendingIds.has(item.id)), ...pending] };
            });
            return data;
        };
        const ensureConversation = async (sourceKey = session.data.activeKey) => {
            if (!sourceKey.startsWith('new:')) return sourceKey;
            if (session.creating.has(sourceKey)) return session.creating.get(sourceKey);
            const workspaceId = entry(sourceKey).workspaceId ?? session.data.workspaceId;
            if (workspaceId == null) throw new Error(text('Tunggu workspace selesai dimuat.', 'Wait for the workspace to load.'));
            const viewVersion = session.viewVersion;
            const promise = (async () => {
                try {
                    const data = await request('/api/c/h', { method: 'POST', body: { conversation_id: uuid(), workspace_id: workspaceId } });
                    const conversation = data?.conversation;
                    const key = keyOf(conversation);
                    if (!key) throw new Error(text('Percakapan belum dikonfirmasi server.', 'The server did not confirm the conversation.'));
                    if (session.disposed) return key;
                    session.entries.set(key, { ...entry(sourceKey), conversation, workspaceId: conversation.workspace_id ?? workspaceId, loaded: true });
                    session.entries.delete(sourceKey);
                    try { localStorage.removeItem(storageKey(session.userKey, sourceKey)); } catch { /* In-memory draft remains available. */ }
                    persist(key);
                    if (session.data.activeKey === sourceKey && session.viewVersion === viewVersion) session.data.activeKey = key;
                    updateConversation(conversation);
                    return key;
                } catch (error) { errorAt(sourceKey, 'conversation', error); throw error; }
                finally { session.creating.delete(sourceKey); notify(); }
            })();
            session.creating.set(sourceKey, promise);
            notify();
            return promise;
        };
        const applyOperation = (key, operation, attempt = entry(key).attempt) => {
            if (!valid(key) || (operation.conversation_id && String(operation.conversation_id) !== key)) return;
            const id = operation.operation_id || operation.id || entry(key).operation?.id || null;
            patch(key, (current) => {
                const previous = current.operation || {};
                const next = { ...previous, ...operation, id, operation_id: id };
                let messages = current.messages;
                const userId = operation.user_message_id;
                const assistantId = operation.assistant_message_id;
                if (userId != null && attempt && !messages.some((message) => message.id === String(userId))) {
                    messages = [...messages, normalizeMessage({ id: userId, role: 'user', content: attempt.text, model: attempt.payload.model, attachments: attempt.attachments, operation_id: id })];
                }
                if (assistantId != null) {
                    const previousMessage = messages.find((message) => message.id === String(assistantId));
                    const assistant = normalizeMessage({
                        ...previousMessage, id: assistantId, role: 'assistant', model: attempt?.payload.model || previousMessage?.model || operation.model,
                        content: typeof operation.partial_content === 'string' ? operation.partial_content : previousMessage?.content ?? '',
                        status: next.status, operation_id: id, created_at: previousMessage?.created_at ?? next.started_at ?? null,
                    });
                    messages = previousMessage ? messages.map((message) => message.id === assistant.id ? assistant : message) : [...messages, assistant];
                }
                return { ...current, operation: next, messages,
                    errors: { ...current.errors, stream: next.status === 'failed' ? (next.error || current.errors.stream) : TERMINAL.has(next.status) ? '' : current.errors.stream } };
            });
            if (attempt && id) {
                attempt.operationId = id;
                attempt.admit?.(id);
                if (!attempt.accepted) {
                    attempt.accepted = true;
                    if (attempt.draftAtSend != null && entry(key).draft === attempt.draftAtSend) patch(key, { draft: '' });
                }
                persist(key);
            }
        };
        const readOperation = async (key, id) => {
            const data = await request(`/api/c/operations/${encodeURIComponent(id)}`);
            if (!data?.operation) throw new Error(text('Status operasi tidak dapat dibaca.', 'The operation status could not be read.'));
            applyOperation(key, data.operation);
            return data.operation;
        };
        const pollOperation = (key, id) => {
            if (session.polls.has(key)) return session.polls.get(key).promise;
            const control = { timer: null, resolve: null };
            control.promise = (async () => {
                try {
                    while (valid(key)) {
                        const operation = await readOperation(key, id);
                        if (!ACTIVE.has(operation.status)) return operation;
                        await new Promise((resolve) => { control.resolve = resolve; control.timer = setTimeout(resolve, 1500); });
                    }
                    return null;
                } catch (error) { if (error.name !== 'AbortError' && valid(key)) errorAt(key, 'stream', error); throw error; }
                finally { session.polls.delete(key); notify(); }
            })();
            session.polls.set(key, control);
            return control.promise;
        };
        const runAttempt = async (key, attempt) => {
            if (session.streams.has(key)) return session.streams.get(key).promise;
            attempt.admission = new Promise((resolve) => { attempt.admit = resolve; });
            const controller = new AbortController();
            const control = { controller, promise: null };
            let sawFinal = false;
            let sawDone = false;
            let frameEvent = 'message';
            let frameData = [];
            let buffer = '';
            let received = '';
            let finishReason = null;
            let streamError = null;
            const dispatch = () => {
                if (!frameData.length) { frameEvent = 'message'; return; }
                const raw = frameData.join('\n');
                const event = frameEvent;
                frameData = []; frameEvent = 'message';
                if (raw === '[DONE]') { sawDone = true; return; }
                const data = JSON.parse(raw);
                if (!valid(key)) return;
                if (data.conversation_id && String(data.conversation_id) !== key) throw new Error(text('Respons tidak sesuai percakapan.', 'The response belongs to a different conversation.'));
                if (event === 'operation' || event === 'state') {
                    // A replay starts from the saved partial snapshot; later deltas only carry the new suffix.
                    if (typeof data.partial_content === 'string') received = data.partial_content;
                    applyOperation(key, data, attempt);
                } else if (event === 'final') {
                    if (!data.message?.id) throw new Error(text('Pesan akhir belum dikonfirmasi server.', 'The final message was not confirmed by the server.'));
                    applyOperation(key, { operation_id: data.operation_id, status: data.status, usage: data.usage, usage_known: data.usage_known }, attempt);
                    const message = normalizeMessage({ ...data.message, finish_reason: finishReason });
                    patch(key, (current) => ({ ...current,
                        messages: current.messages.some((item) => item.id === message.id)
                            ? current.messages.map((item) => item.id === message.id ? { ...message, created_at: message.created_at ?? item.created_at } : item)
                            : [...current.messages, message],
                        errors: { ...current.errors, stream: data.status === 'failed' ? (current.errors.stream || text('Jawaban gagal. Anda dapat mencoba ulang.', 'The response failed. You can retry.')) : '' } }));
                    sawFinal = true;
                } else if (event === 'error' || data.error) {
                    streamError = new Error(data.message || data.error?.message || text('Penyedia gagal menyelesaikan jawaban.', 'The provider could not finish the response.'));
                    if (data.status && attempt.operationId) applyOperation(key, { status: data.status, error: streamError.message }, attempt);
                    errorAt(key, 'stream', streamError);
                } else {
                    const choice = data.choices?.[0];
                    if (choice?.finish_reason) finishReason = choice.finish_reason;
                    const delta = choice?.delta?.content ?? choice?.delta?.refusal;
                    if (typeof delta === 'string' && delta) {
                        received += delta;
                        const assistantId = entry(key).operation?.assistant_message_id;
                        if (assistantId == null) throw new Error(text('Identitas pesan streaming belum diterima.', 'The stream did not supply a message identity.'));
                        patch(key, (current) => ({ ...current, messages: current.messages.map((message) => message.id === String(assistantId)
                            ? { ...message, content: received, status: 'streaming' } : message), operation: { ...current.operation, status: 'streaming' } }));
                    }
                }
            };
            const consume = (chunk, end = false) => {
                buffer += chunk;
                let newline;
                while ((newline = buffer.indexOf('\n')) >= 0) {
                    const line = buffer.slice(0, newline).replace(/\r$/, '');
                    buffer = buffer.slice(newline + 1);
                    if (!line) dispatch();
                    else if (line.startsWith('event:')) frameEvent = line.slice(6).trim();
                    else if (line.startsWith('data:')) frameData.push(line.slice(5).replace(/^ /, ''));
                }
                if (end) {
                    if (buffer.trim()) throw new Error(text('Stream terputus di tengah data.', 'The stream ended in an incomplete frame.'));
                    dispatch();
                }
            };
            control.promise = (async () => {
                patch(key, (current) => ({ ...current, attempt, operation: {
                    ...(attempt.operationId ? current.operation : {}), status: 'sending', model: attempt.payload.model,
                    client_request_id: attempt.payload.client_request_id, started_at: current.operation?.started_at || attempt.startedAt || Date.now(),
                }, errors: { ...current.errors, stream: '' } }));
                persist(key);
                // A browser transport failure ("Failed to fetch", "network error") has no member-facing text of its own.
                const offline = (failure) => failure?.name === 'TypeError'
                    ? new Error(text('Koneksi ke server terputus.', 'The connection to the server was lost.'), { cause: failure }) : failure;
                try {
                    const response = await fetch('/api/c/s', {
                        method: 'POST', credentials: 'same-origin', signal: controller.signal,
                        headers: { Accept: 'text/event-stream', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() },
                        body: JSON.stringify(attempt.payload),
                    }).catch((failure) => { throw offline(failure); });
                    if (!response.ok) throw responseError(response, await response.json().catch(() => null));
                    // Admission is durable once the server names the operation, even before the first event arrives.
                    const admitted = response.headers.get('X-Chat-Operation-Id');
                    if (admitted && valid(key)) applyOperation(key, { id: admitted }, attempt);
                    if (!response.body || !response.headers.get('content-type')?.includes('text/event-stream')) throw new Error(text('Respons streaming tidak valid.', 'Invalid streaming response.'));
                    const reader = response.body.getReader();
                    const decoder = new TextDecoder();
                    try {
                        for (;;) {
                            const { value, done } = await reader.read().catch((failure) => { throw offline(failure); });
                            if (done) break;
                            consume(decoder.decode(value, { stream: true }));
                        }
                        consume(decoder.decode(), true);
                    } finally { reader.releaseLock(); }
                    if (!sawFinal) throw streamError || new Error(text('Koneksi berakhir sebelum hasil tersimpan dikonfirmasi.', 'The connection ended before the saved result was confirmed.'));
                    if (streamError) throw streamError;
                    return entry(key).operation;
                } catch (error) {
                    if (!valid(key)) throw error;
                    if (sawFinal) { errorAt(key, 'stream', error); throw error; }
                    if (attempt.operationId) {
                        // The server owns the operation; follow its saved state instead of submitting again.
                        session.streams.delete(key);
                        if (error.name !== 'AbortError') errorAt(key, 'stream', error);
                        const recovered = await pollOperation(key, attempt.operationId);
                        if (recovered?.status === 'failed') throw new Error(recovered.error || error.message);
                        return recovered;
                    }
                    const rejected = error instanceof ApiError && error.status >= 400 && error.status < 500;
                    patch(key, (current) => ({ ...current, operation: { ...current.operation, status: rejected ? 'failed' : 'uncertain', can_stop: false, recoverable: !rejected },
                        errors: { ...current.errors, stream: rejected ? error.message : `${latest.current.t(error.message)} ${text('Sambungkan ulang permintaan yang sama; jangan kirim ulang sebagai pesan baru.', 'Reconnect the same request; do not submit it as a new message.')}` } }));
                    throw error;
                } finally {
                    attempt.admit?.(null);
                    session.streams.delete(key);
                    if (valid(key)) {
                        persist(key);
                        if (sawFinal || sawDone) loadHistory().catch(() => {});
                        notify();
                    }
                }
            })();
            session.streams.set(key, control);
            notify();
            return control.promise;
        };
        const fetchCapabilities = async () => {
            const model = latest.current.selectedModel;
            const version = ++session.capabilityVersion;
            session.capabilityController?.abort();
            const controller = new AbortController();
            session.capabilityController = controller;
            session.data.capabilities = null;
            session.data.capabilityModel = '';
            session.data.errors.capabilities = '';
            session.data.loading.capabilities = Boolean(model && session.userKey);
            notify();
            if (!model || !session.userKey) return null;
            const load = (async () => {
                try {
                    const data = await apiRequest(`/api/c/capabilities?${new URLSearchParams({ model })}`, { signal: controller.signal });
                    if (session.disposed || version !== session.capabilityVersion) return null;
                    if (!data?.input || !data?.tools) throw new Error(text('Capability model tidak dapat dibaca.', 'Model capabilities could not be read.'));
                    session.data.capabilities = data;
                    session.data.capabilityModel = model;
                    const key = session.data.activeKey;
                    const tools = { ...entry(key).tools };
                    Object.keys(tools).forEach((name) => { if (!data.tools[name]?.available || data.tools[name]?.href) tools[name] = false; });
                    patch(key, { tools });
                    persist(key);
                    return data;
                } catch (error) {
                    if (error.name === 'AbortError' || version !== session.capabilityVersion || session.disposed) return null;
                    session.data.errors.capabilities = error.message;
                    throw error;
                } finally { if (!session.disposed && version === session.capabilityVersion) { session.data.loading.capabilities = false; notify(); } }
            })();
            // Uploads started while this request is in flight wait for it instead of dropping the files.
            session.capabilityLoad = load;
            return load;
        };
        const stop = async (key = session.data.activeKey) => {
            const current = entry(key);
            if (!ACTIVE.has(current.operation?.status)) return current.operation;
            const attempt = current.attempt;
            let id = current.operation?.id || current.operation?.operation_id || attempt?.operationId || null;
            if (!attempt?.payload && session.creating.has(key)) {
                // Nothing reached the chat endpoint yet; send() observes this before submitting.
                const operation = { ...current.operation, status: 'stopped', stop_requested: true, local_only: true, can_stop: false };
                patch(key, { operation });
                return operation;
            }
            patch(key, (value) => ({ ...value, operation: { ...value.operation, stopping: true, stop_requested: true } }));
            try {
                if (!id && session.streams.has(key)) id = await attempt?.admission;
                // Without an admitted operation there is nothing server-side to stop; the stream outcome is already shown.
                if (!id) return entry(key).operation;
                const data = await request(`/api/c/operations/${encodeURIComponent(id)}/stop`, { method: 'POST' });
                if (!data?.operation) throw new Error(text('Penghentian belum dikonfirmasi server.', 'The server did not confirm the stop request.'));
                applyOperation(key, data.operation);
                session.streams.get(key)?.controller.abort();
                if (ACTIVE.has(data.operation.status)) return await pollOperation(key, id);
                return data.operation;
            } catch (error) { errorAt(key, 'stream', error); throw error; }
            finally { patch(key, (value) => ({ ...value, operation: value.operation ? { ...value.operation, stopping: false } : value.operation })); }
        };
        const validateFiles = (files, input, policy) => {
            const maxFiles = Math.min(input.max_files || 0, policy?.max_files ?? input.max_files ?? 0);
            if (files.length > maxFiles) throw new Error(text(`Maksimal ${maxFiles} file untuk model ini.`, `This model accepts up to ${maxFiles} files.`));
            let total = 0;
            let textTotal = 0;
            for (const file of files) {
                const type = file.mime || file.type || '';
                const candidate = { name: file.name || '', type };
                const role = Object.values(policy?.roles || {}).find((item) => acceptedMime(candidate, item.accepted_mimes, item.accepted_extensions));
                const isText = type.startsWith('text/') || type === 'application/json' || (!type && /\.(txt|md|markdown|csv|json)$/i.test(candidate.name));
                const maxBytes = Math.min(input.max_file_bytes || Infinity, input.limits_by_mime?.[type] || Infinity, role?.max_bytes || Infinity,
                    isText ? (role?.max_text_bytes || input.max_text_bytes || Infinity) : Infinity);
                if ((policy && !role) || !acceptedMime(candidate, input.accepted_mimes, input.accepted_extensions)) throw new Error(text(`Tipe file ${candidate.name} tidak didukung model atau kebijakan upload.`, `${candidate.name} is not supported by the model or upload policy.`));
                if (file.size > maxBytes) throw new Error(text(`${candidate.name} melebihi batas ${Math.floor(maxBytes / 1024 / 1024)} MB.`, `${candidate.name} exceeds the ${Math.floor(maxBytes / 1024 / 1024)} MB limit.`));
                total += file.size || 0;
                if (isText) textTotal += file.size || 0;
            }
            if (input.max_total_file_bytes && total > input.max_total_file_bytes) throw new Error(text('Jumlah ukuran lampiran melebihi batas konteks model.', 'The combined attachments exceed the model context limit.'));
            if (input.max_text_bytes && textTotal > input.max_text_bytes) throw new Error(text('Gabungan file teks melebihi batas konteks model.', 'The combined text files exceed the model context limit.'));
        };
        const send = async (textOverride, options = {}) => {
            const sourceKey = session.data.activeKey;
            let key = sourceKey;
            const current = entry(sourceKey);
            const blocked = current.operation?.status === 'uncertain'
                ? text('Sambungkan ulang atau abaikan permintaan sebelumnya yang belum terkonfirmasi.', 'Reconnect or dismiss the unconfirmed previous request first.')
                : current.loading || ACTIVE.has(current.operation?.status) || session.creating.has(sourceKey) || session.streams.has(sourceKey)
                    ? text('Tunggu pemuatan percakapan atau hentikan jawaban yang sedang berjalan.', 'Wait for the conversation to load or stop the current response.') : '';
            if (blocked) {
                const error = new Error(blocked);
                errorAt(sourceKey, 'stream', error);
                throw error;
            }
            const model = latest.current.selectedModel;
            const capabilities = session.data.capabilities;
            const targetId = options.retry_of || options.continuation_of || null;
            const override = typeof textOverride === 'string';
            const content = override ? textOverride : current.draft;
            let pending = null;
            let submitted = false;
            try {
                if (!model || session.data.capabilityModel !== model || !capabilities?.input?.text) throw new Error(text('Pilih model chat yang tersedia dan tunggu capability dimuat.', 'Choose an available chat model and wait for its capabilities.'));
                // Retries and continuations reuse the original turn and its files on the server.
                const files = targetId ? [] : current.attachments.filter((file) => file.status === 'ready');
                if (!targetId) {
                    if (current.attachments.some((file) => file.status !== 'ready')) throw new Error(text('Selesaikan upload atau hapus lampiran yang gagal atau tidak tersedia.', 'Finish uploads, or remove failed or unavailable attachments.'));
                    validateFiles(files, capabilities.input, current.uploadPolicy);
                    if (!content.trim() && !files.length) throw new Error(text('Tulis pesan atau lampirkan file.', 'Write a message or attach a file.'));
                }
                const selectedTools = {};
                for (const [name, value] of Object.entries(current.tools)) {
                    const capability = capabilities.tools[name];
                    if (value && (!capability?.available || capability.href)) throw new Error(capability?.reason || text('Alat yang dipilih tidak tersedia.', 'The selected tool is unavailable.'));
                    if (capability && !capability.href) selectedTools[name] = Boolean(value);
                }
                if (files.length && !selectedTools.file_analysis) throw new Error(text('Aktifkan analisis file untuk mengirim lampiran.', 'Enable file analysis to send attachments.'));
                const attempt = {
                    payload: null, text: targetId ? '' : content, draftAtSend: targetId || override ? null : current.draft, attachments: files, operationId: null, accepted: false,
                    kind: options.retry_of ? 'retry' : options.continuation_of ? 'continue' : 'turn', startedAt: Date.now(),
                };
                // The composer empties immediately; a rejected or cancelled submission restores the text.
                patch(sourceKey, { operation: { status: 'sending', model, started_at: attempt.startedAt }, attempt, ...(attempt.draftAtSend != null ? { draft: '' } : {}) });
                pending = attempt;
                key = await ensureConversation(sourceKey);
                if (!valid(key)) throw new Error(text('Percakapan sudah ditutup.', 'The conversation was closed.'));
                const existing = entry(key);
                if (existing.operation?.local_only && existing.operation.stop_requested) {
                    patch(key, (value) => ({ ...value, attempt: null, draft: value.draft || attempt.draftAtSend || '' }));
                    persist(key);
                    return existing.operation;
                }
                const targetIndex = targetId ? existing.messages.findIndex((message) => message.id === String(targetId)) : -1;
                if (targetId && targetIndex < 0) throw new Error(text('Jawaban yang dipilih tidak ditemukan di percakapan ini.', 'The selected response is not in this conversation.'));
                const original = targetIndex >= 0 ? existing.messages.slice(0, targetIndex).findLast((message) => message.role === 'user') : null;
                attempt.text = targetId ? String(original?.content || '') : content;
                // workspace_v2 builds prior context from owned server history; send only this turn.
                attempt.payload = {
                    model, messages: [{ role: 'user', content: attempt.text }], conversation_id: key, workspace_id: existing.workspaceId,
                    client_request_id: uuid(), attachment_ids: files.map((file) => file.id), tools: selectedTools, stream_protocol: 'workspace_v2',
                    ...(options.retry_of ? { retry_of: Number(options.retry_of) } : {}),
                    ...(options.continuation_of ? { continuation: true, continuation_of: Number(options.continuation_of) } : {}),
                };
                submitted = true;
                return await runAttempt(key, attempt);
            } catch (error) {
                const status = entry(key).operation?.status;
                if (!submitted) patch(key, (value) => ({ ...value, operation: null, attempt: null }));
                if (pending?.draftAtSend && !pending.accepted && status !== 'uncertain' && !entry(key).draft) patch(key, { draft: pending.draftAtSend });
                if (pending) persist(key);
                errorAt(key, 'stream', error);
                throw error;
            }
        };
        const uploadOne = (key, file, localId = uuid()) => {
            const current = entry(key);
            const previous = current.attachments.find((item) => item.local_id === localId);
            const item = { local_id: localId, name: file.name, size: file.size, mime: file.type, status: 'uploading', progress: null, error: '', file };
            patch(key, { attachments: previous ? current.attachments.map((value) => value.local_id === localId ? item : value) : [...current.attachments, item] });
            const xhr = new XMLHttpRequest();
            const control = { xhr, key, cancelled: false, promise: null };
            const update = (fields) => patch(key, (value) => ({ ...value, attachments: value.attachments.map((attachment) => attachment.local_id === localId ? { ...attachment, ...fields } : attachment) }));
            control.promise = new Promise((resolve, reject) => {
                xhr.open('POST', `${pathFor(key)}/attachments`);
                xhr.withCredentials = true;
                xhr.setRequestHeader('Accept', 'application/json');
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.setRequestHeader('X-CSRF-TOKEN', csrf());
                xhr.upload.onprogress = (event) => update({ progress: event.lengthComputable ? Math.round(event.loaded / event.total * 100) : null });
                xhr.upload.onload = () => update({ status: 'processing', progress: 100 });
                const fail = (error) => { update({ status: 'failed', error: error.message }); errorAt(key, 'upload', error); reject(error); };
                xhr.onerror = () => fail(new Error(text('Koneksi upload terputus. Ulangi upload atau hapus lampiran ini.', 'The upload connection was lost. Retry the upload or remove this attachment.')));
                xhr.onabort = () => {
                    if (!control.cancelled) { fail(new Error(text('Upload terputus di browser.', 'Upload was interrupted in the browser.'))); return; }
                    const cancelled = new Error(text('Upload dibatalkan.', 'Upload cancelled.'));
                    cancelled.cancelled = true;
                    reject(cancelled);
                };
                xhr.onload = () => {
                    let data;
                    try { data = JSON.parse(xhr.responseText); } catch { data = null; }
                    if (xhr.status < 200 || xhr.status >= 300 || !data?.attachment?.id || data.attachment.status !== 'ready') {
                        fail(new ApiError(Object.values(data?.errors || {}).flat()[0] || data?.message || text('Upload belum dikonfirmasi server.', 'The upload was not confirmed by the server.'), xhr.status, data));
                        return;
                    }
                    update({ ...data.attachment, local_id: localId, file: undefined, progress: undefined, error: '' });
                    resolve(data.attachment);
                };
                const body = new FormData(); body.append('file', file); xhr.send(body);
            }).finally(() => { session.uploads.delete(localId); notify(); });
            session.uploads.set(localId, control);
            return control.promise;
        };
        const uploadFiles = async (files) => {
            const chosen = Array.from(files || []);
            if (!chosen.length) return [];
            const sourceKey = session.data.activeKey;
            let key = sourceKey;
            let input = session.data.capabilities?.input;
            try {
                if (session.data.loading.capabilities) {
                    await session.capabilityLoad?.catch(() => null);
                    input = session.data.capabilities?.input;
                }
                if (!input && session.data.errors.capabilities) throw new Error(session.data.errors.capabilities);
                if (session.data.capabilityModel !== latest.current.selectedModel || !input || !input.max_files) throw new Error(text('Lampiran tidak tersedia untuk model ini.', 'Attachments are not available for this model.'));
                key = await ensureConversation(sourceKey);
                if (!entry(key).uploadPolicy) await loadAttachments(key);
                validateFiles([...entry(key).attachments, ...chosen], input, entry(key).uploadPolicy);
                errorAt(key, 'upload', '');
                const results = await Promise.allSettled(chosen.map((file) => uploadOne(key, file)));
                const failure = results.find((result) => result.status === 'rejected' && !result.reason?.cancelled);
                if (failure) throw failure.reason;
                return results.filter((result) => result.status === 'fulfilled').map((result) => result.value);
            } catch (error) { if (valid(key)) errorAt(key, 'upload', error); throw error; }
        };
        const selectConversation = async (id) => {
            const key = String(id ?? '');
            if (!key || key.startsWith('new:') || session.deleted.has(key)) return null;
            session.viewVersion++;
            session.data.activeKey = key;
            const version = (entry(key).detailVersion || 0) + 1;
            const messageRevision = entry(key).messageRevision || 0;
            patch(key, (current) => ({ ...current, loading: true, detailVersion: version, errors: { ...current.errors, conversation: '' } }));
            try {
                const data = await request(pathFor(key));
                if (!valid(key) || entry(key).detailVersion !== version) return null;
                if (!data?.conversation || !Array.isArray(data.messages)) throw new Error(text('Respons percakapan tidak valid.', 'Invalid conversation response.'));
                const live = session.streams.has(key) || session.polls.has(key);
                patch(key, (current) => ({ ...current, conversation: data.conversation, workspaceId: data.conversation.workspace_id,
                    messages: live || (current.messageRevision || 0) !== messageRevision
                        ? [...new Map([...data.messages.map(normalizeMessage), ...current.messages].map((message) => [message.id, message])).values()]
                        : data.messages.map(normalizeMessage), loaded: true,
                    errors: { ...current.errors, conversation: '' } }));
                if (session.data.activeKey === key) {
                    // Deep links may open a conversation from another workspace; show that workspace's history.
                    if (data.conversation.workspace_id != null && String(data.conversation.workspace_id) !== String(session.data.workspaceId)) {
                        session.data.workspaceId = data.conversation.workspace_id;
                        session.data.search = ''; session.data.conversations = []; session.data.nextCursor = null;
                        loadHistory().catch(() => {});
                    }
                    const model = entry(key).operation?.model || data.conversation.model;
                    if (model) latest.current.onModelChange?.(model);
                }
                const running = entry(key).messages.findLast((message) => message.role === 'assistant' && message.operation_id && ACTIVE.has(message.status));
                if (running && !live) {
                    applyOperation(key, { id: running.operation_id, assistant_message_id: running.id, status: running.status, model: running.model, started_at: running.created_at || null });
                    pollOperation(key, running.operation_id).catch(() => {});
                } else if (!live && entry(key).operation?.status === 'uncertain' && entry(key).operation.id) {
                    // A remembered attempt with a known operation resolves by reading its saved state; no request is resubmitted.
                    const operationId = entry(key).operation.id;
                    readOperation(key, operationId)
                        .then((operation) => { if (ACTIVE.has(operation.status)) return pollOperation(key, operationId); return operation; })
                        .catch((error) => { if (error.name !== 'AbortError' && valid(key)) errorAt(key, 'stream', error); });
                }
                await loadAttachments(key);
                return data.conversation;
            } catch (error) { if (error.name !== 'AbortError' && valid(key)) errorAt(key, 'conversation', error); throw error; }
            finally { if (valid(key) && entry(key).detailVersion === version) patch(key, { loading: false }); }
        };
        const result = {
            async initialize() {
                if (!session.userKey) return;
                session.data.loading.workspaces = true; notify();
                try {
                    const data = await request('/api/c/workspaces');
                    if (session.disposed) return;
                    if (!Array.isArray(data?.workspaces)) throw new Error(text('Respons workspace tidak valid.', 'Invalid workspace response.'));
                    session.data.workspaces = data.workspaces;
                    const initial = data.workspace?.id ?? data.workspaces[0]?.id ?? null;
                    if (session.data.workspaceId == null && initial != null) {
                        session.data.workspaceId = initial;
                        if (session.data.activeKey === 'new:') {
                            // Keep anything typed (or prefilled) before the default workspace was known.
                            const early = session.entries.get('new:');
                            const nextKey = `new:${initial}`;
                            if (early?.draft) {
                                session.entries.set(nextKey, { ...entry(nextKey), draft: early.draft, tools: early.tools });
                                persist(nextKey);
                            }
                            session.entries.delete('new:');
                            try { localStorage.removeItem(storageKey(session.userKey, 'new:')); } catch { /* Nothing else to clean up. */ }
                            session.data.activeKey = nextKey;
                        }
                    }
                    session.data.errors.workspace = ''; notify();
                    await loadHistory();
                } catch (error) { if (error.name !== 'AbortError' && !session.disposed) { session.data.errors.workspace = error.message; notify(); } throw error; }
                finally { if (!session.disposed) { session.data.loading.workspaces = false; notify(); } }
            },
            async setWorkspace(id) {
                const workspace = session.data.workspaces.find((item) => String(item.id) === String(id));
                if (!workspace) throw new Error(text('Workspace tidak ditemukan.', 'Workspace not found.'));
                session.viewVersion++;
                session.data.workspaceId = workspace.id;
                session.data.activeKey = `new:${workspace.id}`;
                session.data.search = ''; session.data.conversations = []; session.data.nextCursor = null;
                notify(); return loadHistory();
            },
            async createWorkspace(name) {
                try {
                    const data = await request('/api/c/workspaces', { method: 'POST', body: { name } });
                    if (!data?.workspace) throw new Error(text('Workspace belum tersimpan.', 'The workspace was not saved.'));
                    updateWorkspace(data.workspace); await result.setWorkspace(data.workspace.id); return data.workspace;
                } catch (error) { session.data.errors.workspace = error.message; notify(); throw error; }
            },
            async renameWorkspace(id, name) {
                const workspace = session.data.workspaces.find((item) => String(item.id) === String(id));
                const data = await request(`/api/c/workspaces/${encodeURIComponent(id)}`, { method: 'PATCH', body: { name, version: workspace?.version } });
                if (!data?.workspace) throw new Error(text('Nama workspace belum tersimpan.', 'The workspace name was not saved.'));
                updateWorkspace(data.workspace); return data.workspace;
            },
            async saveWorkspace(id, notes, version) {
                const data = await request(`/api/c/workspaces/${encodeURIComponent(id)}`, { method: 'PATCH', body: { notes, version } });
                if (!data?.workspace) throw new Error(text('Catatan belum tersimpan.', 'The notes were not saved.'));
                updateWorkspace(data.workspace); return data.workspace;
            },
            // Re-reads saved workspace names/notes (e.g. after a 409) without changing the selected workspace or conversation.
            async refreshWorkspaces() {
                const data = await request('/api/c/workspaces');
                if (!Array.isArray(data?.workspaces)) throw new Error(text('Respons workspace tidak valid.', 'Invalid workspace response.'));
                if (session.disposed) return null;
                session.data.workspaces = data.workspaces;
                notify();
                return data.workspaces.find((workspace) => String(workspace.id) === String(session.data.workspaceId)) || null;
            },
            setSearch(query) {
                session.historyController?.abort(); session.historyVersion++;
                session.data.search = String(query); session.data.nextCursor = null; session.data.conversations = [];
                session.data.loading.history = true; notify();
                clearTimeout(session.searchTimer);
                session.searchTimer = setTimeout(() => { loadHistory().catch(() => {}); }, 250);
            },
            loadMoreHistory: () => loadHistory(true),
            refreshHistory: () => loadHistory(),
            // A server conversation is created lazily by the first upload or send, so empty drafts never enter history.
            newConversation() {
                session.viewVersion++;
                session.data.activeKey = session.data.workspaceId == null ? 'new:' : `new:${session.data.workspaceId}`;
                notify();
                return Promise.resolve(null);
            },
            selectConversation,
            async renameConversation(id, title) {
                const data = await request(pathFor(id), { method: 'PATCH', body: { title } });
                if (!data?.conversation) throw new Error(text('Judul belum tersimpan.', 'The title was not saved.'));
                updateConversation(data.conversation); return data.conversation;
            },
            async pinConversation(id, pinned) {
                const data = await request(pathFor(id), { method: 'PATCH', body: { pinned } });
                if (!data?.conversation) throw new Error(text('Perubahan pin belum tersimpan.', 'The pin change was not saved.'));
                updateConversation(data.conversation); return data.conversation;
            },
            async deleteConversation(id) {
                const key = String(id);
                await request(pathFor(key), { method: 'DELETE' });
                session.deleted.add(key);
                session.streams.get(key)?.controller.abort();
                const poll = session.polls.get(key);
                if (poll) { clearTimeout(poll.timer); poll.resolve?.(); }
                session.uploads.forEach((upload) => { if (upload.key === key) { upload.cancelled = true; upload.xhr.abort(); } });
                session.entries.delete(key);
                session.overrides.delete(key);
                session.data.conversations = session.data.conversations.filter((conversation) => keyOf(conversation) !== key);
                if (session.data.activeKey === key) { session.viewVersion++; session.data.activeKey = session.data.workspaceId == null ? 'new:' : `new:${session.data.workspaceId}`; }
                try { localStorage.removeItem(storageKey(session.userKey, key)); } catch { /* Deleted drafts are no longer exposed in the UI. */ }
                notify();
            },
            setDraft(value) { const key = session.data.activeKey; patch(key, { draft: String(value) }); persist(key); },
            uploadFiles,
            async retryUpload(localId) {
                const key = session.data.activeKey;
                const input = session.data.capabilities?.input;
                const file = entry(key).attachments.find((item) => item.local_id === localId);
                if (!file?.file) throw new Error(text('Pilih kembali file asli untuk mengunggah ulang.', 'Choose the original file again to retry.'));
                if (!input || session.data.capabilityModel !== latest.current.selectedModel) throw new Error(text('Tunggu capability model dimuat sebelum mencoba ulang.', 'Wait for model capabilities before retrying.'));
                await loadAttachments(key);
                validateFiles(entry(key).attachments.map((item) => item.local_id === localId ? file.file : item), input, entry(key).uploadPolicy);
                errorAt(key, 'upload', '');
                return uploadOne(key, file.file, localId);
            },
            async removeAttachment(id) {
                const key = session.data.activeKey;
                const file = entry(key).attachments.find((item) => (item.id != null && String(item.id) === String(id)) || item.local_id === id);
                if (!file) return;
                const upload = file.local_id ? session.uploads.get(file.local_id) : null;
                if (upload && file.status === 'uploading') {
                    // The body has not finished sending, so the browser can cancel it. Reconcile in case the server stored it anyway.
                    upload.cancelled = true;
                    upload.xhr.abort();
                    patch(key, (current) => ({ ...current, attachments: current.attachments.filter((item) => item.local_id !== file.local_id) }));
                    loadAttachments(key).catch(() => {});
                    return;
                }
                patch(key, (current) => ({ ...current, attachments: current.attachments.map((item) => item === file ? { ...item, removing: true } : item) }));
                try {
                    let serverId = file.id;
                    if (upload) serverId = (await upload.promise).id;
                    if (serverId) await request(`${pathFor(key)}/attachments/${encodeURIComponent(serverId)}`, { method: 'DELETE' });
                    patch(key, (current) => ({ ...current,
                        attachments: current.attachments.filter((item) => !((file.local_id && item.local_id === file.local_id) || (serverId && String(item.id) === String(serverId)) || item === file)),
                        errors: file.status === 'failed' || file.status === 'error' ? { ...current.errors, upload: '' } : current.errors }));
                } catch (error) {
                    patch(key, (current) => ({ ...current, attachments: current.attachments.map((item) => (file.local_id && item.local_id === file.local_id) || (file.id && item.id === file.id) ? { ...item, removing: false } : item) }));
                    errorAt(key, 'upload', error); throw error;
                }
            },
            setTools(nextTools) {
                const key = session.data.activeKey;
                for (const [name, value] of Object.entries(nextTools)) {
                    const capability = session.data.capabilities?.tools?.[name];
                    if (value && (!capability?.available || capability.href)) throw new Error(capability?.reason || text('Alat ini tidak tersedia.', 'This tool is unavailable.'));
                }
                patch(key, { tools: { ...nextTools } }); persist(key);
            },
            send,
            stop: () => stop(),
            async retry(messageId) {
                const key = session.data.activeKey;
                const current = entry(key);
                if (messageId == null) {
                    // Reconnect: the same client_request_id replays or joins the original admission; it never starts a second attempt.
                    const attempt = current.attempt;
                    if (current.operation?.status === 'uncertain' && attempt?.payload) {
                        return runAttempt(key, attempt).catch((error) => {
                            // A definite rejection means nothing was admitted; give the text back to the composer.
                            if (!attempt.accepted && entry(key).operation?.status === 'failed' && attempt.draftAtSend && !entry(key).draft) {
                                patch(key, { draft: attempt.draftAtSend });
                                persist(key);
                            }
                            throw error;
                        });
                    }
                    throw new Error(text('Tidak ada permintaan yang perlu disambungkan ulang.', 'There is no request to reconnect.'));
                }
                const message = current.messages.find((item) => item.id === String(messageId) && item.role === 'assistant');
                if (!message || !['failed', 'stopped'].includes(message.status)) throw new Error(text('Hanya jawaban gagal atau dihentikan yang dapat dicoba ulang.', 'Only failed or stopped responses can be retried.'));
                return send('', { retry_of: message.id });
            },
            async continue(messageId) {
                const message = entry().messages.find((item) => item.id === String(messageId) && item.role === 'assistant');
                if (!message || !TERMINAL.has(message.status) || !String(message.content).trim()) throw new Error(text('Tunggu jawaban berisi teks tersimpan sebelum melanjutkan.', 'Wait for saved response text before continuing.'));
                return send('', { continuation_of: message.id });
            },
            discardUncertain() {
                const key = session.data.activeKey;
                const current = entry(key);
                if (current.operation?.status !== 'uncertain' || session.streams.has(key)) return;
                // Dismissing only forgets the local attempt; a server-accepted turn still appears in saved history.
                patch(key, (value) => ({ ...value, operation: null, attempt: null, draft: value.draft || current.attempt?.draftAtSend || '', errors: { ...value.errors, stream: '' } }));
                persist(key);
            },
            dismissError(type) {
                if (['conversation', 'stream', 'upload'].includes(type)) { errorAt(session.data.activeKey, type, ''); return; }
                if (type in session.data.errors) { session.data.errors[type] = ''; notify(); }
            },
            changeModel(model) {
                const status = entry().operation?.status;
                if (ACTIVE.has(status) || status === 'uncertain') throw new Error(text('Hentikan atau pulihkan permintaan sebelum mengganti model.', 'Stop or recover the request before changing models.'));
                latest.current.onModelChange?.(model);
            },
            handleComposerKeyDown(event) {
                if (event.key !== 'Enter' || event.shiftKey || event.altKey || event.ctrlKey || event.metaKey || event.isComposing || event.nativeEvent?.isComposing || event.keyCode === 229) return;
                event.preventDefault();
                const current = entry();
                if (ACTIVE.has(current.operation?.status) || current.operation?.status === 'uncertain' || current.loading || session.creating.has(session.data.activeKey)) return;
                send().catch(() => {});
            },
            handleComposerPaste(event) {
                const files = Array.from(event.clipboardData?.files || []);
                if (!files.length) return;
                if (!event.clipboardData.getData('text/plain')) event.preventDefault();
                uploadFiles(files).catch(() => {});
            },
            handleComposerDrop(event) {
                const files = Array.from(event.dataTransfer?.files || []);
                if (!files.length) return;
                event.preventDefault(); uploadFiles(files).catch(() => {});
            },
            async refreshContext() {
                const key = session.data.activeKey;
                const jobs = [fetchCapabilities()];
                if (!key.startsWith('new:')) jobs.push(loadAttachments(key));
                const operation = entry(key).operation;
                if (operation?.id && ACTIVE.has(operation.status) && !session.streams.has(key)) jobs.push(pollOperation(key, operation.id));
                const results = await Promise.allSettled(jobs);
                const failed = results.find((item) => item.status === 'rejected');
                if (failed) { errorAt(key, 'upload', failed.reason); throw failed.reason; }
                return results.map((item) => item.value);
            },
            refreshCapabilities: fetchCapabilities,
        };
        return result;
    }, [session]);

    useEffect(() => {
        session.disposed = false;
        actions.initialize().catch(() => {});
        return () => {
            session.disposed = true;
            clearTimeout(session.searchTimer);
            session.historyController?.abort(); session.capabilityController?.abort();
            session.requests.forEach((controller) => controller.abort());
            session.streams.forEach((stream) => stream.controller.abort());
            session.uploads.forEach((upload) => { upload.cancelled = true; upload.xhr.abort(); });
            session.polls.forEach((poll) => { clearTimeout(poll.timer); poll.resolve?.(); });
        };
    }, [actions, session]);

    useEffect(() => {
        const current = session.entries.get(session.data.activeKey);
        const locked = ACTIVE.has(current?.operation?.status) || current?.operation?.status === 'uncertain';
        if (locked && current.operation.model && selectedModel !== current.operation.model) {
            latest.current.onModelChange?.(current.operation.model);
            return;
        }
        actions.refreshCapabilities().catch(() => {});
    }, [actions, selectedModel, session]);

    const key = session.data.activeKey;
    if (!session.entries.has(key)) session.entries.set(key, makeEntry(userKey, key, session.data.workspaceId));
    const current = session.entries.get(key);
    const data = session.data;
    const operation = current.operation;
    const userTurnSaved = operation?.user_message_id != null && current.messages.some((message) => message.id === String(operation.user_message_id));
    const state = {
        ...data, conversationId: key.startsWith('new:') ? null : key, conversation: current.conversation,
        workspace: data.workspaces.find((workspace) => String(workspace.id) === String(data.workspaceId)) || null,
        messages: current.messages, draft: current.draft, attachments: current.attachments, tools: current.tools,
        operation, uploadPolicy: current.uploadPolicy,
        // The submitted turn before the server has confirmed its saved user message.
        pendingTurn: current.attempt?.kind === 'turn' && !userTurnSaved && (ACTIVE.has(operation?.status) || operation?.status === 'uncertain')
            ? { text: current.attempt.text, attachments: current.attempt.attachments, status: operation.status } : null,
        isStreaming: ACTIVE.has(operation?.status),
        modelLocked: ACTIVE.has(operation?.status) || operation?.status === 'uncertain',
        activeStreams: session.streams.size,
        polling: session.polls.has(key),
        loading: { ...data.loading, conversation: current.loading, creating: session.creating.has(key) },
        errors: { ...data.errors, ...current.errors },
    };
    return { state, actions };
}

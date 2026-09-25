import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { apiRequest } from "../../lib/api";
import { capabilitySubmission, capabilityValues } from "./capability";
import { assetSignature } from "./nativeStudio";
import { capabilityKind, modelKinds, sanitizeModel, selectionKind } from "./studioPalette";
import { jobMatchesKind } from "./studioJobs";
import { STUDIO_KINDS, studioKind } from "./studioLinks";
import { batchView, jobCandidates, mediaJobPending, orderOperations, submissionUnresolved, workspaceDraftKey } from "./workspaceMedia";

const api = "/api/media/workspace";
const stored = (key, fallback) => {
    try { const text = sessionStorage.getItem(key); return text && text.length < 2 * 1024 * 1024 ? JSON.parse(text) ?? fallback : fallback; } catch { return fallback; }
};
const persist = (key, value) => { try { if (value == null) sessionStorage.removeItem(key); else sessionStorage.setItem(key, JSON.stringify(value)); } catch { /* Drafts remain usable if browser storage is unavailable. */ } };
const record = (value) => value && typeof value === "object" && !Array.isArray(value) ? value : {};
const omit = (value, keys) => Object.fromEntries(Object.entries(value).filter(([key]) => !keys.includes(key)));
const finished = (job) => ["completed", "failed", "cancelled"].includes(job?.status);
// History can be cleared per studio kind; "other" covers data and file outputs.
export const CLEARABLE_KINDS = [...STUDIO_KINDS, "other"];
const mergeJobs = (current, incoming) => {
    const byId = new Map(current.map((job) => [job.id, job]));
    incoming.forEach((job) => {
        const previous = byId.get(job.id);
        // A late response must not resurrect a job the server already reported as finished.
        if (previous && ["completed", "failed", "cancelled", "rejected"].includes(previous.status) && !previous.can_retry_save && mediaJobPending(job)) return;
        byId.set(job.id, { ...previous, ...job });
    });
    return [...byId.values()].sort((a, b) => String(b.created_at).localeCompare(String(a.created_at)));
};
// The last model and operation per studio kind ("all" = no kind), reopened with that kind.
const readLastModels = (value) => Object.fromEntries(Object.entries(record(value)).flatMap(([key, entry]) => (key === "all" || STUDIO_KINDS.includes(key)) && typeof entry?.model === "string" && entry.model
    ? [[key, { model: entry.model.slice(0, 200), operation: typeof entry.operation === "string" ? entry.operation.slice(0, 100) : "" }]] : []));

// One studio for every media kind. `?kind=` is a filter (default model, palette category, history),
// `?model=`/`?operation=` the setup and `?job=`/`?track=` a result link; the hook keeps them in step.
export default function useGlobalMediaWorkspace({ userId, locale = "id" }) {
    const [params, setParams] = useSearchParams();
    const kind = studioKind(params.get("kind") || "");
    const kindRef = useRef(kind);
    kindRef.current = kind;
    const storageKey = `xsuper:media-workspace:${userId}:v3`;
    const requestKey = `xsuper:media-workspace:${userId}:pending:v2`;
    const saved = useRef(null);
    if (saved.current === null) saved.current = record(stored(storageKey, {}));
    const [lastModels, setLastModels] = useState(() => readLastModels(saved.current.models));
    const lastModelsRef = useRef(lastModels);
    lastModelsRef.current = lastModels;
    const [modelId, setModelId] = useState(() => params.get("model") || lastModels[kind || "all"]?.model || "");
    const [chosenOperation, setChosenOperation] = useState(() => params.get("operation") || (params.get("model") ? "" : lastModels[kind || "all"]?.operation) || "");
    // Where the current model came from: a remembered model that disappeared is replaced silently.
    const modelSource = useRef(params.get("model") ? "link" : lastModels[kind || "all"]?.model ? "memory" : "default");
    const [pendingSummary, setPendingSummary] = useState(null);
    const [catalog, setCatalog] = useState({ models: [], next_cursor: null, total: 0 });
    const catalogRef = useRef(catalog);
    catalogRef.current = catalog;
    const [catalogLoading, setCatalogLoading] = useState(true);
    const [catalogError, setCatalogError] = useState(null);
    const [capabilitySet, setCapabilitySet] = useState(null);
    const [capabilityLoading, setCapabilityLoading] = useState(false);
    const [capabilityError, setCapabilityError] = useState(null);
    const [capabilityRefresh, setCapabilityRefresh] = useState(0);
    const [drafts, setDrafts] = useState(() => record(saved.current.drafts));
    // Studio-level authoring (e.g. video product/UGC fields) spans the model's operations; callers restore it typed.
    const [authoring, setAuthoringState] = useState(() => record(saved.current.authoring));
    // Consent covers the files chosen in this visit only, so it is kept in memory per model/operation.
    const [consent, setConsent] = useState({});
    const [balance, setBalance] = useState(null);
    const [jobs, setJobs] = useState([]);
    const [historyKind, setHistoryKindState] = useState(kind);
    const [history, setHistory] = useState({ kind, ids: [], cursor: null, total: null });
    const [historyLoading, setHistoryLoading] = useState(true);
    const [historyError, setHistoryError] = useState(null);
    const initialLink = useRef(null);
    if (initialLink.current === null) initialLink.current = { jobs: jobCandidates(params.get("job"), kind), track: params.get("track") || "" };
    const [activeId, setActiveId] = useState(() => initialLink.current.jobs[0] || "");
    const fallback = useRef(initialLink.current.jobs[1] || "");
    // A ?job= link (first load or later navigation, never our own selection) asks to open that result.
    const [linkRequest, setLinkRequest] = useState(() => initialLink.current.jobs.length ? { seq: 1, track: initialLink.current.track } : null);
    const ownJob = useRef(params.get("job") || "");
    const [detailLoading, setDetailLoading] = useState(false);
    const [detailError, setDetailError] = useState(null);
    const [actionBusy, setActionBusy] = useState("");
    const [actionError, setActionError] = useState(null);
    const [clearing, setClearing] = useState(false);
    const [clearError, setClearError] = useState(null);
    const [submitting, setSubmitting] = useState(false);
    const [submitError, setSubmitError] = useState(null);
    const [uncertain, setUncertain] = useState(() => stored(requestKey, null));
    const request = useRef(uncertain);
    const lock = useRef(false);
    const frozen = useRef(false);
    const actionLock = useRef(false);
    const detailSequence = useRef(new Map());
    const mounted = useRef(true);
    const selection = useRef(0);
    const created = useRef(new Set());
    const active = useRef(activeId);
    active.current = activeId;
    const modelSequence = useRef(0);
    const historySequence = useRef(0);
    const loadedCapabilities = capabilitySet?.for === modelId ? capabilitySet.capabilities : null;
    const capabilities = useMemo(() => orderOperations(loadedCapabilities), [loadedCapabilities]);
    const model = capabilitySet?.for === modelId ? capabilitySet.model : null;
    const operation = capabilities[chosenOperation] ? chosenOperation : Object.keys(capabilities)[0] || "";
    const capability = capabilities[operation] || null;
    const draftKey = workspaceDraftKey(modelId, operation);
    const draft = drafts[draftKey];
    const values = useMemo(() => capability ? capabilityValues(capability, draft?.values) : {}, [capability, draft?.values]);
    const controls = { count: 1, pro: false, ...draft?.controls, rights_confirmed: consent[draftKey] === true };
    // The server requires explicit permission for every avatar-category model, native or schema-driven.
    const consentRequired = model?.category === "avatar";
    const job = jobs.find((entry) => entry.id === activeId) || null;
    // Header facts while a newly chosen model's capabilities load: the palette row or catalog entry.
    const summary = model || (pendingSummary?.model_id === modelId ? pendingSummary : null) || catalog.models.find((entry) => entry.model_id === modelId) || null;
    const summaryRef = useRef(summary);
    summaryRef.current = summary;
    const resolvedKind = capabilityKind(model, capability);

    // URL writes compose within one tick: each builds on the previous, not on the last rendered query.
    const pendingSearch = useRef(null);
    useEffect(() => { pendingSearch.current = null; }, [params]);
    const updateUrl = useCallback((next) => {
        const updated = new URLSearchParams(pendingSearch.current ?? params);
        for (const [name, value] of Object.entries(next)) { if (value) updated.set(name, value); else updated.delete(name); }
        const text = updated.toString();
        if (text === (pendingSearch.current ?? params.toString())) return;
        pendingSearch.current = text;
        setParams(new URLSearchParams(text), { replace: true });
    }, [params, setParams]);

    useEffect(() => {
        mounted.current = true;
        return () => { mounted.current = false; modelSequence.current += 1; historySequence.current += 1; };
    }, []);
    useEffect(() => { persist(storageKey, { models: lastModels, drafts, authoring }); }, [storageKey, lastModels, drafts, authoring]);

    const rememberJobs = useCallback((data) => {
        if (!mounted.current) return;
        const incoming = data.jobs || (data.job ? [data.job] : []);
        if (incoming.length) setJobs((current) => mergeJobs(current, incoming));
        if (data.balance_tokens != null) setBalance(Number(data.balance_tokens));
    }, []);
    // Job responses do not carry the balance; reservations, refunds and charges are re-read here.
    const refreshBalance = useCallback(async () => {
        try {
            const data = await apiRequest("/api/t/balance");
            if (mounted.current && data?.balance != null && Number.isFinite(Number(data.balance))) setBalance(Number(data.balance));
        } catch { /* The next catalog or capability response carries the balance again. */ }
    }, []);
    // The kind's catalog supplies the default model and the native studios' helper models.
    const loadModels = useCallback(async (cursor = null) => {
        const sequence = ++modelSequence.current;
        setCatalogLoading(true);
        setCatalogError(null);
        try {
            const search = new URLSearchParams({ kind, locale });
            if (cursor) search.set("cursor", cursor);
            const data = await apiRequest(`${api}/models?${search}`);
            if (!mounted.current || sequence !== modelSequence.current) return;
            setCatalog((current) => ({ ...data, models: cursor ? [...new Map([...current.models, ...data.models].map((entry) => [entry.model_id, entry])).values()] : data.models || [] }));
            if (data.balance_tokens != null) setBalance(Number(data.balance_tokens));
            if (!cursor && !frozen.current) setModelId((current) => {
                if (current || !data.models?.[0]?.model_id) return current;
                modelSource.current = "default";
                return data.models[0].model_id;
            });
        } catch (failure) { if (mounted.current && sequence === modelSequence.current) setCatalogError(failure); }
        finally { if (mounted.current && sequence === modelSequence.current) setCatalogLoading(false); }
    }, [kind, locale]);
    useEffect(() => { void loadModels(); return () => { modelSequence.current += 1; }; }, [loadModels]);
    useEffect(() => {
        if (!modelId) return;
        const controller = new AbortController();
        setCapabilityLoading(true);
        setCapabilityError(null);
        apiRequest(`${api}/capabilities?${new URLSearchParams({ model: modelId, locale })}`, { signal: controller.signal }).then((data) => {
            if (controller.signal.aborted) return;
            setCapabilitySet({ ...data, for: modelId });
            if (data.balance_tokens != null) setBalance(Number(data.balance_tokens));
        }).catch((failure) => {
            if (controller.signal.aborted) return;
            // A remembered model that is no longer offered gives way to the kind's first model.
            if (failure.status === 404 && modelSource.current === "memory" && !frozen.current) {
                setLastModels((current) => Object.fromEntries(Object.entries(current).filter(([, entry]) => entry.model !== modelId)));
                modelSource.current = "default";
                setModelId(catalogRef.current.models.find((entry) => entry.model_id !== modelId)?.model_id || "");
                return;
            }
            setCapabilityError(failure);
        }).finally(() => { if (!controller.signal.aborted) setCapabilityLoading(false); });
        return () => controller.abort();
    }, [modelId, capabilityRefresh, locale]);

    // Remember the setup per studio kind once the model's capabilities are known.
    useEffect(() => {
        if (!model || !operation) return;
        const keys = ["all", ...modelKinds(model).filter((entry) => STUDIO_KINDS.includes(entry))];
        setLastModels((current) => keys.every((key) => current[key]?.model === model.model_id && current[key]?.operation === operation) ? current
            : { ...current, ...Object.fromEntries(keys.map((key) => [key, { model: model.model_id, operation }])) });
    }, [model, operation]);

    const seenKind = useRef(kind);
    const followKind = (next) => {
        seenKind.current = next;
        setHistoryKindState(next);
        setHistory((current) => current.kind === next ? current : { kind: next, ids: [], cursor: null, total: null });
    };
    // The URL follows the loaded setup: model and operation stay shareable and reload-stable. A kind
    // filter moves with the capability; "all" (no kind) stays until the member picks a model.
    const synced = useRef("");
    useEffect(() => {
        if (!model || !capability) return;
        const key = `${model.model_id}\n${operation}`;
        if (synced.current === key) return;
        synced.current = key;
        const nextKind = kindRef.current && resolvedKind !== kindRef.current ? resolvedKind : kindRef.current;
        if (nextKind !== kindRef.current) followKind(nextKind);
        updateUrl({ kind: nextKind, model: model.model_id, operation });
    });
    // A kind chosen elsewhere (sidebar, search, links) filters the studio: a model outside it gives way
    // to the kind's remembered or first model, unless the link names a model itself.
    const modelParam = params.get("model") || "";
    const operationParam = params.get("operation") || "";
    const chosen = useRef({ model: modelId, operation: chosenOperation });
    chosen.current = { model: modelId, operation: chosenOperation };
    useEffect(() => {
        if (seenKind.current === kind) return;
        followKind(kind);
        if (!kind || lock.current || frozen.current || (modelParam && modelParam !== chosen.current.model)) return;
        const current = summaryRef.current;
        if (modelKinds(current).includes(kind)) {
            // The model stays; a multi-output model moves to its operation in that kind.
            const fitting = current.category === "avatar" ? null : (current.operations || []).find((entry) => entry.output_kind === kind);
            if (fitting && chosen.current.operation !== fitting.operation && capabilityKind(current, { output_kind: current.operations.find((entry) => entry.operation === chosen.current.operation)?.output_kind }) !== kind) {
                setChosenOperation(fitting.operation);
            }
            return;
        }
        const next = lastModelsRef.current[kind];
        selection.current += 1;
        modelSource.current = next ? "memory" : "default";
        setPendingSummary(null);
        setModelId(next?.model || "");
        setChosenOperation(next?.operation || "");
        setSubmitError(null);
    }, [kind, modelParam]);
    // ?model= and ?operation= (dashboard search, chat tools) apply on first load and on later
    // navigation, but never while a request or live session holds the setup.
    useEffect(() => {
        if (!modelParam || modelParam === chosen.current.model || lock.current || frozen.current) return;
        selection.current += 1;
        modelSource.current = "link";
        setPendingSummary(null);
        setModelId(modelParam);
        setChosenOperation(operationParam);
        setSubmitError(null);
    }, [modelParam, operationParam]);
    useEffect(() => {
        if (!operationParam || operationParam === chosen.current.operation || lock.current || frozen.current) return;
        setChosenOperation(operationParam);
    }, [operationParam]);

    const loadHistory = useCallback(async (cursor = null) => {
        const sequence = ++historySequence.current;
        const target = historyKind;
        setHistoryLoading(true);
        setHistoryError(null);
        try {
            const search = new URLSearchParams();
            if (target) search.set("kind", target);
            if (cursor) search.set("cursor", cursor);
            const data = await apiRequest(`${api}/jobs${search.size ? `?${search}` : ""}`);
            if (!mounted.current || sequence !== historySequence.current) return;
            rememberJobs(data);
            const ids = (data.jobs || []).map((entry) => entry.id);
            setHistory((current) => {
                const same = current.kind === target;
                if (cursor) return { kind: target, ids: [...new Set([...(same ? current.ids : []), ...ids])], cursor: data.next_cursor || null, total: data.total ?? null };
                // Reloading the first page keeps the later pages already on screen.
                const later = same && current.ids.length > ids.length ? current.ids.filter((id) => !ids.includes(id)) : [];
                return { kind: target, ids: [...ids, ...later], cursor: later.length ? current.cursor : data.next_cursor || null, total: data.total ?? null };
            });
        } catch (failure) { if (mounted.current && sequence === historySequence.current) setHistoryError(failure); }
        finally { if (mounted.current && sequence === historySequence.current) setHistoryLoading(false); }
    }, [historyKind, rememberJobs]);
    useEffect(() => { void loadHistory(); }, [loadHistory]);
    const historyJobs = useMemo(() => {
        const byId = new Map(jobs.map((entry) => [entry.id, entry]));
        const listed = history.kind === historyKind ? history.ids.map((id) => byId.get(id)).filter(Boolean) : [];
        const shown = new Set(listed.map((entry) => entry.id));
        // Jobs created in this visit lead the list until the history reloads with them.
        const fresh = jobs.filter((entry) => created.current.has(entry.id) && !shown.has(entry.id) && jobMatchesKind(entry, historyKind));
        return [...fresh, ...listed];
    }, [jobs, history, historyKind]);

    const loadJob = useCallback(async (id, background = false) => {
        if (!id) return;
        const sequence = (detailSequence.current.get(id) || 0) + 1;
        detailSequence.current.set(id, sequence);
        if (!background) { setDetailLoading(true); setDetailError(null); }
        try {
            const data = await apiRequest(`${api}/jobs/${encodeURIComponent(id)}`);
            if (!mounted.current || detailSequence.current.get(id) !== sequence) return;
            rememberJobs(data);
            if (active.current === id) setDetailError(null);
            return data;
        } catch (failure) {
            if (!mounted.current || detailSequence.current.get(id) !== sequence || active.current !== id) return;
            // A bare id with a kind is first read as that kind's native job, then as a common job.
            if (failure.status === 404 && fallback.current) {
                const next = fallback.current;
                fallback.current = "";
                setActiveId(next);
                return;
            }
            setDetailError(failure);
        } finally { if (mounted.current && active.current === id && detailSequence.current.get(id) === sequence) setDetailLoading(false); }
    }, [rememberJobs]);
    useEffect(() => { if (activeId) void loadJob(activeId); }, [activeId, loadJob]);
    // Follow ?job= changes made by navigation (Library, notifications) while the page stays mounted.
    const jobParam = params.get("job") || "";
    const trackParam = params.get("track") || "";
    useEffect(() => {
        if (!jobParam) { ownJob.current = ""; return; }
        if (jobParam === ownJob.current) return;
        ownJob.current = jobParam;
        const candidates = jobCandidates(jobParam, kindRef.current);
        if (!candidates.length) return;
        if (!candidates.includes(active.current)) {
            fallback.current = candidates[1] || "";
            selection.current += 1;
            setActiveId(candidates[0]);
        }
        setDetailError(null);
        setActionError(null);
        setLinkRequest((current) => ({ seq: (current?.seq || 0) + 1, track: trackParam }));
    }, [jobParam, trackParam]);

    // Several images from one request are one set: every member is followed, not only the job opened first.
    const batch = useMemo(() => batchView(job, jobs), [job, jobs]);
    const pending = batch ? batch.pending.length > 0 : mediaJobPending(job);
    const missing = batch ? batch.missing.join(",") : "";
    useEffect(() => { if (missing) missing.split(",").forEach((id) => void loadJob(id, true)); }, [missing, loadJob]);
    const followed = batch ? batch.pending.join(",") : pending ? activeId : "";
    useEffect(() => {
        if (!followed) return;
        let live = true;
        let timer;
        const poll = async () => {
            await Promise.all(followed.split(",").map((id) => loadJob(id, true)));
            if (live) timer = setTimeout(poll, 3000);
        };
        timer = setTimeout(poll, 3000);
        return () => { live = false; clearTimeout(timer); };
    }, [followed, loadJob]);
    // Other running jobs on screen refresh less often, so their cards do not go stale.
    const background = historyJobs.filter((entry) => mediaJobPending(entry) && !followed.split(",").includes(entry.id)).slice(0, 4).map((entry) => entry.id).join(",");
    useEffect(() => {
        if (!background) return;
        let live = true;
        let timer;
        const poll = async () => {
            await Promise.all(background.split(",").map((id) => loadJob(id, true)));
            if (live) timer = setTimeout(poll, 6000);
        };
        timer = setTimeout(poll, 6000);
        return () => { live = false; clearTimeout(timer); };
    }, [background, loadJob]);
    // Settlement or refund happens when a job leaves the pending states.
    const pendingIds = jobs.filter(mediaJobPending).map((entry) => entry.id).join(",");
    const watched = useRef(pendingIds);
    useEffect(() => {
        const before = watched.current ? watched.current.split(",") : [];
        watched.current = pendingIds;
        const now = new Set(pendingIds ? pendingIds.split(",") : []);
        if (before.some((id) => !now.has(id))) void refreshBalance();
    }, [pendingIds, refreshBalance]);

    const selectModel = (id, nextOperation = "", model = null) => {
        if (lock.current || frozen.current || !id) return;
        selection.current += 1;
        modelSource.current = "user";
        const clean = model ? sanitizeModel(model) : null;
        const nextKind = clean ? selectionKind(clean, nextOperation, kindRef.current) : kindRef.current;
        if (nextKind !== kindRef.current) followKind(nextKind);
        setPendingSummary(clean);
        setModelId(id);
        setSubmitError(null);
        setChosenOperation(nextOperation);
        updateUrl({ kind: nextKind, model: id, operation: nextOperation });
    };
    const selectOperation = (next, { carry = false } = {}) => {
        if (lock.current || frozen.current) return;
        selection.current += 1;
        const target = capabilities[next];
        // Moving between a model's related operations (text/image to video) keeps what both accept.
        if (carry && target && next !== operation) {
            const key = workspaceDraftKey(modelId, next);
            const billing = target.billing || {};
            const carried = { ...(billing.count_field ? { count: Math.min(Number(controls.count || 1), billing.max_count || 1) } : {}),
                ...(billing.pro_field ? { pro: controls.pro === true } : {}) };
            setDrafts((current) => ({ ...current, [key]: { ...current[key], values: capabilityValues(target, { ...current[key]?.values, ...values }),
                controls: { ...current[key]?.controls, ...carried } } }));
        }
        setChosenOperation(next);
        setSubmitError(null);
        updateUrl({ operation: next });
    };
    const setValues = (next) => {
        if (frozen.current) return;
        setSubmitError(null);
        // Permission to use a face or voice covers the chosen files only: replacing one asks again.
        if (consentRequired && assetSignature(capability, next) !== assetSignature(capability, values)) setConsent((current) => ({ ...current, [draftKey]: false }));
        setDrafts((current) => ({ ...current, [draftKey]: { ...current[draftKey], values: next } }));
    };
    const setAuthoring = (patch) => {
        if (frozen.current) return;
        setSubmitError(null);
        setAuthoringState((current) => ({ ...current, ...patch }));
    };
    const setControls = (next) => {
        if (frozen.current) return;
        const { rights_confirmed: confirmed, ...rest } = next;
        if (confirmed !== undefined) setConsent((current) => ({ ...current, [draftKey]: confirmed === true }));
        if (Object.keys(rest).length) setDrafts((current) => ({ ...current, [draftKey]: { ...current[draftKey], values, controls: { ...current[draftKey]?.controls, ...rest } } }));
    };
    // "Muat ke form" and examples: a draft for a model/operation, applied before its capabilities load.
    const loadDraft = ({ model: nextModel, operation: nextOperation = "", values: nextValues, authoring: nextAuthoring }, summaryOf = null) => {
        if (lock.current || frozen.current || !nextModel) return false;
        const key = workspaceDraftKey(nextModel, nextOperation || (nextModel === modelId ? operation : ""));
        setDrafts((current) => ({ ...current, [key]: { ...current[key], values: nextValues } }));
        setConsent((current) => ({ ...current, [key]: false }));
        if (nextAuthoring) setAuthoringState((current) => ({ ...current, ...nextAuthoring }));
        if (nextModel !== modelId) selectModel(nextModel, nextOperation, summaryOf);
        else if (nextOperation && nextOperation !== operation) { selection.current += 1; setChosenOperation(nextOperation); updateUrl({ operation: nextOperation }); }
        setSubmitError(null);
        return true;
    };
    // Reset returns the setup to the model's defaults; the snapshot lets the member undo it.
    const resetDraft = (nextAuthoring = null) => {
        if (lock.current || frozen.current) return null;
        const snapshot = { key: draftKey, draft: drafts[draftKey], consent: consent[draftKey] === true, authoring };
        setDrafts((current) => omit(current, [draftKey]));
        setConsent((current) => ({ ...current, [draftKey]: false }));
        if (nextAuthoring) setAuthoringState((current) => ({ ...current, ...nextAuthoring }));
        setSubmitError(null);
        return snapshot;
    };
    const restoreSnapshot = (snapshot) => {
        if (!snapshot || lock.current || frozen.current) return;
        setDrafts((current) => snapshot.draft === undefined ? omit(current, [snapshot.key]) : { ...current, [snapshot.key]: snapshot.draft });
        setConsent((current) => ({ ...current, [snapshot.key]: snapshot.consent }));
        setAuthoringState(snapshot.authoring);
    };
    const selectJob = (id) => {
        selection.current += 1;
        fallback.current = "";
        setActiveId(id);
        setDetailError(null);
        setActionError(null);
        ownJob.current = id;
        updateUrl({ job: id, track: "" });
    };
    // Closing a result keeps it selected (and followed) but drops the link, so a reload does not reopen it.
    const releaseLink = () => { ownJob.current = ""; updateUrl({ job: "", track: "" }); };
    // The history chip is only a filter: it must not look like a URL kind change (that would swap the model).
    const setHistoryKind = (next) => {
        const target = CLEARABLE_KINDS.includes(next) ? next : "";
        setHistoryKindState(target);
        setHistory((current) => current.kind === target ? current : { kind: target, ids: [], cursor: null, total: null });
    };
    // Realtime sessions freeze the setup they started with until they end.
    const freeze = useCallback((value) => { frozen.current = value === true; }, []);
    // `extra` carries studio authoring: composed inputs (e.g. the video prompt) and native execution options.
    const submit = async (admissionPrice, resume = false, extra = {}) => {
        if (lock.current || frozen.current || (!resume && (request.current || !capability))) return;
        let submission = request.current;
        if (!resume) {
            const billing = capability.billing || {};
            const native = capability.contract_version !== 2;
            // Native quantity and Pro travel as top-level execution controls, never inside inputs.
            const counted = native ? [billing.count_field, billing.pro_field].filter(Boolean) : [];
            const body = {
                model: modelId, operation, inputs: omit(capabilitySubmission(capability, { ...values, ...extra.inputs }), counted),
                expected_capability_hash: capability.source_hash, expected_price_tokens: admissionPrice,
                ...extra.execution,
            };
            if (native && billing.count_field) body.count = Number(controls.count || 1);
            if (native && billing.pro_field) body.pro = controls.pro === true;
            if (consentRequired) body.rights_confirmed = controls.rights_confirmed === true;
            if (billing.duration_field === "billing_seconds") body.billing_seconds = Number(controls.billing_seconds);
            if (params.get("conversation_id")) body.conversation_id = params.get("conversation_id");
            submission = { key: crypto.randomUUID(), body };
        }
        if (!submission?.key || !submission.body) return;
        lock.current = true;
        request.current = submission;
        persist(requestKey, submission);
        setSubmitting(true);
        setSubmitError(null);
        const originSelection = selection.current;
        try {
            const data = await apiRequest(`${api}/jobs`, { method: "POST", body: { ...submission.body, idempotency_key: submission.key } });
            if (!data.job?.id && !data.jobs?.[0]?.id) throw new Error("Respons belum memuat pekerjaan tersimpan. Periksa permintaan yang sama.");
            persist(requestKey, null);
            request.current = null;
            if (!mounted.current) return;
            setUncertain(null);
            (data.jobs || [data.job]).forEach((entry) => { if (entry?.id) created.current.add(entry.id); });
            rememberJobs(data);
            if (originSelection === selection.current) {
                const id = data.job?.id || data.jobs[0].id;
                fallback.current = "";
                setActiveId(id);
                ownJob.current = id;
                updateUrl({ job: id, track: "" });
            }
            void refreshBalance();
            void loadHistory();
            return data;
        } catch (failure) {
            // A lost response or an unanswered recovery may hide an accepted paid request: keep the same key.
            const ambiguous = submissionUnresolved(failure, resume);
            if (!ambiguous) { request.current = null; persist(requestKey, null); }
            if (mounted.current) {
                setUncertain(ambiguous ? submission : null);
                setSubmitError(failure);
                if (failure.status === 409 && !frozen.current) setCapabilityRefresh((current) => current + 1);
            }
        } finally { lock.current = false; if (mounted.current) setSubmitting(false); }
    };
    const jobAction = async (id, action) => {
        if (actionLock.current) return;
        actionLock.current = true;
        detailSequence.current.set(id, (detailSequence.current.get(id) || 0) + 1);
        setActionBusy(`${id}:${action}`);
        setActionError(null);
        try {
            const data = await apiRequest(`${api}/jobs/${encodeURIComponent(id)}${action === "delete" ? "" : `/${action}`}`, { method: action === "delete" ? "DELETE" : "POST" });
            if (!mounted.current) return data;
            if (action === "delete") {
                setJobs((current) => current.filter((entry) => entry.id !== id));
                setHistory((current) => ({ ...current, ids: current.ids.filter((entry) => entry !== id) }));
                if (active.current === id) { setActiveId(""); ownJob.current = ""; updateUrl({ job: "", track: "" }); }
            } else rememberJobs(data);
            void refreshBalance();
            return data;
        } catch (failure) {
            if (mounted.current) { setActionError(failure); if (failure.details?.job) rememberJobs(failure.details); }
            throw failure;
        } finally { actionLock.current = false; if (mounted.current) setActionBusy(""); }
    };
    // Clears one kind's finished history; the server keeps running and still-referenced jobs.
    const clearHistory = async (target = historyKind) => {
        if (!CLEARABLE_KINDS.includes(target) || actionLock.current) return null;
        actionLock.current = true;
        setClearing(true);
        setClearError(null);
        try {
            const data = await apiRequest(`${api}/jobs?kind=${encodeURIComponent(target)}`, { method: "DELETE" });
            if (!mounted.current) return data;
            const removed = new Set(historyJobs.filter(finished).map((entry) => entry.id));
            if (removed.has(active.current)) { setActiveId(""); ownJob.current = ""; updateUrl({ job: "", track: "" }); }
            setJobs((current) => current.filter((entry) => !removed.has(entry.id)));
            setHistory((current) => ({ ...current, ids: current.ids.filter((id) => !removed.has(id)) }));
            void loadHistory();
            return data;
        } catch (failure) {
            if (mounted.current) setClearError(failure);
            return null;
        } finally { actionLock.current = false; if (mounted.current) setClearing(false); }
    };
    const reloadCapabilities = () => { if (!frozen.current) setCapabilityRefresh((current) => current + 1); };
    const refresh = () => { void loadModels(); void loadHistory(); void refreshBalance(); if (activeId) void loadJob(activeId); reloadCapabilities(); };
    // After a realtime save the session stays mounted: only history and the balance are re-read.
    const refreshAfterSession = () => { void loadHistory(); void refreshBalance(); };
    return {
        kind, modelId, model, summary, catalog, catalogLoading, catalogError, loadModels,
        capabilities, capability, capabilityLoading, capabilityError, reloadCapabilities,
        operation, draftKey, values, controls, consentRequired, setValues, setControls, selectModel, selectOperation, balance, freeze,
        authoring, setAuthoring, loadDraft, resetDraft, restoreSnapshot,
        jobs, job, batch, activeId, linkRequest, releaseLink, selectJob, loadJob, detailLoading, detailError,
        historyKind, setHistoryKind, historyJobs, historyCursor: history.kind === historyKind ? history.cursor : null, historyTotal: history.kind === historyKind ? history.total : null,
        historyLoading, historyError, loadHistory,
        submitting, submitError, uncertain, submit, actionBusy, actionError, jobAction, refresh, refreshAfterSession,
        clearHistory, clearing, clearError, setClearError,
    };
}

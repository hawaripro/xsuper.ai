import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { apiRequest } from "../../lib/api";
import { capabilitySubmission, capabilityValues } from "./capability";
import { assetSignature } from "./nativeStudio";
import { batchView, jobCandidates, mediaJobPending, submissionUnresolved, workspaceDraftKey } from "./workspaceMedia";

const api = "/api/media/workspace";
const stored = (key, fallback) => {
    try { const text = sessionStorage.getItem(key); return text && text.length < 2 * 1024 * 1024 ? JSON.parse(text) ?? fallback : fallback; } catch { return fallback; }
};
const persist = (key, value) => { try { if (value == null) sessionStorage.removeItem(key); else sessionStorage.setItem(key, JSON.stringify(value)); } catch { /* Drafts remain usable if browser storage is unavailable. */ } };
const omit = (value, keys) => Object.fromEntries(Object.entries(value).filter(([key]) => !keys.includes(key)));
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

export default function useGlobalMediaWorkspace({ kind, userId }) {
    const [params, setParams] = useSearchParams();
    const storageKey = `xsuper:media-workspace:${userId}:${kind || "all"}:v2`;
    const saved = useRef(stored(storageKey, {}));
    const requestKey = `xsuper:media-workspace:${userId}:pending:v2`;
    const [query, setQuery] = useState("");
    const [modelId, setModelId] = useState(() => params.get("model") || saved.current.model || "");
    const [chosenOperation, setChosenOperation] = useState(() => params.get("operation") || saved.current.operation || "");
    const [catalog, setCatalog] = useState({ models: [], next_cursor: null, total: 0 });
    const [catalogLoading, setCatalogLoading] = useState(true);
    const [catalogError, setCatalogError] = useState(null);
    const [capabilitySet, setCapabilitySet] = useState(null);
    const [capabilityLoading, setCapabilityLoading] = useState(false);
    const [capabilityError, setCapabilityError] = useState(null);
    const [capabilityRefresh, setCapabilityRefresh] = useState(0);
    const [drafts, setDrafts] = useState(() => saved.current.drafts && typeof saved.current.drafts === "object" && !Array.isArray(saved.current.drafts) ? saved.current.drafts : {});
    // Studio-level authoring (e.g. video product/UGC fields) spans the model's operations; callers restore it typed.
    const [authoring, setAuthoringState] = useState(() => saved.current.authoring && typeof saved.current.authoring === "object" && !Array.isArray(saved.current.authoring) ? saved.current.authoring : {});
    // Consent covers the files chosen in this visit only, so it is kept in memory per model/operation.
    const [consent, setConsent] = useState({});
    const [balance, setBalance] = useState(null);
    const [jobs, setJobs] = useState([]);
    const [historyCursor, setHistoryCursor] = useState(null);
    const [historyLoading, setHistoryLoading] = useState(true);
    const [historyError, setHistoryError] = useState(null);
    const linked = useRef(null);
    if (linked.current === null) linked.current = { jobs: jobCandidates(params.get("job"), kind), track: params.get("track") || "" };
    const [activeId, setActiveId] = useState(() => linked.current.jobs[0] || "");
    const fallback = useRef(linked.current.jobs[1] || "");
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
    const active = useRef(activeId);
    active.current = activeId;
    const modelSequence = useRef(0);
    const historySequence = useRef(0);
    const capabilities = capabilitySet?.for === modelId ? capabilitySet.capabilities || {} : {};
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

    useEffect(() => {
        mounted.current = true;
        return () => { mounted.current = false; modelSequence.current += 1; historySequence.current += 1; };
    }, []);
    useEffect(() => { persist(storageKey, { model: modelId, operation, drafts, authoring }); }, [storageKey, modelId, operation, drafts, authoring]);

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
    const loadModels = useCallback(async (cursor = null) => {
        const sequence = ++modelSequence.current;
        setCatalogLoading(true);
        setCatalogError(null);
        try {
            const search = new URLSearchParams({ kind, q: query });
            if (cursor) search.set("cursor", cursor);
            const data = await apiRequest(`${api}/models?${search}`);
            if (!mounted.current || sequence !== modelSequence.current) return;
            setCatalog((current) => ({ ...data, models: cursor ? [...new Map([...current.models, ...data.models].map((entry) => [entry.model_id, entry])).values()] : data.models || [] }));
            if (data.balance_tokens != null) setBalance(Number(data.balance_tokens));
            if (!cursor && !query && !frozen.current) setModelId((current) => current || data.models?.[0]?.model_id || "");
        } catch (failure) { if (mounted.current && sequence === modelSequence.current) setCatalogError(failure); }
        finally { if (mounted.current && sequence === modelSequence.current) setCatalogLoading(false); }
    }, [kind, query]);
    useEffect(() => {
        modelSequence.current += 1;
        setCatalogLoading(true);
        const timer = setTimeout(() => { void loadModels(); }, query ? 250 : 0);
        return () => { clearTimeout(timer); modelSequence.current += 1; };
    }, [loadModels, query]);
    useEffect(() => {
        if (!modelId) return;
        const controller = new AbortController();
        setCapabilityLoading(true);
        setCapabilityError(null);
        apiRequest(`${api}/capabilities?model=${encodeURIComponent(modelId)}`, { signal: controller.signal }).then((data) => {
            if (controller.signal.aborted) return;
            setCapabilitySet({ ...data, for: modelId });
            if (data.balance_tokens != null) setBalance(Number(data.balance_tokens));
        }).catch((failure) => { if (!controller.signal.aborted) setCapabilityError(failure); })
            .finally(() => { if (!controller.signal.aborted) setCapabilityLoading(false); });
        return () => controller.abort();
    }, [modelId, capabilityRefresh]);

    const loadHistory = useCallback(async (cursor = null) => {
        const sequence = ++historySequence.current;
        setHistoryLoading(true);
        setHistoryError(null);
        try {
            const search = new URLSearchParams({ kind });
            if (cursor) search.set("cursor", cursor);
            const data = await apiRequest(`${api}/jobs?${search}`);
            if (!mounted.current || sequence !== historySequence.current) return;
            rememberJobs(data);
            setHistoryCursor(data.next_cursor || null);
        } catch (failure) { if (mounted.current && sequence === historySequence.current) setHistoryError(failure); }
        finally { if (mounted.current && sequence === historySequence.current) setHistoryLoading(false); }
    }, [kind, rememberJobs]);
    useEffect(() => { void loadHistory(); }, [loadHistory]);
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
            // A bare id on a studio page is first read as that studio's native job, then as a common job.
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
    useEffect(() => {
        const candidates = jobCandidates(jobParam, kind);
        if (!candidates.length || candidates.includes(active.current)) return;
        fallback.current = candidates[1] || "";
        selection.current += 1;
        setActiveId(candidates[0]);
        setDetailError(null);
        setActionError(null);
    }, [jobParam, kind]);
    // ?model= and ?operation= (dashboard search, chat tools) apply on first load and on later
    // navigation to the same page, but never while a request or live session holds the setup.
    const modelParam = params.get("model") || "";
    const operationParam = params.get("operation") || "";
    const chosen = useRef({ model: modelId, operation: chosenOperation });
    chosen.current = { model: modelId, operation: chosenOperation };
    useEffect(() => {
        if (!modelParam || modelParam === chosen.current.model || lock.current || frozen.current) return;
        selection.current += 1;
        setModelId(modelParam);
        setChosenOperation(operationParam);
        setSubmitError(null);
    }, [modelParam, operationParam]);
    useEffect(() => {
        if (!operationParam || operationParam === chosen.current.operation || lock.current || frozen.current) return;
        setChosenOperation(operationParam);
    }, [operationParam]);
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
    // Settlement or refund happens when a job leaves the pending states.
    const watched = useRef({ id: "", pending: false });
    useEffect(() => {
        if (watched.current.id === activeId && watched.current.pending && !pending) void refreshBalance();
        watched.current = { id: activeId, pending };
    }, [activeId, pending, refreshBalance]);

    const updateUrl = (next) => setParams((current) => {
        const updated = new URLSearchParams(current);
        for (const [name, value] of Object.entries(next)) { if (value) updated.set(name, value); else updated.delete(name); }
        return updated;
    }, { replace: true });
    const selectModel = (id, nextOperation = "") => {
        if (lock.current || frozen.current) return;
        selection.current += 1;
        setModelId(id);
        setSubmitError(null);
        setChosenOperation(nextOperation);
        updateUrl({ model: id, operation: nextOperation });
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
    const selectJob = (id) => {
        selection.current += 1;
        fallback.current = "";
        setActiveId(id);
        setDetailError(null);
        setActionError(null);
        updateUrl({ job: id, track: "" });
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
            rememberJobs(data);
            if (originSelection === selection.current) {
                const id = data.job?.id || data.jobs[0].id;
                fallback.current = "";
                setActiveId(id);
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
                if (active.current === id) { setActiveId(""); updateUrl({ job: "", track: "" }); }
            } else rememberJobs(data);
            void refreshBalance();
            return data;
        } catch (failure) {
            if (mounted.current) { setActionError(failure); if (failure.details?.job) rememberJobs(failure.details); }
            throw failure;
        } finally { actionLock.current = false; if (mounted.current) setActionBusy(""); }
    };
    // Clears this studio's finished history; the server keeps running and still-referenced jobs.
    const clearHistory = async () => {
        if (!kind || actionLock.current) return null;
        actionLock.current = true;
        setClearing(true);
        setClearError(null);
        try {
            const data = await apiRequest(`${api}/jobs?kind=${encodeURIComponent(kind)}`, { method: "DELETE" });
            if (!mounted.current) return data;
            const finished = (entry) => ["completed", "failed", "cancelled"].includes(entry?.status);
            if (finished(jobs.find((entry) => entry.id === active.current))) { setActiveId(""); updateUrl({ job: "", track: "" }); }
            setJobs((current) => current.filter((entry) => !finished(entry)));
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
        query, setQuery, modelId, model, catalog, catalogLoading, catalogError, loadModels,
        capabilities, capability, capabilityLoading, capabilityError, reloadCapabilities,
        operation, draftKey, values, controls, consentRequired, setValues, setControls, selectModel, selectOperation, balance, freeze,
        authoring, setAuthoring,
        jobs, job, batch, activeId, linked: linked.current, historyCursor, historyLoading, historyError, loadHistory, selectJob, loadJob, detailLoading, detailError,
        submitting, submitError, uncertain, submit, actionBusy, actionError, jobAction, refresh, refreshAfterSession,
        clearHistory, clearing, clearError, setClearError,
    };
}

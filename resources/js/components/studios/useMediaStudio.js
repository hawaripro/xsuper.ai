import { useCallback, useEffect, useRef, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { apiRequest } from "../../lib/api";

const endpoints = {
    image: { models: "/api/images/models", history: "/api/images", create: "/api/images", detail: (id) => `/api/images/${id}` },
    video: { models: "/api/v/models", history: "/api/v/history", create: "/api/v/gen", detail: (id) => `/api/v/status/${id}`, cancel: (id) => `/api/v/${id}/cancel` },
    audio: { models: "/api/audio/models", history: "/api/audio", create: "/api/audio", detail: (id) => `/api/audio/${id}`, cancel: (id) => `/api/audio/${id}/cancel` },
};

export const isPending = (job) => ["pending", "processing"].includes(job?.status);
export const modelOptions = (model, name) => Array.isArray(model?.[name]) ? model[name] : [];
export const maxQuantity = (model) => Math.max(1, Math.min(10, Number(model?.max_quantity) || 1));
export const tokenPrice = (model) => model?.billing_mode === "tokens" && Number.isSafeInteger(Number(model.token_cost)) && Number(model.token_cost) > 0 ? Number(model.token_cost) : null;

// Only text/settings live here. Never persist account credentials, outputs or File objects.
export function useStudioDraft(kind, userId, defaults) {
    const key = `ultrai:studio:${kind}:${userId}:v1`;
    const [draft, setDraft] = useState(() => {
        try {
            const stored = JSON.parse(sessionStorage.getItem(key) || "null");
            if (!stored || typeof stored !== "object") return defaults;
            return Object.fromEntries(Object.entries(defaults).map(([name, value]) => [name,
                typeof stored[name] === typeof value && (typeof value !== "string" || stored[name].length <= 8000)
                    ? stored[name] : value,
            ]));
        } catch { return defaults; }
    });
    useEffect(() => {
        try { sessionStorage.setItem(key, JSON.stringify(draft)); } catch { /* Storage can be unavailable in private sessions. */ }
    }, [key, draft]);
    return [draft, setDraft];
}

export function useObjectUrl(file) {
    const [source, setSource] = useState(null);
    useEffect(() => {
        if (!file) { setSource(null); return; }
        const next = URL.createObjectURL(file);
        setSource({ file, url: next });
        return () => URL.revokeObjectURL(next);
    }, [file]);
    return source && source.file === file ? source.url : null;
}

export function useMediaStudio(kind) {
    const paths = endpoints[kind];
    const [params, setParams] = useSearchParams();
    const requestedJob = params.get("job") || "";
    const requestedModel = params.get("model") || "";
    const [models, setModels] = useState([]);
    const [jobs, setJobs] = useState([]);
    const [balance, setBalance] = useState(null);
    const [catalog, setCatalog] = useState(null);
    const [modelLoading, setModelLoading] = useState(true);
    const [modelError, setModelError] = useState(null);
    const [historyLoading, setHistoryLoading] = useState(true);
    const [historyError, setHistoryError] = useState(null);
    const [statusLoading, setStatusLoading] = useState(false);
    const [statusError, setStatusError] = useState(null);
    const [activeId, setActiveId] = useState(requestedJob);
    const [submitting, setSubmitting] = useState(false);
    const [submitError, setSubmitError] = useState(null);
    const [startedAt, setStartedAt] = useState(null);
    const [pollVersion, setPollVersion] = useState(0);
    const mounted = useRef(false);
    const requests = useRef({});
    const submitLock = useRef(false);
    const activeIdRef = useRef(activeId);
    activeIdRef.current = activeId;

    const updateBalance = useCallback((data) => {
        if (data?.balance != null && Number.isFinite(Number(data.balance))) setBalance(Number(data.balance));
    }, []);
    const request = useCallback((name) => {
        requests.current[name]?.abort();
        const controller = new AbortController();
        requests.current[name] = controller;
        return controller;
    }, []);
    const remember = useCallback((data, select = false, mutation = true) => {
        if (!mounted.current) return;
        const incoming = Array.isArray(data?.jobs) ? data.jobs : data?.job ? [data.job] : [];
        if (mutation) {
            requests.current.history?.abort();
            requests.current.status?.abort();
            setHistoryLoading(false);
            setStatusLoading(false);
        }
        if (incoming.length) {
            setJobs((current) => [...incoming, ...current.filter((job) => !incoming.some((next) => next.job_id === job.job_id))]);
            if (select) setActiveId(incoming[0].job_id);
        }
        updateBalance(data);
        setPollVersion((value) => value + 1);
    }, [updateBalance]);
    const loadModels = useCallback(async () => {
        const controller = request("models");
        setModelLoading(true);
        setModelError(null);
        try {
            const data = await apiRequest(paths.models, { signal: controller.signal });
            if (controller.signal.aborted) return;
            if (!Array.isArray(data?.models)) throw new Error("Katalog model tidak dapat dibaca. Muat ulang untuk mencoba lagi.");
            setModels(data.models);
            setCatalog(data);
            updateBalance(data);
        } catch (error) {
            if (!controller.signal.aborted) setModelError(error);
        } finally {
            if (!controller.signal.aborted) setModelLoading(false);
        }
    }, [paths, request, updateBalance]);
    const loadHistory = useCallback(async () => {
        const controller = request("history");
        setHistoryLoading(true);
        try {
            const data = await apiRequest(paths.history, { signal: controller.signal });
            if (controller.signal.aborted) return true;
            if (!Array.isArray(data?.jobs)) throw new Error("Riwayat tidak dapat dimuat");
            // An owned deep-linked result may be older than the history window.
            setJobs((current) => {
                const selected = current.find((job) => job.job_id === activeIdRef.current);
                return selected && !data.jobs.some((job) => job.job_id === selected.job_id) ? [...data.jobs, selected] : data.jobs;
            });
            setActiveId((current) => current || data.jobs.find(isPending)?.job_id || data.jobs[0]?.job_id || "");
            setHistoryError(null);
            updateBalance(data);
            return true;
        } catch (error) {
            if (!controller.signal.aborted) setHistoryError(error);
            return controller.signal.aborted;
        } finally {
            if (!controller.signal.aborted) setHistoryLoading(false);
        }
    }, [paths, request, updateBalance]);
    const loadJob = useCallback(async (id) => {
        if (!id) return;
        const controller = request("status");
        setStatusLoading(true);
        setStatusError(null);
        try {
            const data = await apiRequest(paths.detail(encodeURIComponent(id)), { signal: controller.signal });
            if (controller.signal.aborted) return;
            if (!data?.job?.job_id) throw new Error("Respons belum dapat dikonfirmasi. Periksa riwayat sebelum mengirim lagi.");
            remember(data, false, false);
        } catch (error) {
            if (!controller.signal.aborted) setStatusError(error);
        } finally {
            if (!controller.signal.aborted) setStatusLoading(false);
        }
    }, [paths, request, remember]);
    const refresh = useCallback(() => {
        setPollVersion((value) => value + 1);
        loadModels();
        loadHistory();
        if (activeIdRef.current) loadJob(activeIdRef.current);
    }, [loadModels, loadHistory, loadJob]);

    useEffect(() => {
        mounted.current = true;
        loadModels();
        loadHistory();
        const resume = () => { if (document.visibilityState !== "hidden") refresh(); };
        window.addEventListener("focus", resume);
        document.addEventListener("visibilitychange", resume);
        return () => {
            mounted.current = false;
            Object.values(requests.current).forEach((controller) => controller.abort());
            window.removeEventListener("focus", resume);
            document.removeEventListener("visibilitychange", resume);
        };
    }, [loadModels, loadHistory, refresh]);

    useEffect(() => {
        if (!requestedJob) return;
        setActiveId(requestedJob);
        loadJob(requestedJob);
    }, [requestedJob, loadJob]);

    const pending = jobs.some(isPending) || submitting;
    useEffect(() => {
        if (!pending) return;
        let stopped = false;
        let failures = 0;
        let timer;
        const poll = async () => {
            if (stopped) return;
            if (document.visibilityState !== "hidden") {
                const loaded = await loadHistory();
                failures = loaded ? 0 : failures + 1;
            }
            if (!stopped && failures < 3) timer = window.setTimeout(poll, 4000);
        };
        timer = window.setTimeout(poll, 4000);
        return () => { stopped = true; window.clearTimeout(timer); };
    }, [pending, loadHistory, pollVersion]);

    const selectJob = useCallback((job) => {
        setActiveId(job.job_id);
        setStatusError(null);
        setParams((current) => { const next = new URLSearchParams(current); next.set("job", job.job_id); return next; }, { replace: true });
        if (job.job_id === requestedJob) loadJob(job.job_id);
    }, [setParams, requestedJob, loadJob]);
    const selectModel = useCallback((id) => {
        setParams((current) => { const next = new URLSearchParams(current); next.set("model", id); return next; }, { replace: true });
    }, [setParams]);
    const submit = useCallback(async (body) => {
        if (submitLock.current) return;
        submitLock.current = true;
        setSubmitting(true);
        setSubmitError(null);
        setStartedAt(Date.now());
        try {
            // Do not abort paid submission on navigation; history is authoritative on return.
            const data = await apiRequest(paths.create, { method: "POST", body });
            if (!mounted.current) return;
            if (!data?.job?.job_id && !(Array.isArray(data?.jobs) && data.jobs.length)) throw new Error("Respons belum dapat dikonfirmasi. Periksa riwayat sebelum mengirim lagi.");
            remember(data, true);
            const id = data.job?.job_id || data.jobs[0].job_id;
            setParams((current) => { const next = new URLSearchParams(current); next.set("job", id); return next; }, { replace: true });
            return data;
        } catch (error) {
            if (mounted.current) { setSubmitError(error); remember(error.details, true); }
        } finally {
            submitLock.current = false;
            if (mounted.current) { setSubmitting(false); loadModels(); loadHistory(); }
        }
    }, [paths, remember, loadModels, loadHistory, setParams]);
    const cancel = useCallback(async (id) => {
        try {
            const data = await apiRequest(paths.cancel(encodeURIComponent(id)), { method: "POST" });
            if (!data?.job?.job_id) throw new Error("Pembatalan belum terkonfirmasi. Perbarui riwayat sebelum mencoba lagi.");
            remember(data);
            if (mounted.current) loadModels();
            return data;
        } catch (error) {
            remember(error.details);
            throw error;
        }
    }, [paths, remember, loadModels]);

    return { models, jobs, balance, catalog, modelLoading, modelError, historyLoading, historyError, statusLoading, statusError,
        activeJob: jobs.find((job) => job.job_id === activeId) || null, activeId, requestedModel, requestedJob,
        submitting, submitError, startedAt, refresh, loadModels, loadHistory, loadJob, selectJob, selectModel, submit, cancel };
}

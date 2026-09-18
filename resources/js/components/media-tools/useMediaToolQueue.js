import { useCallback, useEffect, useRef, useState } from "react";
import { apiRequest } from "../../lib/api";

const API = "/api/media-tools";
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const STATUSES = new Set(["pending", "processing", "completed", "failed", "cancelled"]);
const SUBMISSION_EVENT = "ultrai:media-tool-submission";
const submissions = new Map();
const inFlight = new Set();

export const isActiveJob = (job) => job && ["pending", "processing"].includes(job.status);
const validJob = (job, kind) => job?.kind === kind && UUID.test(job.job_id || "") && STATUSES.has(job.status);

function normalizedInputName(name) {
    const basename = name.slice(Math.max(name.lastIndexOf("/"), name.lastIndexOf("\\")) + 1);
    let normalized = "";
    let length = 0;
    for (const character of basename) {
        const code = character.codePointAt(0);
        if (code < 32 || code === 127) continue;
        normalized += character;
        if (++length === 200) break;
    }
    // Match the API's Unicode-length bound and PHP display-name fallback.
    return normalized && normalized !== "0" ? normalized : "Uploaded media";
}

function mergeJobs(current, incoming) {
    const result = new Map(current.map((job) => [job.job_id, job]));
    for (const job of incoming) {
        const previous = result.get(job.job_id);
        if (previous && !isActiveJob(previous) && isActiveJob(job)) continue;
        if (previous && Date.parse(previous.updated_at) > Date.parse(job.updated_at)) continue;
        result.set(job.job_id, job);
    }
    return [...result.values()].sort((a, b) => (Date.parse(b.created_at) || 0) - (Date.parse(a.created_at) || 0));
}

function readSubmission(key) {
    if (submissions.has(key)) return submissions.get(key);
    try {
        const saved = JSON.parse(sessionStorage.getItem(key) || "null");
        if (saved && typeof saved.id === "string" && Number.isFinite(saved.startedAt) && Array.isArray(saved.knownIds)) {
            submissions.set(key, saved);
            return saved;
        }
    } catch {
        // Recovery also works in memory when browser storage is unavailable.
    }
    return null;
}

function rememberSubmission(key, attempt, job = null) {
    if (attempt) submissions.set(key, attempt);
    else submissions.delete(key);
    try {
        if (attempt) sessionStorage.setItem(key, JSON.stringify(attempt));
        else sessionStorage.removeItem(key);
    } catch {
        // Do not turn a storage restriction into another HTTP submission.
    }
    window.dispatchEvent(new CustomEvent(SUBMISSION_EVENT, { detail: { key, job } }));
}

export default function useMediaToolQueue({ kind, userId, jobId }) {
    const storageKey = `ultrai.media-tools.${userId}.${kind}.submission`;
    const [jobs, setJobs] = useState([]);
    const [capabilities, setCapabilities] = useState(null);
    const [capabilityLoading, setCapabilityLoading] = useState(true);
    const [capabilityError, setCapabilityError] = useState(null);
    const [historyLoading, setHistoryLoading] = useState(true);
    const [historyLoaded, setHistoryLoaded] = useState(false);
    const [historyError, setHistoryError] = useState(null);
    const [deepLoading, setDeepLoading] = useState(false);
    const [deepError, setDeepError] = useState(null);
    const [submitting, setSubmitting] = useState(() => inFlight.has(storageKey));
    const [recovery, setRecovery] = useState(() => readSubmission(storageKey));
    const [submitError, setSubmitError] = useState(null);
    const [cancelling, setCancelling] = useState(null);
    const [cancelError, setCancelError] = useState(null);
    const [pollVersion, setPollVersion] = useState(0);
    const mounted = useRef(false);
    const jobsRef = useRef(jobs);
    const jobIdRef = useRef(jobId);
    const historyRequest = useRef(null);
    const capabilityRequest = useRef(null);
    const deepRequest = useRef(null);
    const cancellation = useRef(new Set());
    const mutationVersion = useRef(0);
    jobsRef.current = jobs;
    jobIdRef.current = jobId;

    const rememberJob = useCallback((job, mutation = false) => {
        if (!validJob(job, kind) || !mounted.current) return;
        if (mutation) mutationVersion.current += 1;
        setJobs((current) => mergeJobs(current, [job]));
    }, [kind]);

    const loadCapabilities = useCallback(() => {
        if (capabilityRequest.current) return capabilityRequest.current.promise;
        const controller = new AbortController();
        const request = { controller, promise: null };
        capabilityRequest.current = request;
        setCapabilityLoading(true);
        request.promise = (async () => {
            try {
                const data = await apiRequest(`${API}/capabilities`, { signal: controller.signal });
                if (controller.signal.aborted || !mounted.current) return false;
                if (!data?.available || !data?.limits || !Array.isArray(data[`${kind}_formats`])) {
                    throw new Error("Kemampuan alat tidak dapat dibaca. Periksa kembali ketersediaannya.");
                }
                setCapabilities(data);
                setCapabilityError(null);
                return true;
            } catch (error) {
                if (!controller.signal.aborted && mounted.current) setCapabilityError(error);
                return false;
            } finally {
                if (capabilityRequest.current === request) capabilityRequest.current = null;
                if (!controller.signal.aborted && mounted.current) setCapabilityLoading(false);
            }
        })();
        return request.promise;
    }, [kind]);

    const loadHistory = useCallback(() => {
        if (historyRequest.current) return historyRequest.current.promise;
        const controller = new AbortController();
        const request = { controller, promise: null };
        const version = mutationVersion.current;
        historyRequest.current = request;
        setHistoryLoading(true);
        request.promise = (async () => {
            try {
                const data = await apiRequest(`${API}?kind=${kind}`, { signal: controller.signal });
                if (controller.signal.aborted || !mounted.current) return false;
                if (!Array.isArray(data?.jobs)) throw new Error("Riwayat tidak dapat dimuat");
                const incoming = data.jobs.filter((job) => validJob(job, kind));
                if (version === mutationVersion.current) setJobs((current) => mergeJobs(current, incoming));
                setHistoryLoaded(true);
                setHistoryError(null);
                const attempt = readSubmission(storageKey);
                if (attempt && !inFlight.has(storageKey)) {
                    const candidates = incoming.filter((job) => !attempt.knownIds.includes(job.job_id)
                        && Date.parse(job.created_at) >= attempt.startedAt - 5000
                        && job.format === attempt.format
                        && (!attempt.inputName || job.input_name === normalizedInputName(attempt.inputName)));
                    if (candidates.length === 1) rememberSubmission(storageKey, null, candidates[0]);
                }
                return true;
            } catch (error) {
                if (!controller.signal.aborted && mounted.current) setHistoryError(error);
                return false;
            } finally {
                if (historyRequest.current === request) historyRequest.current = null;
                if (!controller.signal.aborted && mounted.current) setHistoryLoading(false);
            }
        })();
        return request.promise;
    }, [kind, storageKey]);

    const loadJob = useCallback(async (id) => {
        deepRequest.current?.abort();
        if (!id) {
            setDeepLoading(false);
            setDeepError(null);
            return;
        }
        if (!UUID.test(id)) {
            setDeepLoading(false);
            setDeepError(new Error("Tautan pekerjaan tidak valid. Pilih hasil dari riwayat."));
            return;
        }
        const controller = new AbortController();
        const version = mutationVersion.current;
        deepRequest.current = controller;
        setDeepLoading(true);
        setDeepError(null);
        try {
            const data = await apiRequest(`${API}/${encodeURIComponent(id)}`, { signal: controller.signal });
            if (controller.signal.aborted || !mounted.current) return;
            if (!validJob(data?.job, kind)) throw new Error("Pekerjaan ini tidak tersedia di alat ini. Pilih hasil dari riwayat.");
            if (version === mutationVersion.current) rememberJob(data.job);
        } catch (error) {
            if (!controller.signal.aborted && mounted.current) {
                setDeepError(error.status === 404
                    ? new Error("Pekerjaan tidak ditemukan atau bukan milik akun ini.") : error);
            }
        } finally {
            if (!controller.signal.aborted && mounted.current) setDeepLoading(false);
        }
    }, [kind, rememberJob]);

    const refresh = useCallback(() => {
        setPollVersion((value) => value + 1);
        return Promise.all([loadCapabilities(), loadHistory(), loadJob(jobIdRef.current)]);
    }, [loadCapabilities, loadHistory, loadJob]);

    useEffect(() => {
        mounted.current = true;
        const onSubmission = (event) => {
            if (event.detail?.key !== storageKey) return;
            setRecovery(readSubmission(storageKey));
            setSubmitting(inFlight.has(storageKey));
            if (event.detail.job) rememberJob(event.detail.job, true);
        };
        const onFocus = () => {
            if (document.visibilityState !== "hidden") refresh();
        };
        window.addEventListener(SUBMISSION_EVENT, onSubmission);
        window.addEventListener("focus", onFocus);
        document.addEventListener("visibilitychange", onFocus);
        loadCapabilities();
        loadHistory();
        return () => {
            mounted.current = false;
            window.removeEventListener(SUBMISSION_EVENT, onSubmission);
            window.removeEventListener("focus", onFocus);
            document.removeEventListener("visibilitychange", onFocus);
            historyRequest.current?.controller.abort();
            capabilityRequest.current?.controller.abort();
            deepRequest.current?.abort();
            historyRequest.current = null;
            capabilityRequest.current = null;
        };
    }, [storageKey, loadCapabilities, loadHistory, refresh, rememberJob]);

    useEffect(() => { loadJob(jobId); }, [jobId, loadJob]);

    const hasActive = jobs.some(isActiveJob) || Boolean(recovery);
    useEffect(() => {
        if (!hasActive) return;
        let stopped = false;
        let timer;
        let failures = 0;
        const poll = async () => {
            if (stopped) return;
            if (document.visibilityState !== "hidden") {
                const ok = await loadHistory();
                failures = ok ? 0 : failures + 1;
                const selected = jobsRef.current.find((job) => job.job_id === jobIdRef.current);
                if (ok && isActiveJob(selected)) loadJob(selected.job_id);
            }
            if (!stopped && failures < 3) timer = window.setTimeout(poll, 4000 * (failures + 1));
        };
        timer = window.setTimeout(poll, 4000);
        return () => { stopped = true; window.clearTimeout(timer); };
    }, [hasActive, loadHistory, loadJob, pollVersion]);

    const submit = useCallback(async (body, format, inputName = null) => {
        if (inFlight.has(storageKey) || readSubmission(storageKey)) return null;
        const attempt = {
            id: crypto.randomUUID(),
            startedAt: Date.now(),
            format,
            inputName,
            knownIds: jobsRef.current.map((job) => job.job_id),
        };
        inFlight.add(storageKey);
        setSubmitError(null);
        rememberSubmission(storageKey, attempt);
        try {
            // Do not abort a POST on navigation: aborting the browser cannot cancel an accepted server job.
            const data = await apiRequest(`${API}/${kind}`, { method: "POST", body });
            if (!validJob(data?.job, kind)) throw new Error("Respons belum dapat dikonfirmasi. Periksa riwayat sebelum mengirim lagi.");
            inFlight.delete(storageKey);
            rememberSubmission(storageKey, null, data.job);
            return data.job;
        } catch (error) {
            inFlight.delete(storageKey);
            const rejected = error.status >= 400 && error.status < 500 && error.status !== 408;
            rememberSubmission(storageKey, rejected ? null : attempt);
            if (mounted.current) setSubmitError(error);
            return null;
        } finally {
            if (mounted.current) loadHistory();
        }
    }, [storageKey, kind, loadHistory]);

    const cancel = useCallback(async (job) => {
        if (!job.can_cancel || cancellation.current.has(job.job_id)) return;
        cancellation.current.add(job.job_id);
        setCancelling(job.job_id);
        deepRequest.current?.abort();
        setDeepLoading(false);
        setCancelError(null);
        try {
            const data = await apiRequest(`${API}/${encodeURIComponent(job.job_id)}/cancel`, { method: "POST" });
            if (!validJob(data?.job, kind)) throw new Error("Pembatalan belum terkonfirmasi. Periksa status sebelum mencoba lagi.");
            rememberJob(data.job, true);
        } catch (error) {
            rememberJob(error.details?.job, true);
            if (mounted.current) setCancelError({ jobId: job.job_id, error });
        } finally {
            cancellation.current.delete(job.job_id);
            if (mounted.current) {
                setCancelling(null);
                loadHistory();
            }
        }
    }, [kind, rememberJob, loadHistory]);

    return {
        jobs, capabilities, capabilityLoading, capabilityError,
        historyLoading, historyLoaded, historyError, deepLoading, deepError,
        submitting, recovery, submitError, cancelling, cancelError,
        refresh, loadCapabilities, loadHistory, loadJob, submit, cancel,
        clearSubmitError: () => setSubmitError(null),
    };
}

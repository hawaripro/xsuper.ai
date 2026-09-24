import { useEffect, useId, useMemo, useRef, useState } from "react";
import { useLocale } from "../../contexts/LocaleContext";
import { apiRequest } from "../../lib/api";
import CapabilityForm from "./CapabilityForm";
import { schemaDefault, schemaErrors } from "./schema";
import { StudioButton, StudioIcon, StudioNotice, mediaError } from "./StudioUI";
import { realtimeOutcomeUnknown } from "./workspaceMedia";
import "./realtime-media-session.css";

// One finite WMA/WebRTC session per purchase. The browser owns the peer connection and the
// control data channel; the server owns credentials, the heartbeat lease and billing.
const API = "/api/media/realtime";
const OPERATION = "realtime_video";
const HEARTBEAT_MS = 5000;
const HEARTBEAT_TIMEOUT_MS = 4500;
const ICE_HARD_MS = 6000;
const ICE_QUIET_MS = 800;
const STOP_GRACE_MS = 2500;
const NEGOTIATION_MS = 150000;
const MAX_QUEUED = 64;
const MAX_EVENTS = 80;
const MAX_CLIPS = 3;
const DEFAULT_RECORDING_BYTES = 104857600;
const RECORDING_FORMATS = [
    ["video/webm;codecs=vp9,opus", "video/webm", "webm"],
    ["video/webm;codecs=vp8,opus", "video/webm", "webm"],
    ["video/webm", "video/webm", "webm"],
    ["video/mp4;codecs=avc1.42E01E,mp4a.40.2", "video/mp4", "mp4"],
    ["video/mp4", "video/mp4", "mp4"],
];
const RUNNING = new Set(["ice", "gathering", "negotiating", "connecting", "configuring", "ready", "stopping"]);
const TERMINAL = new Set(["closed", "exhausted", "expired", "failed", "uncertain"]);
const LIVE = new Set(["connecting", "configuring", "ready", "stopping"]);
const phaseLabels = {
    idle: "Siap dimulai", ice: "Menyiapkan jalur jaringan…", gathering: "Mengumpulkan kandidat jaringan…",
    negotiating: "Menghubungkan ke model…", connecting: "Membuka kanal kontrol…", configuring: "Menunggu konfirmasi pengaturan…",
    ready: "Sesi langsung berjalan", stopping: "Menghentikan sesi…", closed: "Sesi dihentikan", exhausted: "Aliran selesai",
    expired: "Batas waktu sesi tercapai", failed: "Sesi gagal", uncertain: "Penerimaan sesi belum terkonfirmasi",
};
const phaseHelp = {
    idle: "Video langsung tampil di sini setelah sesi dimulai. Sesi tidak menghasilkan file video; rekaman bersifat opsional.",
    ice: "Belum ada token yang dipakai.", gathering: "Belum ada token yang dipakai.",
    negotiating: "Token dicadangkan dan hanya dibebankan jika penyedia menerima sesi.",
    connecting: "Sesi sudah diterima. Sisa waktu berjalan sejak penerimaan.",
    configuring: "Model sedang memeriksa pengaturan awal. Pembaruan aktif setelah konfirmasi.",
    ready: "Pengaturan dikonfirmasi. Video muncul saat segmen pertama siap.", stopping: "Meminta model berhenti dan menutup koneksi.",
    closed: "Sesi yang berakhir tidak dapat dibuka kembali. Mulai sesi baru untuk pembelian baru.",
    exhausted: "Sesi yang berakhir tidak dapat dibuka kembali. Mulai sesi baru untuk pembelian baru.",
    expired: "Sesi yang berakhir tidak dapat dibuka kembali. Mulai sesi baru untuk pembelian baru.",
    failed: "Sesi yang berakhir tidak dapat dibuka kembali. Mulai sesi baru untuk pembelian baru.",
    uncertain: "Sesi tidak dikirim ulang. Periksa status dan tagihannya di riwayat sesi.",
};
const statusLabels = { preparing: "Menyiapkan", negotiating: "Menghubungkan", connected: "Berjalan", closed: "Dihentikan", exhausted: "Selesai", expired: "Waktu habis", failed: "Gagal", uncertain: "Belum terkonfirmasi" };
const billingLabels = { reserved: "Dicadangkan", charged: "Dibebankan", released: "Dikembalikan" };
const submissionLabels = {
    queued: "Menunggu kanal terbuka", sent: "Terkirim, menunggu tanggapan", configured: "Dikonfirmasi model", pending: "Sedang disiapkan model",
    applied: "Diterima model, belum tentu terlihat", rejected: "Ditolak", failed: "Gagal", unsent: "Tidak terkirim", unknown: "Hasil tidak diketahui",
};
const audioLabels = { pending: "Audio sedang disiapkan", applied: "Audio diterima", rejected: "Audio ditolak" };
const statusTone = { configured: "completed", applied: "completed", rejected: "failed", failed: "failed", unsent: "failed" };

class LocalFailure extends Error {}

const wait = (ms) => new Promise((resolve) => { setTimeout(resolve, ms); });
// Secure contexts provide randomUUID; the fallback still yields a unique, bounded request key.
const newKey = () => globalThis.crypto?.randomUUID?.() ?? `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
const decoder = typeof TextDecoder === "undefined" ? null : new TextDecoder();
const clock = (seconds) => `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, "0")}`;
const ratioOf = (value) => {
    const match = /^(\d+):(\d+)$/.exec(typeof value === "string" ? value : "");
    return match && Number(match[2]) > 0 ? Number(match[1]) / Number(match[2]) : 16 / 9;
};
const inputErrors = (errors) => Object.fromEntries(Object.entries(errors || {}).map(([key, value]) => [key ? `inputs.${key}` : "inputs", value]));
const withoutInputsPrefix = (errors) => Object.fromEntries(Object.entries(errors || {})
    .filter(([key]) => key === "inputs" || key.startsWith("inputs."))
    .map(([key, value]) => [key === "inputs" ? "" : key.slice(7), Array.isArray(value) ? value[0] : value]));
const isUrlOf = (value) => typeof value === "string" && value.startsWith("/api/");

/** Recvonly transceivers follow the published x-fal-media receive list; the SDK default is video. */
function receiveKinds(capability) {
    const receive = capability?.output_schema?.["x-fal-media"]?.receive;
    const kinds = Array.isArray(receive) ? receive.map((entry) => entry?.kind).filter((kind) => kind === "video" || kind === "audio") : [];
    return kinds.length ? [...new Set(kinds)] : ["video"];
}

function eventSchemas(capability) {
    const schemas = new Map();
    for (const schema of capability?.output_schema?.oneOf || []) {
        const type = schema?.properties?.type?.const;
        if (typeof type === "string") schemas.set(type, schema);
    }
    return schemas;
}

/** Non-trickle signalling: one offer after a sufficient candidate set, a quiet period, or a hard bound. */
function gatherIce(pc, relayOffered) {
    return new Promise((resolve) => {
        const counts = { host: 0, srflx: 0, prflx: 0, relay: 0 };
        let done = false;
        let quiet = null;
        const finish = () => {
            if (done) return;
            done = true;
            clearTimeout(hard);
            clearTimeout(quiet);
            pc.removeEventListener("icecandidate", onCandidate);
            pc.removeEventListener("icegatheringstatechange", onState);
            resolve(counts);
        };
        const hard = setTimeout(finish, ICE_HARD_MS);
        const onState = () => { if (pc.iceGatheringState === "complete") finish(); };
        function onCandidate(event) {
            if (!event.candidate) return finish();
            const type = event.candidate.type || /\btyp (\w+)/.exec(event.candidate.candidate || "")?.[1];
            if (type in counts) counts[type] += 1;
            if (relayOffered ? counts.relay > 0 : counts.srflx + counts.host > 0) {
                clearTimeout(quiet);
                quiet = setTimeout(finish, ICE_QUIET_MS);
            }
        }
        pc.addEventListener("icecandidate", onCandidate);
        pc.addEventListener("icegatheringstatechange", onState);
    });
}

function decodeFrame(data) {
    if (typeof data === "string") return data;
    if (decoder && data instanceof ArrayBuffer) return decoder.decode(new Uint8Array(data));
    if (decoder && ArrayBuffer.isView(data)) return decoder.decode(data);
    return null;
}

function RecordIcon() {
    return <svg className="studio-icon realtime-record-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7">
        <circle cx="12" cy="12" r="8" /><circle cx="12" cy="12" r="3.5" fill="currentColor" stroke="none" />
    </svg>;
}

export default function RealtimeMediaSession({ model, capability, inputs, disabled = false, onValidationErrors, onActiveChange, onSaved }) {
    const { t, locale } = useLocale();
    const headingId = useId();
    const updateSchema = capability?.execution?.update_schema || null;
    const maxSeconds = Math.max(1, Number(capability?.execution?.max_session_seconds) || 60);
    const price = Number(capability?.price_tokens);
    const kinds = useMemo(() => receiveKinds(capability), [capability]);
    const schemas = useMemo(() => eventSchemas(capability), [capability]);
    const updateCapability = useMemo(() => updateSchema ? { contract_version: 2, input_schema: updateSchema } : null, [updateSchema]);
    const blankUpdate = () => updateSchema ? schemaDefault(updateSchema, undefined) : {};

    const [phase, setPhaseState] = useState("idle");
    const [session, setSession] = useState(null);
    const [notice, setNotice] = useState(null);
    const [remaining, setRemaining] = useState(null);
    const [media, setMedia] = useState({ video: false, audio: false });
    const [playback, setPlayback] = useState("waiting");
    const [iceSource, setIceSource] = useState(null);
    const [degraded, setDegraded] = useState(false);
    const [submissions, setSubmissions] = useState([]);
    const [events, setEvents] = useState([]);
    const [chunks, setChunks] = useState(0);
    const [updateValues, setUpdateValues] = useState(blankUpdate);
    const [updateErrors, setUpdateErrors] = useState({});
    const [updateUploads, setUpdateUploads] = useState({ busy: false, failed: false });
    const [sending, setSending] = useState(false);
    const [recording, setRecording] = useState({ active: false, bytes: 0, note: null });
    const [clips, setClips] = useState([]);
    const [history, setHistory] = useState({ sessions: [], next: null, loading: false, error: null, closing: null });

    const run = useRef(null);
    const phaseRef = useRef("idle");
    const videoRef = useRef(null);
    const recorderRef = useRef(null);
    const clipsRef = useRef([]);
    const eventId = useRef(0);
    const limitRef = useRef(DEFAULT_RECORDING_BYTES);
    const callbacks = useRef({ onActiveChange, onSaved, onValidationErrors });
    const actions = useRef({});
    const recordingLimit = Number(session?.recording_limit?.max_bytes) || DEFAULT_RECORDING_BYTES;
    const recordingSlots = Number(session?.recording_limit?.max_files ?? MAX_CLIPS);

    useEffect(() => { callbacks.current = { onActiveChange, onSaved, onValidationErrors }; });
    useEffect(() => { limitRef.current = recordingLimit; }, [recordingLimit]);

    const number = (value) => new Intl.NumberFormat(locale).format(value);
    const bytes = (value) => `${new Intl.NumberFormat(locale, { maximumFractionDigits: 1 }).format(value / 1048576)} MB`;
    const stamp = (value) => value ? new Intl.DateTimeFormat(locale, { dateStyle: "medium", timeStyle: "short" }).format(new Date(value)) : "";
    const time = (value) => new Intl.DateTimeFormat(locale, { timeStyle: "medium" }).format(value);
    const setPhase = (next) => { phaseRef.current = next; setPhaseState(next); };
    const logEvent = (entry) => {
        eventId.current += 1;
        const id = eventId.current;
        setEvents((current) => [{ id, at: new Date(), level: "info", ...entry }, ...current].slice(0, MAX_EVENTS));
    };
    const markSubmission = (version, patch) => setSubmissions((current) => current.map((entry) => entry.version === version ? { ...entry, ...patch(entry) } : entry));
    const clipName = (clip) => `realtime-${String(clip.sessionId || "session").slice(0, 8)}-${clip.createdAt.toISOString().replace(/[-:]/g, "").slice(0, 15)}.${clip.extension}`;
    const setupSummary = (values) => typeof values?.prompt === "string" ? (values.prompt.length > 90 ? `${values.prompt.slice(0, 90)}…` : values.prompt) : "";
    const updateSummary = (values) => {
        if (Array.isArray(values?.script)) return `${t("Skrip")} · ${number(values.script.length)} ${t("bagian")}`;
        const parts = [];
        if (typeof values?.prompt === "string") parts.push(values.prompt.length > 90 ? `${values.prompt.slice(0, 90)}…` : values.prompt);
        if (values?.end_image_url) parts.push(t("Bingkai akhir"));
        if (values?.audio_url) parts.push(t("Audio"));
        return parts.join(" · ") || t("Pembaruan");
    };

    const loadHistory = async (cursor = null) => {
        setHistory((current) => ({ ...current, loading: true, error: null }));
        try {
            const data = await apiRequest(`${API}/sessions${cursor ? `?cursor=${encodeURIComponent(cursor)}` : ""}`);
            setHistory((current) => ({ ...current, sessions: cursor ? [...current.sessions, ...(data.sessions || [])] : data.sessions || [], next: data.next_cursor || null, loading: false }));
        } catch (error) {
            setHistory((current) => ({ ...current, loading: false, error }));
        }
    };

    const stopRecorder = () => {
        const recorder = recorderRef.current;
        if (recorder && recorder.state !== "inactive") {
            try { recorder.stop(); } catch { /* The recorder already stopped with its stream. */ }
        }
    };

    const teardown = (attempt) => {
        clearInterval(attempt.heartbeat);
        clearInterval(attempt.tick);
        clearTimeout(attempt.stopTimer);
        attempt.heartbeat = null;
        attempt.tick = null;
        attempt.pending = [];
        stopRecorder();
        if (attempt.channel) {
            const channel = attempt.channel;
            channel.onopen = null; channel.onclose = null; channel.onerror = null; channel.onmessage = null;
            try { channel.close(); } catch { /* Already closed. */ }
        }
        if (attempt.pc) {
            const pc = attempt.pc;
            pc.ontrack = null; pc.onconnectionstatechange = null;
            try { pc.close(); } catch { /* Already closed. */ }
        }
        attempt.resolveExhausted?.();
    };

    const reportClose = async (attempt, reason, detail = null) => {
        if (!attempt.id) return;
        try {
            const data = await apiRequest(`${API}/sessions/${attempt.id}/close`, { method: "POST", body: { reason, ...(detail ? { detail } : {}) } });
            setSession(data.session);
            callbacks.current.onSaved?.(data.session);
        } catch {
            // Without heartbeats the server lease still lapses at its reviewed deadline.
        }
    };

    const finish = async (attempt, reason, detail = null) => {
        if (!attempt || attempt.ending) return;
        attempt.ending = true;
        const graceful = (reason === "stopped" || reason === "expired") && attempt.channel?.readyState === "open" && !attempt.exhausted;
        if (graceful) {
            setPhase("stopping");
            try { attempt.channel.send(JSON.stringify({ type: "stop" })); } catch { /* The peer is already gone. */ }
            await new Promise((resolve) => { attempt.resolveExhausted = resolve; attempt.stopTimer = setTimeout(resolve, STOP_GRACE_MS); });
        }
        teardown(attempt);
        attempt.ended = true;
        const status = { exhausted: "exhausted", failed: "failed", expired: "expired" }[reason] || "closed";
        setSubmissions((current) => current.map((entry) => ["queued", "sent", "pending"].includes(entry.state) ? { ...entry, state: "unknown" } : entry));
        setDegraded(false);
        setRemaining(reason === "expired" ? 0 : null);
        if (run.current === attempt) setPhase(status);
        callbacks.current.onActiveChange?.(false);
        await reportClose(attempt, { exhausted: "exhausted", failed: "failed", expired: "expired" }[reason] || "stopped", detail);
        void loadHistory();
    };

    const fail = (attempt, code, text) => {
        if (!attempt || attempt.ending) return;
        setNotice({ error: true, text });
        void finish(attempt, "failed", code);
    };

    const beat = async (attempt, force = false) => {
        if (!attempt.id || attempt.ending || (!force && attempt.beating)) return;
        if (!force) attempt.beating = true;
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), HEARTBEAT_TIMEOUT_MS);
        try {
            const data = await apiRequest(`${API}/sessions/${attempt.id}/heartbeat`, { method: "POST", signal: controller.signal,
                body: { session_token: attempt.token, configured: attempt.configured } });
            if (run.current !== attempt || attempt.ending) return;
            attempt.misses = 0;
            setDegraded(Boolean(data.degraded));
            if (data.session) {
                setSession(data.session);
                attempt.configuredReported = Boolean(data.session.configured);
            }
            if (!data.alive) {
                const ended = data.session || {};
                if (ended.status === "expired") void finish(attempt, "expired");
                else {
                    setNotice({ error: ended.status === "failed", text: ended.error_message || "Penyedia mengakhiri sesi ini." });
                    void finish(attempt, ended.status === "failed" ? "failed" : "ended", ended.status === "failed" ? ended.terminal_reason : null);
                }
            }
        } catch (error) {
            if (run.current !== attempt || attempt.ending) return;
            if (error?.status === 403 || error?.status === 404) fail(attempt, "lease_lost", "Tab ini tidak lagi memegang sesi, jadi video dihentikan.");
            else {
                attempt.misses = (attempt.misses || 0) + 1;
                if (attempt.misses >= 2) setDegraded(true);
            }
        } finally {
            clearTimeout(timer);
            if (!force) attempt.beating = false;
        }
    };

    const startDeadline = (attempt, remainingMs) => {
        const budget = Number.isFinite(Number(remainingMs)) && remainingMs !== null ? Number(remainingMs) : maxSeconds * 1000;
        const deadline = Date.now() + Math.max(0, budget);
        const tick = () => {
            if (attempt.ending) return;
            const left = Math.max(0, deadline - Date.now());
            setRemaining(left);
            if (left <= 0) void actions.current.finish(attempt, "expired");
        };
        clearInterval(attempt.tick);
        attempt.tick = setInterval(tick, 500);
        tick();
    };

    const sendControl = (attempt, message, version = null) => {
        const payload = JSON.stringify(message);
        const size = new TextEncoder().encode(payload).length;
        const limit = Math.min(Number(attempt.protocol?.max_message_bytes) || 262144, Number(attempt.pc?.sctp?.maxMessageSize) || Infinity);
        if (size > limit) return "too_large";
        const channel = attempt.channel;
        if (channel?.readyState === "open") {
            try { channel.send(payload); return "sent"; } catch { return "closed"; }
        }
        if (channel?.readyState === "connecting" && attempt.pending.length < MAX_QUEUED) {
            attempt.pending.push({ payload, version });
            return "queued";
        }
        return "closed";
    };

    const play = async (withSound) => {
        const video = videoRef.current;
        if (!video) return;
        video.muted = !withSound;
        try {
            await video.play();
            setPlayback(withSound ? "playing" : "muted");
        } catch (error) {
            if (withSound && error?.name === "NotAllowedError") return play(false);
            if (error?.name !== "AbortError") setPlayback("blocked");
        }
    };

    const handleMessage = (attempt, raw) => {
        if (run.current !== attempt || attempt.ended) return;
        let message;
        try { message = JSON.parse(raw); } catch {
            logEvent({ level: "diagnostic", text: "Pesan kontrol bukan JSON dan diabaikan." });
            return;
        }
        const type = message && typeof message === "object" && !Array.isArray(message) && typeof message.type === "string" ? message.type : null;
        const schema = type ? schemas.get(type) : null;
        if (!schema) {
            logEvent({ level: "diagnostic", type: type?.slice(0, 40) || null, text: "Peristiwa di luar kontrak diabaikan." });
            return;
        }
        if (Object.keys(schemaErrors(schema, message)).length) {
            logEvent({ level: "diagnostic", type, text: "Peristiwa tidak sesuai kontrak dan diabaikan." });
            // An end-of-stream notice still ends this attempt; later messages must never reopen it.
            if (type === (attempt.protocol?.ended_event || "stream_exhausted") && !attempt.ending) void finish(attempt, "exhausted", "stream_exhausted");
            return;
        }
        const protocol = attempt.protocol || {};
        const version = Number.isSafeInteger(message.prompt_version) ? message.prompt_version : null;
        const known = version !== null && attempt.issued.has(version);
        const errors = protocol.errors || {};
        if (type === (protocol.ready_event || "configured")) {
            if (version === Number(protocol.initial_version ?? 1) && !attempt.configured) {
                attempt.configured = true;
                markSubmission(version, () => ({ state: "configured" }));
                setPhase("ready");
                logEvent({ type, text: "Pengaturan dikonfirmasi model.", detail: [message.resolution, message.aspect_ratio].filter((value) => typeof value === "string").join(" · ") });
                void beat(attempt, true);
            } else {
                logEvent({ level: "diagnostic", type, text: "Konfirmasi pengaturan tidak cocok dengan versi yang dikirim." });
            }
        } else if (["prompt_pending", "prompt_applied", "prompt_rejected"].includes(type)) {
            if (!known) {
                logEvent({ level: "diagnostic", type, text: "Tanggapan untuk versi yang tidak dikirim tab ini diabaikan." });
                return;
            }
            const state = { prompt_pending: "pending", prompt_applied: "applied", prompt_rejected: "rejected" }[type];
            markSubmission(version, (entry) => ({ state: entry.state === "configured" && state === "pending" ? entry.state : state,
                reason: typeof message.reason === "string" ? message.reason : entry.reason || null }));
            logEvent({ type, text: state === "applied" ? "Arahan diterima model; belum tentu sudah terlihat." : state === "pending" ? "Arahan sedang disiapkan." : "Arahan ditolak.", detail: `v${version}${message.reason ? ` · ${message.reason}` : ""}` });
        } else if (["audio_pending", "audio_applied", "audio_rejected"].includes(type)) {
            const audio = { audio_pending: "pending", audio_applied: "applied", audio_rejected: "rejected" }[type];
            if (known) markSubmission(version, () => ({ audio }));
            logEvent({ type, text: audioLabels[audio], detail: `v${version}${message.reason ? ` · ${message.reason}` : ""}` });
        } else if (type === "error") {
            const code = String(message.code);
            if ((errors.session_failure || []).includes(code)) {
                logEvent({ level: "error", type, text: "Model mengakhiri sesi karena kesalahan.", detail: code });
                fail(attempt, code, message.error || code);
            } else if ((errors.input_failure || []).includes(code) && known) {
                markSubmission(version, () => ({ state: "failed", reason: code }));
                logEvent({ level: "warning", type, text: "Arahan gagal diproses.", detail: `v${version} · ${code}` });
            } else {
                logEvent({ level: "diagnostic", type, text: "Diagnostik dari model.", detail: `${code}${message.error ? ` · ${message.error}` : ""}` });
            }
        } else if (type === (protocol.ended_event || "stream_exhausted")) {
            attempt.exhausted = message;
            logEvent({ type, text: "Aliran selesai.", detail: message.reason });
            if (attempt.ending) attempt.resolveExhausted?.();
            else void finish(attempt, "exhausted", message.reason === "session_limit" ? "session_limit" : "stopped");
        } else if (type === "chunk") {
            setChunks((count) => count + 1);
            logEvent({ type, text: "Segmen video dijadwalkan.", detail: `#${message.chunk_index} · ${Number(message.playback_seconds).toFixed(1)} s` });
        } else if (type === "deadline_missed") {
            logEvent({ level: "warning", type, text: "Video tertahan sementara sampai segmen berikutnya siap.", detail: `${Number(message.late_by_seconds).toFixed(1)} s` });
        } else if (type === "audio_exhausted") {
            logEvent({ type, text: "Audio yang diterima sudah habis; sisa segmen tanpa audio.", detail: `#${message.chunk_index}` });
        } else if (type === "session_info" || (type === "session_metrics" && message.final)) {
            logEvent({ type, text: type === "session_info" ? "Informasi sesi diterima." : "Ringkasan metrik sesi diterima." });
        }
    };

    const wire = (attempt) => {
        const { pc, channel } = attempt;
        channel.binaryType = "arraybuffer";
        pc.ontrack = (event) => {
            if (run.current !== attempt || attempt.ended) return;
            const track = event.track;
            if (!attempt.stream.getTrackById(track.id)) attempt.stream.addTrack(track);
            const video = videoRef.current;
            if (video && video.srcObject !== attempt.stream) video.srcObject = attempt.stream;
            const live = () => { if (run.current === attempt) setMedia((current) => ({ ...current, [track.kind]: true })); };
            track.addEventListener("unmute", live);
            if (!track.muted) live();
            void actions.current.play(true);
        };
        pc.onconnectionstatechange = () => {
            if (run.current !== attempt || attempt.ending) return;
            if (pc.connectionState === "failed") actions.current.fail(attempt, "connection_failed", "Koneksi WebRTC gagal. Jaringan mungkin memblokir jalur media.");
            else setDegraded(pc.connectionState === "disconnected");
        };
        channel.onopen = () => {
            if (run.current !== attempt || attempt.ending) return;
            for (const entry of attempt.pending.splice(0)) {
                try {
                    channel.send(entry.payload);
                    if (entry.version !== null) actions.current.markSubmission(entry.version, () => ({ state: "sent" }));
                } catch {
                    actions.current.fail(attempt, "control_channel_closed", "Kanal kontrol tertutup sebelum pesan terkirim.");
                    return;
                }
            }
            if (phaseRef.current === "connecting") actions.current.setPhase("configuring");
        };
        // The data channel can die while ICE still reports connected: no control message would arrive.
        const died = () => {
            if (run.current === attempt && !attempt.ending) actions.current.fail(attempt, "control_channel_closed", "Kanal kontrol terputus. Sesi tidak dapat diarahkan lagi.");
        };
        channel.onclose = died;
        channel.onerror = died;
        channel.onmessage = (event) => {
            const raw = decodeFrame(event.data);
            if (raw === null) actions.current.logEvent({ level: "diagnostic", text: "Frame kontrol dengan format tidak didukung diabaikan." });
            else actions.current.handleMessage(attempt, raw);
        };
    };

    const negotiate = async (attempt, body) => {
        const deadline = Date.now() + NEGOTIATION_MS;
        let replays = 0;
        // Once one response went missing, a later refusal may hide an earlier accepted offer.
        let unknown = false;
        while (run.current === attempt) {
            let delay = 7000;
            try {
                const data = await apiRequest(`${API}/sessions`, { method: "POST", body });
                if (data?.answer) return data;
                unknown = true;
                if (data?.session) {
                    attempt.id = data.session.id;
                    setSession(data.session);
                }
            } catch (error) {
                // A lost, throttled, proxy-cut or failed response without the session's outcome is not a rejection:
                // replay the same key; the server never re-offers it.
                const ambiguous = realtimeOutcomeUnknown(error) || (error.status === 429 && unknown);
                if (!ambiguous || replays >= 12) {
                    if (unknown && error && typeof error === "object" && !error.details?.session) error.outcomeUnknown = true;
                    throw error;
                }
                unknown = true;
                replays += 1;
                delay = error.status === 429 ? 10000 : Math.min(2000 * replays, 7000);
            }
            if (Date.now() + delay > deadline) throw new LocalFailure("uncertain");
            await wait(delay);
        }
        return null;
    };

    const connect = async (attempt, data) => {
        attempt.id = data.session.id;
        attempt.token = data.session_token;
        attempt.protocol = data.protocol || {};
        const setupVersion = Number(data.configure?.prompt_version ?? attempt.protocol.initial_version ?? 1);
        attempt.issued.add(setupVersion);
        attempt.lastVersion = setupVersion;
        setSession(data.session);
        callbacks.current.onSaved?.(data.session);
        startDeadline(attempt, data.session.remaining_ms);
        if (attempt.closing) {
            void finish(attempt, "stopped");
            return;
        }
        try {
            await attempt.pc.setRemoteDescription({ type: "answer", sdp: data.answer.sdp });
        } catch {
            fail(attempt, "answer_rejected", "Browser tidak dapat memakai jawaban WebRTC dari penyedia. Sesi yang sudah diterima tetap dibebankan.");
            return;
        }
        if (run.current !== attempt || attempt.ending) return;
        attempt.heartbeat = setInterval(() => { void actions.current.beat(attempt); }, HEARTBEAT_MS);
        setPhase("connecting");
        const result = sendControl(attempt, data.configure, setupVersion);
        setSubmissions([{ version: setupVersion, setup: true, summary: setupSummary(inputs), state: result === "sent" ? "sent" : result === "queued" ? "queued" : "unsent" }]);
        if (result === "sent") setPhase("configuring");
        else if (result !== "queued") fail(attempt, "setup_undeliverable", "Pengaturan sesi tidak dapat dikirim melalui kanal kontrol.");
    };

    const startFailed = (attempt, error) => {
        attempt.ending = true;
        attempt.ended = true;
        teardown(attempt);
        const details = error?.details || {};
        const serverSession = details.session || null;
        if (serverSession) {
            setSession(serverSession);
            callbacks.current.onSaved?.(serverSession);
        }
        // Only an offer that may have reached the server can leave a paid outcome unknown.
        const ambiguous = attempt.offerSent && ((error instanceof LocalFailure && error.message === "uncertain") || details.uncertain === true
            || error?.outcomeUnknown === true || (!(error instanceof LocalFailure) && realtimeOutcomeUnknown(error)));
        if (ambiguous) {
            setPhase("uncertain");
            setNotice({ error: true, text: details.message || "Hasil permintaan sesi belum diketahui. Token dapat tetap dicadangkan untuk peninjauan dan sesi tidak dikirim ulang. Periksa riwayat sesi." });
        } else if (serverSession && !["preparing", "negotiating"].includes(serverSession.status)) {
            setPhase(serverSession.status === "failed" ? "failed" : "closed");
            setNotice({ error: !attempt.closing, text: attempt.closing ? "Sesi dihentikan sebelum terhubung." : details.message || error.message });
        } else {
            setPhase("idle");
            if (error?.status === 422 && details.errors) {
                const fields = Object.fromEntries(Object.entries(details.errors).filter(([key]) => key === "inputs" || key.startsWith("inputs.")));
                if (Object.keys(fields).length) callbacks.current.onValidationErrors?.(fields);
            }
            const local = error instanceof LocalFailure ? error.message : error?.status ? mediaError(error)
                : attempt.pc ? "Browser tidak dapat menyiapkan koneksi WebRTC. Belum ada token yang dipakai."
                    : "Server sesi tidak dapat dihubungi. Periksa koneksi lalu coba lagi. Belum ada token yang dipakai.";
            setNotice({ error: true, text: local });
        }
        callbacks.current.onActiveChange?.(false);
        void loadHistory();
    };

    const start = async () => {
        if (disabled || !capability?.source_hash || !Number.isFinite(price) || price < 1 || RUNNING.has(phaseRef.current)) return;
        if (typeof window === "undefined" || typeof window.RTCPeerConnection === "undefined") {
            setNotice({ error: true, text: "Browser ini tidak mendukung WebRTC. Gunakan versi terbaru Chrome, Edge, Firefox, atau Safari." });
            return;
        }
        const errors = schemaErrors(capability.input_schema, inputs);
        if (Object.keys(errors).length) {
            callbacks.current.onValidationErrors?.(inputErrors(errors));
            return;
        }
        const attempt = { key: newKey(), stream: new MediaStream(), pending: [], issued: new Set(), configured: false, lastVersion: 0 };
        run.current = attempt;
        setNotice(null); setSession(null); setSubmissions([]); setEvents([]); setChunks(0); setRemaining(null);
        setMedia({ video: false, audio: false }); setPlayback("waiting"); setIceSource(null); setDegraded(false);
        setUpdateValues(blankUpdate()); setUpdateErrors({});
        if (videoRef.current) videoRef.current.srcObject = null;
        callbacks.current.onActiveChange?.(true);
        setPhase("ice");
        try {
            const ice = await apiRequest(`${API}/ice`, { method: "POST", body: { model, operation: OPERATION } });
            if (run.current !== attempt || attempt.ending) return;
            setIceSource(ice.source);
            const servers = Array.isArray(ice.ice_servers) ? ice.ice_servers : [];
            const pc = new RTCPeerConnection({ iceServers: servers });
            attempt.pc = pc;
            for (const kind of kinds) pc.addTransceiver(kind, { direction: "recvonly" });
            attempt.channel = pc.createDataChannel("control");
            wire(attempt);
            setPhase("gathering");
            const offer = await pc.createOffer();
            const relayOffered = servers.some((server) => [].concat(server?.urls || []).some((url) => /^turns?:/i.test(String(url))));
            // Listeners attach before setLocalDescription starts gathering, so fast candidates are counted.
            const gathering = gatherIce(pc, relayOffered);
            await pc.setLocalDescription(offer);
            await gathering;
            if (run.current !== attempt || attempt.ending) return;
            const sdp = pc.localDescription?.sdp || "";
            if (!/\na=candidate:/.test(sdp)) throw new LocalFailure("Browser tidak menemukan jalur jaringan untuk WebRTC. Periksa koneksi lalu coba lagi. Belum ada token yang dipakai.");
            setPhase("negotiating");
            attempt.offerSent = true;
            const data = await negotiate(attempt, { model, operation: OPERATION, inputs, expected_capability_hash: capability.source_hash,
                expected_price_tokens: price, idempotency_key: attempt.key, sdp });
            if (!data || run.current !== attempt) return;
            await connect(attempt, data);
        } catch (error) {
            if (run.current === attempt && !attempt.ended) startFailed(attempt, error);
        }
    };

    const stop = () => {
        const attempt = run.current;
        if (!attempt || attempt.ending) return;
        if (phaseRef.current === "ice" || phaseRef.current === "gathering") {
            attempt.ending = true;
            attempt.ended = true;
            teardown(attempt);
            run.current = null;
            setPhase("idle");
            setNotice({ error: false, text: "Sesi dibatalkan sebelum dikirim. Belum ada token yang dipakai." });
            callbacks.current.onActiveChange?.(false);
            return;
        }
        if (phaseRef.current === "negotiating") {
            // The offer is in flight; it cannot be withdrawn, so stop as soon as the provider answers.
            attempt.closing = true;
            setPhase("stopping");
            setNotice({ error: false, text: "Menunggu jawaban penyedia sebelum berhenti. Jika sesi sudah diterima, biayanya tetap berlaku." });
            return;
        }
        void finish(attempt, "stopped");
    };

    const prepareNext = () => {
        if (RUNNING.has(phaseRef.current)) return;
        run.current = null;
        setPhase("idle");
        setSession(null); setNotice(null); setSubmissions([]); setEvents([]); setChunks(0); setRemaining(null);
        setMedia({ video: false, audio: false }); setPlayback("waiting"); setIceSource(null); setDegraded(false);
        setUpdateValues(blankUpdate()); setUpdateErrors({});
        if (videoRef.current) videoRef.current.srcObject = null;
    };

    const sendUpdate = async () => {
        const attempt = run.current;
        if (!attempt || phaseRef.current !== "ready" || attempt.updating || !updateSchema) return;
        const errors = schemaErrors(updateSchema, updateValues);
        if (Object.keys(errors).length) {
            setUpdateErrors(errors);
            return;
        }
        // A synchronous guard: a double click must not prepare two versions of the same direction.
        attempt.updating = true;
        setSending(true);
        try {
            if (!attempt.configuredReported) await beat(attempt, true);
            const data = await apiRequest(`${API}/sessions/${attempt.id}/input`, { method: "POST", body: { session_token: attempt.token, inputs: updateValues } });
            if (run.current !== attempt || attempt.ending) return;
            if (data.session) setSession(data.session);
            const version = Number(data.prompt_version);
            // Versions only increase; a prepared message is sent at most once and never replayed.
            if (!Number.isSafeInteger(version) || version <= attempt.lastVersion || Number(data.message?.prompt_version) !== version) {
                logEvent({ level: "diagnostic", text: "Versi pembaruan tidak bertambah, jadi pesan tidak dikirim." });
                return;
            }
            attempt.lastVersion = version;
            attempt.issued.add(version);
            const result = sendControl(attempt, data.message, version);
            setSubmissions((current) => [...current, { version, summary: updateSummary(updateValues),
                state: result === "sent" ? "sent" : result === "queued" ? "queued" : "unsent" }]);
            if (result === "sent" || result === "queued") {
                setUpdateValues(blankUpdate());
                setUpdateErrors({});
            } else {
                setNotice({ error: true, text: result === "too_large" ? "Pembaruan terlalu besar untuk kanal kontrol." : "Kanal kontrol tidak terbuka, jadi pembaruan tidak terkirim." });
            }
        } catch (error) {
            if (error?.status === 422 && error.details?.errors) setUpdateErrors(withoutInputsPrefix(error.details.errors));
            else setNotice({ error: true, text: mediaError(error) });
        } finally {
            attempt.updating = false;
            setSending(false);
        }
    };

    const startRecording = () => {
        const attempt = run.current;
        if (!attempt || attempt.ending || recorderRef.current || !attempt.stream.getTracks().length) return;
        if (typeof MediaRecorder === "undefined") {
            setRecording({ active: false, bytes: 0, note: "Browser ini tidak mendukung perekaman aliran." });
            return;
        }
        const format = RECORDING_FORMATS.find(([type]) => MediaRecorder.isTypeSupported?.(type));
        if (!format) {
            setRecording({ active: false, bytes: 0, note: "Browser ini tidak menyediakan format rekaman video yang didukung." });
            return;
        }
        let recorder;
        try { recorder = new MediaRecorder(attempt.stream, { mimeType: format[0] }); } catch {
            setRecording({ active: false, bytes: 0, note: "Perekaman tidak dapat dimulai di browser ini." });
            return;
        }
        const parts = [];
        const limit = limitRef.current;
        let size = 0;
        let capped = false;
        recorder.ondataavailable = (event) => {
            if (!event.data?.size) return;
            if (size + event.data.size > limit) {
                capped = true;
                if (recorder.state !== "inactive") recorder.stop();
                return;
            }
            size += event.data.size;
            parts.push(event.data);
            setRecording((current) => ({ ...current, bytes: size }));
        };
        recorder.onstop = () => {
            recorderRef.current = null;
            setRecording({ active: false, bytes: 0, note: capped ? "Rekaman berhenti di batas ukuran penyimpanan." : null });
            const blob = parts.length ? new Blob(parts, { type: format[1] }) : null;
            if (!blob?.size) return;
            const clip = { id: newKey(), sessionId: attempt.id, url: URL.createObjectURL(blob), blob, mime: format[1],
                extension: format[2], size: blob.size, createdAt: new Date(), upload: { state: "local" } };
            const next = [clip, ...clipsRef.current];
            next.slice(MAX_CLIPS).forEach((old) => URL.revokeObjectURL(old.url));
            clipsRef.current = next.slice(0, MAX_CLIPS);
            setClips(clipsRef.current);
        };
        recorder.start(1000);
        recorderRef.current = recorder;
        setRecording({ active: true, bytes: 0, note: null });
    };

    const updateClip = (id, patch) => {
        clipsRef.current = clipsRef.current.map((clip) => clip.id === id ? { ...clip, ...patch } : clip);
        setClips(clipsRef.current);
    };

    const discardClip = (clip) => {
        URL.revokeObjectURL(clip.url);
        clipsRef.current = clipsRef.current.filter((entry) => entry.id !== clip.id);
        setClips(clipsRef.current);
    };

    const uploadClip = async (clip) => {
        if (!clip.sessionId || ["uploading", "saved"].includes(clip.upload.state)) return;
        updateClip(clip.id, { upload: { state: "uploading" } });
        try {
            const form = new FormData();
            form.append("file", new File([clip.blob], clipName(clip), { type: clip.mime }));
            const data = await apiRequest(`${API}/sessions/${clip.sessionId}/recording`, { method: "POST", body: form });
            updateClip(clip.id, { upload: { state: "saved", asset: data.recording } });
            if (data.session) {
                setSession((current) => current?.id === data.session.id ? data.session : current);
                callbacks.current.onSaved?.(data.session);
            }
            void loadHistory();
        } catch (error) {
            updateClip(clip.id, { upload: { state: "failed", error } });
        }
    };

    const endListed = async (entry) => {
        if (run.current?.id === entry.id) return stop();
        setHistory((current) => ({ ...current, closing: entry.id }));
        try {
            await apiRequest(`${API}/sessions/${entry.id}/close`, { method: "POST", body: { reason: "stopped" } });
        } catch (error) {
            setHistory((current) => ({ ...current, error }));
        } finally {
            setHistory((current) => ({ ...current, closing: null }));
            void loadHistory();
        }
    };

    const leave = () => {
        const attempt = run.current;
        if (!attempt || attempt.ended) return;
        attempt.ending = true;
        attempt.ended = true;
        teardown(attempt);
        if (attempt.id) {
            // keepalive lets the stop reach the server while the tab or route is going away.
            void apiRequest(`${API}/sessions/${attempt.id}/close`, { method: "POST", body: { reason: "left" }, keepalive: true }).catch(() => {});
        }
        // A page restored from the back/forward cache must show the ended session, never a live one.
        if (run.current === attempt) setPhase(attempt.id ? "closed" : "idle");
        callbacks.current.onActiveChange?.(false);
    };

    actions.current = { finish, fail, beat, play, setPhase, markSubmission, logEvent, handleMessage, leave };

    useEffect(() => {
        void loadHistory();
        const onPageHide = () => actions.current.leave();
        window.addEventListener("pagehide", onPageHide);
        return () => {
            window.removeEventListener("pagehide", onPageHide);
            actions.current.leave();
            callbacks.current.onActiveChange?.(false);
            clipsRef.current.forEach((clip) => URL.revokeObjectURL(clip.url));
        };
        // Mount-only lifecycle: later renders reach the latest handlers through actions.current.
    }, []);

    const running = RUNNING.has(phase);
    const terminal = TERMINAL.has(phase);
    const live = LIVE.has(phase);
    const seconds = remaining == null ? null : Math.ceil(remaining / 1000);
    const progress = remaining == null ? 0 : Math.max(0, Math.min(1, remaining / (maxSeconds * 1000)));
    const canStart = !disabled && Boolean(capability?.source_hash) && Number.isFinite(price) && price > 0 && !running;
    const canRecord = live && (media.video || media.audio) && typeof MediaRecorder !== "undefined";
    const savedRecordings = (session?.recordings || []).filter((entry) => entry.available).length;
    const stageTitle = phase === "ready" ? "Menunggu video pertama…" : phaseLabels[phase];
    const noticeText = notice && (typeof notice.text === "string" ? notice.text : mediaError(notice.text));

    return <section className="realtime-session" aria-labelledby={headingId}>
        <h3 id={headingId} className="studio-visually-hidden">{t("Sesi video realtime")}</h3>
        <div className="realtime-stage" data-phase={phase} style={{ "--realtime-ratio": ratioOf(inputs?.aspect_ratio) }}>
            <video ref={videoRef} className="realtime-video" autoPlay playsInline hidden={!media.video} aria-label={t("Video langsung dari model")} />
            {!media.video && <div className="realtime-stage-message">
                {running && phase !== "stopping" && <span className="studio-spinner" aria-hidden="true" />}
                {!running && <StudioIcon name="video" />}
                <strong>{t(stageTitle)}</strong>
                <p>{t(media.audio && live ? "Audio langsung sedang diputar; model belum mengirim video." : phaseHelp[phase])}</p>
            </div>}
            {(live || seconds != null) && <div className="realtime-stage-bar">
                {live && <span className="realtime-live"><span aria-hidden="true" />{t("Langsung")}</span>}
                {seconds != null && <span className="realtime-countdown" role="timer" aria-label={`${t("Sisa waktu sesi")}: ${number(seconds)} ${t("detik")}`}>{clock(seconds)}</span>}
            </div>}
            {remaining != null && <div className="realtime-stage-progress" aria-hidden="true"><span style={{ transform: `scaleX(${progress})` }} /></div>}
        </div>

        <div className="realtime-controls">
            {!running && !terminal && <StudioButton primary icon="video" disabled={!canStart} onClick={() => { void start(); }}>
                {t("Mulai sesi")}<span className="realtime-price">{Number.isFinite(price) && price > 0 ? `${number(price)} ${t("token")}` : "—"}</span>
            </StudioButton>}
            {running && <StudioButton className="realtime-stop" icon="close" disabled={phase === "stopping"} onClick={stop}>{t(phase === "stopping" ? "Menghentikan…" : "Hentikan sesi")}</StudioButton>}
            {terminal && <StudioButton icon="refresh" onClick={prepareNext}>{t("Siapkan sesi baru")}</StudioButton>}
            {canRecord && <button type="button" className="studio-button realtime-record" aria-pressed={recording.active}
                disabled={!recording.active && clips.length >= MAX_CLIPS}
                onClick={recording.active ? stopRecorder : startRecording}><RecordIcon />{t(recording.active ? "Hentikan rekaman" : "Rekam aliran")}</button>}
            {playback === "muted" && media.audio && <StudioButton icon="volume" onClick={() => { void play(true); }}>{t("Aktifkan suara")}</StudioButton>}
            {playback === "blocked" && <StudioButton icon="play" onClick={() => { void play(true); }}>{t("Putar video")}</StudioButton>}
            <p className="realtime-phase" role="status" aria-live="polite">{t(phaseLabels[phase])}{live && chunks > 0 ? ` · ${number(chunks)} ${t("segmen")}` : ""}</p>
        </div>

        <div className="realtime-terms">
            <p><StudioIcon name="tokens" /><strong>{Number.isFinite(price) && price > 0 ? `${number(price)} ${t("token per sesi")}` : t("Harga sesi belum tersedia")}</strong>
                <span>{t("Satu sesi, maksimal")} {number(maxSeconds)} {t("detik")}</span></p>
            <p className="studio-help">{t("Token dicadangkan saat mulai dan dibebankan sekali ketika penyedia menerima sesi. Menghentikan lebih awal tidak mengembalikan token. Sesi tidak diperpanjang atau disambung ulang otomatis.")}</p>
            {session && <p className="studio-billing"><StudioIcon name="tokens" />{number(session.price_tokens)} {t("token")}<span>·</span>{t(billingLabels[session.billing_status] || "Status tagihan belum tersedia")}</p>}
            {iceSource === "stun" && running && <p className="studio-help">{t("Relay TURN tidak tersedia; jaringan yang ketat mungkin gagal terhubung.")}</p>}
        </div>

        {noticeText && <StudioNotice error={notice.error}>{t(noticeText)}</StudioNotice>}
        {degraded && running && <StudioNotice>{t("Koneksi sesi sedang tidak stabil. Sesi tetap berakhir sesuai batas waktu.")}</StudioNotice>}

        {updateCapability && live && <section className="realtime-panel realtime-update" aria-labelledby={`${headingId}-update`}>
            <div className="realtime-panel-heading"><h3 id={`${headingId}-update`}>{t("Arahkan sesi")}</h3>
                <p className="studio-help">{t(phase === "ready" ? "Setiap pembaruan memakai versi baru dan tidak pernah dikirim ulang." : "Pembaruan aktif setelah model mengonfirmasi pengaturan awal.")}</p></div>
            <CapabilityForm capability={updateCapability} values={updateValues} errors={updateErrors} disabled={phase !== "ready" || sending} idPrefix="realtime-update"
                onChange={(next) => { setUpdateErrors({}); setUpdateValues(next); }} onUploadStateChange={setUpdateUploads} />
            {updateErrors[""] && <StudioNotice error>{t(mediaError(Array.isArray(updateErrors[""]) ? updateErrors[""][0] : updateErrors[""]))}</StudioNotice>}
            <StudioButton primary icon="arrow" disabled={phase !== "ready" || sending || updateUploads.busy || updateUploads.failed} onClick={() => { void sendUpdate(); }}>
                {t(sending ? "Menyiapkan pembaruan…" : "Kirim pembaruan")}
            </StudioButton>
        </section>}

        {submissions.length > 0 && <section className="realtime-panel" aria-labelledby={`${headingId}-inputs`}>
            <div className="realtime-panel-heading"><h3 id={`${headingId}-inputs`}>{t("Arahan sesi")}</h3>
                <p className="studio-help">{t("Diterima berarti model menerima arahan, belum tentu sudah terlihat. Tanpa tanggapan berarti hasilnya tidak diketahui.")}</p></div>
            <ol className="realtime-submissions">{submissions.map((entry) => <li key={entry.version}>
                <span className="realtime-version">v{entry.version}</span>
                <span className="realtime-submission-copy"><strong>{entry.setup ? [t("Pengaturan awal"), entry.summary].filter(Boolean).join(" · ") : entry.summary}</strong>
                    {(entry.reason || entry.audio) && <small>{[entry.reason, entry.audio ? t(audioLabels[entry.audio]) : null].filter(Boolean).join(" · ")}</small>}</span>
                <span className={`studio-status studio-status-${statusTone[entry.state] || "pending"}`}>{t(submissionLabels[entry.state] || entry.state)}</span>
            </li>)}</ol>
        </section>}

        {events.length > 0 && <details className="realtime-panel realtime-events">
            <summary>{t("Peristiwa sesi")} <span>{number(events.length)}</span></summary>
            <ol>{events.map((entry) => <li key={entry.id} data-level={entry.level}>
                <time dateTime={entry.at.toISOString()}>{time(entry.at)}</time>
                <span>{entry.type && <code>{entry.type}</code>} {t(entry.text)}{entry.detail ? <small>{entry.detail}</small> : null}</span>
            </li>)}</ol>
        </details>}

        {(canRecord || clips.length > 0 || recording.note) && <section className="realtime-panel realtime-recordings" aria-labelledby={`${headingId}-recordings`}>
            <div className="realtime-panel-heading"><h3 id={`${headingId}-recordings`}>{t("Rekaman lokal")}</h3>
                <p className="studio-help">{t("Direkam browser dari aliran yang diterima, bukan file dari penyedia.")} {t("Batas")} {bytes(recordingLimit)} · {number(recordingSlots)} {t("rekaman per sesi")}</p></div>
            {recording.active && <p className="realtime-recording-live" role="status"><RecordIcon />{t("Merekam")} · {bytes(recording.bytes)}</p>}
            {recording.note && <StudioNotice>{t(recording.note)}</StudioNotice>}
            {!recording.active && clips.length >= MAX_CLIPS && <p className="studio-help">{t("Buang salah satu rekaman lokal sebelum merekam lagi. Rekaman yang sudah tersimpan tetap ada di Library.")}</p>}
            {clips.map((clip) => <div className="realtime-clip" key={clip.id}>
                <video src={clip.url} controls playsInline preload="metadata" aria-label={t("Rekaman lokal")} />
                <div className="realtime-clip-meta">
                    <strong>{clipName(clip)}</strong>
                    <small>{bytes(clip.size)} · {clip.mime}</small>
                    <div className="studio-toolbar-actions">
                        <a className="studio-button" href={clip.url} download={clipName(clip)}><StudioIcon name="download" />{t("Unduh")}</a>
                        {clip.upload.state !== "saved" && <StudioButton icon="upload" disabled={clip.upload.state === "uploading" || !clip.sessionId || savedRecordings >= recordingSlots}
                            onClick={() => { void uploadClip(clip); }}>{t(clip.upload.state === "uploading" ? "Menyimpan…" : "Simpan ke Library")}</StudioButton>}
                        {clip.upload.state === "saved" && <span className="studio-status studio-status-completed">{t("Tersimpan di Library")}</span>}
                        <StudioButton icon="close" disabled={clip.upload.state === "uploading"} onClick={() => discardClip(clip)}>{t("Buang")}</StudioButton>
                    </div>
                    {clip.upload.state === "failed" && <p className="studio-field-error" role="alert">{t(mediaError(clip.upload.error))}</p>}
                </div>
            </div>)}
        </section>}

        <section className="realtime-panel realtime-history" aria-labelledby={`${headingId}-history`}>
            <div className="realtime-panel-heading realtime-history-heading"><h3 id={`${headingId}-history`}><StudioIcon name="history" />{t("Riwayat sesi")}</h3>
                <StudioButton icon="refresh" disabled={history.loading} onClick={() => { void loadHistory(); }}>{t("Muat ulang")}</StudioButton></div>
            {history.error && <StudioNotice error>{t(mediaError(history.error))}</StudioNotice>}
            {history.loading && !history.sessions.length && <p className="studio-help" role="status">{t("Memuat riwayat sesi…")}</p>}
            {!history.loading && !history.error && !history.sessions.length && <p className="studio-help">{t("Belum ada sesi realtime.")}</p>}
            {history.sessions.length > 0 && <ul className="realtime-history-list">{history.sessions.map((entry) => <li key={entry.id}>
                <div className="realtime-history-copy">
                    <strong>{entry.model_label}</strong>
                    <small>{stamp(entry.created_at)} · {t(statusLabels[entry.status] || entry.status)} · {number(entry.price_tokens)} {t("token")} {t(billingLabels[entry.billing_status] || "")}</small>
                    {typeof entry.settings?.prompt === "string" && <p>{entry.settings.prompt}</p>}
                    {entry.error_message && <p className="realtime-history-error">{t(entry.error_message)}</p>}
                    {entry.recordings?.length > 0 && <p className="realtime-history-recordings">{entry.recordings.map((item, index) => item.available && isUrlOf(item.preview_url)
                        ? <span key={item.id}><a className="studio-text-link" href={item.preview_url} target="_blank" rel="noopener noreferrer">{t("Putar rekaman")} {index + 1}</a>
                            {isUrlOf(item.download_url) && <a className="studio-text-link" href={item.download_url}>{t("Unduh")}</a>}</span>
                        : <span key={item.id || index} className="studio-help">{t("Rekaman")} {index + 1} {t("tidak tersedia lagi")}</span>)}</p>}
                </div>
                {["preparing", "connected"].includes(entry.status) && (entry.id !== session?.id || !running) && <StudioButton icon="close" disabled={history.closing === entry.id}
                    onClick={() => { void endListed(entry); }}>{t("Akhiri")}</StudioButton>}
            </li>)}</ul>}
            {history.next && <StudioButton disabled={history.loading} onClick={() => { void loadHistory(history.next); }}>{t("Muat sesi sebelumnya")}</StudioButton>}
        </section>
    </section>;
}

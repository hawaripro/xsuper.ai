import { useEffect, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { useAuth } from "../contexts/AuthContext";
import { useLocale } from "../contexts/LocaleContext";
import { validationErrors } from "../components/member/MemberUI";
import AudioPlayer from "../components/studios/AudioPlayer";
import { capabilityErrors, capabilitySubmission, fieldRequired } from "../components/studios/capability";
import { isPending, useMediaStudio, useStudioDraft } from "../components/studios/useMediaStudio";
import { StudioButton, StudioCancellation, StudioCatalog, StudioEmpty, StudioField, StudioHeader, StudioHistory, StudioIcon, StudioJobMeta, StudioNotice, StudioProgress, StudioQuote, mediaError } from "../components/studios/StudioUI";

const defaults = { mode: "speech", speechModel: "", musicModel: "", speechPrompt: "", musicPrompt: "", voice: "", speed: "1", duration: "", tempo: "", instrumental: false, custom: false };
const modes = [{ id: "speech", label: "Voiceover", detail: "Teks menjadi suara", icon: "voice" }, { id: "music", label: "Musik & sound design", detail: "Suasana menjadi komposisi", icon: "music" }];
const operationFor = (mode) => mode === "speech" ? "text_to_speech" : "music";
const numericValue = (value) => value === "" || value == null ? "" : Number(value);
const numericAllowed = (value, param) => value !== "" && Number.isFinite(Number(value))
    && (!Array.isArray(param.options) || param.options.includes(Number(value)))
    && (param.min == null || Number(value) >= Number(param.min)) && (param.max == null || Number(value) <= Number(param.max));

function AudioNumberField({ name, label, param, value, error, disabled, onChange }) {
    const { t } = useLocale();
    const id = `audio-${name}`;
    const unit = name === "speed" ? "×" : param.unit || (name === "duration" ? t("detik") : "");
    const bounded = param.min != null && param.max != null;
    return <StudioField id={id} label={label} error={error}>
        {Array.isArray(param.options) ? <select id={id} value={value} disabled={disabled} onChange={(event) => onChange(event.target.value)}>
            {value === "" && <option value="">{t("Pilih nilai")}</option>}
            {param.options.map((option) => <option key={option} value={option}>{option} {unit}</option>)}
        </select> : <>
            {bounded && <div className="studio-range-value"><output htmlFor={id}>{value} {unit}</output><span>{param.min}–{param.max} {unit}</span></div>}
            <input id={id} type={bounded && name !== "tempo" && value !== "" ? "range" : "number"} min={param.min ?? undefined} max={param.max ?? undefined} step={name === "speed" ? "0.05" : "1"} value={value} disabled={disabled} aria-invalid={Boolean(error)} aria-valuetext={`${value} ${unit}`} onChange={(event) => onChange(event.target.value)} />
        </>}
    </StudioField>;
}

function AudioTracks({ job }) {
    const { t } = useLocale();
    const [params] = useSearchParams();
    const [selected, setSelected] = useState(() => Number(params.get("track") || 0));
    const outputs = job.outputs;
    const index = Number.isInteger(selected) && selected >= 0 && selected < outputs.length ? selected : 0;
    return <>
        {outputs.length > 1 && <div className="studio-toolbar studio-canvas-toolbar">
            <StudioField id="audio-track" label="Pilih track">
                <select id="audio-track" value={index} onChange={(event) => setSelected(Number(event.target.value))}>
                    {outputs.map((output, track) => <option key={output.url} value={track}>{t("Track")} {track + 1}</option>)}
                </select>
            </StudioField>
            <p className="studio-help">{t("Semua track berasal dari satu permintaan. Pilihan track tidak menambah tagihan.")}</p>
        </div>}
        <AudioPlayer key={`${job.job_id}:${index}`} job={job} output={outputs[index]} />
    </>;
}

function AudioStudio({ userId }) {
    const { t } = useLocale();
    const studio = useMediaStudio("audio");
    const [draft, setDraft] = useStudioDraft("audio", userId, defaults);
    const [cancellation, setCancellation] = useState(null);
    const mode = draft.mode === "music" ? "music" : "speech";
    const operation = operationFor(mode);
    const models = studio.models.filter((model) => model.capabilities?.[operation]);
    const selectedId = studio.requestedModel || draft[`${mode}Model`];
    const model = models.find((item) => item.id === selectedId) || null;
    const capability = model?.capabilities?.[operation];
    const fields = Object.fromEntries((capability?.params || []).map((param) => [param.name, param]));
    const voices = (fields.voice?.options || []).map((id) => model.audio?.voices?.find((voice) => voice.id === id) || { id, label: id, language: null });
    const voice = voices.find((item) => item.id === draft.voice);
    const prompt = draft[`${mode}Prompt`];
    const limit = Number(model?.audio?.max_characters) > 0 ? Math.min(4000, Number(model.audio.max_characters)) : 4000;
    const unit = Number.isInteger(capability?.price_tokens) && capability.price_tokens > 0 ? capability.price_tokens : null;
    const values = { prompt };
    for (const param of capability?.params || []) {
        const value = draft[param.name];
        values[param.name] = ["speed", "duration", "tempo"].includes(param.name) ? numericValue(value) : value;
    }
    const clientErrors = capabilityErrors(capability, values);
    for (const name of ["duration", "tempo"]) {
        if (fields[name] && values[name] !== "" && (!Number.isInteger(values[name]) || !numericAllowed(values[name], fields[name]))) clientErrors[name] = "Pilih nilai dalam batas model.";
    }
    const errors = { ...clientErrors, ...validationErrors(studio.submitError) };
    const optionsValid = Object.keys(clientErrors).filter((key) => key !== "prompt").length === 0;
    const canGenerate = Boolean(model && capability?.source_hash && !studio.modelLoading && !studio.modelError && !studio.submitting && unit != null && unit <= 2147483647 && studio.balance != null && studio.balance >= unit && prompt.trim() && prompt.trim().length <= limit && Object.keys(clientErrors).length === 0);
    const job = studio.activeJob;
    const englishVoices = voices.length > 0 && voices.every((item) => /^en(?:-|$)/i.test(item.language || ""));

    useEffect(() => {
        if (!studio.models.length) return;
        setDraft((current) => {
            const requested = studio.models.find((item) => item.id === studio.requestedModel);
            if (studio.requestedModel && !requested) return current;
            const currentKind = current.mode === "music" ? "music" : "speech";
            const kind = requested && !requested.capabilities?.[operationFor(currentKind)]
                ? requested.capabilities?.text_to_speech ? "speech" : "music" : currentKind;
            const op = operationFor(kind);
            const available = studio.models.filter((item) => item.capabilities?.[op]);
            const selected = requested || available.find((item) => item.id === current[`${kind}Model`]) || available[0];
            if (!selected?.capabilities?.[op]) return current;
            const next = { ...current, mode: kind, [`${kind}Model`]: selected.id };
            for (const param of selected.capabilities[op].params || []) {
                const previous = current[param.name];
                if (["speed", "duration", "tempo"].includes(param.name)) {
                    next[param.name] = numericAllowed(previous, param) ? String(previous) : String(param.default ?? param.options?.[0] ?? "");
                } else if (Array.isArray(param.options)) {
                    next[param.name] = param.options.includes(previous) ? previous : param.default ?? param.options[0] ?? "";
                } else if (previous == null || previous === "") {
                    next[param.name] = param.default ?? "";
                }
            }
            return next;
        });
    }, [studio.models, studio.requestedModel, mode, setDraft]);
    const set = (name, value) => setDraft((current) => ({ ...current, [name]: value }));
    const selectMode = (next) => {
        if (next === mode) return;
        const available = studio.models.filter((item) => item.capabilities?.[operationFor(next)]);
        const chosen = available.find((item) => item.id === draft[`${next}Model`]) || available[0];
        set("mode", next);
        studio.selectModel(chosen?.id || "");
    };
    const generate = (event) => {
        event.preventDefault();
        if (!canGenerate) return;
        studio.submit({
            operation, model: model.id,
            expected_price_tokens: unit, expected_capability_hash: capability.source_hash,
            ...capabilitySubmission(capability, values),
        });
    };
    return <div className="media-studio studio-audio">
        <StudioHeader kind="audio" title="Studio audio" description="Temukan suara untuk cerita dan suasana Anda." balance={studio.balance} onRefresh={studio.refresh} busy={studio.modelLoading || studio.submitting} />
        <section className="studio-audio-workbench">
            <div className="studio-audio-tabs" role="tablist" aria-label={t("Jenis audio")}>{modes.map((item, index) => <button type="button" key={item.id} id={`audio-tab-${item.id}`} role="tab" aria-selected={mode === item.id} aria-controls={`audio-panel-${item.id}`} tabIndex={mode === item.id ? 0 : -1} disabled={studio.submitting} onClick={() => selectMode(item.id)} onKeyDown={(event) => { if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return; event.preventDefault(); const next = event.key === "Home" ? 0 : event.key === "End" ? modes.length - 1 : (index + (event.key === "ArrowRight" ? 1 : -1) + modes.length) % modes.length; selectMode(modes[next].id); document.getElementById(`audio-tab-${modes[next].id}`)?.focus(); }}><span className={`studio-audio-mode-icon studio-color-${item.icon}`}><StudioIcon name={item.icon} /></span><span>{t(item.label)}<small>{t(item.detail)}</small></span></button>)}</div>
            <form onSubmit={generate} aria-busy={studio.submitting} id={`audio-panel-${mode}`} role="tabpanel" aria-labelledby={`audio-tab-${mode}`}>
                <div className="studio-audio-editor-grid">
                    <div className="studio-audio-editor">
                        <div className="studio-section-heading"><h2>{t(mode === "speech" ? "Naskah voiceover" : "Arahan musik")}</h2><span className="studio-help">{prompt.length}/{limit}</span></div>
                        <StudioField id="audio-prompt" label={mode === "speech" ? "Teks yang akan diucapkan" : (fields.custom && draft.custom ? "Lirik lagu" : "Deskripsi komposisi")} error={errors.prompt} className="studio-audio-prompt">
                            <textarea id="audio-prompt" rows={7} value={prompt} required maxLength={limit} disabled={studio.submitting} aria-invalid={Boolean(errors.prompt)} placeholder={t(mode === "speech" ? "Tulis naskah yang ingin dibacakan…" : (fields.custom && draft.custom ? "Tulis lirik lengkap lagu Anda…" : "Jelaskan genre, instrumen, suasana, dan perkembangan musik…"))} onChange={(event) => set(`${mode}Prompt`, event.target.value)} />
                        </StudioField>
                        <div className="studio-audio-editor-note"><StudioIcon name={mode === "speech" ? "voice" : "music"} /><p>{t(mode === "speech" ? "Suara yang dihasilkan adalah suara AI, bukan rekaman manusia." : "Semua track yang dikembalikan model tersimpan dalam satu hasil. Editor ini tidak memisahkan stem atau notasi.")}</p></div>
                        {mode === "speech" && englishVoices && <StudioNotice>{t("Pilihan suara model ini menggunakan bahasa Inggris (American English). Pelafalan bahasa lain tidak dijamin.")}</StudioNotice>}
                        {mode === "music" && (fields.custom || fields.instrumental) && <div className="studio-music-direction">
                            {fields.custom && <label className="studio-checkbox"><input type="checkbox" checked={Boolean(draft.custom)} disabled={studio.submitting} onChange={(event) => set("custom", event.target.checked)} /><span>{t("Gunakan lirik sendiri")}<small>{t("Prompt dikirim sebagai lirik lagu, bukan deskripsi.")}</small></span></label>}
                            {fields.instrumental && <label className="studio-checkbox"><input type="checkbox" checked={Boolean(draft.instrumental)} disabled={studio.submitting} onChange={(event) => set("instrumental", event.target.checked)} /><span>{t("Instrumental tanpa vokal")}<small>{t("Hasil hanya musik, tanpa suara penyanyi.")}</small></span></label>}
                        </div>}
                        {mode === "music" && fields.tempo && <div className="studio-music-direction">
                            <AudioNumberField name="tempo" label={fieldRequired(fields.tempo, values) ? "Arahan tempo" : "Arahan tempo (opsional)"} param={fields.tempo} value={draft.tempo} error={errors.tempo} disabled={studio.submitting} onChange={(value) => set("tempo", value)} />
                            <p className="studio-help">{t("Tempo menjadi arahan prompt, bukan janji tempo yang presisi. Arahan ikut dihitung dalam batas karakter prompt oleh server.")}</p>
                        </div>}
                    </div>
                    <aside className="studio-audio-inspector">
                        <div className="studio-section-heading"><h2><StudioIcon name="settings" className={`studio-color-${mode === "speech" ? "voice" : "music"}`} />{t(mode === "speech" ? "Karakter suara" : "Pengaturan komposisi")}</h2></div>
                        <StudioCatalog studio={studio} id="audio-model" value={selectedId} models={models} error={errors.model || errors.mode} onChange={studio.selectModel} />
                        {mode === "speech" && fields.voice && <StudioField id="audio-voice" label="Suara" error={errors.voice}>
                            <select id="audio-voice" value={voice?.id || ""} disabled={studio.submitting || !voices.length} onChange={(event) => set("voice", event.target.value)}>
                                {!voice && <option value="">{t("Pilih suara")}</option>}
                                {voices.map((item) => <option value={item.id} key={item.id}>{item.label}{item.language ? ` · ${item.language}` : ""}</option>)}
                            </select>
                            {voice?.language && <p className="studio-help">{t("Bahasa suara")}: {voice.language}</p>}
                        </StudioField>}
                        {mode === "speech" && fields.speed && <AudioNumberField name="speed" label="Kecepatan bicara" param={fields.speed} value={draft.speed} error={errors.speed} disabled={studio.submitting} onChange={(value) => set("speed", value)} />}
                        {mode === "music" && fields.duration && <AudioNumberField name="duration" label="Durasi" param={fields.duration} value={draft.duration} error={errors.duration} disabled={studio.submitting} onChange={(value) => set("duration", value)} />}
                        {model && !optionsValid && <StudioNotice>{t(mode === "speech" ? "Pilih suara dan kecepatan yang didukung model." : "Pilih durasi dan tempo dalam batas model.")}</StudioNotice>}
                        {model && unit == null && <StudioNotice error>{t("Harga token model belum tersedia. Pilih model lain atau hubungi pengelola.")}</StudioNotice>}
                        <StudioQuote total={unit} unit={unit} balance={studio.balance} />
                    </aside>
                </div>
                <div className="studio-audio-renderbar"><div><p>{t("Pembatalan hanya tersedia sebelum pengiriman ke penyedia dimulai.")}</p><small>{t("Hasil dan tagihan tersimpan pada riwayat akun Anda.")}</small></div><StudioButton type="submit" primary icon={mode === "speech" ? "voice" : "music"} disabled={!canGenerate}>{t(studio.submitting ? "Mengirim permintaan…" : mode === "speech" ? "Generate suara" : "Generate musik")}<StudioIcon name="arrow" /></StudioButton></div>
                {studio.submitError && <div className="studio-audio-feedback"><StudioNotice error>{t(studio.submitError.status ? mediaError(studio.submitError) : "Respons belum dapat dikonfirmasi. Periksa riwayat sebelum mengirim lagi.")}</StudioNotice></div>}
            </form>
        </section>
        <section className="studio-audio-output" aria-label={t("Hasil audio")}>
            <div className="studio-section-heading"><h2><StudioIcon name="audio" className="studio-color-audio" />{t("Dengarkan hasilnya")}</h2>{job && <span className="studio-help">{job.model}</span>}</div>
            {studio.statusError && <StudioNotice error action={<StudioButton onClick={() => studio.loadJob(studio.activeId)} disabled={studio.statusLoading}>{t("Coba lagi")}</StudioButton>}>{t(mediaError(studio.statusError))}</StudioNotice>}
            {studio.submitting || isPending(job) ? <StudioProgress job={job} submitting={studio.submitting} /> : job?.status === "completed" && job.outputs?.length ? <AudioTracks key={job.job_id} job={job} /> : <StudioEmpty icon="audio" title="Belum ada audio untuk diputar" description="Buat suara atau musik, atau pilih hasil dari riwayat. Waveform akan dibuat dari file audio hasil yang sebenarnya." />}
            {job && <StudioJobMeta job={job} checking={studio.statusLoading} onCheck={() => studio.loadJob(job.job_id)} onCancel={setCancellation} />}
        </section>
        <StudioHistory studio={studio} kind="audio" title="Riwayat audio" />
        {cancellation && <StudioCancellation key={cancellation.job_id} job={studio.jobs.find((item) => item.job_id === cancellation.job_id) || cancellation} onCancel={studio.cancel} onClose={() => setCancellation(null)} />}
    </div>;
}

export default function AudioGenerator() {
    const { user } = useAuth();
    return user ? <AudioStudio key={user.id} userId={user.id} /> : null;
}

import { useEffect, useState } from "react";
import { useAuth } from "../contexts/AuthContext";
import { useLocale } from "../contexts/LocaleContext";
import { validationErrors } from "../components/member/MemberUI";
import AudioPlayer from "../components/studios/AudioPlayer";
import { isPending, tokenPrice, useMediaStudio, useStudioDraft } from "../components/studios/useMediaStudio";
import { StudioButton, StudioCancellation, StudioCatalog, StudioEmpty, StudioField, StudioHeader, StudioHistory, StudioIcon, StudioJobMeta, StudioNotice, StudioProgress, StudioQuote, mediaError } from "../components/studios/StudioUI";

const defaults = { mode: "speech", speechModel: "", musicModel: "", speechPrompt: "", musicPrompt: "", voice: "", speed: "1", duration: "", tempo: "", instrumental: false, custom: false };
const modes = [{ id: "speech", label: "Voiceover", detail: "Teks menjadi suara", icon: "voice" }, { id: "music", label: "Musik & sound design", detail: "Suasana menjadi komposisi", icon: "music" }];
const validRange = (range) => range && range.min != null && range.max != null && Number.isFinite(Number(range.min)) && Number.isFinite(Number(range.max)) && Number(range.max) >= Number(range.min);
const inRange = (value, range) => value !== "" && validRange(range) && Number.isFinite(Number(value)) && Number(value) >= Number(range.min) && Number(value) <= Number(range.max);

function AudioStudio({ userId }) {
    const { t } = useLocale();
    const studio = useMediaStudio("audio");
    const [draft, setDraft] = useStudioDraft("audio", userId, defaults);
    const [cancellation, setCancellation] = useState(null);
    const mode = draft.mode === "music" ? "music" : "speech";
    const models = studio.models.filter((model) => model.audio?.kind === mode);
    const selectedId = studio.requestedModel || draft[`${mode}Model`];
    const model = models.find((item) => item.id === selectedId) || null;
    const config = model?.audio;
    const voices = Array.isArray(config?.voices) ? config.voices : [];
    const voice = voices.find((item) => item.id === draft.voice);
    const prompt = draft[`${mode}Prompt`];
    const limit = Number(config?.max_characters) > 0 ? Math.min(4000, Number(config.max_characters)) : 4000;
    const unit = tokenPrice(model);
    const errors = validationErrors(studio.submitError);
    const job = studio.activeJob;
    const englishVoices = voices.length > 0 && voices.every((item) => /^en(?:-|$)/i.test(item.language));
    const speedAvailable = validRange(config?.speed);
    const durationAvailable = validRange(config?.duration);
    const tempoValid = !draft.tempo || (Number.isInteger(Number(draft.tempo)) && Number(draft.tempo) >= 40 && Number(draft.tempo) <= 200);
    const optionsValid = mode === "speech" ? Boolean(voice) && (!speedAvailable || inRange(draft.speed, config.speed)) : (!durationAvailable || inRange(draft.duration, config.duration)) && tempoValid;
    const canGenerate = Boolean(model && !studio.modelLoading && !studio.modelError && !studio.submitting && unit != null && unit <= 2147483647 && studio.balance != null && studio.balance >= unit && prompt.trim() && prompt.trim().length <= limit && optionsValid);

    useEffect(() => {
        if (!studio.models.length) return;
        setDraft((current) => {
            const requested = studio.models.find((item) => item.id === studio.requestedModel);
            if (studio.requestedModel && !requested) return current;
            const kind = requested?.audio?.kind || (current.mode === "music" ? "music" : "speech");
            if (!["speech", "music"].includes(kind)) return current;
            const available = studio.models.filter((item) => item.audio?.kind === kind);
            const selected = requested || available.find((item) => item.id === current[`${kind}Model`]) || available[0];
            if (!selected) return current;
            const audio = selected.audio;
            const next = { ...current, mode: kind, [`${kind}Model`]: selected.id };
            if (kind === "speech") {
                const options = Array.isArray(audio.voices) ? audio.voices : [];
                next.voice = options.some((item) => item.id === current.voice) ? current.voice : options[0]?.id || "";
                if (validRange(audio.speed)) next.speed = inRange(current.speed, audio.speed) ? current.speed : String(audio.speed.default ?? audio.speed.min);
            } else if (validRange(audio.duration)) next.duration = inRange(current.duration, audio.duration) ? current.duration : String(audio.duration.default ?? audio.duration.min);
            return next;
        });
    }, [studio.models, studio.requestedModel, mode, setDraft]);
    const set = (name, value) => setDraft((current) => ({ ...current, [name]: value }));
    const selectMode = (next) => {
        if (next === mode) return;
        const available = studio.models.filter((item) => item.audio?.kind === next);
        const chosen = available.find((item) => item.id === draft[`${next}Model`]) || available[0];
        set("mode", next);
        studio.selectModel(chosen?.id || "");
    };
    const generate = (event) => {
        event.preventDefault();
        if (!canGenerate) return;
        // Capability submission: the operation names the contract; params travel only when the
        // model declares them, so a prompt-only model (Suno) sends nothing it does not support.
        studio.submit({
            operation: mode === "speech" ? "text_to_speech" : "music",
            model: model.id,
            prompt: prompt.trim(),
            ...(mode === "speech"
                ? { voice: draft.voice, ...(speedAvailable ? { speed: Number(draft.speed) } : {}) }
                : {
                    ...(durationAvailable ? { duration: Number(draft.duration) } : {}),
                    ...(draft.tempo ? { tempo: Number(draft.tempo) } : {}),
                    ...(config?.instrumental && draft.instrumental ? { instrumental: true } : {}),
                    ...(config?.custom_lyrics && draft.custom ? { custom: true } : {}),
                }),
        });
    };
    return <div className="media-studio studio-audio">
        <StudioHeader kind="audio" title="Studio audio" description="Temukan suara untuk cerita dan suasana Anda." balance={studio.balance} onRefresh={studio.refresh} busy={studio.modelLoading || studio.submitting} />
        <section className="studio-audio-workbench">
            <div className="studio-audio-tabs" role="tablist" aria-label={t("Jenis audio")}>{modes.map((item, index) => <button type="button" key={item.id} id={`audio-tab-${item.id}`} role="tab" aria-selected={mode === item.id} aria-controls={`audio-panel-${item.id}`} tabIndex={mode === item.id ? 0 : -1} disabled={studio.submitting} onClick={() => selectMode(item.id)} onKeyDown={(event) => { if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return; event.preventDefault(); const next = event.key === "Home" ? 0 : event.key === "End" ? modes.length - 1 : (index + (event.key === "ArrowRight" ? 1 : -1) + modes.length) % modes.length; selectMode(modes[next].id); document.getElementById(`audio-tab-${modes[next].id}`)?.focus(); }}><span className={`studio-audio-mode-icon studio-color-${item.icon}`}><StudioIcon name={item.icon} /></span><span>{t(item.label)}<small>{t(item.detail)}</small></span></button>)}</div>
            <form onSubmit={generate} aria-busy={studio.submitting} id={`audio-panel-${mode}`} role="tabpanel" aria-labelledby={`audio-tab-${mode}`}>
                <div className="studio-audio-editor-grid"><div className="studio-audio-editor"><div className="studio-section-heading"><h2>{t(mode === "speech" ? "Naskah voiceover" : "Arahan musik")}</h2><span className="studio-help">{prompt.length}/{limit}</span></div><StudioField id="audio-prompt" label={mode === "speech" ? "Teks yang akan diucapkan" : (config?.custom_lyrics && draft.custom ? "Lirik lagu" : "Deskripsi komposisi")} error={errors.prompt} className="studio-audio-prompt"><textarea id="audio-prompt" rows={7} value={prompt} required maxLength={limit} disabled={studio.submitting} aria-invalid={Boolean(errors.prompt)} placeholder={t(mode === "speech" ? "Tulis naskah yang ingin dibacakan…" : (config?.custom_lyrics && draft.custom ? "Tulis lirik lengkap lagu Anda…" : "Jelaskan genre, instrumen, suasana, dan perkembangan musik…"))} onChange={(event) => set(`${mode}Prompt`, event.target.value)} /></StudioField><div className="studio-audio-editor-note"><StudioIcon name={mode === "speech" ? "voice" : "music"} /><p>{t(mode === "speech" ? "Suara yang dihasilkan adalah suara AI, bukan rekaman manusia." : "Satu hasil audio utuh. Tidak ada stem, notasi, atau track terpisah yang dibuat oleh editor ini.")}</p></div>{mode === "speech" && englishVoices && <StudioNotice>{t("Pilihan suara model ini menggunakan bahasa Inggris (American English). Pelafalan bahasa lain tidak dijamin.")}</StudioNotice>}{mode === "music" && (config?.custom_lyrics || config?.instrumental) && <div className="studio-music-direction">{config?.custom_lyrics && <label className="studio-checkbox"><input type="checkbox" checked={Boolean(draft.custom)} disabled={studio.submitting} onChange={(event) => set("custom", event.target.checked)} /><span>{t("Gunakan lirik sendiri")}<small>{t("Prompt dikirim sebagai lirik lagu, bukan deskripsi.")}</small></span></label>}{config?.instrumental && <label className="studio-checkbox"><input type="checkbox" checked={Boolean(draft.instrumental)} disabled={studio.submitting} onChange={(event) => set("instrumental", event.target.checked)} /><span>{t("Instrumental tanpa vokal")}<small>{t("Hasil hanya musik, tanpa suara penyanyi.")}</small></span></label>}</div>}{mode === "music" && <div className="studio-music-direction"><StudioField id="audio-tempo" label="Arahan tempo (opsional)" error={errors.tempo} hint={t("40–200 BPM sebagai arahan prompt, bukan janji tempo yang presisi.")}><div className="studio-input-unit"><input id="audio-tempo" type="number" min="40" max="200" step="1" value={draft.tempo} disabled={studio.submitting} onChange={(event) => set("tempo", event.target.value)} aria-describedby="audio-tempo-hint" /><span>BPM</span></div></StudioField><p className="studio-help">{t("Arahan tempo ikut dihitung dalam batas karakter prompt oleh server.")}</p></div>}</div>
                    <aside className="studio-audio-inspector"><div className="studio-section-heading"><h2><StudioIcon name="settings" className={`studio-color-${mode === "speech" ? "voice" : "music"}`} />{t(mode === "speech" ? "Karakter suara" : "Pengaturan komposisi")}</h2></div><StudioCatalog studio={studio} id="audio-model" value={selectedId} models={models} error={errors.model || errors.mode} onChange={studio.selectModel} />
                        {mode === "speech" && <><StudioField id="audio-voice" label="Suara" error={errors.voice}><select id="audio-voice" value={voice?.id || ""} disabled={studio.submitting || !voices.length} onChange={(event) => set("voice", event.target.value)}>{!voices.length && <option value="">{t("Suara belum tersedia")}</option>}{voices.map((item) => <option value={item.id} key={item.id}>{item.label} · {item.language}</option>)}</select>{voice && <p className="studio-help">{t("Bahasa suara")}: {voice.language}</p>}</StudioField>{speedAvailable && <StudioField id="audio-speed" label="Kecepatan bicara" error={errors.speed}><div className="studio-range-value"><output htmlFor="audio-speed">{Number(draft.speed).toFixed(2)}×</output><span>{Number(config.speed.min)}–{Number(config.speed.max)}×</span></div><input id="audio-speed" type="range" min={config.speed.min} max={config.speed.max} step="0.05" value={draft.speed} disabled={studio.submitting} onChange={(event) => set("speed", event.target.value)} aria-valuetext={`${Number(draft.speed).toFixed(2)}×`} /></StudioField>}</>}
                        {mode === "music" && durationAvailable && <StudioField id="audio-duration" label="Durasi" error={errors.duration}><div className="studio-range-value"><output htmlFor="audio-duration">{draft.duration} {t("detik")}</output><span>{config.duration.min}–{config.duration.max} {t("detik")}</span></div><input id="audio-duration" type="range" min={config.duration.min} max={config.duration.max} step="1" value={draft.duration} disabled={studio.submitting} onChange={(event) => set("duration", event.target.value)} aria-valuetext={`${draft.duration} ${t("detik")}`} /></StudioField>}
                        {model && !optionsValid && <StudioNotice>{t(mode === "speech" ? "Pilih suara dan kecepatan yang didukung model." : "Pilih durasi dan tempo dalam batas model.")}</StudioNotice>}
                        {model && unit == null && <StudioNotice error>{t("Harga token model belum tersedia. Pilih model lain atau hubungi pengelola.")}</StudioNotice>}
                        <StudioQuote total={unit} unit={unit} balance={studio.balance} />
                    </aside>
                </div>
                <div className="studio-audio-renderbar"><div><p>{t("Pembatalan hanya tersedia sebelum pengiriman ke penyedia dimulai.")}</p><small>{t("Hasil dan tagihan tersimpan pada riwayat akun Anda.")}</small></div><StudioButton type="submit" primary icon={mode === "speech" ? "voice" : "music"} disabled={!canGenerate}>{t(studio.submitting ? "Mengirim permintaan…" : mode === "speech" ? "Generate suara" : "Generate musik")}<StudioIcon name="arrow" /></StudioButton></div>
                {studio.submitError && <div className="studio-audio-feedback"><StudioNotice error>{t(studio.submitError.status ? mediaError(studio.submitError) : "Respons belum dapat dikonfirmasi. Periksa riwayat sebelum mengirim lagi.")}</StudioNotice></div>}
            </form>
        </section>
        <section className="studio-audio-output" aria-label={t("Hasil audio")}><div className="studio-section-heading"><h2><StudioIcon name="audio" className="studio-color-audio" />{t("Dengarkan hasilnya")}</h2>{job && <span className="studio-help">{job.model}</span>}</div>{studio.statusError && <StudioNotice error action={<StudioButton onClick={() => studio.loadJob(studio.activeId)} disabled={studio.statusLoading}>{t("Coba lagi")}</StudioButton>}>{t(mediaError(studio.statusError))}</StudioNotice>}{studio.submitting || isPending(job) ? <StudioProgress job={job} submitting={studio.submitting} /> : job?.status === "completed" && job.audio_url ? <AudioPlayer key={job.job_id} job={job} /> : <StudioEmpty icon="audio" title="Belum ada audio untuk diputar" description="Buat suara atau musik, atau pilih hasil dari riwayat. Waveform akan dibuat dari file audio hasil yang sebenarnya." />}{job && <StudioJobMeta job={job} checking={studio.statusLoading} onCheck={() => studio.loadJob(job.job_id)} onCancel={setCancellation} />}</section>
        <StudioHistory studio={studio} kind="audio" title="Riwayat audio" />
        {cancellation && <StudioCancellation key={cancellation.job_id} job={studio.jobs.find((item) => item.job_id === cancellation.job_id) || cancellation} onCancel={studio.cancel} onClose={() => setCancellation(null)} />}
    </div>;
}

export default function AudioGenerator() {
    const { user } = useAuth();
    return user ? <AudioStudio key={user.id} userId={user.id} /> : null;
}

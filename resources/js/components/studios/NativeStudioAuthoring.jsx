import { useLocale } from "../../contexts/LocaleContext";
import { fieldRequired } from "./capability";
import { StudioField, StudioIcon, StudioNotice, mediaError } from "./StudioUI";
import { VIDEO_ANGLES, VIDEO_PROMPT_LIMIT, VIDEO_STORIES, VIDEO_TABS, composeVideoPrompt, englishOnly, promptLimit, voiceOptions } from "./nativeStudio";

// Fixed-form authoring of the native studios, rendered inside the unified workspace form. These
// components only edit drafts; operation and model moves are decided by the workspace.
const arrowKeys = ["ArrowLeft", "ArrowRight", "Home", "End"];
function tabKey(event, index, items, select, prefix) {
    if (!arrowKeys.includes(event.key)) return;
    event.preventDefault();
    const next = event.key === "Home" ? 0 : event.key === "End" ? items.length - 1 : (index + (event.key === "ArrowRight" ? 1 : -1) + items.length) % items.length;
    select(items[next].id);
    document.getElementById(`${prefix}-${items[next].id}`)?.focus();
}

export function VideoAuthoring({ draft, tab, errors, disabled, imageToVideo, referenceRequired, referenceModels, onChange, onSelectTab }) {
    const { t } = useLocale();
    const composed = tab === "product" || tab === "ugc";
    const angles = draft.angles.split(",").filter(Boolean);
    const finalPrompt = composeVideoPrompt({ ...draft, tab });
    return <section className="media-native-authoring" aria-label={t("Arahan video")}>
        <div className="studio-section-heading"><h2>{t("Arahan video")}</h2><StudioIcon name="prompt" className="studio-color-video" /></div>
        <div className="studio-tabs" role="tablist" aria-label={t("Mode generator")}>{VIDEO_TABS.map((item, index) => <button type="button" role="tab" key={item.id} id={`video-tab-${item.id}`}
            aria-controls={`video-panel-${item.id}`} aria-selected={tab === item.id} tabIndex={tab === item.id ? 0 : -1} disabled={disabled}
            onClick={() => onSelectTab(item.id)} onKeyDown={(event) => tabKey(event, index, VIDEO_TABS, onSelectTab, "video-tab")}>
            <StudioIcon name={item.icon} className={`studio-color-${item.id}`} />{t(item.label)}</button>)}</div>
        <div id={`video-panel-${tab}`} role="tabpanel" aria-labelledby={`video-tab-${tab}`} className="studio-authoring-fields">
            {!composed ? <StudioField id="video-prompt" label="Prompt" error={errors.prompt} hint={`${draft.prompt.length}/4000 ${t("karakter")}`}>
                <textarea id="video-prompt" value={draft.prompt} rows={6} maxLength={VIDEO_PROMPT_LIMIT} required disabled={disabled} aria-invalid={Boolean(errors.prompt)}
                    aria-describedby="video-prompt-hint" placeholder={t("Jelaskan subjek, gerakan kamera, suasana, dan pencahayaan…")} onChange={(event) => onChange({ prompt: event.target.value }, "prompt")} />
            </StudioField> : <>
                <div className="studio-field-pair">
                    <StudioField id="video-product" label="Nama produk" error={errors.product}><input id="video-product" value={draft.product} maxLength={160} required disabled={disabled}
                        aria-invalid={Boolean(errors.product)} onChange={(event) => onChange({ product: event.target.value }, "product")} /></StudioField>
                    <StudioField id="video-features" label="Keunggulan / Key Feature"><input id="video-features" value={draft.features} maxLength={700} disabled={disabled}
                        onChange={(event) => onChange({ features: event.target.value }, "prompt")} /></StudioField>
                </div>
                {tab === "product" ? <fieldset className="studio-angle-fieldset"><legend>{t("Arahan sudut kamera")}</legend>
                    <p className="studio-help">{t("Pilihan ini menyusun prompt, bukan klip terpisah atau durasi adegan yang dijamin.")}</p>
                    <div className="studio-angle-list">{VIDEO_ANGLES.map((angle) => <button type="button" key={angle.id} disabled={disabled} aria-pressed={angles.includes(angle.id)}
                        onClick={() => onChange({ angles: (angles.includes(angle.id) ? angles.filter((id) => id !== angle.id) : [...angles, angle.id]).join(",") }, "prompt")}>
                        <StudioIcon name={angles.includes(angle.id) ? "check" : "video"} /><span>{t(angle.label)}</span></button>)}</div>
                </fieldset> : <StudioField id="video-story" label="Alur cerita UGC"><select id="video-story" value={draft.story} disabled={disabled} onChange={(event) => onChange({ story: event.target.value }, "prompt")}>
                    {VIDEO_STORIES.map((story) => <option key={story.id} value={story.id}>{t(story.label)}</option>)}</select></StudioField>}
                <StudioField id="video-cta" label="Call-to-Action (CTA) — Opsional" error={errors.cta} hint={t("CTA dikirim sebagai arahan kreatif; teks dan ucapan pada hasil bergantung pada model.")}>
                    <input id="video-cta" value={draft.cta} maxLength={500} disabled={disabled} aria-invalid={Boolean(errors.cta)} onChange={(event) => onChange({ cta: event.target.value }, "cta")} /></StudioField>
                <details className="studio-prompt-preview"><summary>{t("Lihat prompt yang dikirim")}</summary><p>{finalPrompt}</p><small>{finalPrompt.length}/4000 {t("karakter")}</small></details>
                {finalPrompt.length > VIDEO_PROMPT_LIMIT && <StudioNotice error>{t("Prompt terlalu panjang. Kurangi detail hingga 4000 karakter.")}</StudioNotice>}
            </>}
            {tab === "reference" && (imageToVideo
                ? <p className="studio-help">{t("Unggah gambar referensi pada kolom Gambar referensi di bawah; video dibuat dari gambar tersebut.")}</p>
                : <StudioNotice error>{t(referenceModels ? "Model ini tidak mendukung gambar referensi. Pilih model image-to-video pada daftar Model." : "Belum ada model image-to-video yang tersedia untuk akun ini.")}</StudioNotice>)}
            {composed && imageToVideo && <label className="studio-checkbox"><input type="checkbox" checked={referenceRequired || draft.withReference} disabled={disabled || referenceRequired}
                onChange={(event) => onChange({ withReference: event.target.checked })} />
                <span>{t("Buat dari gambar referensi")}<small>{t(referenceRequired ? "Model ini wajib memakai gambar referensi." : "Gambar produk diunggah pada kolom Gambar referensi; komposisi mengikuti rasio gambar.")}</small></span></label>}
            {!composed && tab !== "reference" && referenceRequired && imageToVideo && <p className="studio-help">{t("Model ini wajib memakai gambar referensi.")}</p>}
            {tab !== "reference" && !imageToVideo && <p className="studio-help">{t("Model ini menerima prompt teks tanpa gambar referensi.")}</p>}
            {errors.mode && <StudioNotice error>{t(mediaError(Array.isArray(errors.mode) ? errors.mode[0] : errors.mode))}</StudioNotice>}
        </div>
    </section>;
}

export function ProOption({ supported, active, unit, multiplier, description, perUnit = "token", disabled, onToggle, error }) {
    const { t, locale } = useLocale();
    const format = (value) => new Intl.NumberFormat(locale).format(value);
    return <div className="studio-pro">
        <div className="studio-pro-heading"><span id="media-pro-label"><StudioIcon name="pro" />Pro <small>{multiplier}× {t("token")}</small></span>
            <button type="button" className="studio-switch" role="switch" aria-checked={supported && active} aria-labelledby="media-pro-label" aria-describedby="media-pro-help"
                disabled={!supported || disabled} onClick={onToggle}><span /></button></div>
        <p id="media-pro-help">{t(supported ? description : "Mode Pro tidak didukung oleh model ini.")}</p>
        {supported && unit != null && <div className="studio-pro-prices"><span>Standard <strong>{format(unit)}</strong></span><span>Pro <strong>{format(unit * multiplier)}</strong></span><small>{t(perUnit)}</small></div>}
        {error && <p className="studio-field-error" role="alert">{t(mediaError(Array.isArray(error) ? error[0] : error))}</p>}
    </div>;
}

const AUDIO_MODES = [
    { id: "speech", operation: "text_to_speech", label: "Voiceover", detail: "Teks menjadi suara", icon: "voice" },
    { id: "music", operation: "music", label: "Musik & sound design", detail: "Suasana menjadi komposisi", icon: "music" },
];
// Native audio params this authoring renders itself; anything else stays with the generic form.
export const AUDIO_AUTHORED = ["prompt", "voice", "speed", "duration", "tempo", "instrumental", "custom"];

function AudioNumberField({ name, label, param, value, error, disabled, onChange }) {
    const { t } = useLocale();
    const id = `audio-${name}`;
    const unit = name === "speed" ? "×" : param.unit || (name === "duration" ? t("detik") : "");
    const bounded = param.min != null && param.max != null;
    const current = value ?? "";
    const change = (event) => onChange(event.target.value === "" ? "" : Number(event.target.value));
    return <StudioField id={id} label={label} error={error}>
        {Array.isArray(param.options) ? <select id={id} value={current} disabled={disabled} aria-invalid={Boolean(error)} onChange={change}>
            {current === "" && <option value="">{t("Pilih nilai")}</option>}
            {param.options.map((option) => <option key={option} value={option}>{option} {unit}</option>)}
        </select> : <>
            {bounded && <div className="studio-range-value"><output htmlFor={id}>{current} {unit}</output><span>{param.min}–{param.max} {unit}</span></div>}
            <input id={id} type={bounded && name !== "tempo" && current !== "" ? "range" : "number"} min={param.min ?? undefined} max={param.max ?? undefined}
                step={name === "speed" ? "0.05" : "1"} value={current} disabled={disabled} aria-invalid={Boolean(error)} aria-valuetext={`${current} ${unit}`} onChange={change} />
        </>}
    </StudioField>;
}

export function AudioAuthoring({ capability, native, values, errors, disabled, available, onValues, onSelectMode }) {
    const { t } = useLocale();
    const mode = capability.operation === "text_to_speech" ? "speech" : "music";
    const params = Object.fromEntries((capability.params || []).map((param) => [param.name, param]));
    const voices = voiceOptions(params.voice, native?.audio?.voices || []);
    const voice = voices.find((item) => item.id === values.voice);
    const limit = promptLimit(native);
    const prompt = typeof values.prompt === "string" ? values.prompt : "";
    const lyrics = Boolean(params.custom) && values.custom === true;
    const set = (name, value) => onValues({ ...values, [name]: value });
    const invalidOptions = Object.keys(errors).some((key) => ["voice", "speed", "duration", "tempo"].includes(key));
    return <section className="media-native-authoring" aria-label={t("Jenis audio")}>
        <div className="studio-audio-tabs" role="tablist" aria-label={t("Jenis audio")}>{AUDIO_MODES.map((item, index) => <button type="button" role="tab" key={item.id} id={`audio-tab-${item.id}`}
            aria-selected={mode === item.id} aria-controls="audio-panel" tabIndex={mode === item.id ? 0 : -1} disabled={disabled || (mode !== item.id && !available.includes(item.id))}
            onClick={() => { if (item.id !== mode) onSelectMode(item.id); }} onKeyDown={(event) => tabKey(event, index, AUDIO_MODES, (id) => { if (id !== mode && available.includes(id)) onSelectMode(id); }, "audio-tab")}>
            <span className={`studio-audio-mode-icon studio-color-${item.icon}`}><StudioIcon name={item.icon} /></span><span>{t(item.label)}<small>{t(item.detail)}</small></span></button>)}</div>
        <div id="audio-panel" role="tabpanel" aria-labelledby={`audio-tab-${mode}`} className="studio-authoring-fields">
            <div className="studio-section-heading"><h2>{t(mode === "speech" ? "Naskah voiceover" : "Arahan musik")}</h2><span className="studio-help">{prompt.length}/{limit}</span></div>
            <StudioField id="audio-prompt" label={mode === "speech" ? "Teks yang akan diucapkan" : lyrics ? "Lirik lagu" : "Deskripsi komposisi"} error={errors.prompt} className="studio-audio-prompt">
                <textarea id="audio-prompt" rows={7} value={prompt} required maxLength={limit} disabled={disabled} aria-invalid={Boolean(errors.prompt)} onChange={(event) => set("prompt", event.target.value)}
                    placeholder={t(mode === "speech" ? "Tulis naskah yang ingin dibacakan…" : lyrics ? "Tulis lirik lengkap lagu Anda…" : "Jelaskan genre, instrumen, suasana, dan perkembangan musik…")} />
            </StudioField>
            <div className="studio-audio-editor-note"><StudioIcon name={mode === "speech" ? "voice" : "music"} /><p>{t(mode === "speech" ? "Suara yang dihasilkan adalah suara AI, bukan rekaman manusia." : "Semua track yang dikembalikan model tersimpan dalam satu hasil. Editor ini tidak memisahkan stem atau notasi.")}</p></div>
            {params.voice && englishOnly(voices) && <StudioNotice>{t("Pilihan suara model ini menggunakan bahasa Inggris (American English). Pelafalan bahasa lain tidak dijamin.")}</StudioNotice>}
            {params.voice && <StudioField id="audio-voice" label="Suara" error={errors.voice}>
                <select id="audio-voice" value={voice?.id || ""} disabled={disabled || !voices.length} aria-invalid={Boolean(errors.voice)} onChange={(event) => set("voice", event.target.value)}>
                    {!voice && <option value="">{t("Pilih suara")}</option>}
                    {voices.map((item) => <option value={item.id} key={item.id}>{item.label}{item.language ? ` · ${item.language}` : ""}</option>)}
                </select>
                {voice?.language && <p className="studio-help">{t("Bahasa suara")}: {voice.language}</p>}
            </StudioField>}
            {params.speed && <AudioNumberField name="speed" label="Kecepatan bicara" param={params.speed} value={values.speed} error={errors.speed} disabled={disabled} onChange={(value) => set("speed", value)} />}
            {params.duration && <AudioNumberField name="duration" label="Durasi" param={params.duration} value={values.duration} error={errors.duration} disabled={disabled} onChange={(value) => set("duration", value)} />}
            {(params.custom || params.instrumental) && <div className="studio-music-direction">
                {params.custom && <label className="studio-checkbox"><input type="checkbox" checked={values.custom === true} disabled={disabled} onChange={(event) => set("custom", event.target.checked)} />
                    <span>{t("Gunakan lirik sendiri")}<small>{t("Prompt dikirim sebagai lirik lagu, bukan deskripsi.")}</small></span></label>}
                {params.instrumental && <label className="studio-checkbox"><input type="checkbox" checked={values.instrumental === true} disabled={disabled} onChange={(event) => set("instrumental", event.target.checked)} />
                    <span>{t("Instrumental tanpa vokal")}<small>{t("Hasil hanya musik, tanpa suara penyanyi.")}</small></span></label>}
            </div>}
            {params.tempo && <div className="studio-music-direction">
                <AudioNumberField name="tempo" label={fieldRequired(params.tempo, values) ? "Arahan tempo" : "Arahan tempo (opsional)"} param={params.tempo} value={values.tempo} error={errors.tempo} disabled={disabled} onChange={(value) => set("tempo", value)} />
                <p className="studio-help">{t("Tempo menjadi arahan prompt, bukan janji tempo yang presisi. Arahan ikut dihitung dalam batas karakter prompt oleh server.")}</p>
            </div>}
            {invalidOptions && <StudioNotice>{t(mode === "speech" ? "Pilih suara dan kecepatan yang didukung model." : "Pilih durasi dan tempo dalam batas model.")}</StudioNotice>}
        </div>
    </section>;
}

export function AvatarRules() {
    const { t } = useLocale();
    return <details><summary>{t("Aturan penggunaan avatar")}</summary><p className="studio-help">{t("Gunakan wajah dan suara milik sendiri atau dengan izin pemiliknya. Jangan menyamar untuk menipu, membuat kesan dukungan palsu, atau membuat konten intim tanpa persetujuan. Nyatakan bahwa video dibuat dengan AI bila dapat disalahartikan sebagai rekaman asli.")}</p></details>;
}

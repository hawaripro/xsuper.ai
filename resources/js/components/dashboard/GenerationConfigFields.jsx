import { useLocale } from "../../contexts/LocaleContext";

const pathFields = [
    ["image_path", "Endpoint gambar", "images/generations"],
    ["video_path", "Endpoint video", "videos/generations"],
    ["video_status_path", "Endpoint status video", "videos/generations/{id}"],
];
const listFields = [
    ["sizes", "Ukuran yang didukung"],
    ["durations", "Durasi yang didukung (detik)"],
    ["aspect_ratios", "Rasio aspek yang didukung"],
];
const booleanFields = [
    ["supports_size", "Kirim parameter ukuran"],
    ["supports_n", "Kirim parameter jumlah"],
    ["supports_duration", "Kirim parameter durasi"],
    ["supports_aspect_ratio", "Kirim parameter rasio aspek"],
];

export function generationConfigDraft(config) {
    const value = config || {};
    return {
        ...value,
        ...Object.fromEntries(pathFields.map(([key]) => [key, value[key] ?? ""])),
        ...Object.fromEntries(listFields.map(([key]) => [key, value[key] ? JSON.stringify(value[key]) : ""])),
        max_quantity: value.max_quantity ?? "",
        ...Object.fromEntries(booleanFields.map(([key]) => [key, value[key] == null ? "" : String(value[key])])),
    };
}

export function parseGenerationConfig(draft) {
    const config = {};
    const errors = {};
    for (const [key] of pathFields) {
        const value = String(draft[key] || "").trim();
        if (!value) continue;
        const path = key === "video_status_path" ? value.replace("{id}", "job-id") : value;
        if (!/^[A-Za-z0-9._~-]+(?:\/[A-Za-z0-9._~-]+)*$/.test(path) || path.split("/").some((part) => part === "." || part === "..") || (key === "video_status_path" && value.split("{id}").length !== 2)) {
            errors[key] = "Gunakan path relatif yang aman; status video wajib memuat satu {id}.";
        } else config[key] = value;
    }
    for (const [key] of listFields) {
        const text = String(draft[key] || "").trim();
        if (!text) continue;
        try {
            const values = JSON.parse(text);
            const validItem = (item) => key === "durations"
                ? Number.isInteger(item) && item >= 1 && item <= 600
                : typeof item === "string" && (key === "aspect_ratios" ? /^[1-9][0-9]{0,3}:[1-9][0-9]{0,3}$/.test(item) : item.length <= 32 && /^[A-Za-z0-9][A-Za-z0-9._:-]*$/.test(item));
            if (!Array.isArray(values) || values.length > 20 || !values.every(validItem) || new Set(values).size !== values.length) throw new Error();
            config[key] = values;
        } catch {
            errors[key] = "Masukkan daftar JSON yang valid dan unik, maksimal 20 pilihan.";
        }
    }
    if (draft.max_quantity !== "" && draft.max_quantity != null) {
        const quantity = Number(draft.max_quantity);
        if (!Number.isInteger(quantity) || quantity < 1 || quantity > 10) errors.max_quantity = "Jumlah maksimal harus bilangan bulat 1–10.";
        else config.max_quantity = quantity;
    }
    for (const [key] of booleanFields) {
        if (draft[key] === "true" || draft[key] === "false") config[key] = draft[key] === "true";
    }
    return { config: Object.keys(config).length ? config : null, errors };
}

export default function GenerationConfigFields({ value, onChange, category, disabled = false, errors = {} }) {
    const { t } = useLocale();
    const fieldClass = "ui-input mt-1 min-h-10";
    const error = (key) => errors[key] && <span role="alert" className="mt-1 block text-xs text-red-600 dark:text-red-300">{t(Array.isArray(errors[key]) ? errors[key][0] : errors[key])}</span>;
    const patch = (key, next) => onChange({ ...value, [key]: next });
    if (category === "audio") {
        return <section className="min-w-0 space-y-3" aria-label={t("Konfigurasi audio")}>
            <h3 className="text-sm font-semibold">{t("Konfigurasi audio")}</h3>
            {!value.audio_kind ? <p className="text-sm leading-6 text-slate-600 dark:text-slate-300">{t("Audio memerlukan model fal yang didukung. Simpan lalu sinkronkan koneksi untuk melihat kemampuan terverifikasi; harga dan publikasi tidak diatur otomatis.")}</p> : <>
                <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div><dt className="text-slate-600 dark:text-slate-400">{t("Jenis audio")}</dt><dd className="mt-1 font-medium">{t(value.audio_kind === "speech" ? "Suara / text-to-speech" : "Musik / efek suara")}</dd></div>
                    <div><dt className="text-slate-600 dark:text-slate-400">{t("Batas karakter")}</dt><dd className="mt-1 tabular-nums">{value.max_characters}</dd></div>
                    {value.speed_min != null && <div><dt className="text-slate-600 dark:text-slate-400">{t("Kecepatan suara")}</dt><dd className="mt-1 tabular-nums">{value.speed_min}–{value.speed_max}× · {t("Bawaan provider")}: {value.speed_default}×</dd></div>}
                    {value.duration_min != null && <div><dt className="text-slate-600 dark:text-slate-400">{t("Durasi audio (detik)")}</dt><dd className="mt-1 tabular-nums">{value.duration_min}–{value.duration_max} · {t("Bawaan provider")}: {value.duration_default}</dd></div>}
                    <div className="min-w-0 sm:col-span-2"><dt className="text-slate-600 dark:text-slate-400">{t("Endpoint audio")}</dt><dd className="mt-1 break-all font-mono text-xs">{value.audio_path}</dd></div>
                    <div className="min-w-0 sm:col-span-2"><dt className="text-slate-600 dark:text-slate-400">{t("Endpoint status audio")}</dt><dd className="mt-1 break-all font-mono text-xs">{value.audio_status_path}</dd></div>
                </dl>
                {value.audio_kind === "speech" && <>
                    <p className="text-sm leading-6 text-slate-600 dark:text-slate-300">{t("Kokoro ini menyediakan suara bahasa Inggris Amerika, bukan suara bahasa Indonesia.")}</p>
                    <ul className="grid gap-x-6 gap-y-2 text-xs sm:grid-cols-2">
                        {(value.voices || []).map((voice) => <li key={voice.id}><span className="font-medium">{voice.label}</span> · {voice.language} <code className="text-slate-600 dark:text-slate-400">{voice.id}</code></li>)}
                    </ul>
                </>}
                <p className="text-xs leading-5 text-slate-600 dark:text-slate-400">{t("Satu permintaan menghasilkan satu audio. Batas dan pilihan mengikuti skema provider; biaya token harus ditetapkan secara eksplisit.")}</p>
            </>}
        </section>;
    }
    return <fieldset disabled={disabled} className="min-w-0 space-y-3">
        <legend className="mb-2 text-sm font-semibold">{t("Konfigurasi generasi")}</legend>
        <p className="text-xs leading-5 text-slate-600 dark:text-slate-400">{t("Kosong memakai bawaan provider. Daftar menggunakan JSON; [] menghapus pilihan. Path relatif memakai koneksi tersimpan, bukan URL atau kunci API.")}</p>
        {category === "video" && <div className="space-y-2 text-sm leading-6 text-slate-700 dark:text-slate-300">
            <p>{t(value.supports_pro ? "Standard: 12 langkah inferensi, kualitas encoding high. Pro: 16 langkah, maximum; tepat 2× biaya token dasar." : "Mode Pro tidak tersedia pada adapter model ini.")}</p>
            <p>{t(value.supports_reference_image
                ? (value.reference_required ? "Satu gambar referensi wajib diunggah. Rasio video mengikuti gambar." : "Satu gambar referensi opsional memilih varian image-to-video; rasio mengikuti gambar.")
                : "Gambar referensi tidak didukung oleh adapter model ini.")}</p>
            {value.reference_model && <p className="break-all font-mono text-xs">{value.reference_model}</p>}
        </div>}
        <div className="grid min-w-0 gap-3 sm:grid-cols-2">
            {pathFields.filter(([key]) => category === "image" ? key === "image_path" : key !== "image_path").map(([key, label, placeholder]) => <label key={key} className="min-w-0 text-xs font-medium">
                {t(label)}
                <input className={`${fieldClass} font-mono`} value={value[key]} maxLength={200} placeholder={placeholder} spellCheck={false} aria-invalid={!!errors[key]} onChange={(event) => patch(key, event.target.value)} />
                {error(key)}
            </label>)}
            {listFields.filter(([key]) => category === "image" ? key === "sizes" : key !== "sizes").map(([key, label]) => <label key={key} className="min-w-0 text-xs font-medium">
                {t(label)}
                <input className={`${fieldClass} font-mono`} value={value[key]} placeholder={key === "durations" ? "[8, 10]" : key === "sizes" ? '["auto", "1024x1024"]' : '["16:9", "9:16"]'} aria-invalid={!!errors[key]} onChange={(event) => patch(key, event.target.value)} />
                {error(key)}
            </label>)}
            <label className="text-xs font-medium">{t("Jumlah maksimal per permintaan")}
                <input className={fieldClass} type="number" min="1" max="10" step="1" value={value.max_quantity} aria-invalid={!!errors.max_quantity} onChange={(event) => patch("max_quantity", event.target.value)} />
                {error("max_quantity")}
            </label>
            {booleanFields.filter(([key]) => category === "image" ? ["supports_size", "supports_n"].includes(key) : ["supports_duration", "supports_aspect_ratio"].includes(key)).map(([key, label]) => <label key={key} className="text-xs font-medium">{t(label)}
                <select className={fieldClass} value={value[key]} onChange={(event) => patch(key, event.target.value)}>
                    <option value="">{t("Bawaan provider")}</option>
                    <option value="true">{t("Ya")}</option>
                    <option value="false">{t("Tidak")}</option>
                </select>
            </label>)}
        </div>
    </fieldset>;
}

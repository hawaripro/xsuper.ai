import { useEffect, useMemo, useRef, useState } from "react";
import { useAuth } from "../contexts/AuthContext";
import { useLocale } from "../contexts/LocaleContext";
import { validationErrors } from "../components/member/MemberUI";
import { isPending, maxQuantity, modelOptions, tokenPrice, useMediaStudio, useObjectUrl, useStudioDraft } from "../components/studios/useMediaStudio";
import { StudioButton, StudioCancellation, StudioCatalog, StudioEmpty, StudioField, StudioHeader, StudioHistory, StudioIcon, StudioJobMeta, StudioNotice, StudioProgress, StudioQuote, mediaError } from "../components/studios/StudioUI";
import { apiRequest } from "../lib/api";

const angles = [
    { id: "closeup", label: "Close-up Detail", prompt: "Extreme close-up shot focusing on product details, texture, and craftsmanship. Macro lens feel, shallow depth of field." },
    { id: "lifestyle", label: "Lifestyle / In-Use", prompt: "Product being used naturally in an everyday setting. Authentic, relatable, warm lighting." },
    { id: "spin", label: "360° Product Spin", prompt: "Product rotating on a clean surface, showing all angles. Smooth turntable rotation, studio lighting." },
    { id: "cinematic", label: "Cinematic Hero Shot", prompt: "Cinematic hero shot of the product, dramatic lighting, slow reveal, premium feel." },
    { id: "beforeafter", label: "Before & After", prompt: "A clear visual comparison showing the product before and after use, with a natural transition." },
];
const stories = [
    { id: "problem", label: "Problem → Solution", prompt: "Talent shows a common problem, then introduces the product as the solution. Natural setting and delivery." },
    { id: "impression", label: "First Impression Jujur", prompt: "Talent opens and tries the product for the first time on camera. First-impression presentation, selfie-style camera." },
    { id: "beforeafter", label: "Before & After Rutinitas", prompt: "Talent shows their routine before the product, then after. Day-in-life style." },
    { id: "routine", label: "Bagian dari Hari-hari", prompt: "Product integrated into a daily routine. Morning or evening ritual, cozy setting." },
    { id: "friend", label: "Rekomendasi ke Teman", prompt: "Talent talking directly to camera about the product. Casual, conversational tone." },
];
const defaults = { model: "", mode: "prompt", prompt: "", product: "", features: "", angles: "closeup,lifestyle", story: "problem", cta: "", ratio: "", duration: "", count: "1", pro: false, variations: false };
const baseTabs = [{ id: "prompt", label: "Teks ke video", icon: "prompt" }, { id: "product", label: "Produk", icon: "product" }, { id: "ugc", label: "UGC", icon: "ugc" }];
const referenceTab = { id: "reference", label: "Gambar ke video", icon: "video" };

function VideoPlayer({ job }) {
    const { t } = useLocale();
    const player = useRef(null);
    const [error, setError] = useState(false);
    useEffect(() => {
        const element = player.current;
        if (element && element.getAttribute("src") !== job.video_url) element.setAttribute("src", job.video_url);
        return () => { if (element) { element.pause(); element.removeAttribute("src"); element.load(); } };
    }, [job.video_url]);
    return <><div className="studio-video-screen"><video ref={player} src={job.video_url} poster={job.thumbnail_url || undefined} controls playsInline preload="metadata" aria-label={`${t("Hasil video")}: ${job.prompt}`} onError={() => setError(true)} /></div>{error && <StudioNotice error>{t("Video tidak dapat diputar di browser ini. Buka atau unduh hasil aslinya.")}</StudioNotice>}<div className="studio-toolbar studio-player-links"><a className="studio-button studio-download" href={job.video_url} download><StudioIcon name="download" />{t("Unduh video")}</a><a className="studio-text-link" href={job.video_url} target="_blank" rel="noreferrer">{t("Buka asli")}</a></div></>;
}

function VideoStudio({ userId }) {
    const { t, locale } = useLocale();
    const studio = useMediaStudio("video");
    const [draft, setDraft] = useStudioDraft("video", userId, defaults);
    const [reference, setReference] = useState(null);
    const [referenceError, setReferenceError] = useState(null);
    const [referenceReady, setReferenceReady] = useState(false);
    const [cancellation, setCancellation] = useState(null);
    const [uploading, setUploading] = useState(false);
    const referenceUrl = useObjectUrl(reference);
    const fileInput = useRef(null);
    const selectedId = studio.requestedModel || draft.model;
    const model = studio.models.find((item) => item.id === selectedId) || null;
    const supportsReference = model?.reference_image?.supported === true;
    // Models whose provider accepts a reference image (image-to-video).
    const referenceModels = studio.models.filter((item) => item.reference_image?.supported === true);
    // The tab is always offered. Hiding it whenever the current model happened to be text-only
    // made image-to-video undiscoverable; selecting it moves to a capable model instead.
    const tabs = [baseTabs[0], referenceTab, baseTabs[1], baseTabs[2]];
    const mode = tabs.some((tab) => tab.id === draft.mode) ? draft.mode : "prompt";
    const supportsPro = model?.pro?.supported === true && model.pro.multiplier === 2;
    const pro = supportsPro && draft.pro;
    const ratioFromImage = Boolean(reference && supportsReference && model.reference_image.aspect_ratio_from_image);
    const selectedAngles = draft.angles.split(",");
    const count = Math.max(1, Math.min(Number(draft.count) || 1, maxQuantity(model)));
    const unit = tokenPrice(model);
    const total = unit == null ? null : unit * count * (pro ? 2 : 1);
    const errors = validationErrors(studio.submitError);
    const job = studio.activeJob;

    useEffect(() => {
        if (!studio.models.length) return;
        const next = studio.models.find((item) => item.id === (studio.requestedModel || draft.model));
        if (studio.requestedModel && !next) return;
        const chosen = next || studio.models[0];
        const durations = modelOptions(chosen, "durations").map(String);
        const ratios = modelOptions(chosen, "aspect_ratios");
        setDraft((current) => ({ ...current, model: chosen.id, ratio: ratios.includes(current.ratio) ? current.ratio : ratios[0] || "", duration: durations.includes(current.duration) ? current.duration : durations[0] || "", count: String(Math.min(Number(current.count) || 1, maxQuantity(chosen))), pro: chosen.pro?.supported === true && current.pro }));
    }, [studio.models, studio.requestedModel, draft.model, setDraft]);
    useEffect(() => {
        if (!supportsReference) {
            setReference(null);
            setReferenceReady(false);
            setReferenceError(null);
            if (fileInput.current) fileInput.current.value = "";
        }
    }, [supportsReference, reference]);

    const finalPrompt = useMemo(() => {
        if (mode === "prompt" || mode === "reference") return draft.prompt.trim();
        const parts = [`Create a ${mode === "ugc" ? "UGC-style" : "product"} video for "${draft.product.trim()}".`];
        if (draft.features.trim()) parts.push(`Key features: ${draft.features.trim()}.`);
        if (mode === "product") angles.forEach((angle) => { if (draft.angles.split(",").includes(angle.id)) parts.push(angle.prompt); });
        if (mode === "ugc") { const story = stories.find((item) => item.id === draft.story); if (story) parts.push(story.prompt); }
        return parts.join(" ");
    }, [mode, draft.prompt, draft.product, draft.features, draft.angles, draft.story]);
    const canGenerate = Boolean(model && !studio.modelLoading && !studio.modelError && !studio.submitting && !uploading && total != null && total <= 2147483647 && studio.balance != null && studio.balance >= total && ((mode === "prompt" || mode === "reference") ? draft.prompt.trim() : draft.product.trim()) && finalPrompt.length <= 4000 && (!reference || referenceReady) && (!model.reference_image?.required || reference) && (mode !== "reference" || reference) && !referenceError);
    const set = (name, value) => setDraft((current) => ({ ...current, [name]: value }));
    // Mirrors AudioGenerator.selectMode: a mode that needs a capability moves the selection to a
    // model that has it rather than leaving an unusable mode/model combination on screen.
    const selectMode = (next) => {
        if (next === mode) return;
        // Prefer a model that genuinely is image-to-video (reference required) over one that
        // merely tolerates a reference, so the tab lands on what the user actually asked for.
        if (next === "reference" && !supportsReference && referenceModels.length) {
            const target = referenceModels.find((item) => item.reference_image?.required === true) || referenceModels[0];
            studio.selectModel(target.id);
        }
        set("mode", next);
    };
    const chooseReference = (event) => {
        const file = event.target.files?.[0];
        if (!file) return;
        setReferenceReady(false);
        setReference(null);
        const max = Number(model?.reference_image?.max_bytes) || 10485760;
        const types = model?.reference_image?.mime_types || [];
        if (!types.includes(file.type) || file.size > max) {
            setReferenceError(`${t("Pilih gambar JPEG, PNG, atau WebP hingga")} ${Math.round(max / 1048576)} MiB.`);
            event.target.value = "";
            return;
        }
        setReferenceError(null);
        setReference(file);
    };
    const removeReference = () => { setReference(null); setReferenceReady(false); setReferenceError(null); if (fileInput.current) fileInput.current.value = ""; };
    const generate = async (event) => {
        event.preventDefault();
        if (!canGenerate) return;
        // Capability submission: the reference becomes a stable, ownership-checked asset id
        // instead of a raw upload, so the job records what it used and can be retried safely.
        let referenceId = null;
        if (reference) {
            setUploading(true);
            try {
                const form = new FormData();
                form.append("file", reference);
                form.append("role", "image_ref");
                const data = await apiRequest("/api/media/assets", { method: "POST", body: form });
                referenceId = data?.asset?.id;
                if (!referenceId) throw new Error("upload failed");
            } catch {
                setReferenceError(t("Gambar referensi gagal diunggah. Coba lagi."));
                setUploading(false);
                return;
            }
            setUploading(false);
        }
        studio.submit({
            operation: referenceId ? "image_to_video" : "text_to_video",
            model: model.id,
            prompt: finalPrompt,
            count,
            pro,
            mode: (mode === "prompt" || mode === "reference") ? "prompt" : "ab_testing",
            ugc_variation: count > 1 && draft.variations,
            ...((mode === "product" || mode === "ugc") && draft.cta.trim() ? { cta: draft.cta.trim() } : {}),
            // The reference frame fixes the geometry, so image_to_video declares no aspect ratio.
            ...(!referenceId && !ratioFromImage && draft.ratio ? { aspect_ratio: draft.ratio } : {}),
            ...(draft.duration ? { duration: Number(draft.duration) } : {}),
            ...(referenceId ? { reference_image: referenceId } : {}),
        });
    };

    return <div className="media-studio studio-video">
        <StudioHeader kind="video" title="Studio video" description="Susun arahan. Tentukan kualitas. Putar hasilnya." balance={studio.balance} onRefresh={studio.refresh} busy={studio.modelLoading || studio.submitting} />
        <form onSubmit={generate} className="studio-video-workbench" aria-busy={studio.submitting}>
            <div className="studio-video-editing">
                <section className="studio-monitor" aria-label={t("Hasil video")}><div className="studio-toolbar studio-monitor-heading"><h2><StudioIcon name="video" className="studio-color-video" />{t("Monitor video")}</h2><span className="studio-help">{job ? [job.model, job.pro_mode ? "Pro" : "Standard"].join(" · ") : t("Hasil asli dari model")}</span></div>{studio.statusError && <StudioNotice error action={<StudioButton onClick={() => studio.loadJob(studio.activeId)} disabled={studio.statusLoading}>{t("Coba lagi")}</StudioButton>}>{t(mediaError(studio.statusError))}</StudioNotice>}{studio.submitting || isPending(job) ? <div className="studio-video-screen"><StudioProgress job={job} submitting={studio.submitting} /></div> : job?.status === "completed" && job.video_url ? <VideoPlayer key={job.job_id} job={job} /> : <div className="studio-video-screen"><StudioEmpty icon="video" title="Adegan Anda dimulai di sini" description="Hasil video akan dapat diputar, dicari posisinya, dan diunduh setelah proses selesai." /></div>}{job && <StudioJobMeta job={job} checking={studio.statusLoading} onCheck={() => studio.loadJob(job.job_id)} onCancel={setCancellation} />}{job?.has_reference && job.reference_url && <details className="studio-saved-reference"><summary>{t("Gambar referensi permintaan ini")}</summary><a href={job.reference_url} target="_blank" rel="noreferrer"><img src={job.reference_url} alt={t("Gambar referensi video tersimpan")} loading="lazy" /></a></details>}</section>
                <section className="studio-authoring"><div className="studio-section-heading"><h2>{t("Arahan video")}</h2><StudioIcon name="prompt" className="studio-color-video" /></div><div className="studio-tabs" role="tablist" aria-label={t("Mode generator")}>{tabs.map((tab, index) => <button type="button" role="tab" id={`video-tab-${tab.id}`} aria-controls={`video-panel-${tab.id}`} aria-selected={mode === tab.id} tabIndex={mode === tab.id ? 0 : -1} disabled={studio.submitting} key={tab.id} onClick={() => selectMode(tab.id)} onKeyDown={(event) => { if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return; event.preventDefault(); const next = event.key === "Home" ? 0 : event.key === "End" ? tabs.length - 1 : (index + (event.key === "ArrowRight" ? 1 : -1) + tabs.length) % tabs.length; selectMode(tabs[next].id); document.getElementById(`video-tab-${tabs[next].id}`)?.focus(); }}><StudioIcon name={tab.icon} className={`studio-color-${tab.id}`} />{t(tab.label)}</button>)}</div>
                    <div id={`video-panel-${mode}`} role="tabpanel" aria-labelledby={`video-tab-${mode}`} className="studio-authoring-fields">
                        {(mode === "prompt" || mode === "reference") ? <><StudioField id="video-prompt" label="Prompt" error={errors.prompt} hint={`${draft.prompt.length}/4000 ${t("karakter")}`}><textarea id="video-prompt" value={draft.prompt} rows={6} maxLength={4000} required disabled={studio.submitting} onChange={(event) => set("prompt", event.target.value)} aria-invalid={Boolean(errors.prompt)} aria-describedby="video-prompt-hint" placeholder={t("Jelaskan subjek, gerakan kamera, suasana, dan pencahayaan…")} /></StudioField>{mode === "reference" && <p className="studio-help">{t("Unggah gambar referensi di panel Pengaturan video; video dibuat dari gambar tersebut.")}</p>}</> : <>
                            <div className="studio-field-pair"><StudioField id="video-product" label="Nama produk"><input id="video-product" value={draft.product} maxLength={160} required disabled={studio.submitting} onChange={(event) => set("product", event.target.value)} /></StudioField><StudioField id="video-features" label="Keunggulan / Key Feature"><input id="video-features" value={draft.features} maxLength={700} disabled={studio.submitting} onChange={(event) => set("features", event.target.value)} /></StudioField></div>
                            {mode === "product" ? <fieldset className="studio-angle-fieldset"><legend>{t("Arahan sudut kamera")}</legend><p className="studio-help">{t("Pilihan ini menyusun prompt, bukan klip terpisah atau durasi adegan yang dijamin.")}</p><div className="studio-angle-list">{angles.map((angle) => <button type="button" key={angle.id} disabled={studio.submitting} aria-pressed={selectedAngles.includes(angle.id)} onClick={() => set("angles", (selectedAngles.includes(angle.id) ? selectedAngles.filter((id) => id !== angle.id) : [...selectedAngles, angle.id]).join(","))}><StudioIcon name={selectedAngles.includes(angle.id) ? "check" : "video"} /><span>{t(angle.label)}</span></button>)}</div></fieldset> : <StudioField id="video-story" label="Alur cerita UGC"><select id="video-story" value={draft.story} disabled={studio.submitting} onChange={(event) => set("story", event.target.value)}>{stories.map((story) => <option key={story.id} value={story.id}>{t(story.label)}</option>)}</select></StudioField>}
                            <StudioField id="video-cta" label="Call-to-Action (CTA) — Opsional" error={errors.cta} hint={t("CTA dikirim sebagai arahan kreatif; teks dan ucapan pada hasil bergantung pada model.")}><input id="video-cta" value={draft.cta} maxLength={500} disabled={studio.submitting} onChange={(event) => set("cta", event.target.value)} /></StudioField>
                            <details className="studio-prompt-preview"><summary>{t("Lihat prompt yang dikirim")}</summary><p>{finalPrompt}</p><small>{finalPrompt.length}/4000 {t("karakter")}</small></details>
                            {finalPrompt.length > 4000 && <StudioNotice error>{t("Prompt terlalu panjang. Kurangi detail hingga 4000 karakter.")}</StudioNotice>}
                        </>}
                    </div>
                </section>
            </div>
            <aside className="studio-video-inspector"><div className="studio-section-heading"><h2><StudioIcon name="settings" className="studio-color-video" />{t("Pengaturan video")}</h2></div><StudioCatalog studio={studio} id="video-model" value={selectedId} error={errors.model} onChange={studio.selectModel} />
                <div className="studio-pro"><div className="studio-pro-heading"><span id="video-pro-label"><StudioIcon name="pro" />Pro <small>2× {t("token")}</small></span><button type="button" className="studio-switch" role="switch" aria-checked={pro} aria-labelledby="video-pro-label" aria-describedby="video-pro-benefit" disabled={!supportsPro || studio.submitting} onClick={() => set("pro", !pro)}><span /></button></div><p id="video-pro-benefit">{t(supportsPro ? model.pro.description || "16 langkah inferensi dan encoding maksimum; Standard menggunakan 12 langkah dan encoding tinggi." : "Mode Pro tidak didukung oleh model ini.")}</p>{supportsPro && unit != null && <div className="studio-pro-prices"><span>Standard <strong>{new Intl.NumberFormat(locale).format(unit)}</strong></span><span>Pro <strong>{new Intl.NumberFormat(locale).format(unit * 2)}</strong></span><small>{t("token / video")}</small></div>}{errors.pro_mode && <p className="studio-field-error">{t(mediaError(errors.pro_mode[0] || errors.pro_mode))}</p>}</div>
                <div className="studio-field-pair"><StudioField id="video-ratio" label="Rasio aspek" error={errors.aspect_ratio}><select id="video-ratio" value={ratioFromImage ? "reference" : draft.ratio} disabled={studio.submitting || ratioFromImage || modelOptions(model, "aspect_ratios").length < 2} onChange={(event) => set("ratio", event.target.value)}>{ratioFromImage ? <option value="reference">{t("Dari gambar")}</option> : !modelOptions(model, "aspect_ratios").length ? <option value="">{t("Otomatis")}</option> : modelOptions(model, "aspect_ratios").map((ratio) => <option key={ratio} value={ratio}>{ratio}</option>)}</select></StudioField><StudioField id="video-duration" label="Durasi" error={errors["settings.duration"]}><select id="video-duration" value={draft.duration} disabled={studio.submitting || modelOptions(model, "durations").length < 2} onChange={(event) => set("duration", event.target.value)}>{!modelOptions(model, "durations").length ? <option value="">{t("Otomatis")}</option> : modelOptions(model, "durations").map((duration) => <option key={duration} value={duration}>{duration} {t("detik")}</option>)}</select></StudioField></div>
                {supportsReference ? <div className="studio-reference"><StudioField id="video-reference" label={model.reference_image.required ? "Gambar referensi (wajib)" : "Gambar referensi (opsional)"} error={errors.reference_image} hint={`${t("JPEG, PNG, atau WebP")} · ${Math.round(Number(model.reference_image.max_bytes) / 1048576)} MiB ${t("maksimum")}`}><input ref={fileInput} id="video-reference" type="file" accept={model.reference_image.mime_types.join(",")} onChange={chooseReference} disabled={studio.submitting} aria-describedby="video-reference-hint" /></StudioField>{referenceUrl && <div className="studio-reference-preview"><img src={referenceUrl} alt={t("Pratinjau gambar referensi")} onLoad={(event) => {
                    const { naturalWidth: width, naturalHeight: height } = event.currentTarget;
                    if (width > 8192 || height > 8192 || width * height > 40000000) {
                        setReferenceReady(false);
                        setReferenceError(t("Gambar melebihi batas 8192 piksel per sisi atau 40 megapiksel. Pilih file yang lebih kecil."));
                    } else setReferenceReady(true);
                }} onError={() => { setReferenceReady(false); setReferenceError(t("Gambar tidak dapat dibaca. Hapus dan pilih file lain.")); }} /><div><strong>{reference.name}</strong><small>{(reference.size / 1048576).toFixed(2)} MiB</small></div><StudioButton icon="close" onClick={removeReference} disabled={studio.submitting} aria-label={t("Hapus gambar referensi")} /></div>}{referenceError && <StudioNotice error>{referenceError}</StudioNotice>}<p className="studio-help">{t("Gambar diunggah saat Generate. Pilih ulang file setelah meninggalkan halaman.")}{ratioFromImage && ` ${t("Komposisi mengikuti rasio gambar referensi.")}`}</p></div> : mode === "reference" ? <StudioNotice error>{t(referenceModels.length ? "Model ini tidak mendukung gambar referensi. Pilih model image-to-video pada daftar Model." : "Belum ada model image-to-video yang tersedia untuk akun ini.")}</StudioNotice> : <p className="studio-help">{t("Model ini menerima prompt teks tanpa gambar referensi.")}</p>}
                <StudioField id="video-count" label="Jumlah video" error={errors.count}><select id="video-count" value={count} disabled={studio.submitting || !model || maxQuantity(model) === 1} onChange={(event) => set("count", event.target.value)}>{Array.from({ length: maxQuantity(model) }, (_, index) => <option key={index + 1} value={index + 1}>{index + 1}</option>)}</select></StudioField>
                {count > 1 && <label className="studio-checkbox"><input type="checkbox" checked={draft.variations} disabled={studio.submitting} onChange={(event) => set("variations", event.target.checked)} /><span>{t("Variasikan komposisi tiap video")}<small>{t("Arahan tambahan dikirim ke model untuk hasil berikutnya.")}</small></span></label>}
                {model && unit == null && <StudioNotice error>{t("Harga token model belum tersedia. Pilih model lain atau hubungi pengelola.")}</StudioNotice>}
                {studio.submitError && <StudioNotice error>{t(studio.submitError.status ? mediaError(studio.submitError) : "Respons belum dapat dikonfirmasi. Periksa riwayat sebelum mengirim lagi.")}</StudioNotice>}
                <StudioQuote total={total} unit={unit} count={count} pro={pro} balance={studio.balance} /><StudioButton type="submit" primary icon="video" disabled={!canGenerate}>{t(uploading ? "Mengunggah gambar referensi…" : studio.submitting ? "Mengirim permintaan…" : "Generate video")}<StudioIcon name="arrow" /></StudioButton><p className="studio-help">{t("Pembatalan hanya tersedia sebelum pengiriman video dimulai. Token dikembalikan jika permintaan ditolak atau proses gagal.")}</p>
            </aside>
        </form>
        <StudioHistory studio={studio} kind="video" title="Riwayat video" />
        {cancellation && <StudioCancellation key={cancellation.job_id} job={studio.jobs.find((item) => item.job_id === cancellation.job_id) || cancellation} onCancel={studio.cancel} onClose={() => setCancellation(null)} />}
    </div>;
}

export default function VideoGenerator() {
    const { user } = useAuth();
    return user ? <VideoStudio key={user.id} userId={user.id} /> : null;
}

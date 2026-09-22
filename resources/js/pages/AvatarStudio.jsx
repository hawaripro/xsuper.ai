import { useEffect, useState } from "react";
import { useAuth } from "../contexts/AuthContext";
import { useLocale } from "../contexts/LocaleContext";
import { validationErrors } from "../components/member/MemberUI";
import { isPending, tokenPrice, useMediaStudio, useStudioDraft } from "../components/studios/useMediaStudio";
import { capabilityErrors, capabilitySubmission, capabilityValues } from "../components/studios/capability";
import CapabilityForm from "../components/studios/CapabilityForm";
import VideoPlayer from "../components/studios/VideoPlayer";
import { StudioButton, StudioCancellation, StudioCatalog, StudioEmpty, StudioHeader, StudioHistory, StudioIcon, StudioJobMeta, StudioNotice, StudioProgress, StudioQuote, mediaError } from "../components/studios/StudioUI";

const defaults = { model: "", values: {} };

function AvatarWorkbench({ userId }) {
    const { t, locale } = useLocale();
    const studio = useMediaStudio("avatar");
    const [draft, setDraft] = useStudioDraft("avatar", userId, defaults);
    const [consent, setConsent] = useState(false);
    const [cancellation, setCancellation] = useState(null);
    const selectedId = studio.requestedModel || draft.model;
    const model = studio.models.find((item) => item.id === selectedId);
    const capability = model?.capabilities?.talking_avatar;
    const job = studio.activeJob;

    useEffect(() => {
        if (!studio.models.length) return;
        const selected = studio.models.find((item) => item.id === (studio.requestedModel || draft.model));
        if (studio.requestedModel && !selected) return;
        const chosen = selected || studio.models[0];
        setDraft((current) => ({ model: chosen.id, values: capabilityValues(chosen.capabilities?.talking_avatar, current.values) }));
    }, [studio.models, studio.requestedModel, draft.model, setDraft]);

    const price = capability?.price_tokens ?? tokenPrice(model);
    const duration = Number(draft.values.duration);
    const durationParam = capability?.params?.find((param) => param.name === "duration");
    const total = price != null && Number.isInteger(duration) && duration > 0 ? price * duration : null;
    const clientErrors = capabilityErrors(capability, draft.values);
    const errors = { ...clientErrors, ...validationErrors(studio.submitError) };
    const inputReady = capability?.source_hash && Object.keys(clientErrors).length === 0;
    const canSubmit = Boolean(inputReady && consent && total != null && total <= 2147483647 && studio.balance != null && total <= studio.balance && !studio.modelLoading && !studio.modelError && !studio.submitting);
    const changeValues = (values) => {
        if (values.avatar_photo !== draft.values.avatar_photo || values.speech_audio !== draft.values.speech_audio) setConsent(false);
        setDraft((current) => ({ ...current, values }));
    };
    const generate = (event) => {
        event.preventDefault();
        if (!canSubmit) return;
        studio.submit({
            model: model.id, operation: "talking_avatar", rights_confirmed: true,
            expected_price_tokens: total, expected_capability_hash: capability.source_hash,
            ...capabilitySubmission(capability, draft.values),
        });
    };

    return <div className="media-studio studio-avatar">
        <StudioHeader kind="video" title="Studio avatar" description="Padukan foto dan suara yang Anda berhak gunakan." balance={studio.balance} onRefresh={studio.refresh} busy={studio.modelLoading || studio.submitting} />
        <div className="studio-video-workbench">
            <section className="studio-monitor" aria-label={t("Hasil avatar")}>
                <div className="studio-toolbar studio-monitor-heading"><h2><StudioIcon name="video" />{t("Pratinjau avatar")}</h2><span className="studio-help">{t("Foto + audio → video")}</span></div>
                {studio.statusError && <StudioNotice error action={<StudioButton onClick={() => studio.loadJob(studio.activeId)} disabled={studio.statusLoading}>{t("Coba lagi")}</StudioButton>}>{t(mediaError(studio.statusError))}</StudioNotice>}
                {studio.submitting || isPending(job) ? <div className="studio-video-screen"><StudioProgress job={job} submitting={studio.submitting} /></div>
                    : job?.status === "completed" && job.video_url ? <VideoPlayer key={job.job_id} job={job} />
                        : <div className="studio-video-screen"><StudioEmpty icon="video" title={job?.status === "failed" ? "Avatar belum berhasil dibuat" : "Foto Anda, ucapan Anda"} description={job?.error_message || "Pilih foto wajah dan audio ucapan. Video hasilnya akan tampil di sini."} /></div>}
                {job && <StudioJobMeta job={job} checking={studio.statusLoading} onCheck={() => studio.loadJob(job.job_id)} />}
                {isPending(job) && <div className="studio-player-links"><StudioButton onClick={() => setCancellation(job)}>{t("Batalkan permintaan")}</StudioButton></div>}
                {job?.reference_url && <details className="studio-saved-reference"><summary>{t("Referensi yang digunakan")}</summary><img src={job.reference_url} alt={t("Foto wajah")} />{job.speech_audio_url && <audio src={job.speech_audio_url} controls preload="none" aria-label={t("Audio ucapan")} />}</details>}
            </section>
            <form className="studio-video-inspector" onSubmit={generate} aria-busy={studio.submitting}>
                <div className="studio-section-heading"><h2>{t("Bahan avatar")}</h2><StudioIcon name="settings" /></div>
                <StudioCatalog studio={studio} id="avatar-model" value={selectedId} error={errors.model} onChange={studio.selectModel} />
                {model?.avatar_audio_mode === "soundtrack" && <p className="studio-help">{t("Audio Fal menggantikan soundtrack video; sinkronisasi bibir tidak dijamin. Audio minimal 2 detik, maksimal 15 MB.")}</p>}
                {capability ? <CapabilityForm capability={capability} values={draft.values} errors={errors} disabled={studio.submitting} idPrefix="avatar" onChange={changeValues} />
                    : !studio.modelLoading && !studio.modelError && <StudioNotice>{t("Belum ada model avatar aktif. Hubungi pengelola untuk mengaktifkan model yang sudah diverifikasi.")}</StudioNotice>}
                <label className="studio-checkbox"><input type="checkbox" checked={consent} disabled={studio.submitting || !inputReady} onChange={(event) => setConsent(event.target.checked)} /><span>{t("Saya memiliki hak atau izin untuk menggunakan wajah dan suara ini.")}<small>{t("Persetujuan diperlukan sebelum token dicadangkan.")}</small></span></label>
                <details><summary>{t("Aturan penggunaan avatar")}</summary><p className="studio-help">{t("Gunakan wajah dan suara milik sendiri atau dengan izin pemiliknya. Jangan menyamar untuk menipu, membuat kesan dukungan palsu, atau membuat konten intim tanpa persetujuan. Nyatakan bahwa video dibuat dengan AI bila dapat disalahartikan sebagai rekaman asli.")}</p></details>
                {price != null && durationParam && <p className="studio-help">{new Intl.NumberFormat(locale).format(price)} {t("token per detik")} · 480p · {durationParam.min}–{durationParam.max} {t("detik")}</p>}
                {studio.submitError && <StudioNotice error>{t(studio.submitError.status ? mediaError(studio.submitError) : "Respons belum dapat dikonfirmasi. Periksa riwayat sebelum mengirim lagi.")}</StudioNotice>}
                <StudioQuote total={total} balance={studio.balance} />
                <StudioButton type="submit" primary icon="video" disabled={!canSubmit}>{t(studio.submitting ? "Mengirim permintaan…" : "Generate avatar")}<StudioIcon name="arrow" /></StudioButton>
                {!canSubmit && capability && !studio.submitting && <p className="studio-help">{t(!inputReady ? "Lengkapi foto, audio, dan pengaturan yang didukung." : !consent ? "Konfirmasikan izin penggunaan wajah dan suara untuk melanjutkan." : "Periksa saldo dan harga sebelum melanjutkan.")}</p>}
            </form>
        </div>
        <StudioHistory studio={studio} kind="video" title="Riwayat avatar" />
        {cancellation && <StudioCancellation key={cancellation.job_id} job={studio.jobs.find((item) => item.job_id === cancellation.job_id) || cancellation} onCancel={studio.cancel} onClose={() => setCancellation(null)} />}
    </div>;
}

export default function AvatarStudio() {
    const { user } = useAuth();
    return user ? <AvatarWorkbench key={user.id} userId={user.id} /> : null;
}

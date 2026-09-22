import { useEffect, useRef, useState } from "react";
import { useLocale } from "../../contexts/LocaleContext";
import { StudioIcon, StudioNotice } from "./StudioUI";

export default function VideoPlayer({ job }) {
    const { t } = useLocale();
    const player = useRef(null);
    const [error, setError] = useState(false);
    useEffect(() => {
        const element = player.current;
        setError(false);
        if (element && element.getAttribute("src") !== job.video_url) element.setAttribute("src", job.video_url);
        return () => { if (element) { element.pause(); element.removeAttribute("src"); element.load(); } };
    }, [job.video_url]);
    return <>
        <div className="studio-video-screen"><video ref={player} src={job.video_url} poster={job.thumbnail_url || undefined} controls playsInline preload="metadata" aria-label={`${t("Hasil video")}: ${job.prompt || job.model}`} onError={() => setError(true)} /></div>
        {error && <StudioNotice error>{t("Video tidak dapat diputar di browser ini. Buka atau unduh hasil aslinya.")}</StudioNotice>}
        <div className="studio-toolbar studio-player-links"><a className="studio-button studio-download" href={job.video_url} download><StudioIcon name="download" />{t("Unduh video")}</a><a className="studio-text-link" href={job.video_url} target="_blank" rel="noreferrer">{t("Buka asli")}</a></div>
    </>;
}

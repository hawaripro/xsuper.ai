import { useEffect, useMemo, useRef, useState } from "react";
import { useLocale } from "../../contexts/LocaleContext";
import { StudioButton, StudioIcon, StudioNotice } from "./StudioUI";

const clock = (value) => {
    const seconds = Math.max(0, Math.floor(Number(value) || 0));
    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, "0")}`;
};

export default function AudioPlayer({ job, output }) {
    const { t } = useLocale();
    const audio = useRef(null);
    const [playing, setPlaying] = useState(false);
    const [ready, setReady] = useState(false);
    const [position, setPosition] = useState(0);
    const [duration, setDuration] = useState(0);
    const [volume, setVolume] = useState(0.8);
    const [playbackError, setPlaybackError] = useState(false);
    const [wave, setWave] = useState(null);
    const [waveError, setWaveError] = useState(false);
    const [waveLoading, setWaveLoading] = useState(true);

    useEffect(() => {
        const element = audio.current;
        const controller = new AbortController();
        let context;
        let stopped = false;
        if (element) {
            element.volume = 0.8;
            if (element.getAttribute("src") !== output.url) element.setAttribute("src", output.url);
        }
        const decode = async () => {
            try {
                const AudioContextClass = window.AudioContext || window.webkitAudioContext;
                if (!AudioContextClass) throw new Error("Audio decoding unavailable");
                const response = await fetch(output.url, { credentials: "same-origin", signal: controller.signal, headers: { Accept: "audio/*" } });
                if (!response.ok || Number(response.headers.get("content-length")) > 64 * 1024 * 1024) throw new Error("Audio cannot be decoded");
                const bytes = await response.arrayBuffer();
                if (stopped) return;
                if (bytes.byteLength > 64 * 1024 * 1024) throw new Error("Audio cannot be decoded");
                context = new AudioContextClass();
                const buffer = await context.decodeAudioData(bytes);
                if (stopped) return;
                const count = 160;
                const samplesPerBin = Math.max(1, Math.ceil(buffer.length / count));
                const peaks = new Array(count).fill(0);
                for (let channel = 0; channel < buffer.numberOfChannels; channel += 1) {
                    const samples = buffer.getChannelData(channel);
                    for (let index = 0; index < count; index += 1) {
                        const start = index * samplesPerBin;
                        const end = Math.min(samples.length, start + samplesPerBin);
                        for (let sample = start; sample < end; sample += 1) peaks[index] = Math.max(peaks[index], Math.abs(samples[sample]));
                    }
                }
                setWave({ peaks, sampleRate: buffer.sampleRate, channels: buffer.numberOfChannels });
            } catch (error) {
                if (!stopped && error.name !== "AbortError") setWaveError(true);
            } finally {
                if (context && context.state !== "closed") await context.close().catch(() => {});
                if (!stopped) setWaveLoading(false);
            }
        };
        decode();
        return () => {
            stopped = true;
            controller.abort();
            if (context && context.state !== "closed") context.close().catch(() => {});
            if (element) { element.pause(); element.removeAttribute("src"); element.load(); }
        };
    }, [output.url]);
    const path = useMemo(() => wave ? wave.peaks.map((peak, index) => { const height = Math.min(1, peak) * 136; return `M${index * 6.25 + 3.125} ${80 - height / 2}v${height}`; }).join(" ") : "", [wave]);
    const progress = duration > 0 ? Math.min(100, position / duration * 100) : 0;
    const seek = (value) => { if (audio.current && duration > 0) { audio.current.currentTime = Math.min(duration, Math.max(0, value)); setPosition(audio.current.currentTime); } };
    const toggle = async () => {
        const element = audio.current;
        if (!element) return;
        if (!element.paused) { element.pause(); return; }
        try { await element.play(); setPlaybackError(false); } catch { setPlaybackError(true); }
    };
    return <div className="studio-audio-player">
        <audio ref={audio} src={output.url} preload="metadata" onCanPlay={() => setReady(true)} onLoadedMetadata={(event) => setDuration(Number.isFinite(event.currentTarget.duration) ? event.currentTarget.duration : 0)} onDurationChange={(event) => setDuration(Number.isFinite(event.currentTarget.duration) ? event.currentTarget.duration : 0)} onTimeUpdate={(event) => setPosition(event.currentTarget.currentTime)} onPlay={() => setPlaying(true)} onPause={() => setPlaying(false)} onEnded={() => setPlaying(false)} onError={() => { setPlaybackError(true); setReady(false); }} />
        <div className="studio-audio-wave"><div className="studio-wave-heading"><span><StudioIcon name={job.mode === "speech" ? "voice" : "music"} />{t(job.mode === "speech" ? "Voiceover" : "Musik / sound design")}</span><span>{wave ? `${(wave.sampleRate / 1000).toFixed(1)} kHz · ${wave.channels === 1 ? "Mono" : `${wave.channels} ch`}` : t("Audio hasil model")}</span></div>{wave ? <div className="studio-wave-drawing"><svg viewBox="0 0 1000 160" preserveAspectRatio="none" role="img" aria-label={t("Waveform dari audio hasil yang didekode")}><path d="M0 80H1000" className="studio-wave-baseline" /><path d={path} strokeWidth="3.6" strokeLinecap="round" className="studio-wave-unplayed" /><path d={path} strokeWidth="3.6" strokeLinecap="round" className="studio-wave-played" style={{ clipPath: `inset(0 ${100 - progress}% 0 0)` }} /></svg><span className="studio-wave-playhead" style={{ left: `${progress}%` }} /></div> : <div className="studio-wave-message" role="status"><StudioIcon name="audio" /><p>{t(waveLoading ? "Mendekode waveform dari file audio…" : "Waveform tidak dapat dibaca. Pemutar dan unduhan tetap tersedia.")}</p></div>}<div className="studio-wave-time"><time>{clock(position)}</time><time>{clock(duration)}</time></div></div>
        <div className="studio-audio-transport"><StudioButton icon={playing ? "pause" : "play"} primary onClick={toggle} disabled={!ready} aria-label={t(playing ? "Jeda audio" : "Putar audio")}>{t(playing ? "Jeda" : "Putar")}</StudioButton><StudioButton icon="refresh" onClick={() => seek(0)} disabled={!ready} aria-label={t("Kembali ke awal audio")} /><div className="studio-audio-seek"><label htmlFor="audio-seek">{t("Posisi audio")}</label><input id="audio-seek" type="range" min="0" max={duration || 1} step="0.01" value={Math.min(position, duration || 1)} onChange={(event) => seek(Number(event.target.value))} disabled={!ready || !duration} aria-valuetext={`${clock(position)} / ${clock(duration)}`} /></div><div className="studio-audio-volume"><label htmlFor="audio-volume"><StudioIcon name="volume" />{t("Volume")}</label><input id="audio-volume" type="range" min="0" max="1" step="0.01" value={volume} onChange={(event) => { const next = Number(event.target.value); setVolume(next); if (audio.current) audio.current.volume = next; }} disabled={!ready} aria-valuetext={`${Math.round(volume * 100)}%`} /></div></div>
        {playbackError && <StudioNotice error action={<StudioButton icon="refresh" onClick={() => { setPlaybackError(false); setReady(false); audio.current?.load(); }}>{t("Coba lagi")}</StudioButton>}>{t("Audio tidak dapat diputar. Coba lagi atau unduh file aslinya.")}</StudioNotice>}
        <div className="studio-toolbar studio-player-links"><p className="studio-help">{t(waveError ? "Waveform tidak tersedia untuk format ini." : "Waveform menampilkan amplitudo audio asli, bukan animasi simulasi.")}</p><a className="studio-button studio-download" href={output.url} download><StudioIcon name="download" />{t("Unduh audio")}</a></div>
    </div>;
}

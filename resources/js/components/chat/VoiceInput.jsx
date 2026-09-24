import React, { useEffect, useId, useRef, useState } from 'react';
import { useLocale } from '../../contexts/LocaleContext';
import MediaActionDialog from '../MediaActionDialog';
import ChatIcon from './ChatIcon';
import './chat-workspace-panels.css';

export default function VoiceInput({ onTranscript, disabled = false }) {
    const { locale } = useLocale();
    const en = locale === 'en';
    const Recognition = typeof window === 'undefined' ? null : window.SpeechRecognition || window.webkitSpeechRecognition;
    const [open, setOpen] = useState(false);
    const [status, setStatus] = useState('idle');
    const [transcript, setTranscript] = useState('');
    const [interim, setInterim] = useState('');
    const [error, setError] = useState('');
    const recognition = useRef(null);
    const transcriptId = useId();
    const recording = status === 'listening' || status === 'processing';

    useEffect(() => () => {
        const active = recognition.current;
        if (active) { active.onresult = null; active.onerror = null; active.onend = null; active.abort(); }
    }, []);

    const close = () => {
        const active = recognition.current;
        recognition.current = null;
        if (active) { active.onend = null; active.onerror = null; active.onresult = null; active.abort(); }
        setOpen(false); setStatus('idle'); setInterim(''); setTranscript(''); setError('');
    };
    const start = () => {
        if (!Recognition) return;
        setTranscript(''); setInterim(''); setError('');
        const active = new Recognition();
        recognition.current = active;
        active.lang = en ? 'en-US' : 'id-ID';
        active.interimResults = true;
        active.continuous = true;
        active.onstart = () => { if (recognition.current === active) setStatus('listening'); };
        active.onresult = (event) => {
            if (recognition.current !== active) return;
            const parts = Array.from(event.results);
            setTranscript(parts.filter((result) => result.isFinal).map((result) => result[0].transcript).join(' '));
            setInterim(parts.filter((result) => !result.isFinal).map((result) => result[0].transcript).join(' '));
        };
        active.onerror = (event) => {
            if (recognition.current !== active) return;
            const message = event.error === 'not-allowed' || event.error === 'service-not-allowed'
                ? (en ? 'Microphone or speech-recognition permission was denied. Allow it in your browser settings, or use text input.' : 'Izin mikrofon atau pengenalan suara ditolak. Izinkan melalui pengaturan browser, atau gunakan input teks.')
                : event.error === 'audio-capture' ? (en ? 'No microphone is available. Check the input device or type your message.' : 'Mikrofon tidak tersedia. Periksa perangkat input atau ketik pesan Anda.')
                    : event.error === 'no-speech' ? (en ? 'No speech was detected. Try again, or type your message.' : 'Suara tidak terdeteksi. Coba lagi atau ketik pesan Anda.')
                        : (en ? `Browser speech recognition failed (${event.error}). You can still type your message.` : `Pengenalan suara browser gagal (${event.error}). Anda tetap dapat mengetik pesan.`);
            setError(message); setStatus('error');
        };
        active.onend = () => {
            if (recognition.current !== active) return;
            recognition.current = null;
            setInterim(''); setStatus((current) => current === 'error' ? current : 'review');
        };
        try { setStatus('listening'); active.start(); }
        catch (failure) { recognition.current = null; setStatus('error'); setError(failure.message); }
    };
    const useTranscript = async () => {
        try { await onTranscript(transcript); close(); }
        catch (failure) { setError(failure.message); }
    };
    const label = en ? 'Voice input' : 'Input suara';
    const unavailable = en ? 'This browser does not provide speech recognition. Type your message instead.' : 'Browser ini tidak menyediakan pengenalan suara. Ketik pesan Anda.';

    // Unsupported browsers keep a focusable trigger that explains why voice is unavailable instead of hiding the reason.
    return <>
        <button type="button" className="cwp-voice-trigger cwp-icon-button" disabled={disabled} aria-disabled={Recognition ? undefined : true}
            aria-haspopup="dialog" onClick={() => setOpen(true)} title={Recognition ? label : unavailable} aria-label={label}><ChatIcon name="microphone" /></button>
        {open && !Recognition && <MediaActionDialog title={label} description={unavailable} closeLabel={en ? 'Close' : 'Tutup'} onClose={() => setOpen(false)} />}
        {open && Recognition && <MediaActionDialog title={label} description={en ? 'Your browser handles speech recognition and may use its own speech service. Review the transcript before adding it to your draft. Nothing is sent to the chat automatically.' : 'Browser menangani pengenalan suara dan dapat memakai layanan suaranya sendiri. Periksa transkrip sebelum menambahkannya ke draft. Tidak ada yang dikirim ke chat secara otomatis.'}
            closeLabel={en ? 'Cancel' : 'Batal'} confirmLabel={en ? 'Add to draft' : 'Tambahkan ke draft'} busyLabel={en ? 'Listening…' : 'Mendengarkan…'} confirmDisabled={recording || !transcript.trim() || disabled} error={error} onClose={close} onConfirm={useTranscript}>
            <div className="cwp-voice-controls">
                {recording ? <button type="button" className="cwp-button" disabled={status === 'processing'} onClick={() => { setStatus('processing'); recognition.current?.stop(); }}><ChatIcon name="stop" />{status === 'processing' ? (en ? 'Finishing…' : 'Menyelesaikan…') : (en ? 'Stop recording' : 'Hentikan rekaman')}</button>
                    : <button type="button" className="cwp-button" onClick={start}><ChatIcon name="microphone" />{transcript ? (en ? 'Record again' : 'Rekam ulang') : (en ? 'Start recording' : 'Mulai rekaman')}</button>}
                <span role="status">{status === 'listening' ? (en ? 'Microphone active' : 'Mikrofon aktif') : status === 'review' ? (en ? 'Review your transcript' : 'Periksa transkrip Anda') : ''}</span>
            </div>
            <label htmlFor={transcriptId} className="cwp-dialog-label">{en ? 'Transcript' : 'Transkrip'}<textarea id={transcriptId} className="cwp-dialog-input" rows={5} value={transcript} disabled={recording} onChange={(event) => setTranscript(event.target.value)} dir="auto" /></label>
            {interim && <p className="cwp-voice-interim">{interim}</p>}
        </MediaActionDialog>}
    </>;
}

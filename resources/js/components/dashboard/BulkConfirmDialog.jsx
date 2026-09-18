import { useEffect, useId, useRef } from "react";
import { createPortal } from "react-dom";
import { useLocale } from "../../contexts/LocaleContext";

export default function BulkConfirmDialog({ title, description, count, rows = [], destructive = false, busy, onConfirm, onCancel }) {
    const { t } = useLocale();
    const ref = useRef(null);
    const titleId = useId();
    useEffect(() => {
        ref.current.showModal();
    }, []);

    return createPortal(
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            onCancel={(event) => { event.preventDefault(); if (!busy) onCancel(); }}
            className="m-auto w-[calc(100%_-_2rem)] max-w-lg rounded-2xl border border-slate-200 bg-white p-5 text-slate-900 backdrop:bg-slate-950/55 dark:border-white/10 dark:bg-slate-900 dark:text-white"
        >
            <h2 id={titleId} className="text-base font-bold">{title} ({count})</h2>
            <p className="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">{description}</p>
            {rows.length > 0 && <ul className="my-4 max-h-48 space-y-1 overflow-y-auto rounded-lg bg-slate-50 p-3 text-xs dark:bg-white/5">
                {rows.map((row) => <li key={row.id} className="break-words"><span className="font-mono">#{row.id}</span> {row.label}</li>)}
            </ul>}
            <div className="mt-5 flex flex-wrap justify-end gap-2">
                <button type="button" className="ui-btn-secondary" disabled={busy} onClick={onCancel} autoFocus>{t("Batal")}</button>
                <button type="button" className="ui-btn-primary" disabled={busy} onClick={onConfirm}>
                    {busy ? t("Memproses…") : `${destructive ? t("Hapus") : t("Simpan")} ${count} ${t("baris")}`}
                </button>
            </div>
        </dialog>,
        document.body,
    );
}

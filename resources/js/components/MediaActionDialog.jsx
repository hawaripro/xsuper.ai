import { useEffect, useId, useRef } from "react";
import { createPortal } from "react-dom";

export default function MediaActionDialog({ title, description, children, closeLabel, confirmLabel, busyLabel, busy = false, confirmDisabled = false, error, onConfirm, onClose }) {
    const ref = useRef(null);
    const closeButton = useRef(null);
    const hasConfirmation = Boolean(onConfirm);
    const titleId = useId();
    const descriptionId = useId();

    useEffect(() => {
        const element = ref.current;
        const previousFocus = document.activeElement;
        element.showModal();
        return () => {
            element.close();
            if (previousFocus instanceof HTMLElement && previousFocus.isConnected) previousFocus.focus({ preventScroll: true });
        };
    }, []);

    useEffect(() => {
        if (!busy && !hasConfirmation) closeButton.current?.focus({ preventScroll: true });
    }, [busy, hasConfirmation]);

    return createPortal(
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            aria-describedby={descriptionId}
            onCancel={(event) => { event.preventDefault(); event.stopPropagation(); if (!busy) onClose(); }}
            className="m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%_-_2rem)] max-w-lg overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 text-slate-900 backdrop:bg-slate-950/55 dark:border-white/10 dark:bg-slate-900 dark:text-white"
        >
            <h2 id={titleId} className="text-lg font-semibold">{title}</h2>
            <p id={descriptionId} aria-live="polite" className="mt-3 text-base leading-6 text-slate-700 dark:text-slate-200">{description}</p>
            {children && <div className="mt-4">{children}</div>}
            {error && <p role="alert" className="mt-4 text-sm leading-6 text-red-700 dark:text-red-300">{error}</p>}
            <div className="mt-5 flex flex-wrap justify-end gap-2">
                <button ref={closeButton} type="button" autoFocus disabled={busy} onClick={onClose} className="ui-btn-secondary min-h-11">{closeLabel}</button>
                {onConfirm && <button type="button" disabled={busy || confirmDisabled} aria-busy={busy} onClick={onConfirm} className="ui-btn-primary min-h-11">{busy ? busyLabel : confirmLabel}</button>}
            </div>
        </dialog>,
        document.body,
    );
}

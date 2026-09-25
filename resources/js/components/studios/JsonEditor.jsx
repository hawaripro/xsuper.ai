import { useMemo } from "react";
import { useLocale } from "../../contexts/LocaleContext";
import { StudioButton, StudioIcon, mediaError } from "./StudioUI";
import { jsonDraftText, parseJsonDraft } from "./studioForm";

// JSON view of the request inputs. Until edited it mirrors the form; edits are parsed and validated with
// the capability's schema, and only a valid object is ever applied (missing required fields may remain:
// the form flags them before anything is sent).
export default function JsonEditor({ capability, hidden, values, text, dirty, disabled, onEdit, onApply, onDiscard, onCopied }) {
    const { t } = useLocale();
    const current = useMemo(() => jsonDraftText(values, capability, hidden), [values, capability, hidden]);
    const shown = dirty ? text : current;
    const result = useMemo(() => dirty ? parseJsonDraft(text, capability, { hidden, values }) : null, [dirty, text, capability, hidden, values]);
    const blocking = Object.entries(result?.blocking || {});
    const incomplete = Object.entries(result?.incomplete || {});
    const where = result?.syntax?.line ? ` (${t("baris")} ${result.syntax.line}, ${t("kolom")} ${result.syntax.column})` : "";
    const status = !dirty ? "Sinkron dengan form. Ubah JSON lalu terapkan."
        : result.syntax ? null
            : !result.ok ? "Perbaiki isian berikut sebelum menerapkan."
                : incomplete.length ? "JSON valid. Isian wajib yang masih kosong akan ditandai di form." : "JSON valid dan siap diterapkan.";
    const copy = async () => {
        try { await navigator.clipboard.writeText(shown); onCopied?.("JSON disalin."); } catch { onCopied?.("Browser tidak mengizinkan menyalin. Salin manual dari kotak JSON."); }
    };
    const errorRow = ([path, message], tone) => <li key={`${tone}:${path}`} className={`sw-json-${tone}`}><code dir="ltr">{path || "(root)"}</code><span>{t(mediaError(message))}</span></li>;
    return <div className="sw-json">
        <div className="sw-json-head">
            <label htmlFor="sw-json-input">{t("Input JSON")}</label>
            <StudioButton icon="copy" className="sw-compact" onClick={() => { void copy(); }}>{t("Salin")}</StudioButton>
        </div>
        <textarea id="sw-json-input" className="sw-json-input" value={shown} rows={18} spellCheck={false} autoCapitalize="off" autoCorrect="off" dir="ltr"
            disabled={disabled} aria-invalid={Boolean(result && !result.ok)} aria-describedby="sw-json-status" onChange={(event) => onEdit(event.target.value)} />
        <div id="sw-json-status" className={`sw-json-status${result && !result.ok ? " is-invalid" : ""}`} role="status">
            <StudioIcon name={result && !result.ok ? "info" : "check"} />
            <span>{result?.syntax ? `${t("JSON tidak valid")}${where}. ${t("Perubahan belum diterapkan.")}` : t(status)}</span>
        </div>
        {(blocking.length > 0 || incomplete.length > 0) && <ul className="sw-json-errors" aria-label={t("Hasil validasi JSON")}>
            {blocking.slice(0, 12).map((entry) => errorRow(entry, "blocking"))}
            {incomplete.slice(0, 12).map((entry) => errorRow(entry, "incomplete"))}
        </ul>}
        <div className="sw-json-actions">
            <StudioButton primary disabled={disabled || !dirty || !result?.ok} onClick={onApply}>{t("Terapkan JSON")}</StudioButton>
            <StudioButton disabled={disabled || !dirty} onClick={onDiscard}>{t("Buang perubahan")}</StudioButton>
        </div>
    </div>;
}

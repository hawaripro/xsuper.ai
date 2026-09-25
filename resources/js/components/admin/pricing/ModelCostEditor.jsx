import { useState } from "react";
import { useLocale } from "../../../contexts/LocaleContext";
import { apiRequest } from "../../../lib/api";
import MediaActionDialog from "../../MediaActionDialog";

const tokenFields = [["input_per_million", "Input / 1M tokens"], ["output_per_million", "Output / 1M tokens"], ["cache_read_per_million", "Cache read / 1M tokens"], ["cache_write_per_million", "Cache write / 1M tokens"]];

export default function ModelCostEditor({ row, onSaved, onClose }) {
    const { t } = useLocale();
    const fields = row.kind === "llm" ? tokenFields : [["unit_cost", "Provider cost per sale unit"]];
    const [values, setValues] = useState(Object.fromEntries(fields.map(([key]) => [key, row.cost?.[key] ?? ""])));
    const [state, setState] = useState({ busy: false, error: "" });
    const save = async () => {
        setState({ busy: true, error: "" });
        try {
            const body = { currency: row.currency, ...Object.fromEntries(fields.map(([key]) => [key, values[key] === "" ? null : Number(values[key])])), ...(row.kind === "media" ? { unit: row.price_unit } : {}) };
            await apiRequest(`/api/admin/pricing/auto/models/${row.id}`, { method: "PUT", body });
            await onSaved();
            onClose();
        } catch (error) { setState({ busy: false, error: error.message }); }
    };
    const reset = async () => {
        setState({ busy: true, error: "" });
        try {
            await apiRequest(`/api/admin/pricing/auto/models/${row.id}/cost`, { method: "DELETE" });
            await onSaved();
            onClose();
        } catch (error) { setState({ busy: false, error: error.message }); }
    };
    return <MediaActionDialog title={`${t("Manual cost")} · ${row.display_name}`} description={t("Manual costs are never overwritten by a refresh. Saving a cost does not apply sale prices.")}
        onClose={onClose} onConfirm={save} closeLabel={t("Cancel")} confirmLabel={t("Save manual cost")} busyLabel={t("Saving…")} busy={state.busy} error={state.error}>
        <p className="mb-3 text-sm">{t("Billing currency")}: {row.currency?.toUpperCase()} · {row.price_unit}</p>
        <div className="grid gap-3 sm:grid-cols-2">{fields.map(([key, label]) => <label key={key} className="text-sm">{t(label)}
            <input className="ui-input mt-1 min-h-10" type="number" min="0.0000000001" step="any" value={values[key]} disabled={state.busy}
                onChange={(event) => setValues((current) => ({ ...current, [key]: event.target.value }))} />
        </label>)}</div>
        {row.kind === "llm" && <p className="mt-3 text-xs text-slate-600 dark:text-slate-400">{t("Input and output must be positive. Leave unknown cache costs blank.")}</p>}
        {row.source === "manual" && <button type="button" className="ui-btn-secondary mt-4 min-h-10" onClick={reset} disabled={state.busy}>{t("Reset to automatic source")}</button>}
    </MediaActionDialog>;
}

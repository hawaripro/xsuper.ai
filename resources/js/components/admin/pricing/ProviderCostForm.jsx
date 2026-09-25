import { useId, useState } from "react";
import { useLocale } from "../../../contexts/LocaleContext";
import { apiRequest } from "../../../lib/api";

export default function ProviderCostForm({ provider, onSaved }) {
    const { t } = useLocale();
    const id = useId();
    const [draft, setDraft] = useState({ currency: provider.cost_currency || "usd", rate: provider.cost_idr_per_unit ?? "", note: provider.cost_note ?? "", paid: "", units: "" });
    const [calculator, setCalculator] = useState(false);
    const [state, setState] = useState({ busy: false, error: "", saved: false });
    const patch = (key) => (event) => setDraft((current) => ({ ...current, [key]: event.target.value }));
    const calculated = Number(draft.units) > 0 && Number(draft.paid) > 0 ? Number(draft.paid) / Number(draft.units) : null;
    const save = async (event) => {
        event.preventDefault();
        setState({ busy: true, error: "", saved: false });
        try {
            const body = { cost_currency: draft.currency, cost_note: draft.note || null,
                ...(calculator ? { paid_idr: Number(draft.paid), units_received: Number(draft.units) } : { cost_idr_per_unit: draft.rate === "" ? null : Number(draft.rate) }) };
            const data = await apiRequest(`/api/admin/pricing/auto/providers/${provider.id}`, { method: "PUT", body });
            setDraft((current) => ({ ...current, rate: data.provider.cost_idr_per_unit ?? "" }));
            await onSaved?.(data.provider);
            setState({ busy: false, error: "", saved: true });
        } catch (error) {
            setState({ busy: false, error: error.message, saved: false });
        }
    };
    return <form onSubmit={save} className="space-y-3">
        <div className="grid gap-3 sm:grid-cols-2">
            <label className="text-sm" htmlFor={`${id}-currency`}>{t("Billing currency")}
                <select id={`${id}-currency`} className="ui-input mt-1 min-h-10" value={draft.currency} onChange={patch("currency")} disabled={state.busy}>
                    <option value="usd">USD</option><option value="credit">{t("Credits")}</option>
                </select>
            </label>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={calculator} onChange={(event) => setCalculator(event.target.checked)} disabled={state.busy} />{t("Calculate from a top-up")}</label>
            {calculator ? <>
                <label className="text-sm" htmlFor={`${id}-paid`}>{t("Paid (IDR)")}<input id={`${id}-paid`} className="ui-input mt-1 min-h-10" type="number" min="0.0001" step="any" required value={draft.paid} onChange={patch("paid")} disabled={state.busy} /></label>
                <label className="text-sm" htmlFor={`${id}-units`}>{t("Units received")} ({draft.currency === "usd" ? "$" : t("Credits")})<input id={`${id}-units`} className="ui-input mt-1 min-h-10" type="number" min="0.0001" step="any" required value={draft.units} onChange={patch("units")} disabled={state.busy} /></label>
                <p className="text-sm tabular-nums sm:col-span-2">{t("Calculated cost (IDR per unit)")}: {calculated == null ? "—" : calculated.toFixed(4)}</p>
            </> : <label className="text-sm sm:col-span-2" htmlFor={`${id}-rate`}>{t("Landed cost (IDR per unit)")}<input id={`${id}-rate`} className="ui-input mt-1 min-h-10" type="number" min="0.0001" step="0.0001" value={draft.rate} onChange={patch("rate")} disabled={state.busy} /><span className="mt-1 block text-xs text-slate-600 dark:text-slate-400">{t("Blank USD cost follows the default exchange rate. Blank credit cost is unknown.")}</span></label>}
            <label className="text-sm sm:col-span-2" htmlFor={`${id}-note`}>{t("Cost note")}<input id={`${id}-note`} className="ui-input mt-1 min-h-10" maxLength={255} value={draft.note} onChange={patch("note")} disabled={state.busy} /></label>
        </div>
        {state.error && <p role="alert" className="ui-alert" data-tone="bad">{state.error}</p>}
        {state.saved && <p role="status" className="text-sm text-emerald-700 dark:text-emerald-300">{t("Cost saved. Preview prices before applying.")}</p>}
        <button className="ui-btn-secondary min-h-10" type="submit" disabled={state.busy}>{t(state.busy ? "Saving…" : "Save provider cost")}</button>
    </form>;
}

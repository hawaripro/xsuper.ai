import { useMemo, useState } from "react";
import { useLocale } from "../../contexts/LocaleContext";

const money = (value, digits = 4) => (value === null || value === undefined || value === "")
    ? "—"
    : Number(value).toLocaleString("en-US", { minimumFractionDigits: 0, maximumFractionDigits: digits });

/**
 * The editable table below lists one row per meter, so a model's input and
 * output prices sit far apart and are easy to half-fill. Billing needs BOTH
 * active rows (UsageBillingService::apiRates), and a half-priced model fails
 * every request with no visible cause — so pair them up here and flag the gap.
 */
export default function RatePairSummary({ rates = [], onFocusModel }) {
    const { t, locale } = useLocale();
    const [onlyIncomplete, setOnlyIncomplete] = useState(false);
    const [query, setQuery] = useState("");

    const pairs = useMemo(() => {
        const byModel = new Map();
        rates.filter((rate) => rate.service === "api").forEach((rate) => {
            if (!byModel.has(rate.model)) byModel.set(rate.model, { model: rate.model, meters: {} });
            byModel.get(rate.model).meters[rate.meter] = rate;
        });
        return [...byModel.values()].map((entry) => {
            const input = entry.meters.input_tokens || null;
            const output = entry.meters.output_tokens || null;
            const activeInput = !!input?.is_active && Number(input?.price_usd) > 0;
            const activeOutput = !!output?.is_active && Number(output?.price_usd) > 0;
            return {
                ...entry,
                input,
                output,
                billable: activeInput && activeOutput,
                // Exactly one side priced: the dangerous state.
                lopsided: activeInput !== activeOutput,
                ratio: activeInput && activeOutput
                    ? Number(output.price_usd) / Number(input.price_usd)
                    : null,
            };
        }).sort((a, b) => Number(b.lopsided) - Number(a.lopsided) || a.model.localeCompare(b.model));
    }, [rates]);

    const visible = pairs.filter((pair) => {
        if (onlyIncomplete && pair.billable) return false;
        if (!query.trim()) return true;
        return pair.model.toLowerCase().includes(query.trim().toLowerCase());
    });

    const broken = pairs.filter((pair) => !pair.billable).length;

    return (
        <div className="rp-wrap">
            <div className="rp-bar">
                <label className="rp-search">
                    {t("Cari model")}
                    <input className="ui-input mt-1" type="search" value={query} placeholder={t("ID model")}
                        onChange={(event) => setQuery(event.target.value)} />
                </label>
                <label className="rp-check">
                    <input type="checkbox" checked={onlyIncomplete} onChange={(event) => setOnlyIncomplete(event.target.checked)} />
                    {t("Hanya yang belum bisa ditagih")}
                </label>
                <span className="rp-count" data-warn={broken > 0 ? "" : undefined}>
                    {Number(broken).toLocaleString(locale)} {t("dari")} {Number(pairs.length).toLocaleString(locale)} {t("model belum siap ditagih")}
                </span>
            </div>
            <div className="max-w-full overflow-x-auto">
                <table className="w-full text-left text-xs rp-table">
                    <thead>
                        <tr>
                            <th>{t("Model")}</th>
                            <th>{t("Input")} <small>USD / 1M</small></th>
                            <th>{t("Output")} <small>USD / 1M</small></th>
                            <th>{t("Input")} <small>IDR</small></th>
                            <th>{t("Output")} <small>IDR</small></th>
                            <th>{t("Rasio out/in")}</th>
                            <th>{t("Status tagih")}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {visible.map((pair) => (
                            <tr key={pair.model} data-broken={pair.billable ? undefined : ""}>
                                <td>
                                    <button type="button" className="rp-model" onClick={() => onFocusModel?.(pair.model)}>
                                        {pair.model}
                                    </button>
                                </td>
                                <td className="tabular-nums">{money(pair.input?.price_usd, 6)}</td>
                                <td className="tabular-nums">{money(pair.output?.price_usd, 6)}</td>
                                <td className="tabular-nums">{money(pair.input?.price_idr, 0)}</td>
                                <td className="tabular-nums">{money(pair.output?.price_idr, 0)}</td>
                                <td className="tabular-nums">{pair.ratio === null ? "—" : `${pair.ratio.toFixed(1)}×`}</td>
                                <td>
                                    {pair.billable
                                        ? <span className="ui-status" data-tone="good">{t("Siap")}</span>
                                        : pair.lopsided
                                            ? <span className="ui-status" data-tone="bad">{t("Hanya satu sisi berharga")}</span>
                                            : <span className="ui-status" data-tone="warn">{t("Belum berharga")}</span>}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {!visible.length && <p className="p-5 text-xs text-slate-500 dark:text-slate-400">{t("Tidak ada model yang cocok.")}</p>}
            </div>
        </div>
    );
}

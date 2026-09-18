import { useCallback, useEffect, useRef, useState } from "react";
import { useLocale } from "../../contexts/LocaleContext";
import { apiRequest } from "../../lib/api";
import { ErrorState, LoadingState } from "./AsyncState";

export default function TokenPackageTable() {
    const { t } = useLocale();
    const [packages, setPackages] = useState(null);
    const [drafts, setDrafts] = useState({});
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState("");
    const [busy, setBusy] = useState(null);
    const [errors, setErrors] = useState({});
    const [status, setStatus] = useState({ error: "", success: "" });
    const requestId = useRef(0);
    const load = useCallback(async (signal) => {
        const id = ++requestId.current;
        try {
            const data = await apiRequest("/api/admin/token-packages", { signal });
            if (!signal?.aborted && id === requestId.current) { setPackages(data.token_packages); setLoadError(""); }
        } catch (error) {
            if (error.name !== "AbortError" && id === requestId.current) setLoadError(error.message);
        } finally { if (!signal?.aborted && id === requestId.current) setLoading(false); }
    }, []);
    useEffect(() => {
        const controller = new AbortController();
        load(controller.signal);
        return () => controller.abort();
    }, [load]);
    const patch = (id, key, value) => {
        setDrafts((current) => ({ ...current, [id]: { ...current[id], [key]: value } }));
        setErrors((current) => ({ ...current, [id]: { ...current[id], [key]: undefined } }));
    };
    const save = async (row) => {
        const changes = drafts[row.id];
        const payload = Object.fromEntries(Object.entries(changes).map(([key, value]) => [key, ["base_tokens", "bonus_tokens", "price_idr", "sort_order"].includes(key) ? (value === "" ? null : Number(value)) : value]));
        const values = { ...row, ...payload };
        const fields = {};
        for (const [key, min, max] of [["base_tokens", 1, 2147483647], ["bonus_tokens", 0, 2147483647], ["price_idr", 1, 4294967295], ["sort_order", 0, 65535]]) {
            if (values[key] == null || !Number.isInteger(Number(values[key])) || Number(values[key]) < min || Number(values[key]) > max) fields[key] = t("Masukkan bilangan bulat dalam batas yang ditampilkan.");
        }
        if (!values.name.trim() || values.name.length > 80) fields.name = t("Nama paket wajib diisi, maksimal 80 karakter.");
        if (Number(values.base_tokens) + Number(values.bonus_tokens) > 2147483647) fields.bonus_tokens = t("Total token melebihi batas paket.");
        if (Object.keys(fields).length) { setErrors((current) => ({ ...current, [row.id]: fields })); return; }
        setBusy(row.id);
        setStatus({ error: "", success: "" });
        try {
            const result = await apiRequest(`/api/admin/token-packages/${row.id}`, { method: "PATCH", body: payload });
            setPackages((current) => current.map((item) => item.id === row.id ? result.token_package : item));
            setDrafts((current) => Object.fromEntries(Object.entries(current).filter(([id]) => Number(id) !== row.id)));
            setErrors((current) => ({ ...current, [row.id]: {} }));
            setStatus({ error: "", success: `${t("Paket token tersimpan")}: ${result.token_package.name}.` });
        } catch (error) {
            setErrors((current) => ({ ...current, [row.id]: error.details?.errors || {} }));
            setStatus({ error: error.message, success: "" });
        } finally { setBusy(null); }
    };
    const fieldError = (id, field) => errors[id]?.[field] && <span className="mt-1 block text-xs text-red-600 dark:text-red-300">{[errors[id][field]].flat()[0]}</span>;
    const fields = [["base_tokens", "Token dasar", 1, 2147483647], ["bonus_tokens", "Bonus token", 0, 2147483647], ["price_idr", "Harga IDR", 1, 4294967295], ["sort_order", "Urutan", 0, 65535]];

    return <section className="ui-card-flat min-w-0 [&_button:disabled]:cursor-not-allowed [&_button:disabled]:opacity-50" aria-labelledby="token-packages-title">
        <div className="ui-card-header"><div><h2 id="token-packages-title" className="ui-section-title">{t("Paket token Deposit")}</h2><p className="mt-1 text-xs leading-5 text-slate-600 dark:text-slate-400">{t("Token dasar + bonus = total kredit. Harga dan isi paket tersimpan untuk checkout baru; pesanan yang sudah dibuat tidak berubah.")}</p></div></div>
        {loadError && <div className="p-4"><ErrorState message={loadError} onRetry={() => load()} /></div>}
        {loading && !packages ? <div className="p-4"><LoadingState label={t("Memuat paket token…")} /></div> : packages && <>
            <div className="max-w-full overflow-x-auto"><table className="w-full text-left text-xs">
                <thead className="bg-slate-50 text-slate-600 dark:bg-white/5 dark:text-slate-400"><tr><th className="p-3">{t("Paket")}</th>{fields.map(([key, label]) => <th className="p-3" key={key}>{t(label)}</th>)}<th className="p-3">{t("Total kredit")}</th><th className="p-3">{t("Aktif")}</th><th className="p-3">{t("Tindakan")}</th></tr></thead>
                <tbody>{packages.map((original) => {
                    const row = { ...original, ...drafts[original.id] };
                    const total = Number(row.base_tokens) + Number(row.bonus_tokens);
                    return <tr key={row.id} className="border-t border-slate-200 align-top dark:border-white/10">
                        <td className="min-w-48 p-3"><label className="block"><span className="sr-only">{t("Nama paket")} {row.code}</span><input className="ui-input min-h-10" value={row.name} maxLength={80} disabled={busy !== null} aria-invalid={!!errors[row.id]?.name} onChange={(event) => patch(row.id, "name", event.target.value)} /></label><span className="mt-1 block font-mono text-slate-500 dark:text-slate-400">{row.code}</span>{fieldError(row.id, "name")}</td>
                        {fields.map(([key, label, min, max]) => <td className="p-3" key={key}><input className="ui-input min-h-10 min-w-28 tabular-nums" aria-label={`${t(label)} ${row.code}`} type="number" min={min} max={max} step="1" value={row[key]} disabled={busy !== null} aria-invalid={!!errors[row.id]?.[key]} onChange={(event) => patch(row.id, key, event.target.value)} />{fieldError(row.id, key)}</td>)}
                        <td className="min-w-28 p-3 pt-6 font-semibold tabular-nums">{Number.isFinite(total) && row.base_tokens !== "" && row.bonus_tokens !== "" ? total.toLocaleString() : "—"}</td>
                        <td className="p-3"><label className="flex min-h-10 items-center gap-2"><input type="checkbox" aria-label={`${t("Aktif")} ${row.code}`} checked={!!row.is_active} disabled={busy !== null} onChange={(event) => patch(row.id, "is_active", event.target.checked)} />{t(row.is_active ? "Aktif" : "Nonaktif")}</label>{fieldError(row.id, "is_active")}</td>
                        <td className="min-w-40 p-3"><div className="flex flex-wrap gap-2"><button type="button" className="ui-btn-primary" disabled={busy !== null || !drafts[row.id]} onClick={() => save(original)}>{busy === row.id ? t("Menyimpan…") : t("Simpan paket token")}</button>{drafts[row.id] && <button type="button" className="ui-btn-secondary" disabled={busy !== null} onClick={() => setDrafts((current) => Object.fromEntries(Object.entries(current).filter(([id]) => Number(id) !== row.id)))}>{t("Buang draf")}</button>}</div></td>
                    </tr>;
                })}</tbody>
            </table></div>
            {!packages.length && <p className="p-4 text-sm text-slate-600 dark:text-slate-400">{t("Belum ada paket token. Import paket yang disetujui sebelum membuka Deposit.")}</p>}
        </>}
        {(status.error || status.success) && <p role={status.error ? "alert" : "status"} className={`p-4 text-sm ${status.error ? "text-red-700 dark:text-red-300" : "text-emerald-700 dark:text-emerald-300"}`}>{status.error || status.success}</p>}
    </section>;
}

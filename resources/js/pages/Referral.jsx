import React, { useCallback, useEffect, useState } from "react";
import { apiRequest } from "../lib/api";
import {
    Button,
    InlineAlert,
    MemberPage,
    Metric,
    PageHeader,
    Panel,
    SectionHeader,
    Spinner,
    StatePanel,
    StatusBadge,
    errorMessage,
    formatCount,
    formatLocalDate,
} from "../components/member/MemberUI";
import { useLocale } from "../contexts/LocaleContext";

export default function Referral() {
    const { t } = useLocale();
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [copyState, setCopyState] = useState("idle");
    const [copyError, setCopyError] = useState(null);

    const load = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            setData(await apiRequest("/api/referrals/me"));
        } catch (requestError) {
            setError(requestError);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    const copyLink = async () => {
        if (!data?.referral?.link) return;
        setCopyError(null);
        try {
            await navigator.clipboard.writeText(data.referral.link);
            setCopyState("copied");
        } catch (clipboardError) {
            setCopyState("idle");
            setCopyError(new Error(t("Browser menolak akses clipboard. Pilih link lalu salin secara manual.")));
        }
    };

    return (
        <MemberPage>
            <PageHeader
                eyebrow={t("Program anggota")}
                title={t("Referral")}
                description={t("Bagikan link resmi Anda dan pantau atribusi serta bonus yang benar-benar tercatat.")}
                actions={<Button variant="secondary" onClick={load} disabled={loading}>{loading ? t("Memuat…") : t("Muat ulang")}</Button>}
            />

            {loading ? (
                <Panel className="flex min-h-56 items-center justify-center"><Spinner label={t("Memuat program referral")} /></Panel>
            ) : error ? (
                <Panel className="p-4"><StatePanel type="error" title={t("Referral tidak dapat dimuat")} description={errorMessage(error)} action={<Button variant="secondary" onClick={load}>{t("Coba lagi")}</Button>} /></Panel>
            ) : !data?.program?.enabled ? (
                <Panel className="p-4"><StatePanel type="error" title={t("Program referral sedang tidak aktif")} description={t("Link dan bonus referral tidak dapat digunakan sampai program diaktifkan kembali oleh pengelola.")} action={<Button variant="secondary" onClick={load}>{t("Periksa lagi")}</Button>} /></Panel>
            ) : (
                <>
                    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <Metric label={t("Undangan")} value={formatCount(data.stats?.invited)} detail={t("Pendaftaran via link")} />
                        <Metric label={t("Teratribusi")} value={formatCount(data.stats?.attributed)} detail={t("Menunggu kualifikasi")} />
                        <Metric label={t("Terkualifikasi")} value={formatCount(data.stats?.qualified)} detail={t("Referral yang memenuhi syarat")} />
                        <Metric label={t("Bonus diperoleh")} value={`${formatCount(data.stats?.days_earned)} ${t("hari")}`} detail={`${formatCount(data.program?.reward_days)} ${t("hari per referral")}`} />
                    </div>

                    <div className="grid gap-5 lg:grid-cols-[minmax(0,1.15fr)_minmax(280px,.85fr)]">
                        <Panel className="p-4">
                            <SectionHeader title={t("Link referral Anda")} description={t("Kode ini dibuat backend dan terhubung langsung ke akun Anda.")} />
                            <div className="mt-4 flex flex-col gap-2 sm:flex-row">
                                <input
                                    aria-label={t("Link referral")}
                                    readOnly
                                    value={data.referral?.link || ""}
                                    onFocus={(event) => event.target.select()}
                                    className="h-10 min-w-0 flex-1 rounded-lg border border-slate-300 bg-slate-50 px-3 font-mono text-[12px] text-slate-800 outline-none focus:border-red-400 focus:ring-2 focus:ring-red-500/15 dark:border-white/10 dark:bg-white/[0.035] dark:text-slate-200"
                                />
                                <Button onClick={copyLink} disabled={!data.referral?.link}>{copyState === "copied" ? t("Tersalin") : t("Salin link")}</Button>
                            </div>
                            <div className="mt-3 flex flex-wrap items-center gap-2 text-[11px] text-slate-500 dark:text-slate-400">
                                <span>{t("Kode")}</span>
                                <code className="rounded bg-slate-100 px-2 py-1 font-semibold text-slate-800 dark:bg-white/[0.06] dark:text-slate-200">{data.referral?.code || "—"}</code>
                            </div>
                            {copyState === "copied" && <div className="mt-3"><InlineAlert tone="success">{t("Link referral sudah disalin ke clipboard.")}</InlineAlert></div>}
                            {copyError && <div className="mt-3"><InlineAlert tone="error">{copyError.message}</InlineAlert></div>}
                        </Panel>

                        <Panel className="p-4">
                            <SectionHeader title={t("Cara kerja")} description={t("Status backend menentukan kapan bonus tercatat.")} />
                            <ol className="mt-4 space-y-3">
                                {[
                                    ["1", t("Bagikan link"), t("Calon anggota membuka UltrAI melalui link Anda.")],
                                    ["2", t("Atribusi dicatat"), t("Pendaftaran yang valid muncul sebagai teratribusi.")],
                                    ["3", t("Syarat terpenuhi"), t("Setiap referral terkualifikasi memberi {days} hari sesuai aturan aktif.").replace("{days}", formatCount(data.program?.reward_days))],
                                ].map(([number, title, description]) => (
                                    <li key={number} className="flex gap-3">
                                        <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-red-600 text-[11px] font-bold text-white dark:bg-red-500">{number}</span>
                                        <div><p className="text-[12px] font-semibold text-slate-900 dark:text-white">{title}</p><p className="mt-0.5 text-[11px] leading-4 text-slate-500 dark:text-slate-400">{description}</p></div>
                                    </li>
                                ))}
                            </ol>
                        </Panel>
                    </div>

                    <Panel className="overflow-hidden">
                        <div className="border-b border-slate-200 p-4 dark:border-white/[0.08]"><SectionHeader title={t("Referral terbaru")} description={t("Maksimal 20 pendaftaran terbaru dari link Anda.")} /></div>
                        {!Array.isArray(data.recent_referrals) || data.recent_referrals.length === 0 ? (
                            <div className="p-4"><StatePanel title={t("Belum ada referral")} description={t("Bagikan link Anda; pendaftaran yang teratribusi akan tampil di sini.")} compact /></div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[560px] text-left">
                                    <thead className="bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500 dark:bg-white/[0.025] dark:text-slate-400"><tr><th className="px-4 py-2.5 font-semibold">{t("Anggota")}</th><th className="px-4 py-2.5 font-semibold">{t("Status")}</th><th className="px-4 py-2.5 font-semibold">{t("Teratribusi")}</th><th className="px-4 py-2.5 font-semibold">{t("Terkualifikasi")}</th></tr></thead>
                                    <tbody className="divide-y divide-slate-100 dark:divide-white/[0.06]">
                                        {data.recent_referrals.map((referral) => (
                                            <tr key={referral.id} className="text-[12px] text-slate-600 dark:text-slate-300">
                                                <td className="px-4 py-3 font-medium text-slate-900 dark:text-white">{referral.name || t("Anggota")}</td>
                                                <td className="px-4 py-3"><StatusBadge value={referral.status} /></td>
                                                <td className="px-4 py-3">{formatLocalDate(referral.attributed_at)}</td>
                                                <td className="px-4 py-3">{referral.qualified_at ? formatLocalDate(referral.qualified_at) : "—"}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Panel>
                </>
            )}
        </MemberPage>
    );
}

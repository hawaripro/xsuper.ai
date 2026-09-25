import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { useLocale } from "../../../contexts/LocaleContext";
import { apiRequest } from "../../../lib/api";
import { LoadingState } from "../../dashboard/AsyncState";
import ProviderCostForm from "./ProviderCostForm";

export default function ProviderCostCard({ providerId }) {
    const { t, localizedPath } = useLocale();
    const [provider, setProvider] = useState(null);
    const [error, setError] = useState("");
    const [attempt, setAttempt] = useState(0);
    useEffect(() => {
        const controller = new AbortController();
        apiRequest("/api/admin/pricing/auto", { signal: controller.signal }).then((result) => {
            if (!controller.signal.aborted) {
                setProvider(result.providers.find((entry) => entry.id === Number(providerId)) ?? null);
                setError("");
            }
        }).catch((failure) => { if (failure.name !== "AbortError") setError(failure.message); });
        return () => controller.abort();
    }, [providerId, attempt]);
    return <section className="ui-card-flat p-4">
        <h2 className="ui-section-title">{t("Provider landed cost")}</h2>
        <p className="mb-4 mt-2 max-w-prose text-sm text-slate-600 dark:text-slate-400">{t("Record the actual cost of a provider top-up. Sale margins are managed centrally.")}</p>
        {error ? <div><p role="alert" className="ui-alert" data-tone="bad">{error}</p><button type="button" className="ui-btn-secondary mt-3" onClick={() => setAttempt((value) => value + 1)}>{t("Retry")}</button></div>
            : provider ? <ProviderCostForm key={provider.id} provider={provider} onSaved={setProvider} /> : <LoadingState label={t("Loading provider costs…")} />}
        <Link className="ui-btn-secondary mt-4 inline-flex min-h-10" to={localizedPath("/admin/settings")}>{t("Open automatic pricing")}</Link>
    </section>;
}

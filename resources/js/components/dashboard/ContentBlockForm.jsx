import { useLocale } from "../../contexts/LocaleContext";

const SURFACES = ["dashboard", "landing", "pricing", "models"];
const SURFACE_LABELS = { dashboard: "Dashboard", landing: "Public landing", pricing: "Pricing", models: "Models" };

/**
 * Structured editor for a versioned CMS content block. Each approved key renders
 * plain form fields instead of raw JSON; the shape mirrors the server payload
 * rules in ContentController::payloadRules so a valid form always saves cleanly.
 */
export default function ContentBlockForm({ contentKey, draft, onChange, fieldErrors = {} }) {
    const { t } = useLocale();
    const value = draft && typeof draft === "object" ? draft : {};
    const errorFor = (path) => fieldErrors[`draft.${path}`];
    const patch = (next) => onChange({ ...value, ...next });

    const labelClass = "mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-200";
    const helpClass = "mt-1 text-[11px] leading-4 text-slate-500 dark:text-slate-400";
    const errClass = "mt-1 block text-[11px] text-red-600 dark:text-red-400";

    const TextField = ({ label, path, get, set, textarea, maxLength, placeholder, help, required }) => {
        const err = errorFor(path);
        return (
            <label className="block">
                <span className={labelClass}>{label}{required && <span className="text-red-500"> *</span>}</span>
                {textarea ? (
                    <textarea className="ui-input min-h-24 resize-y" maxLength={maxLength} placeholder={placeholder} value={get() ?? ""} onChange={(e) => set(e.target.value)} aria-invalid={!!err} />
                ) : (
                    <input className="ui-input min-h-10" maxLength={maxLength} placeholder={placeholder} value={get() ?? ""} onChange={(e) => set(e.target.value)} aria-invalid={!!err} />
                )}
                {help && <span className={helpClass}>{help}</span>}
                {err && <span className={errClass}>{err}</span>}
            </label>
        );
    };

    // Optional { label, url } action block shared by hero + announcement.
    const ActionEditor = ({ field }) => {
        const action = value[field];
        const enabled = action && typeof action === "object";
        return (
            <div className="rounded-xl border border-slate-200 p-3 dark:border-white/10">
                <label className="flex min-h-9 items-center gap-2 text-xs font-semibold text-slate-700 dark:text-slate-200">
                    <input type="checkbox" className="h-4 w-4 rounded border-slate-300 accent-red-600" checked={enabled}
                        onChange={(e) => patch({ [field]: e.target.checked ? { label: "", url: "" } : undefined })} />
                    {t("Action button (optional)")}
                </label>
                {enabled && (
                    <div className="mt-3 grid gap-3 sm:grid-cols-2">
                        <TextField label={t("Button label")} path={`${field}.label`} maxLength={80} required
                            get={() => action.label} set={(v) => patch({ [field]: { ...action, label: v } })} />
                        <TextField label={t("Button URL")} path={`${field}.url`} maxLength={500} placeholder="/paket" required
                            get={() => action.url} set={(v) => patch({ [field]: { ...action, url: v } })} />
                    </div>
                )}
            </div>
        );
    };

    // Repeatable list of item objects (faq / help articles).
    const ItemList = ({ items, blank, addLabel, itemLabel, render }) => {
        const list = Array.isArray(items) ? items : [];
        const setList = (next) => patch({ items: next });
        return (
            <div className="space-y-3">
                {list.map((item, index) => (
                    <div key={index} className="rounded-xl border border-slate-200 p-3 dark:border-white/10">
                        <div className="mb-2 flex items-center justify-between">
                            <span className="text-[11px] font-bold uppercase tracking-wide text-slate-500">{itemLabel} {index + 1}</span>
                            <button type="button" className="text-[11px] font-semibold text-red-600 hover:underline dark:text-red-400"
                                onClick={() => setList(list.filter((_, i) => i !== index))}>{t("Remove")}</button>
                        </div>
                        {render(item, index, (nextItem) => setList(list.map((it, i) => (i === index ? nextItem : it))))}
                    </div>
                ))}
                <button type="button" className="ui-btn-secondary min-h-10 px-4 text-xs"
                    onClick={() => setList([...list, { ...blank }])}>+ {addLabel}</button>
            </div>
        );
    };

    if (contentKey === "home.hero") {
        return (
            <div className="space-y-4">
                <TextField label={t("Headline")} path="headline" maxLength={160} required
                    get={() => value.headline} set={(v) => patch({ headline: v })} />
                <TextField label={t("Description")} path="description" maxLength={600} textarea required
                    get={() => value.description} set={(v) => patch({ description: v })} />
                <ActionEditor field="primary_action" />
            </div>
        );
    }

    if (contentKey === "home.faq") {
        return (
            <div>
                <span className={labelClass}>{t("Question list")}</span>
                <ItemList items={value.items} blank={{ question: "", answer: "" }} addLabel={t("Add question")} itemLabel={t("Question")}
                    render={(item, index, setItem) => (
                        <div className="space-y-3">
                            <TextField label={t("Question")} path={`items.${index}.question`} maxLength={300} required
                                get={() => item.question} set={(v) => setItem({ ...item, question: v })} />
                            <TextField label={t("Answer")} path={`items.${index}.answer`} maxLength={3000} textarea required
                                get={() => item.answer} set={(v) => setItem({ ...item, answer: v })} />
                        </div>
                    )} />
            </div>
        );
    }

    if (contentKey === "system.announcement") {
        const surfaces = Array.isArray(value.surfaces) ? value.surfaces : [];
        const toggleSurface = (name, on) => patch({ surfaces: on ? [...new Set([...surfaces, name])] : surfaces.filter((s) => s !== name) });
        return (
            <div className="space-y-4">
                <TextField label={t("Message")} path="message" maxLength={500} textarea required
                    get={() => value.message} set={(v) => patch({ message: v })} />
                <label className="block">
                    <span className={labelClass}>{t("Urgency level")}</span>
                    <select className="ui-input min-h-10" value={value.level || "info"} onChange={(e) => patch({ level: e.target.value })}>
                        <option value="info">{t("Info")}</option>
                        <option value="success">{t("Success")}</option>
                        <option value="warning">{t("Warning")}</option>
                        <option value="critical">{t("Critical")}</option>
                    </select>
                </label>
                <div>
                    <span className={labelClass}>{t("Show on pages")}</span>
                    <div className="flex flex-wrap gap-3">
                        {SURFACES.map((name) => (
                            <label key={name} className="flex min-h-9 items-center gap-2 text-xs text-slate-700 dark:text-slate-200">
                                <input type="checkbox" className="h-4 w-4 rounded border-slate-300 accent-red-600"
                                    checked={surfaces.includes(name)} onChange={(e) => toggleSurface(name, e.target.checked)} />
                                {t(SURFACE_LABELS[name] || name)}
                            </label>
                        ))}
                    </div>
                    {errorFor("surfaces") && <span className={errClass}>{errorFor("surfaces")}</span>}
                </div>
                <ActionEditor field="action" />
            </div>
        );
    }

    if (contentKey === "help.articles") {
        return (
            <div>
                <span className={labelClass}>{t("Article list")}</span>
                <ItemList items={value.items} blank={{ slug: "", title: "", summary: "", body: "" }} addLabel={t("Add article")} itemLabel={t("Article")}
                    render={(item, index, setItem) => (
                        <div className="space-y-3">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <TextField label={t("Slug")} path={`items.${index}.slug`} maxLength={120} placeholder="cara-mulai" required
                                    help={t("Lowercase letters, numbers, and hyphens only.")} get={() => item.slug} set={(v) => setItem({ ...item, slug: v })} />
                                <TextField label={t("Title")} path={`items.${index}.title`} maxLength={200} required
                                    get={() => item.title} set={(v) => setItem({ ...item, title: v })} />
                            </div>
                            <TextField label={t("Summary")} path={`items.${index}.summary`} maxLength={500} textarea required
                                get={() => item.summary} set={(v) => setItem({ ...item, summary: v })} />
                            <TextField label={t("Body")} path={`items.${index}.body`} maxLength={20000} textarea required
                                get={() => item.body} set={(v) => setItem({ ...item, body: v })} />
                        </div>
                    )} />
            </div>
        );
    }

    return <p className="text-xs text-slate-500 dark:text-slate-400">{t("This block type has no structured editor yet.")}</p>;
}

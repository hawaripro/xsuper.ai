import { useLocale } from "../../contexts/LocaleContext";

const SURFACES = ["dashboard", "landing", "pricing", "models"];
const SURFACE_LABELS = { dashboard: "Dashboard", landing: "Public landing", pricing: "Pricing", models: "Models" };

const labelClass = "mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-200";
const helpClass = "mt-1 text-[11px] leading-4 text-slate-500 dark:text-slate-400";
const errClass = "mt-1 block text-[11px] text-red-600 dark:text-red-400";

// Field components live at module scope so their identity is stable across the
// parent's re-renders; defining them inside the render remounted each input on
// every keystroke, dropping focus after a single character.
function TextField({ label, value, onChange, textarea, maxLength, placeholder, help, required, error }) {
    return (
        <label className="block">
            <span className={labelClass}>{label}{required && <span className="text-red-500"> *</span>}</span>
            {textarea ? (
                <textarea className="ui-input min-h-24 resize-y" maxLength={maxLength} placeholder={placeholder} value={value ?? ""} onChange={(e) => onChange(e.target.value)} aria-invalid={!!error} />
            ) : (
                <input className="ui-input min-h-10" maxLength={maxLength} placeholder={placeholder} value={value ?? ""} onChange={(e) => onChange(e.target.value)} aria-invalid={!!error} />
            )}
            {help && <span className={helpClass}>{help}</span>}
            {error && <span className={errClass}>{error}</span>}
        </label>
    );
}

function ActionEditor({ t, field, action, onChange, errorFor }) {
    const enabled = action && typeof action === "object";
    return (
        <div className="rounded-xl border border-slate-200 p-3 dark:border-white/10">
            <label className="flex min-h-9 items-center gap-2 text-xs font-semibold text-slate-700 dark:text-slate-200">
                <input type="checkbox" className="h-4 w-4 rounded border-slate-300 accent-red-600" checked={enabled}
                    onChange={(e) => onChange(e.target.checked ? { label: "", url: "" } : undefined)} />
                {t("Action button (optional)")}
            </label>
            {enabled && (
                <div className="mt-3 grid gap-3 sm:grid-cols-2">
                    <TextField label={t("Button label")} maxLength={80} required error={errorFor(`${field}.label`)}
                        value={action.label} onChange={(v) => onChange({ ...action, label: v })} />
                    <TextField label={t("Button URL")} maxLength={500} placeholder="/paket" required error={errorFor(`${field}.url`)}
                        value={action.url} onChange={(v) => onChange({ ...action, url: v })} />
                </div>
            )}
        </div>
    );
}

function ItemList({ t, items, blank, addLabel, itemLabel, renderItem }) {
    const list = Array.isArray(items) ? items : [];
    return (
        <div className="space-y-3">
            {list.map((item, index) => (
                <div key={index} className="rounded-xl border border-slate-200 p-3 dark:border-white/10">
                    <div className="mb-2 flex items-center justify-between">
                        <span className="text-[11px] font-bold uppercase tracking-wide text-slate-500">{itemLabel} {index + 1}</span>
                        <button type="button" className="text-[11px] font-semibold text-red-600 hover:underline dark:text-red-400"
                            onClick={() => renderItem.setList(list.filter((_, i) => i !== index))}>{t("Remove")}</button>
                    </div>
                    {renderItem.render(item, index, (nextItem) => renderItem.setList(list.map((it, i) => (i === index ? nextItem : it))))}
                </div>
            ))}
            <button type="button" className="ui-btn-secondary min-h-10 px-4 text-xs"
                onClick={() => renderItem.setList([...list, { ...blank }])}>+ {addLabel}</button>
        </div>
    );
}

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

    if (contentKey === "home.hero") {
        return (
            <div className="space-y-4">
                <TextField label={t("Headline")} maxLength={160} required error={errorFor("headline")}
                    value={value.headline} onChange={(v) => patch({ headline: v })} />
                <TextField label={t("Description")} maxLength={600} textarea required error={errorFor("description")}
                    value={value.description} onChange={(v) => patch({ description: v })} />
                <ActionEditor t={t} field="primary_action" action={value.primary_action} errorFor={errorFor}
                    onChange={(next) => patch({ primary_action: next })} />
            </div>
        );
    }

    if (contentKey === "home.faq") {
        return (
            <div>
                <span className={labelClass}>{t("Question list")}</span>
                <ItemList t={t} items={value.items} blank={{ question: "", answer: "" }} addLabel={t("Add question")} itemLabel={t("Question")}
                    renderItem={{
                        setList: (next) => patch({ items: next }),
                        render: (item, index, setItem) => (
                            <div className="space-y-3">
                                <TextField label={t("Question")} maxLength={300} required error={errorFor(`items.${index}.question`)}
                                    value={item.question} onChange={(v) => setItem({ ...item, question: v })} />
                                <TextField label={t("Answer")} maxLength={3000} textarea required error={errorFor(`items.${index}.answer`)}
                                    value={item.answer} onChange={(v) => setItem({ ...item, answer: v })} />
                            </div>
                        ),
                    }} />
            </div>
        );
    }

    if (contentKey === "system.announcement") {
        const surfaces = Array.isArray(value.surfaces) ? value.surfaces : [];
        const toggleSurface = (name, on) => patch({ surfaces: on ? [...new Set([...surfaces, name])] : surfaces.filter((s) => s !== name) });
        return (
            <div className="space-y-4">
                <TextField label={t("Message")} maxLength={500} textarea required error={errorFor("message")}
                    value={value.message} onChange={(v) => patch({ message: v })} />
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
                <ActionEditor t={t} field="action" action={value.action} errorFor={errorFor}
                    onChange={(next) => patch({ action: next })} />
            </div>
        );
    }

    if (contentKey === "help.articles") {
        return (
            <div>
                <span className={labelClass}>{t("Article list")}</span>
                <ItemList t={t} items={value.items} blank={{ slug: "", title: "", summary: "", body: "" }} addLabel={t("Add article")} itemLabel={t("Article")}
                    renderItem={{
                        setList: (next) => patch({ items: next }),
                        render: (item, index, setItem) => (
                            <div className="space-y-3">
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <TextField label={t("Slug")} maxLength={120} placeholder="cara-mulai" required error={errorFor(`items.${index}.slug`)}
                                        help={t("Lowercase letters, numbers, and hyphens only.")} value={item.slug} onChange={(v) => setItem({ ...item, slug: v })} />
                                    <TextField label={t("Title")} maxLength={200} required error={errorFor(`items.${index}.title`)}
                                        value={item.title} onChange={(v) => setItem({ ...item, title: v })} />
                                </div>
                                <TextField label={t("Summary")} maxLength={500} textarea required error={errorFor(`items.${index}.summary`)}
                                    value={item.summary} onChange={(v) => setItem({ ...item, summary: v })} />
                                <TextField label={t("Body")} maxLength={20000} textarea required error={errorFor(`items.${index}.body`)}
                                    value={item.body} onChange={(v) => setItem({ ...item, body: v })} />
                            </div>
                        ),
                    }} />
            </div>
        );
    }

    return <p className="text-xs text-slate-500 dark:text-slate-400">{t("This block type has no structured editor yet.")}</p>;
}

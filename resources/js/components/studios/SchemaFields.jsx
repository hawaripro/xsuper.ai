import { useId, useLayoutEffect, useRef, useState } from "react";
import { useLocale } from "../../contexts/LocaleContext";
import AssetUploadField from "./AssetUploadField";
import { StudioButton, StudioField, StudioNotice } from "./StudioUI";
import { displaySchema, resolveSchema, schemaDefault, schemaEqual, schemaErrors, schemaPath, schemaType, schemaVariants } from "./schema";
import DimensionControl from "./DimensionControl";
import { FieldSection, FormField, SeedControl } from "./FieldControls";
import { FIELD_GROUPS, fieldGroup, orderFields } from "./studioForm";
import { ratioChoices } from "./studioSizing";

const labelFor = (schema, fallback) => schema?.title || schema?.["x-fal-label"] || fallback;
const valueLabel = (value) => typeof value === "string" ? value : JSON.stringify(value);
const without = (value, key) => Object.fromEntries(Object.entries(value || {}).filter(([name]) => name !== key));
// Owned-file leaves: limits a parent union/array imposed are already copied into the annotation.
const assetInput = (path, annotation, single, bounds = {}) => ({
    key: path, role: annotation.role, kind: annotation.kind, single, acceptsUrl: annotation.accepts_url === true,
    maxFileSize: annotation.max_file_size, maxPixels: annotation.max_pixels, ...bounds,
});

function constraints(schema, t) {
    if (schema["x-workspace-asset"] || schema["x-workspace-file-object"] === true) return "";
    const label = (original, translated) => t ? t(translated) : original;
    return [
        schema.minimum != null && `${label("min", "Minimum")}: ${schema.minimum}`,
        schema.maximum != null && `${label("max", "Maksimum")}: ${schema.maximum}`,
        typeof schema.exclusiveMinimum === "number" && `> ${schema.exclusiveMinimum}`,
        typeof schema.exclusiveMaximum === "number" && `< ${schema.exclusiveMaximum}`,
        schema.multipleOf != null && `${label("step", "Kelipatan")}: ${schema.multipleOf}`,
        schema.minLength != null && `${label("min length", "Panjang minimum")}: ${schema.minLength}`,
        schema.maxLength != null && `${label("max length", "Panjang maksimum")}: ${schema.maxLength}`,
        schema.minItems != null && `${label("min items", "Item minimum")}: ${schema.minItems}`,
        schema.maxItems != null && `${label("max items", "Item maksimum")}: ${schema.maxItems}`,
        schema.pattern && `${label("pattern", "Pola")}: ${schema.pattern}`,
        schema.format && `${label("format", "Format")}: ${schema.format}`,
    ].filter(Boolean).join(" · ");
}

function SchemaObject({ schema, value, onChange, path, root, errors, disabled, depth, grouped = false, quantityInput = null }) {
    const { t } = useLocale();
    const [newKey, setNewKey] = useState("");
    const [keyError, setKeyError] = useState("");
    const id = useId();
    const fieldNodes = useRef(new Map());
    const focusKey = useRef(null);
    useLayoutEffect(() => {
        if (focusKey.current === null) return;
        const node = fieldNodes.current.get(focusKey.current);
        const control = node?.matches("button") ? node : node?.querySelector('[data-sw-field-control] :is(input:not(:disabled),textarea:not(:disabled),select:not(:disabled),button:not(:disabled),summary)') || node;
        if (control) { control.focus(); focusKey.current = null; }
    });
    const data = value && typeof value === "object" && !Array.isArray(value) ? value : {};
    const properties = schema.properties || {};
    const required = schema.required || [];
    const keys = [...Object.keys(properties), ...Object.keys(data).filter((key) => !Object.hasOwn(properties, key))];
    const set = (key, next) => onChange(next === undefined ? without(data, key) : { ...data, [key]: next });
    const childFor = (key) => {
        if (Object.hasOwn(properties, key)) return properties[key];
        const matches = Object.entries(schema.patternProperties || {}).filter(([pattern]) => {
            try { return new RegExp(pattern, "u").test(key); } catch { return false; }
        }).map(([, child]) => child);
        return matches.length ? { allOf: matches } : schema.additionalProperties ?? {};
    };
    const add = () => {
        if (!newKey || Object.hasOwn(data, newKey) || Object.hasOwn(properties, newKey)) { setKeyError(t("Gunakan nama bidang baru yang belum ada.")); return; }
        if (schema.propertyNames && Object.keys(schemaErrors(schema.propertyNames, newKey, root)).length) { setKeyError(t("Nama bidang tidak sesuai aturan model.")); return; }
        const child = childFor(newKey);
        if (child === false) { setKeyError(t("Nama bidang tidak sesuai pola yang didukung.")); return; }
        if (grouped) focusKey.current = newKey;
        set(newKey, schemaDefault(child, undefined, true, root));
        setNewKey("");
        setKeyError("");
    };
    const canAdd = schema.additionalProperties !== false || Object.keys(schema.patternProperties || {}).length > 0;
    const optional = keys.filter((key) => !required.includes(key) && key !== "prompt");
    const compact = optional.length > 6;
    // An optional field carrying an error opens the group; once open it stays open while the member edits.
    const [expanded, setExpanded] = useState(false);
    const flagged = optional.some((key) => Object.keys(errors).some((at) => at === schemaPath(path, key) || at.startsWith(`${schemaPath(path, key)}.`)));
    const renderField = (key) => <SchemaField key={key} schema={childFor(key)} value={data[key]} path={schemaPath(path, key)} label={key}
        root={root} errors={errors} disabled={disabled} required={required.includes(key)} depth={depth + 1} onChange={(next) => set(key, next)} />;
    const addField = canAdd && <div className="schema-map-add">
            <StudioField id={id} label="Nama bidang tambahan" error={keyError} hint={schema.patternProperties ? Object.keys(schema.patternProperties).join(" · ") : undefined}>
                <input id={id} value={newKey} disabled={disabled || schema.maxProperties != null && Object.keys(data).length >= schema.maxProperties}
                    onChange={(event) => { setNewKey(event.target.value); setKeyError(""); }}
                    onKeyDown={(event) => { if (event.key === "Enter" && !event.nativeEvent.isComposing) { event.preventDefault(); add(); } }} />
            </StudioField>
            <StudioButton disabled={disabled || !newKey || schema.maxProperties != null && Object.keys(data).length >= schema.maxProperties} onClick={add}>{t("Tambah bidang")}</StudioButton>
        </div>;
    if (grouped) {
        const numeric = (source) => {
            const resolved = displaySchema(source, root);
            return ["integer", "number"].includes(resolved?.type) && !Object.hasOwn(resolved, "const") && !schemaVariants(resolved).length;
        };
        const paired = Object.hasOwn(properties, "width") && Object.hasOwn(properties, "height") && numeric(properties.width) && numeric(properties.height);
        const pairRequired = required.includes("width") || required.includes("height");
        const sizeDescription = paired ? ["width", "height"].map((axis) => {
            const description = displaySchema(properties[axis], root)?.description;
            return description ? `${t(axis === "width" ? "Lebar (px)" : "Tinggi (px)")}: ${t(description)}` : null;
        }).filter(Boolean).join("\n") : null;
        const sizeSchema = paired ? { type: "object", title: "Ukuran output", description: sizeDescription, properties: { width: properties.width, height: properties.height }, required: required.filter((key) => key === "width" || key === "height"), additionalProperties: false } : null;
        const entries = keys.filter((key) => !paired || key !== "height").map((key) => {
            const pair = paired && key === "width";
            const child = childFor(key);
            return {
                key, pair, schema: pair ? sizeSchema : child, label: pair ? "Ukuran output" : labelFor(displaySchema(child, root), key),
                required: pair ? pairRequired : required.includes(key),
                present: pair ? data.width !== undefined || data.height !== undefined : data[key] !== undefined,
                group: fieldGroup(key, child, { required: required.includes(key), root, quantity: quantityInput }),
                messages: Object.entries(errors).filter(([at]) => (pair ? ["width", "height"] : [key]).some((name) => at === name || at.startsWith(`${name}.`))),
            };
        });
        const changeEntry = (entry, next) => {
            if (next === undefined) focusKey.current = entry.key;
            if (!entry.pair) { set(entry.key, next); return; }
            const rest = without(without(data, "width"), "height");
            onChange(next === undefined ? rest : { ...rest, ...next });
        };
        return <div className="sw-form-sections">{FIELD_GROUPS.map((group) => {
            const fields = orderFields(entries.filter((entry) => entry.group === group.id));
            const extras = group.id === "advanced" && canAdd;
            if (!fields.length && !extras) return null;
            const flagged = fields.some((entry) => entry.messages.length > 0);
            return <FieldSection key={group.id} group={group} count={fields.reduce((count, entry) => count + (entry.pair ? 2 : 1), 0)} flagged={flagged || Boolean(extras && keyError)}>
                {fields.filter((entry) => entry.required || entry.present).map((entry) => <div key={entry.key} tabIndex={-1} ref={(node) => { if (node) fieldNodes.current.set(entry.key, node); else fieldNodes.current.delete(entry.key); }}>
                    <SchemaField schema={entry.schema} value={entry.pair ? { ...(Object.hasOwn(data, "width") ? { width: data.width } : {}), ...(Object.hasOwn(data, "height") ? { height: data.height } : {}) } : data[entry.key]}
                        path={entry.key} label={entry.label} root={root} errors={errors} disabled={disabled} required={entry.required} depth={depth + 1} grouped sizePair={entry.pair} onChange={(next) => changeEntry(entry, next)} />
                </div>)}
                {fields.some((entry) => !entry.required && !entry.present) && <div className="sw-add-chips">{fields.filter((entry) => !entry.required && !entry.present).map((entry) => <div key={entry.key} className="sw-add-chip-wrap">
                    <button type="button" className="sw-add-chip" ref={(node) => { if (node) fieldNodes.current.set(entry.key, node); else fieldNodes.current.delete(entry.key); }}
                        aria-label={`${t("Tambahkan")} ${t(entry.label)}`} disabled={disabled || entry.schema === false} onClick={() => {
                            focusKey.current = entry.key;
                            changeEntry(entry, entry.pair
                                ? { width: schemaDefault(properties.width, undefined, true, root), height: schemaDefault(properties.height, undefined, true, root) }
                                : schemaDefault(entry.schema, undefined, true, root));
                        }}>+ {t(entry.label)}</button>
                    {entry.messages.map(([at, message]) => <p key={at} className="studio-field-error" role="alert">{at !== entry.key && `${at}: `}{t(Array.isArray(message) ? message[0] : message)}</p>)}
                </div>)}</div>}
                {extras && addField}
            </FieldSection>;
        })}</div>;
    }
    return <div className="schema-object">
        {(compact ? keys.filter((key) => !optional.includes(key)) : keys).map(renderField)}
        {compact && <details className="schema-optional-fields" open={expanded || flagged} onToggle={(event) => setExpanded(event.currentTarget.open)}><summary>{t("Pengaturan opsional")} ({optional.length})</summary><div className="schema-object">{optional.map(renderField)}</div></details>}
        {addField}
        {!keys.length && !canAdd && <p className="studio-help">{t("Objek ini tidak memerlukan bidang tambahan.")}</p>}
    </div>;
}

function SchemaArray({ schema, value, onChange, path, root, errors, disabled, depth }) {
    const { t } = useLocale();
    const data = Array.isArray(value) ? value : [];
    const rowKeys = useRef([]);
    while (rowKeys.current.length < data.length) rowKeys.current.push(crypto.randomUUID());
    const move = (index, destination) => {
        const next = [...data];
        [next[index], next[destination]] = [next[destination], next[index]];
        [rowKeys.current[index], rowKeys.current[destination]] = [rowKeys.current[destination], rowKeys.current[index]];
        onChange(next);
    };
    const tuple = schema.prefixItems || (Array.isArray(schema.items) ? schema.items : []);
    const itemAt = (index) => tuple[index] ?? (Array.isArray(schema.items) ? schema.additionalItems ?? {} : schema.items ?? {});
    const homogeneous = !tuple.length ? displaySchema(schema.items || {}, root) : null;
    const annotation = homogeneous?.["x-workspace-asset"];
    // File lists stay one multi-upload control; entries may mix owned uploads and accepted public links.
    // The surrounding SchemaField shows the list-level message; rows show their own entry errors.
    if (annotation) return <AssetUploadField input={assetInput(path, annotation, false, { max: schema.maxItems, min: schema.minItems })}
        meta={{ label: "Berkas" }} value={data} disabled={disabled} invalid={Boolean(errors[path])} itemErrors={data.map((_, index) => errors[schemaPath(path, index)])} onChange={onChange} />;
    const canAdd = itemAt(data.length) !== false && (schema.maxItems == null || data.length < schema.maxItems);
    return <div className="schema-array">
        {data.map((entry, index) => <div className="schema-array-row" key={rowKeys.current[index]}>
            <SchemaField schema={itemAt(index)} value={entry} path={schemaPath(path, index)} label={`${t("Item")} ${index + 1}`} required
                root={root} errors={errors} disabled={disabled} depth={depth + 1} onChange={(next) => onChange(data.map((item, at) => at === index ? next : item))} />
            <div className="schema-array-actions">
                {!tuple.length && <>
                    <StudioButton aria-label={`${t("Pindahkan ke atas")} ${index + 1}`} disabled={disabled || index === 0} onClick={() => move(index, index - 1)}>{t("Naik")}</StudioButton>
                    <StudioButton aria-label={`${t("Pindahkan ke bawah")} ${index + 1}`} disabled={disabled || index === data.length - 1} onClick={() => move(index, index + 1)}>{t("Turun")}</StudioButton>
                </>}
                <StudioButton icon="close" disabled={disabled || data.length <= (schema.minItems || 0) || tuple.length > 0 && index !== data.length - 1}
                    aria-label={`${t("Hapus item")} ${index + 1}`} onClick={() => { rowKeys.current.splice(index, 1); onChange(data.filter((_, at) => at !== index)); }}>{t("Hapus")}</StudioButton>
            </div>
        </div>)}
        {!data.length && <p className="studio-help">{t("Daftar kosong. Tambahkan item sesuai kebutuhan.")}</p>}
        <StudioButton disabled={disabled || !canAdd} onClick={() => onChange([...data, schemaDefault(itemAt(data.length), undefined, true, root)])}>{t("Tambah item")}</StudioButton>
    </div>;
}

// Provider File objects are one upload/link bound to `.url`; their optional metadata stays secondary.
const fileObjectLeaf = (schema, root) => {
    if (schema?.["x-workspace-file-object"] !== true || !schema.properties?.url) return null;
    return displaySchema(schema.properties.url, root)?.["x-workspace-asset"] || null;
};

function SchemaFileObject({ schema, annotation, value, onChange, path, root, errors, disabled, depth }) {
    const { t } = useLocale();
    const [expanded, setExpanded] = useState(false);
    const data = value && typeof value === "object" && !Array.isArray(value) ? value : {};
    const required = schema.required || [];
    const urlPath = schemaPath(path, "url");
    const urlError = errors[urlPath];
    const metadata = { ...schema, "x-workspace-file-object": undefined, properties: without(schema.properties, "url"), required: required.filter((key) => key !== "url") };
    const flagged = Object.keys(errors).some((at) => at.startsWith(`${path ? `${path}.` : ""}`) && at !== urlPath && at !== path);
    return <div className="schema-file-object">
        <AssetUploadField input={assetInput(urlPath, annotation, true)} meta={{ label: "Berkas" }} value={typeof data.url === "string" ? data.url : ""} disabled={disabled} invalid={Boolean(urlError)}
            onChange={(next) => onChange(next === "" && !required.includes("url") ? without(data, "url") : { ...data, url: next })} />
        {urlError && <p className="studio-field-error" role="alert">{t(Array.isArray(urlError) ? urlError[0] : urlError)}</p>}
        {Object.keys(metadata.properties).length > 0 && <details className="schema-file-metadata" open={expanded || flagged} onToggle={(event) => setExpanded(event.currentTarget.open)}>
            <summary>{t("Metadata berkas (lanjutan)")}</summary>
            <SchemaObject schema={metadata} value={without(data, "url")} path={path} root={root} errors={errors} disabled={disabled} depth={depth + 1}
                onChange={(next) => onChange(Object.hasOwn(data, "url") ? { ...next, url: data.url } : next)} />
        </details>}
    </div>;
}
const isObject = (value) => value !== null && typeof value === "object" && !Array.isArray(value);
// Member paths an input-mode branch asks for: required members and lists or texts it makes non-empty.
function branchMembers(branch, path = []) {
    if (!isObject(branch)) return [];
    const required = Array.isArray(branch.required) ? branch.required : [];
    const members = [];
    for (const name of required) {
        const nested = branchMembers(branch.properties?.[name], [...path, name]);
        members.push(...(nested.length ? nested : [[...path, name]]));
    }
    for (const [name, child] of Object.entries(isObject(branch.properties) ? branch.properties : {})) {
        if (required.includes(name) || !isObject(child)) continue;
        if ((child.minItems ?? 0) >= 1 || (child.minLength ?? 0) >= 1) members.push([...path, name]);
        else members.push(...branchMembers(child, [...path, name]));
    }
    return members;
}
function memberTitle(variant, path, root) {
    let node = variant;
    for (const segment of path) node = displaySchema(node?.properties?.[segment], root);
    return node?.title || path[path.length - 1];
}
function variantLabel(entry, index, t, root, branch) {
    const asset = entry?.["x-workspace-asset"] || fileObjectLeaf(entry, root);
    if (asset) return t(asset.accepts_url === true ? "Berkas atau tautan publik" : "Berkas");
    if (entry?.type === "null") return t("Kosong (null)");
    if (isObject(branch) && !branch.title) {
        const titles = [...new Set(branchMembers(branch).map((path) => t(memberTitle(entry, path, root))))];
        if (titles.length) return `${t("Dengan")} ${titles.join(" + ")}`;
    }
    return labelFor(entry, entry?.type || `${t("Pilihan")} ${index + 1}`);
}

function SchemaUnion({ schema, value, onChange, path, root, errors, disabled, depth, grouped = false, quantityInput = null }) {
    const { t } = useLocale();
    const id = useId();
    const variants = schemaVariants(schema).map((entry) => displaySchema(entry, root));
    const branches = schema?.oneOf || schema?.anyOf || [];
    const [choice, setChoice] = useState(null);
    const matched = variants.findIndex((entry) => Object.keys(schemaErrors(entry, value, root)).length === 0);
    const selected = choice ?? (matched >= 0 ? matched : Math.max(0, variants.findIndex((entry) => entry?.type === schemaType(value))));
    return <div className="schema-union">
        <StudioField id={id} label="Bentuk input">
            <select id={id} value={selected} disabled={disabled} onChange={(event) => {
                const index = Number(event.target.value);
                setChoice(index);
                onChange(schemaDefault(variants[index], undefined, true, root));
            }}>{variants.map((entry, index) => <option key={index} value={index}>{variantLabel(entry, index, t, root, branches[index])}</option>)}</select>
        </StudioField>
        <SchemaControl key={selected} schema={variants[selected]} value={value} onChange={onChange} path={path} root={root} errors={errors} disabled={disabled} depth={depth + 1} grouped={grouped} quantityInput={quantityInput} />
    </div>;
}

function SchemaAny(props) {
    const { t } = useLocale();
    const id = useId();
    const [type, setType] = useState(() => props.value === undefined ? "string" : schemaType(props.value));
    return <div className="schema-any">
        <StudioField id={id} label="Jenis nilai"><select id={id} value={type} disabled={props.disabled}
            onChange={(event) => { setType(event.target.value); props.onChange(schemaDefault({ type: event.target.value })); }}>
            {["string", "number", "boolean", "object", "array", "null"].map((entry) => <option value={entry} key={entry}>{t(entry)}</option>)}
        </select></StudioField>
        <SchemaControl {...props} schema={{ ...props.schema, type }} />
    </div>;
}

function SchemaControl({ schema: source, value, onChange, path, root, errors, disabled, depth, grouped = false, quantityInput = null, controlId, descriptionId }) {
    const { t } = useLocale();
    const generatedId = useId();
    const id = controlId || generatedId;
    const schema = displaySchema(source, root) || {};
    const error = errors[path];
    const describedBy = descriptionId || (error ? `${id}-error` : undefined);
    if (depth > 48 || source === false || schema.$ref) return <StudioNotice error>{t("Struktur ini tidak dapat dibaca. Muat ulang model.")}</StudioNotice>;
    // Owned-file leaves render as uploads before any union, enum or format handling. SchemaField shows the message.
    const asset = schema["x-workspace-asset"];
    if (asset) return <AssetUploadField input={assetInput(path, asset, true)} meta={{ label: "Berkas" }} value={value} onChange={onChange} disabled={disabled} invalid={Boolean(error)} />;
    const fileObject = fileObjectLeaf(schema, root);
    if (fileObject) return <SchemaFileObject {...{ schema, annotation: fileObject, value, onChange, path, root, errors, disabled, depth }} />;
    if (schemaVariants(schema).length) return <SchemaUnion {...{ schema, value, onChange, path, root, errors, disabled, depth, grouped, quantityInput }} />;
    if (Object.hasOwn(schema, "const")) return <code className="schema-constant">{valueLabel(schema.const)}</code>;
    if (schema.enum) return <select id={id} aria-label={schema.title || path} value={schema.enum.findIndex((option) => schemaEqual(option, value))} disabled={disabled}
        aria-invalid={Boolean(error)} aria-describedby={descriptionId} onChange={(event) => onChange(schema.enum[Number(event.target.value)])}>
        <option value={-1} disabled>{t("Pilih nilai")}</option>{schema.enum.map((option, index) => <option value={index} key={index}>{valueLabel(option)}</option>)}
    </select>;
    const type = schema.type || (schema.properties || schema.additionalProperties || schema.patternProperties ? "object" : schema.items || schema.prefixItems ? "array" : null);
    if (type === "object") return <SchemaObject {...{ schema, value, onChange, path, root, errors, disabled, depth, grouped, quantityInput }} />;
    if (type === "array") return <SchemaArray {...{ schema, value, onChange, path, root, errors, disabled, depth }} />;
    if (type === "null") return <p className="studio-help"><code>null</code> · {t("Nilai null eksplisit")}</p>;
    if (!type) return <SchemaAny {...{ schema, value, onChange, path, root, errors, disabled, depth, grouped, quantityInput }} />;
    const common = { id, disabled, "aria-label": schema.title || path, "aria-invalid": Boolean(error), "aria-describedby": describedBy };
    let control;
    if (type === "boolean") control = <label className="studio-checkbox"><input {...common} type="checkbox" checked={value === true} onChange={(event) => onChange(event.target.checked)} /><span>{value === true ? "true" : "false"}</span></label>;
    else if (type === "number" || type === "integer") control = <input {...common} type="number" value={typeof value === "number" ? value : ""}
        min={schema.minimum} max={schema.maximum} step={schema.multipleOf || (type === "integer" ? 1 : "any")}
        onChange={(event) => onChange(event.target.value === "" ? "" : Number(event.target.value))} />;
    else {
        const link = schema.format === "uri" || schema.format === "url";
        const multiline = !link && (schema["_fal_ui_field"] === "textarea" || schema["x-fal-ui"]?.control === "textarea" || schema.format === "text" || /prompt|description|text|code/i.test(path.split(".").pop()));
        // Link parameters are only text; the provider fetches them, the browser never does. Provider
        // examples are often non-conforming, so only real defaults ever fill a value.
        const props = { ...common, value: typeof value === "string" ? value : "", maxLength: schema.maxLength, minLength: schema.minLength,
            placeholder: link ? "https://" : undefined, onChange: (event) => onChange(event.target.value) };
        control = multiline ? <textarea {...props} rows={4} dir="auto" /> : <input {...props} type="text" dir={link ? "ltr" : "auto"} inputMode={link ? "url" : undefined} spellCheck={link ? false : undefined} />;
    }
    return <>{control}{error && !controlId && <span id={`${id}-error`} className="studio-visually-hidden">{t(Array.isArray(error) ? error[0] : error)}</span>}</>;
}

function SchemaField({ schema: source, value, onChange, path, label, root, errors, disabled, required = false, depth = 0, grouped = false, sizePair = false }) {
    const { t } = useLocale();
    const id = useId();
    const schema = displaySchema(source, root) || {};
    const title = labelFor(schema, label);
    const present = value !== undefined;
    const error = errors[path];
    if (grouped) {
        const hint = constraints(schema, t);
        const describedBy = [schema.description && `${id}-description`, hint && `${id}-hint`, !sizePair && error && `${id}-error`].filter(Boolean).join(" ") || undefined;
        const branches = schemaVariants(schema).map((entry) => displaySchema(entry, root));
        const enumBranch = branches.find((entry) => ratioChoices(entry?.enum));
        const objectBranch = branches.find((entry) => entry?.properties && ["width", "height"].every((axis) => ["integer", "number"].includes(displaySchema(entry.properties[axis], root)?.type)) && Object.keys(entry.properties).every((key) => key === "width" || key === "height"));
        const sizeUnion = branches.length === 2 && enumBranch && objectBranch;
        // A union-level enum default is not a default for its custom object branch.
        const customSchema = sizeUnion && Object.hasOwn(objectBranch, "default") && schemaType(objectBranch.default) !== "object" ? without(objectBranch, "default") : objectBranch;
        const selectable = source !== false && !schema.$ref && !schema["x-workspace-asset"] && !fileObjectLeaf(schema, root) && !Object.hasOwn(schema, "const");
        // Provider schemas often make a field nullable (`anyOf: [T, {type: null}]`): leaving the field out
        // already sends nothing, so the one non-null branch drives the control.
        const concrete = branches.filter((entry) => entry?.type !== "null");
        const nullable = branches.length > 1 && concrete.length === 1 ? concrete[0] : null;
        const choices = selectable && (ratioChoices(schema.enum) || (sizeUnion ? ratioChoices(enumBranch.enum) : null) || (nullable ? ratioChoices(nullable.enum) : null));
        const seedSchema = path === "seed" ? [nullable, branches.length ? null : schema].find((entry) => entry && ["integer", "number"].includes(entry.type) && !entry.enum) : null;
        let control;
        let composite = false;
        if (sizePair || choices) control = <DimensionControl id={id} label={title} value={value} onChange={onChange} choices={choices || null} sizeSchema={sizePair ? schema : sizeUnion ? customSchema : null}
            root={root} allowAutomatic={sizePair && !required} path={sizePair ? "" : path} errors={errors} disabled={disabled} describedBy={describedBy} />;
        else if (selectable && seedSchema) control = <SeedControl id={id} label={title} schema={seedSchema} value={value} onChange={onChange}
            disabled={disabled} describedBy={describedBy} invalid={Boolean(error)} required={required} />;
        else {
            const shown = nullable || schema;
            const file = Boolean(shown["x-workspace-asset"] || fileObjectLeaf(shown, root));
            composite = !selectable || file || (!nullable && Boolean(branches.length)) || !shown.enum && !["string", "number", "integer", "boolean"].includes(shown.type);
            control = <SchemaControl {...{ value, onChange, path, root, errors, disabled, depth }} schema={nullable || source} controlId={composite ? undefined : id} descriptionId={describedBy} />;
        }
        return <FormField id={id} label={title} required={required} description={schema.description} hint={hint} error={sizePair ? undefined : error} disabled={disabled} composite={composite} onRemove={!required ? () => onChange(undefined) : undefined}>{control}</FormField>;
    }
    return <fieldset className={`schema-field studio-field ${present ? "is-enabled" : "is-unset"}`} aria-describedby={schema.description ? `${id}-description` : undefined}>
        <legend><span>{title}{required && <span className="schema-required"> *</span>}</span>
            {!required && <label className="schema-enable"><input type="checkbox" checked={present} disabled={disabled || source === false}
                onChange={(event) => onChange(event.target.checked ? schemaDefault(source, undefined, true, root) : undefined)} />{t(present ? "Disertakan" : "Opsional")}</label>}
        </legend>
        {schema.description && <p className="studio-help" id={`${id}-description`}>{schema.description}</p>}
        {required || present ? <SchemaControl {...{ value, onChange, path, root, errors, disabled, depth }} schema={source} /> : <p className="studio-help">{t("Tidak dikirim. Aktifkan untuk mengisi nilai.")}</p>}
        {constraints(schema) && <p className="studio-help schema-constraints">{constraints(schema)}</p>}
        {error && <p className="studio-field-error" role="alert">{t(Array.isArray(error) ? error[0] : error)}</p>}
    </fieldset>;
}

export default function SchemaFields({ schema, values, errors = {}, disabled = false, onChange, grouped = false, quantityInput = null }) {
    const root = resolveSchema(schema, schema);
    return <div className="schema-fields"><SchemaControl schema={root} value={values} root={schema} path="" errors={errors} disabled={disabled} depth={0} onChange={onChange} grouped={grouped} quantityInput={quantityInput} /></div>;
}

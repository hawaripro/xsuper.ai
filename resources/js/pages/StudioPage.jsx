import { useEffect, useState } from "react";
import { Link, useLocation } from "react-router-dom";
import AnnouncementRibbon from "../components/AnnouncementRibbon";
import { UltrLockup, UltrLogoPlain } from "../components/UltrLogo";
import MediaStudio from "../components/studios/MediaStudio";
import ModelPalette from "../components/studios/ModelPalette";
import { ModelMark, StudioIcon } from "../components/studios/StudioUI";
import useGlobalMediaWorkspace from "../components/studios/useGlobalMediaWorkspace";
import useStudioModelLists from "../components/studios/useStudioModelLists";
import { useAuth } from "../contexts/AuthContext";
import { useLocale } from "../contexts/LocaleContext";
import { useTheme } from "../contexts/ThemeContext";
import "../components/studios/studio-form.css";
import "../components/studios/studio-palette.css";
import "../components/studios/studio-detail.css";
import "./studio-workspace.css";

const apple = () => typeof navigator !== "undefined" && /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || "");

function StudioApp({ user }) {
    const { t, locale, localizedPath, otherLocalePath } = useLocale();
    const { theme, toggleTheme } = useTheme();
    const location = useLocation();
    const studio = useGlobalMediaWorkspace({ userId: user.id, locale });
    const lists = useStudioModelLists(user.id);
    const [paletteOpen, setPaletteOpen] = useState(false);
    const [realtimeActive, setRealtimeActive] = useState(false);
    const locked = studio.submitting || realtimeActive;
    const isDark = theme === "dark";
    const format = (value) => new Intl.NumberFormat(locale).format(value);
    const shortcut = apple() ? "⌘ /" : "Ctrl /";
    // Ctrl+/ (⌘/ on Apple keyboards) opens the model palette from anywhere on the page.
    useEffect(() => {
        const open = (event) => {
            if (!(event.ctrlKey || event.metaKey) || event.key !== "/" || event.altKey || event.shiftKey) return;
            event.preventDefault();
            if (!locked && !document.querySelector("dialog[open]")) setPaletteOpen(true);
        };
        window.addEventListener("keydown", open);
        return () => window.removeEventListener("keydown", open);
    }, [locked]);
    const remember = lists.remember;
    const modelKey = studio.model ? `${studio.model.model_id}:${studio.model.name}:${studio.model.logo_url || ""}` : "";
    useEffect(() => { if (studio.model) remember(studio.model); }, [modelKey]);
    const summary = studio.summary;
    return <div className={`media-studio sw-root${isDark ? " is-dark" : ""}`}>
        <div className="sw-ribbon"><AnnouncementRibbon surface="dashboard" /></div>
        <header className="sw-topbar">
            <div className="sw-topbar-start">
                <Link to={localizedPath("/dashboard")} className="sw-brand" aria-label={t("XSuper.ai — kembali ke Dashboard")}>
                    <UltrLockup height={24} className="sw-brand-full" /><UltrLogoPlain size={30} className="sw-brand-mark" />
                </Link>
                <nav className="sw-breadcrumb" aria-label="Breadcrumb">
                    <Link to={localizedPath("/dashboard")}>{t("Dashboard")}</Link><span aria-hidden="true">/</span><span aria-current="page">{t("Studio Media")}</span>
                </nav>
                <button type="button" className="sw-model-chip" onClick={() => setPaletteOpen(true)} disabled={locked} aria-haspopup="dialog" aria-keyshortcuts="Control+/ Meta+/"
                    title={t(locked ? "Model terkunci selama permintaan atau sesi berjalan." : "Pilih model")}>
                    <ModelMark model={summary} /><span className="sw-model-chip-name">{summary?.name || studio.modelId || t("Pilih model")}</span>
                    <kbd>{shortcut}</kbd><StudioIcon name="chevron" />
                </button>
            </div>
            <div className="sw-topbar-end">
                <Link to={localizedPath("/token-usage")} className="sw-balance" aria-label={`${t("Saldo token")}: ${studio.balance == null ? "—" : format(studio.balance)}`} title={t("Lihat saldo dan riwayat token")}>
                    <StudioIcon name="tokens" /><strong>{studio.balance == null ? "—" : format(studio.balance)}</strong><span>{t("token")}</span>
                </Link>
                <Link to={otherLocalePath(`${location.pathname}${location.search}${location.hash}`)} className="sw-tool" aria-label={locale === "en" ? "Ganti ke bahasa Indonesia" : "Switch to English"}>{locale === "en" ? "ID" : "EN"}</Link>
                <button type="button" className="sw-tool" onClick={toggleTheme} aria-label={isDark ? t("Mode terang") : t("Mode gelap")} title={isDark ? t("Mode terang") : t("Mode gelap")}><StudioIcon name={isDark ? "sun" : "moon"} /></button>
                <Link to={localizedPath("/profile")} className="sw-account" aria-label={`${t("Akun")}: ${user.name || user.email || ""}`} title={user.name || user.email || ""}>
                    {String(user.name || user.email || "U").trim().charAt(0).toUpperCase()}
                </Link>
            </div>
        </header>
        <MediaStudio studio={studio} lists={lists} onOpenPalette={() => { if (!locked) setPaletteOpen(true); }} realtimeActive={realtimeActive} onRealtimeActive={setRealtimeActive} />
        {paletteOpen && <ModelPalette userId={user.id} kind={studio.kind} selectedId={studio.modelId} recent={lists.recent} favorites={lists.favorites}
            onSelect={(model) => { setPaletteOpen(false); studio.selectModel(model.model_id, "", model); }} onClose={() => setPaletteOpen(false)} />}
    </div>;
}

// Full-page media studio: its own top bar under the announcement ribbon, outside the dashboard layout.
export default function StudioPage() {
    const { user } = useAuth();
    return user ? <StudioApp key={user.id} user={user} /> : null;
}

import { useCallback, useEffect, useState } from "react";
import { readModels, rememberModel, toggleFavorite } from "./studioPalette";

// "Terakhir dipakai" and "Favorit" are display hints kept per member in this browser; every selected
// model is re-checked by the server. Other tabs of the same member stay in step via storage events.
const read = (key) => {
    try { return readModels(localStorage.getItem(key)); } catch { return []; }
};

export default function useStudioModelLists(userId) {
    const recentKey = `xsuper:studio:${userId}:recent-models:v1`;
    const favoriteKey = `xsuper:studio:${userId}:favorite-models:v1`;
    const [recent, setRecent] = useState(() => read(recentKey));
    const [favorites, setFavorites] = useState(() => read(favoriteKey));
    useEffect(() => {
        const sync = (event) => {
            if (event.key === recentKey) setRecent(readModels(event.newValue));
            if (event.key === favoriteKey) setFavorites(readModels(event.newValue));
        };
        window.addEventListener("storage", sync);
        return () => window.removeEventListener("storage", sync);
    }, [recentKey, favoriteKey]);
    // Identical values do not fire storage events, so persisting on change cannot ping-pong between tabs.
    useEffect(() => { try { localStorage.setItem(recentKey, JSON.stringify(recent)); } catch { /* Lists stay in memory. */ } }, [recentKey, recent]);
    useEffect(() => { try { localStorage.setItem(favoriteKey, JSON.stringify(favorites)); } catch { /* Lists stay in memory. */ } }, [favoriteKey, favorites]);
    const remember = useCallback((model) => setRecent((current) => rememberModel(current, model)), []);
    const toggle = useCallback((model) => setFavorites((current) => toggleFavorite(current, model)), []);
    const isFavorite = useCallback((id) => favorites.some((entry) => entry.model_id === id), [favorites]);
    return { recent, favorites, remember, toggleFavorite: toggle, isFavorite };
}

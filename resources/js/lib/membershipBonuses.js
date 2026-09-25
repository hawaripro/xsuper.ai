const inRange = (value, min, max) => value !== "" && Number.isFinite(Number(value)) && Number(value) >= min && Number(value) <= max;

/**
 * Drafts membership bonuses for every duration package of a `/api/pricing/settings` catalog: `valuePct`% of each
 * package price is returned as bonus, `tokenPct`% of that as media tokens (down to a multiple of 25) and the rest as
 * Saldo AI (down to $0.25). Unsaved draft prices win over saved ones. Null when the catalog has no token revenue
 * floor or wallet conversion rate, or a percentage is out of range.
 */
export function membershipBonusDrafts(catalog, drafts, valuePct, tokenPct) {
    const tokenRevenue = Number(catalog?.valuation?.token_revenue_idr);
    const walletRate = Number(catalog?.valuation?.wallet_idr_per_usd);
    if (!(tokenRevenue > 0 && walletRate > 0) || !inRange(valuePct, 0, 1000) || !inRange(tokenPct, 0, 100)) return null;
    const value = Number(valuePct) / 100;
    const share = Number(tokenPct) / 100;
    const next = { ...drafts };
    for (const [id, original] of Object.entries(catalog.duration_packages || {})) {
        const price = Number(drafts[id]?.price_idr ?? original.price_idr);
        next[id] = {
            ...drafts[id],
            bonus_tokens: Math.floor(price * value * share / tokenRevenue / 25) * 25,
            bonus_wallet_usd: (Math.floor(price * value * (1 - share) / walletRate * 4) / 4).toFixed(2),
        };
    }
    return next;
}

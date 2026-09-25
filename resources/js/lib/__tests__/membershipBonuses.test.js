import { describe, it, expect } from "vitest";
import { membershipBonusDrafts } from "../membershipBonuses.js";

// Spec vectors: token revenue floor r = Rp399.000 / 4.500 tokens; admin helper defaults 107% value, 60% token share.
const catalog = (walletIdrPerUsd, tokenRevenueIdr = 399000 / 4500) => ({
    valuation: { token_revenue_idr: tokenRevenueIdr, wallet_idr_per_usd: walletIdrPerUsd },
    duration_packages: { "1_day": { price_idr: 10000 }, "1_month": { price_idr: 55000 } },
});

describe("membershipBonusDrafts", () => {
    it("prices the Saldo AI bonus with the conversion rate of the catalog it is given", () => {
        expect(membershipBonusDrafts(catalog(16000), {}, 107, 60)["1_month"]).toEqual({ bonus_tokens: 375, bonus_wallet_usd: "1.25" });
        expect(membershipBonusDrafts(catalog(32000), {}, 107, 60)["1_month"]).toEqual({ bonus_tokens: 375, bonus_wallet_usd: "0.50" });
    });

    it("prices an unsaved draft price and keeps the row's other draft edits", () => {
        expect(membershipBonusDrafts(catalog(32000), { "1_month": { price_idr: "110000", is_active: false } }, 107, 60)).toEqual({
            "1_day": { bonus_tokens: 50, bonus_wallet_usd: "0.00" },
            "1_month": { price_idr: "110000", is_active: false, bonus_tokens: 775, bonus_wallet_usd: "1.25" },
        });
    });

    it("refuses to price without an active token package, a conversion rate, or in-range percentages", () => {
        expect(membershipBonusDrafts(catalog(16000, null), {}, 107, 60)).toBeNull();
        expect(membershipBonusDrafts(catalog(0), {}, 107, 60)).toBeNull();
        expect(membershipBonusDrafts(catalog(16000), {}, 1001, 60)).toBeNull();
        expect(membershipBonusDrafts(catalog(16000), {}, 107, "")).toBeNull();
    });
});

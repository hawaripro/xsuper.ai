# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Confirmed with the product owner: UltrAI serves both people using AI directly and developers or product teams integrating AI into their applications. These audiences share one platform, with distinct workspace and developer-console entry points.

## Product Purpose

Give users a coherent UltrAI experience for working with AI and building with AI APIs. The public site is Studio Orbital with localized landing, subscription pricing, AI model catalog, payment methods, FAQ, audience sections, and policy pages. Repository application changes remain local until explicit release authorization.

## Positioning

Confirmed with the product owner: UltrAI is the customer-facing platform and brand, not marketed primarily as a gateway or proxy. Own the product experience, navigation, account, workspace, and console. Identify third-party models and their makers accurately. Do not claim UltrAI trained, owns, or operates a proprietary foundation model without evidence.

## Operating Context

The application uses Laravel with a React SPA for login, member, and administrator workflows. Public home and legal pages render HTML through Blade with a separate Vite CSS/JavaScript entry; useful text and links work without JavaScript. Canonical homepage remains https://ultrai.id. Mobile browser access remains part of the web product.

## Capabilities and Constraints

Repository evidence, not new business commitments:

- Public landing page and login; existing enrollment and package purchases use assisted flows.
- Member dashboard, chat, conversation history, prompt templates, profile, duration-based packages, referral, and support routes.
- API access and API-key management, model discovery, usage views, and permissions.
- Video-generation routes and other creative-tool entries; visible routes are not proof that every existing tool is fully implemented.
- Administrator routes for accounts, orders, usage, model/provider management, and operational settings.
- Existing backend contracts, authorization, permissions, expiry, device limits, and payment behavior must survive the eventual frontend cutover unless separately approved.
- Public landing and legal pages are real application code. Their workbench examples are labeled illustrations; actual workspace and developer actions link to existing authenticated routes. No production account, payment, or deployment action is performed during local verification.

## Brand Commitments

Use the existing UltrAI name and actual logo. Indonesian remains on unprefixed canonical paths; English uses `/en` subdirectories. `/models` mirrors Bazaarlink's production information architecture: 240px sticky filters for input modality, capability, billing, context and provider; category tabs; search; and a compact six-column comparison table. Retired router and internal-provider model identities are removed across list APIs, chat defaults/bypasses, proxy aliases/env fallbacks, response output, stored models, API-key allowlists, and project documentation. Dark mode has no AI-logo tiles; only Anthropic, GLM/Z.ai, and Kimi marks turn white. Workspace tabs are explicitly readable and topbar Sign in is red. Subscription pricing is an infinite 3/2/1-card arrow carousel. Payment SVGs are cropped to artwork bounds and rendered at one optical height; QRIS/GoPay turn white in dark mode and GoPay white cutouts are transparent.

## Evidence on Hand

- `resources/js/app.jsx`: current surface and role inventory.
- `routes/web.php`: session API and protected workflow boundaries.
- `config/marketing.php`, `app/Models/DurationOrder.php`, and `resources/views/public/`: public copy, legal terms, authoritative package prices, and server-rendered content.
- `resources/js/components/UltrLogo.jsx` and `public/ultr-icons.png`: incumbent brand mark.
- `resources/js/pages/` and `resources/js/layouts/`: existing application behavior and navigation.

No verified uptime SLA, benchmarks, customer totals, partner endorsements, proprietary-model claims, or new prices were provided. Do not fabricate them. Any sample usage, conversations, projects, request counts, or accounts in prototypes are explicitly illustrative.

## Product Principles

- One UltrAI identity, two clear user journeys: use AI and build with AI.
- Demonstrate the product rather than relying on third-party logo walls or unsupported performance claims.
- Keep model attribution and product capabilities truthful.
- Make daily workflows and developer integration easy to find and understand.
- Separate design exploration from production functionality and implementation approval.

## Open Decisions

- Public-site changes remain local; release awaits explicit authorization. Two malicious workflows executed successfully on GitHub-hosted runners on 2026-04-30 and 2026-05-18. Remote main cleanup commit `3c2db90` removes `ci.yml`, and malicious branch `mega-gvufrv8g` is deleted. A live DB credential was present in tracked source during both incidents; it has been scrubbed from the current files but remains in history and must be rotated immediately. Rotate old GitHub credentials and review account access.
- Duration prices start from `DurationOrder::PACKAGES` plus independent USD defaults, then admin database overrides become authoritative. PAYG rates remain unpublished until explicitly configured and activated.
- Which existing creative-tool and administrative workflows are production-ready; validate before implementing their redesigned behavior.

# Live Event Manager – User Guide

## Overview

Live Event Manager is a WordPress plugin for ticketing and secure playback of live and on-demand events. It ties together **Stripe** (checkout), a **streaming provider** (**Mux** or self-hosted **OvenMediaEngine / OME**), **JWT-style playback tokens**, and **Upstash Redis** (HTTP) for sessions and magic links.

When a visitor already has access, event pages can show a **Watch** path automatically; otherwise they see purchase or resend options.

**Plugin version:** see the WordPress Plugins list or `live-event-manager.php` header (e.g. 1.1.0).

## Quick start

### 1. Activate and open Live Events

1. **Plugins** → activate **Live Event Manager**.
2. In the admin sidebar, open **Live Events** (custom post type `lem_event`).

### 2. Cache (required for production access flows)

1. Go to **Live Events → Settings**.
2. Under **Upstash Redis**, add your **REST URL** and **REST token** from [Upstash](https://upstash.com) (no server Redis extension required).
3. Use **Test Upstash Connection** (or **Live Events → Debug**) to verify.

Until Upstash is configured, the plugin shows a notice and session/magic-link behavior should not be relied on.

### 3. Stripe

On **Settings**, enter Stripe **publishable** and **secret** keys and webhook secrets (test/live as needed). Copy the **Stripe webhook** URL from **Debug** (or Settings) into the Stripe Dashboard.

### 4. Streaming provider

1. **Settings** → **Active provider**: choose **Mux** or **OME**.
2. **Live Events → Vendors** → open each provider tab you use and save **Credentials** (and any extra tabs that provider exposes).

### 5. Streams and events

1. **Live Events → Live Streams** — create a stream (options such as reduced latency / test mode depend on provider).
2. **Live Events → Add New** — create an event; in **Event Details**, pick the stream, set date/time, free vs paid, and Stripe price for paid events.
3. Publish the event.

## Streaming providers

### Mux (cloud)

1. **Vendors** → **Mux** → **Credentials**:
   - Signing key ID and private key (for playback tokens)
   - API token ID and secret (for Live API / streams)
   - Optional webhook secret
2. **Settings** → set **Active provider** to Mux if this is your default path.

Streams are created via the Mux API; playback IDs and ingest details appear on **Live Streams** when you expand a stream.

### OvenMediaEngine (OME, self-hosted)

1. **Vendors** → **OME** → **Credentials**: server URL, API URL/token, application/stream names, **signing key** for Signed Policy URLs, ports/TTL as needed. See [OvenMediaEngine](https://ovenmediaengine.com).
2. **Settings** → set **Active provider** to **OME** when events should use OME playback (Signed Policy + OvenPlayer-style flows).

Playback tokens are provider-specific (Mux JWT vs OME signed URLs); the plugin stores what each provider needs for the viewer session.

## Live Streams

**Live Events → Live Streams** combines stream listing, creation, and per-stream setup:

- Select or create a stream.
- For supported providers (e.g. Mux), use **RTMP ingest URL** and **stream key** with OBS or similar.
- **Simulcast targets** (e.g. YouTube/Twitch RTMP) are managed here when the provider supports them.

**Bookmarks:** the old **Stream Setup** menu slug still redirects to **Live Streams** with the same query args.

**Publisher (optional):** a separate screen exists at  
`edit.php?post_type=lem_event&page=live-event-manager-publisher`  
(event picker + RTMP instructions). It is not in the main submenu; the **User Guide** page in the admin includes a quick link.

## Events

1. **Live Events → Add New** (or **All Events** → edit).
2. Title, content, featured image, **Event Details** meta:
   - **Stream** selection and playback fields (often auto-filled from the stream).
   - **Schedule**, **Free vs Paid**, **Stripe Price ID** and display price for paid events.
3. Use the block editor blocks **Event ticket** (`lem/event-ticket`) and **Gated video** (`lem/gated-video`) on pages as needed.
4. Shortcode: `[simulcast_player]` where documented for your theme.

## Access, tokens, and revocations

- **Live Events → Access Tokens** — list JWT/session records, revoke, resend magic links.
- **Live Events → Revoke access** — record **entitlement revocations** (email + event) in the database; checks consult this together with cache/session state.

Free events can issue access immediately after email validation (when configured). Paid events issue access after **Stripe Checkout** and webhook processing.

## Payments

**Live Events → Payments** lists paid checkouts tied to issued access (Stripe Checkout session IDs, expiry, revoked state). Filter by event; **CSV export** is available from that screen.

## Template packs

**Live Events → Templates** — upload a template pack ZIP, activate it, or download bundled packs.

- Installed packs live under `wp-content/lem-templates/{slug}/`.
- The active pack overrides files such as `single-event.php` and `event-ticket-block.php`; missing files fall back to the plugin `templates/` directory or bundled `template-packs/{slug}/` copies.

## Viewer experience

- **Purchase** — ticket block collects email; paid paths redirect to Stripe.
- **Magic link** — email contains a one-time style link and often an **access code** for resend flows.
- **Resend** — confirmation, event, and watch surfaces use the regenerate / validate email actions (`lem_regenerate_jwt`, etc.).
- **Device rules** — **Live Events → Devices** configures identification behavior used in device-swap flows.
- **Watch** — with a valid token, the player loads (Mux player or OME/OvenPlayer per provider). Entitlement checks can run via AJAX (`lem_check_event_access`) on the event page.

## Optional: Ably live chat

On **Services → Chat**, configure the **Ably** provider and set your API key. Activate Ably as the chat provider. When configured, watch pages request short-lived Ably tokens for scoped chat channels. If chat is not configured, chat UI stays hidden.

## REST API

Base URL: `/wp-json/lem/v1/` (your site URL + REST prefix).

- Most routes require an administrator (`manage_options`), e.g. **live-streams**, **rtmp-info**, **simulcast-targets**, **stream-status**, **jwt-settings** (GET).
- **`POST /lem/v1/check-jwt-status`** — supply a JWT (e.g. `jwt` or `token` parameter); used for **revocation / status** checks from edge infrastructure (e.g. Cloudflare Worker) that already validates signatures separately.

## Admin pages (reference)

| Menu item | Purpose |
|-----------|---------|
| **All Events** / **Add New** | CPT list and new event |
| **Live Streams** | Streams, RTMP, simulcast |
| **Vendors** | Per-provider credentials (Mux, OME, …) |
| **Access Tokens** | JWT/session list, revoke, resend |
| **Revoke access** | DB entitlement revocations |
| **Payments** | Checkout records, CSV |
| **Settings** | Upstash, active streaming/payment provider, device rules |
| **Services** | Vendor credentials (Mux, OME, Stripe, PayPal, Ably chat) |
| **Devices** | Device identification settings |
| **Templates** | Template packs |
| **User Guide** | This document (rendered from `docs/user-guide.md`) |
| **Debug** | Health checks, tests, webhook URLs |

**Mux playback restrictions** may still be managed via API flows surfaced on **Vendors** / **Debug** in some setups; there is no separate top-level “Restrictions” menu in current builds.

## Troubleshooting

- **Upstash / sessions** — confirm REST URL and token; test from **Debug** or Settings. Magic links and multi-step access depend on cache working.
- **Stripe / no access after payment** — webhook URL and signing secret; replay events from Stripe Dashboard; check **Debug** and `wp-content/debug.log` for `[LEM]` lines.
- **Mux API errors** — **Vendors → Mux** token ID/secret and signing keys; **Live Streams** errors often link back to **Vendors**.
- **OME playback** — verify Signed Policy **signing key** and server/API URLs on **Vendors → OME**.
- **Token valid but no video** — **Access Tokens** and **Revoke access**; confirm event/stream IDs and provider on the event.

## Reporting issues

Include:

1. Steps to reproduce  
2. Event ID, URLs (sanitize secrets)  
3. Active provider (Mux vs OME) and whether Upstash/Stripe show as configured on **Debug**  
4. Relevant lines from `wp-content/debug.log`  

For integration details beyond this guide, see the repository **README.md**.

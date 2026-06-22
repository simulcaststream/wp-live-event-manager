# Live Event Manager

Secure, WordPress-native streaming paywall for live and on-demand events.  
The plugin combines Stripe for checkout, pluggable streaming backends (**Mux** or self-hosted **OvenMediaEngine / OME**), and JWT-backed access control with Upstash Redis for sessions—while keeping the viewer workflow simple.

**Bundled version:** 1.1.0 (see `live-event-manager.php`).

## Key Capabilities

- **Entitlement-aware event pages** – Visitors with an active ticket see a “Watch Now” path; others get purchase or resend options.
- **Streaming providers** – Choose an **active provider** in Settings: **Mux** (cloud, signed playback) or **OME** ([OvenMediaEngine](https://ovenmediaengine.com), self-hosted). Per-provider credentials live under **Live Events → Vendors**.
- **Stripe checkout + webhooks** – Paid events mint playback tokens after payment confirmation; **Payments** lists recorded checkouts (Stripe session IDs, filters, CSV export).
- **Playback security** – JWTs are scoped to the event and expire automatically; **Access Tokens** lists and revokes sessions; **Revoke access** records DB-level entitlement revocations checked across the stack.
- **Upstash Redis (HTTP)** – Sessions, magic links, and related caches use the Upstash REST API (no `phpredis` requirement). The plugin shows an admin notice until URL + token are set; magic-link and session flows expect cache to be configured.
- **Magic links + device rules** – One-time links, device identification, and email/code resend UI on confirmation, event, and watch surfaces.
- **Optional Ably live chat** – With an Ably API key in Settings, watch pages can obtain short-lived chat tokens for realtime channels.
- **Template packs** – Override front-end templates and assets without editing the plugin: upload ZIPs, activate a pack under **Live Events → Templates**, with files resolved from `wp-content/lem-templates/{slug}/` (bundled packs in `template-packs/` fill gaps after updates).
- **Blocks & shortcode** – Block editor: **Event ticket** and **Gated video**; shortcode: `[simulcast_player]`.
- **REST API (`lem/v1`)** – Admin-authenticated routes for live streams, RTMP info, simulcast targets, stream status, and JWT settings; **`POST /lem/v1/check-jwt-status`** supports edge revocation checks (e.g. Cloudflare Worker) using the token payload.
- **Admin tools** – **Live Streams**, **Vendors**, **Access Tokens**, **Revoke access**, **Payments**, **Settings**, **Devices**, **Templates**, **User Guide**, and **Debug** (Stripe/Mux/Upstash tests, webhooks, logging).

## Requirements

- WordPress 5.0+, PHP 7.4+, MySQL 5.7+
- **[LEM Adaptors](https://github.com/simulcaststream/wp-lem-adaptors)** (or your own provider plugin) for Mux, OME, Stripe, and/or PayPal
- Stripe account with price IDs for paid events (when using Stripe)
- **Upstash Redis** – REST URL and token ([Upstash](https://upstash.com)) for production session/token behavior
- Streaming credentials for your chosen provider (**Mux** and/or **OME** as configured under **Vendors**)
- Optional: **Ably** account for live chat (configure under **Live Events → Services → Chat**)

## Setup

1. **Install and activate** Live Event Manager and **LEM Adaptors**.
2. Open **Live Events → Settings** and configure **Upstash Redis** (required for full access flows), optional **Ably**, and the **active streaming/payment provider**.
3. Open **Live Events → Vendors** and enter credentials for **Mux** and/or **OME**, **Stripe** and/or **PayPal** (the active provider in Settings determines which path events use by default).
4. Create a **Live Event** (`lem_event`): attach stream/playback details, set free vs paid and Stripe price if needed, then publish.
5. Test entitlement: free events can issue access immediately; paid events go through Stripe Checkout; on success the webhook emails a magic link.

Operator documentation: **Live Events → User Guide** (see also `[docs/user-guide.md](docs/user-guide.md)`—some menu names there may lag the UI).

## Viewer Experience

- **Ticket purchase** – Event blocks validate email, send viewers to Stripe when required, and show status inline.
- **Watch shortcut** – The event page can call `lem_check_event_access` so entitled viewers see the player without a separate “watch” post in many setups.
- **Player** – Valid JWTs load playback (Mux player or provider-appropriate embed). Failed entitlement hides the player and keeps resend options visible.
- **Magic link email** – One-time URL, access code, and resend instructions where configured.
- **Resend** – Confirmation, watch, and event surfaces use `lem_regenerate_jwt` / email validation flows.
- **Chat** – If Ably is configured, viewers receive short-lived tokens for scoped chat channels on watch pages.

## Caching & Sessions

- **Upstash** is the supported cache layer: HTTPS requests to your Redis-compatible Upstash database.
- Configure **REST URL** and **REST token** under **Live Events → Settings**; use **Debug** to test the connection.
- Without Upstash, cache operations are effectively no-ops and admin notices remind you to configure it; do not rely on magic links or multi-device session behavior until it is set up.

## REST & Edge Revocations

- Namespace: `wp-json/lem/v1/…` (most routes require `manage_options` / authenticated admin).
- **`check-jwt-status`** accepts a JWT and returns revocation/validity information for use in front-line infrastructure (e.g. workers) that already validate signatures.

## Admin Screens (under Live Events)

| Screen | Purpose |
|--------|---------|
| **Live Streams** | Create, list, and manage streams for the active provider. |
| **Vendors** | Per-provider credentials and options (Mux, OME, etc.). |
| **Access Tokens** | Inspect and revoke JWTs / sessions. |
| **Revoke access** | Entitlement-level revocations (email + event) stored in the database and reflected in checks. |
| **Payments** | Paid ticket / checkout records tied to issued access; filter by event; CSV export. |
| **Settings** | Stripe, Upstash, Ably, active provider, webhooks, and global options. |
| **Devices** | Device identification and related rules. |
| **Templates** | Install and activate template packs; download bundled packs. |
| **User Guide** | In-dashboard documentation. |
| **Debug** | Health checks, webhook URLs, Redis/Upstash and vendor connectivity. |

Mux **playback restriction** APIs are still available from the plugin where integrated (e.g. vendor/debug workflows); there is not a separate top-level “Playback Restrictions” menu item in current builds.

## Support Checklist

1. Use **Live Events → Debug** to verify Stripe, Mux (or OME), and **Upstash** connectivity.
2. Enable `WP_DEBUG_LOG` and watch for `[LEM]` lines in `wp-content/debug.log`.
3. Check **Access Tokens** and **Revoke access** if a viewer should or should not have playback.
4. Re-deliver or replay Stripe webhooks from the Stripe Dashboard if payments did not issue access.

---

Built for production streaming workflows: WordPress front end, Stripe paywall, flexible streaming backends, and Redis-backed access control.

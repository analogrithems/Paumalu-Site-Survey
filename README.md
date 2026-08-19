# Paumalu Site Survey

A WordPress plugin that replaces Paumalu Electric's fillable-PDF residential electrical inspection
punch list with a mobile-first web app. A technician completes an inspection on a phone with photo
evidence, a reviewer approves it, and the plugin auto-drafts a prioritized, customer-facing action
plan that the homeowner reads and signs online or on-site.

Built for [Paumalu Electric](https://paumaluelectric.wordpress.com/), a residential electrical
contractor on the North Shore of Oahu.

## What it does

1. A technician fills out the ~85-item inspection catalog (transcribed from the original PDF punch
   list) on a phone, across a main panel and any number of subpanels, attaching photos to failed
   items. Answers autosave as they go, with a `localStorage` fallback so a dropped connection doesn't
   cost the visit.
2. The technician submits the survey for review. A reviewer (editor role or above) reads it, can
   request changes with notes back to the technician, and accepts it.
3. On acceptance, the plugin auto-drafts a customer proposal from the failed items — grouped into
   🔴 Immediate Hazards, 🟡 Recommended Maintenance, and 🔵 Optional Upgrades — translating technician
   wording ("Check neutral bar for multiple conductors where prohibited") into customer wording
   ("Main panel neutral bar has multiple conductors landed under single terminals; correction
   recommended"). The reviewer edits the draft, picks photos, and sends it.
4. The customer opens a private tokenized link, reads a clean printable page (no login required), and
   signs off — either from the emailed link or on the technician's tablet on-site.

The proposal is a summary and a sales document, not a dump of the full inspection. It carries scope,
not dollar amounts.

## Requirements

- WordPress 6.5+
- PHP 8.3+
- Node.js (for building the front end) — see `package.json` for the exact toolchain
- [`wp-env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/)
  and Docker for local development

## Local development

```bash
npm install
npx wp-env start        # Docker Desktop must be running
npm run build            # builds the React app into build/
```

The site comes up at `http://localhost:8888` (admin / password: `admin` / `password`). Outgoing mail
is caught by MailHog rather than sent.

```bash
npm run start             # watch mode for the front end
npx wp-env run cli wp <...>   # wp-cli inside the container
```

## Testing

PHP integration tests live under `tests/` and run against the wp-env database:

```bash
npx wp-env run cli wp eval-file wp-content/plugins/paumalu-site-survey/tests/<suite>.php
```

Suites: `repository-test`, `rest-test`, `photo-test`, `review-test`, `proposal-test`.

An end-to-end browser suite (`tests/e2e.mjs`, Playwright) walks the full technician → reviewer →
customer path, including the emailed proposal link and both signing paths. It relies on a local-only
mail-capture mu-plugin (`tests/mu/pe-mail-log.php`) and must never be run against production.

## Architecture

- **Data model** — a `pe_site_survey` custom post type carrying a single JSON meta blob per survey,
  snapshotted against a versioned question catalog so old surveys keep rendering correctly as the
  catalog changes.
- **Roles** — a dedicated `pe_technician` role that can create and edit its own surveys but cannot
  touch posts, pages, or other technicians' work; reviewers are `editor` or `administrator`.
- **Front end** — a React app (`@wordpress/scripts`) served from custom rewrite rules
  (`/survey/`, `/survey/{id}/`, `/survey/{id}/review/`), not a shortcode.
- **Photos** — resized and re-encoded client-side before upload (HEIC → JPEG, EXIF/GPS stripped,
  capped at 1600px) so a phone photo over LTE actually makes it to the server.
- **Proposals** — server-rendered, mobile-first, print-friendly public pages at `/proposal/{token}/`,
  the token stored only as a SHA-256 hash and never re-shown once emailed.

See [`CONTEXT.md`](CONTEXT.md) for full architecture notes, environment details, and project history.

## License

GPL-2.0-or-later, per the plugin header and `package.json`.

# Paumalu Site Survey — project context

Handoff notes for anyone (human or agent) picking this up cold. Last updated 2026-08-19, plugin
version **0.7.1**.

---

## 1. What this is

Paumalu Electric (residential electrical contractor, North Shore of Oahu — owner **Paul Hancock**,
manager **Josh Hancock**) ran residential service inspections off a fillable PDF:
`Residential_Electrical_Service_Inspection_Punch_List_Fillable.pdf` (in this folder — the source of
truth for the question catalog). ~85 checklist items across 10 sections.

Filling a PDF on a phone in a crawlspace is bad, and the result is a technician artifact, not
something you hand a homeowner. This WordPress plugin replaces it end to end:

1. A technician completes an inspection on a phone at a mobile-first web form, with photos.
2. Josh reviews it in WordPress and accepts it.
3. The plugin auto-drafts a **customer sales proposal** — a prioritized action plan, not a dump of
   all 85 items — which Josh edits.
4. The customer gets a private link, reads a clean printable page, and signs it. Or signs on the
   technician's tablet on site.

The translation from technician wording ("Check neutral bar for multiple conductors where
prohibited") to customer wording ("Main panel neutral bar has multiple conductors landed under
single terminals; correction recommended") **is the core value of the plugin**. It lives in the
catalog's `proposal_text` field.

### Where the code is

```
/Users/aaroncollins/Development/tasks/Fangen/paumalu-site-survey/   <- the plugin (deployable folder)
/Users/aaroncollins/Development/resi.paumaluelectric.com/           <- this file + the source PDF
```

There is **no git repo**. Changes are made in place and deployed with rsync over SSH.

---

## 2. Decisions already made (do not relitigate without asking)

| Area | Decision |
|---|---|
| Item answers | Pass / Fail / N/A, plus severity, note, photos on failures |
| Technician UI | Front-end mobile page with autosave, **not** wp-admin |
| Pricing | **Scope only, no dollar amounts** anywhere |
| Sign-off wording | Approves *a scope of work*, never "accepts an estimate" — no price makes estimate language legally muddy |
| Sign-off paths | Emailed private link **and** on-site tablet signing |
| Panels | Repeatable — main panel plus any number of subpanels |
| Stack | PHP 8.3 backend + React front end via `@wordpress/scripts` |
| Edit rules | **Author can always edit, even after submission** (Aaron overrode the recommendation to lock) |
| Upgrades | Added an Upgrade Opportunities section beyond the PDF |
| Proposal output | Web page + browser print-to-PDF, no bundled PDF library |
| Dependencies | No Composer. Small SPL autoloader. `build/` is committed so the folder deploys as-is |

Because the author can always edit, a reviewer can be reading a document that shifts underneath
them. Mitigation: on submit the answers are snapshotted to `_pe_review_snapshot`; any later author
edit sets `_pe_dirty_since_review`, and the review screen shows a "9 items changed since this was
submitted" banner listing the changed keys.

---

## 3. Architecture

Namespace `Paumalu\SiteSurvey`. Text domain and slug `paumalu-site-survey`. Meta/option prefix
`_pe_` / `pe_`.

### Data model

CPT `pe_site_survey` — `public => false`, `show_ui => true`, `map_meta_cap => true`,
`capability_type => ['site_survey','site_surveys']`.

Statuses: `draft` → `pending` (Ready for Review) → `pe_changes_req` → `pe_accepted`. `draft` and
`pending` are WordPress built-ins, which gives the workflow almost for free: a role without
`publish_site_surveys` can move its own post to `pending` but no further — that *is* "submit for
approval".

The **proposal has its own lifecycle in meta** (`draft`/`sent`/`viewed`/`signed`/`declined`) rather
than more post statuses. The survey's status is Josh's workflow; the proposal's is the customer's.
Conflating them would have meant eight statuses describing two unrelated things.

Answers live in one JSON meta blob `_pe_survey_data`. A handful of flat metas sit alongside purely
so the admin list table and future reporting can query without unpacking JSON: `_pe_customer_name`,
`_pe_service_address`, `_pe_inspection_date`, `_pe_overall_condition`, `_pe_schema_version`,
`_pe_submitted_at`, `_pe_fail_counts`.

> **Gotcha that will bite you:** `update_post_meta()` calls `wp_unslash()` on its value, which
> mangles JSON. All JSON writes must go through `SurveyRepository::store_json()`, which pre-slashes.

### Question catalog

`includes/Catalog/catalog-v1.php` returns the full definition (71 items currently loaded). Each item
carries `key`, `label` (technician wording, verbatim from the PDF), `scope` (`survey` or `panel` —
`panel`-scoped items repeat per panel, which is how repeatable panels work without a bespoke UI),
`default_severity`, `proposal_text`, `polarity` (the Safety Hazards section inverts: a "fail" there
means *found*, so it renders Not Present / Present / N/A while storing the same three states), and
`input` (`status` or `number` for the electrical readings).

Default severities encode domain judgment: Federal Pacific / Zinsco / Challenger equipment, open
splices, overheating evidence, aluminum branch wiring, missing bonding and extension cords as
permanent wiring default to **Immediate**; missing GFCI, aged smoke alarms and corrosion to
**Recommended**; missing AFCI and missing panel directory to **Optional**.

The catalog is **versioned and snapshotted onto each survey at creation**. The punch list will
change; surveys written against v1 must still render correctly in a year.

### Roles and capabilities

Role `pe_technician` ("Technician"): `read`, `upload_files`, `edit_site_surveys`,
`edit_published_site_surveys`, `read_site_surveys`, `delete_site_surveys`. Deliberately **withheld**:
`publish_site_surveys`, `edit_others_site_surveys`, and every `*_posts` / `*_pages` capability — so
technicians cannot create posts or pages and cannot see each other's surveys.

`editor` and `administrator` additionally get `pe_review_site_surveys` and `pe_send_proposal`. Josh
is an editor. Sending is a separate capability from reviewing because emailing a customer is the one
action that reaches outside the company.

Two things this needed guarding:

- `upload_files` normally exposes the **entire** media library. `Media/MediaRestrictions.php` filters
  `ajax_query_attachments_args` and the attachment list down to the current user's own uploads.
- `Setup/AdminLockout.php` redirects technicians out of wp-admin on `admin_init` (REST and AJAX
  exempted) and hides the admin bar. They live entirely on the front end.

### Front end

Rewrite rules + `template_include`, not a shortcode on a page — the app needs the whole document
(viewport meta, no theme chrome, no widgets over a form somebody is filling in one-handed).

| Path | Screen |
|---|---|
| `/` | Public signpost: "this is an internal tool", link to the real website |
| `/survey/` | My surveys |
| `/survey/new/` | Start one |
| `/survey/{id}/` | The form |
| `/survey/{id}/review/` | Josh's review (same app, gated on `pe_review_site_surveys`) |
| `/survey/{id}/proposal/` | Josh's proposal editor |
| `/proposal/{40-hex-token}/` | The customer's page — public, no login |

**Requires pretty permalinks.** Under "Plain" the routes silently 404, so `Frontend/Router.php`
prints an admin notice with a one-click link to the permalinks screen.

React is mounted from `src/`, built with `@wordpress/scripts`. React comes from WordPress as an
external so it is not duplicated in the bundle.

**Autosave:** debounced PATCH ~1.5s, mirrored synchronously to `localStorage` keyed by survey id. On
load, if the local copy is newer than the server's, the app offers to restore it. This is the
difference between a dropped signal costing nothing and costing 50 minutes of work.

`src/api.js` is plain `fetch`, not `@wordpress/api-fetch`, because autosave needs to abort an
in-flight request when the next keystroke lands, and needs to tell "the server said no" apart from
"the phone lost signal" — the second is recoverable and must not surface as a scary error to
somebody standing in an attic. Photo upload uses XHR purely for `upload.onprogress`, which `fetch`
still cannot report.

REST namespace `paumalu/v1`, cookie auth + nonce:

```
GET    /catalog
GET    /surveys                        POST   /surveys
GET    /surveys/{id}                   PATCH  /surveys/{id}      DELETE /surveys/{id}
POST   /surveys/{id}/submit
POST   /surveys/{id}/request-changes   (reviewer)
POST   /surveys/{id}/accept            (reviewer)
GET|POST /surveys/{id}/notes
GET|POST /surveys/{id}/photos          PATCH|DELETE /photos/{id}
GET|POST /surveys/{id}/proposal        (reviewer)
POST   /surveys/{id}/proposal/regenerate (reviewer)
POST   /surveys/{id}/proposal/send       (reviewer + pe_send_proposal)
POST   /surveys/{id}/proposal/sign       (anyone who can edit the survey — the tablet)
```

### Photos

Resized **client-side** before upload (`src/photos.js`): `createImageBitmap` with
`imageOrientation: 'from-image'`, drawn to canvas at max 1600px long edge, re-encoded JPEG q0.82.
This solves three problems at once — iPhone HEIC becomes JPEG that WordPress can actually process, a
4MB original becomes ~250KB so it uploads over LTE, and the canvas re-encode strips EXIF including
GPS, so the privacy result is free. **Orientation must be applied during the draw** or everything
lands sideways.

Photos attach to the survey via `post_parent`, caption in the native `post_excerpt` (which also
becomes the alt text). Up to four are chosen for the proposal gallery.

> Signatures are attachments on the same `post_parent`, so they would otherwise appear in the
> technician's photo grid and be selectable for the customer gallery. They carry
> `_pe_signature_image` and `PhotoService::for_survey()` excludes them with a `NOT EXISTS` meta
> query. If you add another kind of attached image, remember this.

### Proposal and sign-off

`Proposal/ProposalBuilder::draft()` walks every failed item, groups by severity into 🔴 Immediate
Hazards / 🟡 Recommended Maintenance / 🔵 Optional Upgrades, and emits each item's `proposal_text`.
It honours the *answer's* severity over the catalog default — the technician may have downgraded it
standing in front of the thing. Interested upgrades append to the optional bucket.

`regenerate()` merges by `line_id()` = `key|panel`, so reworded lines stay reworded and deleted lines
stay deleted when new findings are pulled in.

**Token security:** 20 random bytes → 40 hex chars, shown once, stored only as a SHA-256 hash.
Lookup is by hash in SQL, so there is no secret-dependent branch in PHP to time. Not even Josh's own
screen can recover the token after minting. Expiry is configurable, default 60 days. Signing revokes
the token, otherwise a forwarded email keeps working against a signed document for another two
months.

**Signature PNG validation is layered** (`Proposal/Signature.php`): data-URL prefix → bounded encoded
length *before* decode → strict base64 → magic bytes `\x89PNG\r\n\x1a\n` → `getimagesizefromstring()`
plus `IMAGETYPE_PNG`. The last two defeat polyglot files.

**The drawn mark is optional.** Under the E-SIGN Act what makes a signature is a deliberate act
adopting the document — a typed name with intent, timestamp and IP is exactly that. Requiring the
canvas would mean a customer whose phone failed to load one JavaScript file cannot approve the work
at all, trading a real loss for a marginal gain in evidentiary weight. Both signing routes go through
the same `Signature::record()` so they cannot drift apart. `method` records `drawn` vs `typed`,
`via` records `link` vs `onsite`.

Client IP comes from `REMOTE_ADDR` only. Forwarded-for headers are client-spoofable and this site is
not behind a controlled proxy.

The public page is rate-limited on a transient keyed to `md5(REMOTE_ADDR)`, counting **misses only**,
so a customer refreshing their own proposal is unaffected.

---

## 4. File map

```
paumalu-site-survey.php          Bootstrap, SPL autoloader, VERSION constant
includes/
  Plugin.php                     Wires every subsystem; registration order matters for Landing
  Admin/ListTable.php            Triage columns: customer, technician, status, red/yellow/blue counts
  Admin/SettingsPage.php         Company name, license, phone, address, logo, terms, disclaimer,
                                 notify emails, token expiry.  Option key pe_site_survey_settings
  Catalog/Catalog.php            Version constant + loader
  Catalog/catalog-v1.php         THE QUESTION CATALOG — the domain knowledge lives here
  Data/Meta.php                  Every meta key as a constant. Add new keys here, never inline
  Data/SurveyRepository.php      JSON read/write (store_json pre-slashes), validation, defaults
  Frontend/Router.php            /survey/* rewrites, auth gate, cache suppression, head cleanup
  Frontend/ProposalRouter.php    /proposal/{token}/ , rate limit, POST-back sign/decline, PRG
  Frontend/Landing.php           The public "/" signpost
  Media/MediaRestrictions.php    Technicians see only their own uploads
  Media/PhotoService.php         Upload, resize bookkeeping, for_survey() (excludes signatures)
  PostType/SurveyPostType.php    CPT registration
  PostType/Statuses.php          The four statuses and the legal transitions between them
  Proposal/Proposal.php          Storage, lifecycle, sanitize, token mint/lookup/revoke
  Proposal/ProposalBuilder.php   draft() and regenerate()
  Proposal/ProposalMailer.php    The one customer-facing HTML email; revokes token on send failure
  Proposal/Signature.php         PNG validation and signature recording
  Proposal/Notifications.php     Internal mail on signed / declined / viewed
  Rest/*.php                     Controller.php is the shared base (permissions, get_survey)
  Review/Notes.php               Two-way thread stored as comments on the survey post
  Review/Workflow.php            Submit, snapshot, diff, accept, request changes
  Review/Notifications.php       Internal workflow mail
  Setup/Activator.php            Registers rules THEN flushes — order matters or routes 404
  Setup/AdminLockout.php         Technicians out of wp-admin
  Setup/Capabilities.php         REVIEW and SEND_PROPOSAL constants
  Setup/Roles.php                install() — run on activation
src/
  index.js  api.js  routes.js  photos.js  style.scss
  components/  App SurveyList SurveyForm PanelEditor ItemRow PhotoField
               SaveIndicator NoteThread ReviewPanel ProposalEditor SignaturePad
templates/
  app.php        Standalone document that mounts React
  proposal.php   The customer's page — server-rendered, works with no JavaScript
  landing.php    The public signpost, styles inline (dead-end page, not worth a second request)
assets/
  proposal.css   Standalone CSS for the customer page, with a @media print block
  signature.js   Vanilla pad for the customer page (sibling of SignaturePad.js, not shared —
                 one runs unbuilt on a plain page, the other lives in the bundle)
build/           Committed. Rebuild with `npm run build` after ANY change under src/
tests/           repository-test.php rest-test.php photo-test.php review-test.php e2e.mjs
```

---

## 5. Environments

### Local

`wp-env` on Docker, pinned to PHP 8.3 (the local CLI is newer — the pin exists so we do not ship
syntax Dreamhost rejects). The code now lives at
`/Users/aaroncollins/Development/resi.paumaluelectric.com/Paumalu-Site-Survey` (moved out of the
`tasks/Fangen` scratch location once the GitHub repo — `analogrithems/Paumalu-Site-Survey` — was
created).

Node **20+** is required for the build toolchain (pinned in `.nvmrc`) since the `@wordpress/scripts`
0.6.2 dependency bump pulls in `serialize-javascript@7.x`, which uses the Web Crypto global that
Node only exposes unflagged from v19 on. Node itself is never part of the deployed plugin — this
only matters for `npm run build`.

```bash
cd /Users/aaroncollins/Development/resi.paumaluelectric.com/Paumalu-Site-Survey
nvm use                     # picks up .nvmrc (Node 20)
npx wp-env start            # Docker Desktop must be running
npm run build                # after any src/ change
npx wp-env run cli wp <...> # wp-cli inside the container
```

Site at `http://localhost:8888`, admin `admin` / `password`. Mail is caught by MailHog, not sent.
PHP tests run as `npx wp-env run cli wp eval-file wp-content/plugins/paumalu-site-survey/tests/<f>.php`.

### Production

`https://resi.paumaluelectric.com` — Dreamhost shared hosting.

- SSH: `paumaluelectric@pdx1-shared-a1-09.dreamhost.com`, **key-based auth already set up**
- WordPress root: `/home/paumaluelectric/resi.paumaluelectric.com`
- Plugin path: `.../wp-content/plugins/paumalu-site-survey`
- Web SAPI is PHP **8.3.30**; the shell's default CLI is **8.2.30**. The activation check reads the
  web version. If you run `wp` over SSH and hit a version complaint, that mismatch is why.
- **WP Super Cache is active.** Every app route is per-user and authenticated, so a cached copy would
  serve one technician's survey to the next one. Both routers define `DONOTCACHEPAGE`,
  `DONOTCACHEOBJECT`, `DONOTCACHEDB` and call `nocache_headers()`. Verify after deploying:
  `curl -sI https://resi.paumaluelectric.com/survey/ | grep -i cache-control` must show
  `no-store, private`.
- TLS is real (Let's Encrypt, issued 2026-08-19, expires 2026-11-17, SANs `resi.` and `www.resi.`).
  `siteurl` and `home` are both `https://`. **The site had no HTTPS earlier in the project** — that
  matters for the credential note below.
- `wp-config.php` lines ~103–104 derive `WP_SITEURL`/`WP_HOME` from `$_SERVER['HTTP_HOST']`, which
  looks like host-header injection but is gated behind `preg_match("/^(.*)\.dream\.website$/")` and
  is inert on the real hostname. Already investigated; leave it alone.

**Deploy** (rsync over SSH; `.distignore` lists what stays behind):

```bash
npm run build
rsync -az --delete --exclude-from=.distignore ./ \
  paumaluelectric@pdx1-shared-a1-09.dreamhost.com:/home/paumaluelectric/resi.paumaluelectric.com/wp-content/plugins/paumalu-site-survey/
```

Then flush rewrites, or new routes 404:
`ssh ... "cd <wp-root> && php -d ... wp rewrite flush"` (or deactivate/reactivate the plugin).

### DNS note

An earlier probe of this host returned a WordPress.com page (`192.0.78.24`) from a stale cache. The
correct A record is **69.163.183.189**. If you get a 404 with `Host-Header: WordPress.com`, you are
talking to a stale resolver, not to this site. Check with `dig +short @1.1.1.1` against more than one
resolver before concluding anything about deployment state.

---

## 6. SECURITY — read this before doing anything

1. **An application password was disclosed in plaintext chat** (`resi_r3zq7t`) at a time when the
   site had no HTTPS. It must **never** be written into any file, script, or commit — that rule stands
   regardless of the next point. This is a test site, not production for a live customer base, and
   Aaron intends to keep using those credentials for live testing rather than rotate them — so do not
   flag revocation as outstanding work.
2. **No secrets in files or in chat.** SSH is key-based; do not ask for or accept a password.
3. Do not run the e2e suite against production. It creates surveys, uploads photos and exercises
   accept — it would leave junk in the review queue for whoever is covering review that day.

---

## 7. Current state

**Phases 1–7 are built,** plus a docs/dashboard pass beyond the original plan. Version 0.7.1 is what
is live in production right now.

| Phase | Status |
|---|---|
| 1 Foundation (CPT, statuses, roles, lockout, media restriction, settings, list table) | done, deployed |
| 2 Catalog | done, deployed |
| 3 Field app (REST CRUD, React form, panels, autosave) | done, deployed |
| 4 Photos | done, deployed |
| 5 Review (submit, snapshot + diff banner, notes, request changes, accept, notifications) | done, deployed as 0.5.0 |
| 6 Proposal (builder, editor UI, public token page, both signing paths, print stylesheet) | done, deployed as 0.6.0 |
| 7 Email polish (proposal-send HTML template, submit/changes/accept + signed/declined/viewed notifications) | done, deployed as 0.6.0 |
| 8 Deploy | per-phase; 0.7.1 is what is live right now |
| 9 Docs + dashboard queue (role-based guides, in-app links, reviewer dashboard widget) | done, deployed as 0.7.0 |

**0.7.0** adds `docs/` (technician, editor, administrator guides), linked from the app itself —
GitHub/docs links in the technician surveys page footer, and role-gated Editor Guide/Administrator
Guide/GitHub links in wp-admin under Site Surveys, via a single `includes/Setup/Links.php` registry
consumed by both PHP and the React boot object. It also adds a wp-admin dashboard widget
(`includes/Admin/DashboardWidget.php`) showing surveys awaiting review and signed-but-unscheduled
proposals, with a "Mark scheduled" action that sets a new `_pe_scheduled_at` meta key to drop a
survey off the second list — that meta key is the only new piece of state this phase introduces;
nothing else about the survey/proposal lifecycle changed.

**Correction (2026-08-19):** this file previously listed phase 7 as "not started". It is not — it
shipped with phase 6. `includes/Review/Notifications.php` covers submitted/changes-requested/accepted/
notes, `includes/Proposal/Notifications.php` covers signed/declined/viewed, and
`includes/Proposal/ProposalMailer.php` is the customer-facing HTML send. All three are registered in
`Plugin.php` and covered by passing assertions in `review-test.php` and `proposal-test.php`
(re-verified live this session). Recipients are **not** hardcoded to Josh — `reviewer_emails()` in both
notification classes reads the `notify_emails` setting first, and falls back to every account holding
`pe_review_site_surveys` (i.e. any `editor` or `administrator`, not one specific person).

Production was verified live at 0.6.0: plugin active at 0.6.0, all **14** `paumalu/v1` routes
present, all 6 rewrite rules in the `rewrite_rules` option, the landing page served at `/` with
`noindex`, `/survey/` 302ing to login under `no-store, private`, and a bad proposal token 404ing —
also under `no-store`, which matters because WP Super Cache must never write a proposal page to disk.
Earlier at 0.5.0: `/catalog` 401s anonymously, `POST /surveys/1/accept` 404s to a stranger, served
assets byte-identical to local, no `http://` URLs in served HTML, plugin PHP files return 200 with a
zero-byte body (the ABSPATH guard executing rather than leaking source).

Deployed 0.6.3 (the wp-admin review-link fix): rsync'd via `.distignore` — which had gone missing
from the repo in the move to this directory and was recreated — flushed rewrites via the activator,
and re-verified `/survey/` still returns `no-store, private` and `/` still 200s. `.distignore` is now
committed so this doesn't happen again.

Deployed 0.7.0 (docs + dashboard widget): full local regression first — all 5 PHP suites (253
assertions) and the Playwright e2e suite (87 assertions) passing — then `rsync -az --delete
--exclude-from=.distignore` (`docs/` and `DEVELOPER.md` added to `.distignore` alongside the existing
dev-only exclusions, since the in-app links point at GitHub rather than at anything served locally),
`wp rewrite flush`, and reverification that `/survey/` still returns `no-store, private` and `/` still
200s. `wp eval 'echo \Paumalu\SiteSurvey\VERSION;'` confirmed 0.7.0 live.

Deployed 0.7.1 (plugin metadata only): added `Plugin URI` (the GitHub repo) and `Author URI`
(`https://github.com/analogrithems`) to the plugin header so the wp-admin plugins list links out
correctly — verified via `get_plugin_data()` locally before deploying. No code paths changed; same
rsync + verify pattern as above.

A throwaway probe on production created an accepted survey, minted a token, fetched the resulting URL
over HTTP and force-deleted itself: 200, intro and line text present, `noindex` present, signature
form present, `no-store` header, zero rows left behind. That is the whole customer path proven in the
real environment, not only under the test harness.

**Note on wp-cli here:** the default `wp` on this host runs PHP 8.2, but the plugin targets 8.3.
Prefix with `WP_CLI_PHP=/usr/local/php83/bin/php`. To reinstall caps and flush rewrites after a
deploy, `wp eval "\Paumalu\SiteSurvey\Setup\Activator::activate();"` — safer than a
deactivate/activate cycle, which flushes once with no rules registered.

### Three real bugs the test suite caught before any customer saw them

Written up because two of them are the kind of thing that comes back.

1. **`'post_status' => 'any'` silently cannot see an accepted survey.** `WP_Query` resolves `any` to
   every status *except* those registered `exclude_from_search => true`, and both custom statuses set
   that flag. `Proposal::find_by_token()` used `any`, so it always returned `null` — meaning **no
   emailed customer proposal link could ever have worked**, since a proposal is only sent once the
   survey reaches `pe_accepted`. Fixed by adding `PostType\Statuses::all()` and asking for the list
   explicitly. **Any future query against surveys must use `Statuses::all()`, never `'any'`.**
2. **The PNG validator accepted magic bytes followed by garbage.** `getimagesizefromstring()` reads
   the IHDR fields and believes them — it verifies neither the chunk CRC nor that any pixel data
   follows, so eight valid magic bytes plus `"AAAA..."` came back as a legitimate
   1094795585-pixel-square PNG. `Signature::decode_png()` now bounds dimensions at
   `MAX_DIMENSION = 4000` **and then** calls `imagecreatefromstring()`, which is the only thing that
   proves an image exists. Order matters: decoding before the bounds check would turn the hardening
   step into a decompression bomb.
3. **`regenerate()` resurrected lines the reviewer had deleted.** The docblock promised "a line he
   deleted stays deleted", but the code only compared against the current groups, which cannot tell
   "never seen" from "seen and thrown out". Josh removes an upgrade the customer already declined,
   hits Refresh after a new finding, and the removed line is back in the document he then sends.
   Fixed with a `dismissed` list on the document: `Proposal::sanitize()` records ids that vanished
   from every bucket, drops any put back by hand, and `regenerate()` seeds its `seen` map from it.
   Moving a line between buckets keeps its id, so re-prioritising is not read as deletion.

### Test suites

All under `tests/`, run with the local `php` against the wp-env database. 246 assertions, all green
at 0.6.0: `proposal-test` 80, `review-test` 61, `photo-test` 40, `repository-test` 33, `rest-test` 32.

Two harness gotchas before writing more: `rest_do_request()` with `set_param()` does **not** populate
`get_json_params()`, so any endpoint reading the body needs
`set_header( 'Content-Type', 'application/json' )` plus `set_body()` — see the `$post_json` helper in
`proposal-test.php`. And `pre_wp_mail` is how the suite captures mail without sending; `$atts['to']`
is a string, not an array.

### Just completed in the current session

- `Frontend/Landing.php` + `templates/landing.php` — the public signpost at `/`. Verified locally:
  root renders it, `/survey/` still serves the app when authenticated, `/proposal/{bad}` still 404s.
  Two guards matter and both are load-bearing: the app's rewrites resolve to `index.php` with only a
  custom query var, so WordPress thinks `is_front_page()` is true on `/survey/` too; and it steps
  aside entirely if `show_on_front` is ever set to a real page.
- `src/components/ProposalEditor.js` mounted in `App.js` on `route.name === 'proposal'`, loading its
  own survey so it can show the customer name and refuse to offer Send until the survey is accepted.
- `src/components/SignaturePad.js` — on-site signing inside the app.
- "Build the proposal" entry point in `ReviewPanel`, shown once the survey is accepted.
- Proposal editor styles in `src/style.scss`; bundle rebuilt.
- `/surveys/{id}/proposal/sign` `image` arg changed from required to optional, so the REST route
  agrees with the server-side rule that a typed name is a valid signature.
- `tests/proposal-test.php` — 80 assertions across auto-draft, permissions, reviewer edits, photo
  ownership, regenerate, send, token, signatures, immutability and on-site signing. This is what
  surfaced the three bugs above.
- Deployed 0.6.0 to production and re-ran the activator; verified live.

### Dependency hygiene

GitHub Dependabot flagged 39 alerts, all in `package-lock.json`, all `devDependencies` (build
tooling only — `npm audit --omit=dev` was 0 both before and after, and none of it ships in the
plugin `.distignore` excludes `node_modules`). Bumped `@wordpress/env` 10→11.13 and
`@wordpress/scripts` 30→34.1, plus targeted `overrides` for `uuid`, `linkify-it`, `markdown-it`,
`serialize-javascript`, `adm-zip`, and `minimatch`, which closed 19 of the 39. That upgrade requires
**Node 20+** (`.nvmrc` added) — `serialize-javascript@7.x` needs the Web Crypto global Node only
exposes unflagged from v19, and `npm run build` fails with `ReferenceError: crypto is not defined`
under Node 18. `npm run build` was re-verified working under Node 20; the emitted `build/index.js`
is byte-identical (webpack's `[compared for emit]`), so this did not touch the shipped bundle.

The remaining ~20 alerts are all nested inside `@wordpress/env`'s own optional tooling (`puppeteer`,
`lighthouse`, `@sentry/node`, `@opentelemetry/*` — used by `wp-env`'s own test/telemetry
subcommands, which this project never calls) plus `extract-zip`, which has **no patched version
published yet** (GHSA-jmr9-qjv8-65gv). None of it is reachable from `npm run build`/`start`/`lint:js`
or from anything deployed. Not forcing further overrides there — the risk of breaking `wp-env`'s own
zip-extraction path outweighs closing alerts on code we never execute.

Known side effect: `npm run lint:js` currently crashes on a pre-existing upstream bug in
`@wordpress/eslint-plugin@25.9.0`'s `no-unknown-ds-tokens` rule (`ERR_REQUIRE_ESM` on its own
`design-tokens.mjs`) — unrelated to this change, not a security issue, not fixed by pinning to an
older `@wordpress/eslint-plugin` version since it ships bundled inside `@wordpress/scripts`. Doesn't
block `build`/`start`, so left as-is; worth a retry once WordPress ships a patch.

### wp-admin review link went to a blank screen

The CPT has `supports => [ 'title', 'author' ]` and no `add_meta_box()` calls anywhere — clicking a
survey's title in wp-admin (or "Edit" in row actions) landed a reviewer on the default post-edit
screen with nothing on it: no questions, no answers, no way to accept or request changes. That screen
was never meant to be used; the review UI is the front-end app at `/survey/{id}/review/`, but nothing
pointed there from wp-admin.

Fixed in `includes/Admin/ListTable.php`: a `get_edit_post_link` filter redirects a survey's edit link
to `Router::url( $id . '/review/' )`, and a `post_row_actions` filter relabels "Edit" to "Review" and
removes "Quick Edit" (its status dropdown doesn't know this plugin's custom statuses, and the review
screen is the only place a status should change). Verified locally end-to-end: clicking the title
lands on the front-end review app with full Q&A and Accept/Request-changes controls; row actions read
"Review | Trash"; submitting a request-changes note flips the list table's status filter to
"Changes Requested (1)"; the technician's own `/survey/` list already showed a "Changes Requested"
pill for the same survey with no code change needed (`src/components/SurveyList.js` already handled
it). All 5 PHP test suites (253 assertions) still green.

### Immediate next steps

**Done, later session:** `tests/e2e.mjs` now runs the full flow through the proposal and both signing
paths in a mobile viewport (87 assertions, local only). `assets/signature.js` and
`src/components/SignaturePad.js` are reconciled — both now accept a typed-name-only signature,
matching the server-side rule. See `DEVELOPER.md` for how to run the suite.

Still open:

1. Fill in the proposal footer once Aaron supplies licence number, logo and mailing address; those
   are Settings fields already, so it is data entry rather than code.

### Open questions for Aaron (asked, still unanswered)

- Hawaii contractor license number, logo, and business mailing address for the proposal footer.
- Whether declined proposals need a follow-up workflow or just a status.

Reviewer notifications are **not** tied to one named person — any account with the `editor` role (or
above) is a reviewer, and `Notifications::reviewer_emails()` picks up every such account automatically
unless the `notify_emails` setting is explicitly filled in. No specific person's email is a blocker for
this to work; just make sure whoever should be reviewing actually has an `editor` account.

---

## 8. Working style Aaron expects

From his standing preferences: **surface ambiguities and recommendations before writing code**, using
structured choices rather than open questions. He overrides recommendations sometimes (the
author-always-editable decision was one) — present the trade-off, then build what he picks.

Deployment cadence has been **per phase**, not per commit.

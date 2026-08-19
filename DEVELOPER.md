# Developer guide

This complements [README.md](README.md) — read that first for the project overview and the basic
`npm install` / `wp-env start` / `npm run build` quick start. This file goes deeper on the parts that
have actually caused problems: Node version pinning, `wp-env` command resolution, and a full
walkthrough of every test suite including the Playwright end-to-end run.

## Node version — this one bites

`.nvmrc` pins Node **20**, and `wp-scripts build` needs it: under Node 18, `webpack`/`serialize-javascript`
fail with `ReferenceError: crypto is not defined` (they expect the global `crypto` that only exists on
Node ≥19). If your shell's default `node` resolves to something older via a different nvm alias, switch
before building:

```bash
source ~/.nvm/nvm.sh && nvm use 20
npm run build
```

Do this before any `npm run build` / `npm run start` / bare `node tests/e2e.mjs` invocation if you hit
that error.

## `npx wp-env` can resolve to the wrong package

In some shells, `npx wp-env ...` resolves to an unrelated, deprecated npm package also named `wp-env`,
not this project's `@wordpress/env` devDependency. If `wp-env start` behaves strangely or errors on
flags that `@wordpress/env`'s docs say it should accept, call the local binary directly instead:

```bash
./node_modules/.bin/wp-env start
./node_modules/.bin/wp-env run cli wp <...>
```

The `npm run env:start` / `env:stop` / `env:clean` scripts in `package.json` already do this correctly,
so prefer those when you don't need extra flags.

## Running the PHP test suites

Each suite is a plain PHP script run through `wp eval-file` inside the wp-env container — no PHPUnit
bootstrap, no separate test database:

```bash
./node_modules/.bin/wp-env run cli wp eval-file wp-content/plugins/paumalu-site-survey/tests/repository-test.php
./node_modules/.bin/wp-env run cli wp eval-file wp-content/plugins/paumalu-site-survey/tests/rest-test.php
./node_modules/.bin/wp-env run cli wp eval-file wp-content/plugins/paumalu-site-survey/tests/photo-test.php
./node_modules/.bin/wp-env run cli wp eval-file wp-content/plugins/paumalu-site-survey/tests/review-test.php
./node_modules/.bin/wp-env run cli wp eval-file wp-content/plugins/paumalu-site-survey/tests/proposal-test.php
```

Each prints `[ ok ]` / `[FAIL]` lines and a pass/fail count. They write real posts, meta, and
attachments into the wp-env database — that's expected and fine, since wp-env's database is disposable
(`wp-env clean all` resets it).

## Running the end-to-end suite (`tests/e2e.mjs`)

**Never run this against production.** It creates surveys, uploads photos, and exercises the full
accept → proposal → sign flow — against `resi.paumaluelectric.com` that would leave junk in Josh's real
queue and send real email. It's local-only by construction: it depends on
`tests/mu/pe-mail-log.php`, a mail-capture mu-plugin that only `.wp-env.json` mounts, and reads
proposal tokens back from that mu-plugin's REST endpoint rather than a real mailbox — there's no
production equivalent for it to talk to.

### One-time setup: local test users

The suite logs in as two separate users in two separate browser contexts (a technician and a
reviewer, plus an anonymous third context for the customer). It does not create these accounts for
you — they need to exist first:

```bash
./node_modules/.bin/wp-env run cli wp user create fieldtech fieldtech@example.test \
  --role=pe_technician --user_pass=fieldpass123 --display_name="Field Tech"

./node_modules/.bin/wp-env run cli wp user create josh josh@example.test \
  --role=editor --user_pass=joshpass123 --display_name="Josh Hancock"
```

These are throwaway wp-env-local accounts, not anything that touches production.

### Running it

```bash
source ~/.nvm/nvm.sh && nvm use 20   # if node resolves <19 in your shell
node tests/e2e.mjs
```

It uses the system's installed Chrome (`channel: 'chrome'`) rather than downloading a Playwright
browser build, so there's no separate `playwright install` step. It emulates an iPhone 13 viewport
throughout, since the technician form is mobile-first and that's the only viewport that actually
matters for that half of the app.

Environment variables, all optional (defaults match the setup above):

| Variable | Default | Purpose |
|---|---|---|
| `PE_BASE` | `http://localhost:8888` | Site base URL |
| `PE_USER` / `PE_PASS` | `fieldtech` / `fieldpass123` | Technician login |
| `PE_REVIEWER` / `PE_REVIEWER_PASS` | `josh` / `joshpass123` | Reviewer login |

### What it walks through

One continuous run, in order: technician creates and fills a survey across two panels, attaches
photos, submits; reviewer opens it, sees the diff-changes banner, requests changes; technician sees
the reviewer's note, resubmits; reviewer accepts and builds a proposal (including a regression check
that refreshing the editor from the survey doesn't resurrect a manually-deleted line); reviewer sends
the proposal, and the suite reads the emailed link straight out of the mail-log mu-plugin (the token is
shown once, in that email, and never displayed again — even to the reviewer who sent it); an anonymous
customer context opens that link and signs it, once via typed-name-only and once via the drawn pad;
reviewer sees it locked as signed; and a final permission-boundary probe confirms the technician
account gets `403` on the accept and request-changes endpoints regardless of UI state.

Screenshots land in `tests/screenshots/` on each run for visual spot-checks. Console errors during the
run are asserted against, not just logged.

### If it fails

- `mail log unavailable` — `tests/mu` isn't mounted; confirm `.wp-env.json` still has
  `"wp-content/mu-plugins": "./tests/mu"` and restart wp-env.
- A hang at a navigation/submit step that used to pass — check for a stray `addEventListener` on a form
  without `{ once: true }` in whatever test code runs before that step; a non-`once` listener persists
  across the rest of the run and can intercept a later, unrelated submit on the same element.
- Login steps that appear to fill the form but never navigate past `wp-login.php` are a browser-timing
  issue, not a suite bug — rerunning is usually sufficient.

## Deploying

Bump the `Version:` docblock line and `const VERSION` in `paumalu-site-survey.php` together, commit,
push to `main`, then tag the commit and push the tag — `git tag vX.Y.Z && git push origin vX.Y.Z`. `.github/workflows/release.yml`
builds the bundle, packages a zip (same exclusion list as the old rsync deploy, via `.distignore`),
and publishes it as a GitHub Release. Every production site self-updates from that release feed via
the vendored Plugin Update Checker (`includes/Setup/Updates.php`) — no manual SFTP/SSH step for a
normal release.

See [CONTEXT.md](CONTEXT.md) for the Dreamhost environment specifics (no HTTPS, WP Super Cache, app
passwords can't install plugins), the fallback manual rsync procedure for a from-scratch install or a
broken self-updater, and the deploy history.

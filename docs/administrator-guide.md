# Administrator guide

This covers what only a WordPress Administrator needs to do: create accounts for technicians and
reviewers, and configure the plugin's settings. Day-to-day survey and proposal work is covered in the
[Technician guide](technician-guide.md) and [Editor guide](editor-guide.md) — administrators can do
everything an editor can, plus what's below.

## Roles this plugin uses

The plugin installs one new role and extends two existing ones the first time it activates:

| Role | Where it comes from | What it can do here |
|---|---|---|
| **Technician** (`pe_technician`) | Created by this plugin | Create and edit their own surveys, upload their own photos. Cannot see other technicians' surveys, cannot approve anything, and cannot access wp-admin at all — they're redirected straight to the front-end app. |
| **Editor** | Built into WordPress | Everything a Technician can do, plus: see every survey, request changes, accept surveys, build and send proposals. This is the "reviewer" role referenced throughout the Editor guide. |
| **Administrator** | Built into WordPress | Everything an Editor can do, plus manage plugin settings and WordPress itself. |

Technicians are deliberately limited to just this plugin — they get no ability to create posts, pages,
or touch anything else on the site, and the media library shows each of them only their own uploads.

## Creating a technician account

1. **Users → Add New** in wp-admin.
2. Fill in their name and email as usual.
3. Set **Role** to **Technician**.
4. Create the user and send them their login — they should go straight to `/survey/` afterward, not
   wp-admin (wp-admin will redirect them out if they try).

## Creating a reviewer (editor) account

1. **Users → Add New**.
2. Set **Role** to **Editor**.
3. That's the entire step — no separate plugin-specific role exists for reviewing. Anyone with the
   Editor role (or Administrator) automatically gets full review and proposal-sending access the
   moment their account is created, because those capabilities are attached to the Editor role itself,
   not assigned per-user.

If someone should stop reviewing surveys, change their WordPress role away from Editor (or
Administrator). There's no separate switch to flip in this plugin.

## Plugin settings

**Site Surveys → Settings** in wp-admin holds everything that appears on customer-facing proposals and
controls notifications:

- **Company name, contractor license number, phone, mailing address** — printed on every proposal.
- **Notify on submission** — comma-separated email addresses that get notified whenever a technician
  submits a survey for review. Add every reviewer's email here, or the team may not know a survey is
  waiting.
- **Proposal link expires after (days)** — how long a customer's emailed sign-off link stays valid
  (default 60).
- **Inspection disclaimer** — the visual-inspection / not-a-code-compliance-certification language
  shown on the proposal.
- **Sign-off terms** — the wording the customer approves when they sign; no pricing appears here or
  anywhere else in the document by design.

## Where surveys live in wp-admin

**Site Surveys** in the left menu is a triage list, not an editing screen — there's nothing to fill in
on a survey's own edit page, since all of the actual questions and answers live in the front-end app.
Use the list to scan status, customer, technician, and failure counts, and click through to open the
front-end review screen for anything that needs a decision.

## Documentation links in the app

A link back to this repository and its docs is shown to technicians on their front-end surveys page,
and to reviewers/administrators under **Site Surveys** in wp-admin (administrators see both the Editor
and Administrator guide links; editors see only the Editor guide link). If you fork or relocate this
plugin, update the repository URL in `includes/Setup/Links.php` so those links keep pointing somewhere
useful.

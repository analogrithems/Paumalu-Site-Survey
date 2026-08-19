# Editor guide (reviewing surveys and sending proposals)

This is for whoever reviews technicians' submitted inspections, decides what goes back to them, and
turns an accepted survey into a proposal the customer can sign. In this plugin that's anyone with the
**Editor** role (or Administrator) — referred to below as "you" / "the reviewer."

You work in the same front-end app the technicians use, at `/survey/`, not in wp-admin — wp-admin is
only for triage and settings (see below).

## Finding surveys to review

At `/survey/` you see **All surveys**, not just your own — every technician's, with customer name,
status, and a red/yellow/blue failure count. You can also triage from **wp-admin → Site Surveys**,
which gives you a sortable list with the same information; clicking a row's title or its **Review**
link takes you to the same front-end review screen. There's nothing to review inside wp-admin itself —
survey posts have no editable fields there.

## Reviewing a submitted survey

Open a survey that's **Submitted** and you'll see the full form exactly as the technician filled it
in, with a **Review decision** panel above it. Read through, then:

- **Request changes** — requires a note explaining what needs to change (the button won't fire without
  one). This sends it back to the technician with your note attached, and it moves out of your queue
  until they resubmit — there's nothing further for you to do on it until then. If you open it again
  while it's waiting on them, you'll see a banner saying so, with your last note repeated.
- **Accept survey** — accepts it as-is. An optional note is still recorded to the thread even on
  accept, useful for anything worth logging that isn't itself a blocker.

If the technician edited the survey after submitting it (including while it was in your queue), you'll
see a banner listing exactly what changed since submission, with an option to show the details — a
status flip, a note change, new photos — so you're never reviewing a document that's silently drifted
from what you last read.

## Notes

Notes are a running two-way thread on the survey, visible to both you and the technician. Use them to
explain a change request in more detail, ask a follow-up question, or just leave a record — anything
written here is what the technician sees when they come back to a returned survey.

## Building the proposal

Once a survey is **Accepted**, an **Build the proposal** button appears — this takes you to the
proposal editor. Opening it auto-drafts a proposal from every failed item on the survey, grouped into
three tiers that mirror the color coding used everywhere else in the app:

- 🔴 **Immediate Hazards**
- 🟡 **Recommended Maintenance**
- 🔵 **Optional Upgrades**

This draft is a starting point, not the final document — you're expected to edit it:

- Rewrite or trim any line's wording — it's pre-filled from the catalog but it's your name that goes
  out with it.
- Move a line to a different tier if you judge it differently than the default.
- Remove a line the customer doesn't need to see, or add a custom one with **+ Add an item**.
- Write an opening note to the customer.
- Pick up to four photos from the ones the technician attached, each with its own caption. The
  proposal is a sales document, not a photo dump of everything captured on site — choose the shots
  that actually make the case.

**Refresh from survey** re-pulls the auto-draft from the current state of the survey — useful if the
technician added findings after your first draft, but it only restarts the automatic generation; your
own custom lines and edits are yours to manage, not silently preserved across a refresh you didn't ask
for.

**Save draft** just saves your work. Nothing goes to the customer until you explicitly send it.

There's no pricing anywhere in this document by design — it's scoped as a recommended plan the customer
approves, not a dollar estimate.

## Sending it and getting it signed

**Send to customer** emails the customer a private link to a clean, mobile-friendly page showing your
proposal — sign-off included. It's disabled until the survey is Accepted, so you can draft it early but
can't jump the gun on sending it.

Two ways a proposal gets signed:

- **The emailed link** — the customer opens it on their own device and signs there. This works with a
  typed name alone or a drawn signature; either one is a valid, legally binding sign-off, so don't
  worry if a customer's phone can't manage the drawing pad.
- **On site** — if you're standing there with the customer, the same signing pad appears right in the
  proposal editor under **Sign on site**. Anything unsaved is saved first, so what they sign is exactly
  what's on your screen.

Once signed, the proposal locks — you can still read it, but not edit it. That's intentional: it's a
record of exactly what was agreed to.

## Checking the send log and fixing a wrong email address

Above the **Send to customer** button is a **Send to** field, pre-filled with the customer's email on
file. You can edit it right there before sending — handy when you catch a typo, or when the customer
gives you a better address to use than the one on the original survey. Changing it here only affects
where this proposal is sent; it doesn't update the customer's saved contact info.

Every time a proposal is emailed — the first send and any resend — it's added to a **Send history**
list on this screen, most recent first, showing the address, the time, and whether it went out:

- **Sent** means our mail server handed the message off successfully. It does **not** confirm the
  customer's inbox actually received or opened it — there's no read receipt or delivery confirmation
  here, just proof that the attempt was made and accepted for delivery on our end.
- **Send failed** means the attempt didn't go through — usually a bad or mistyped address. If there's
  more detail available (like a rejection from the mail server), it's shown under the entry.

If you sent to the wrong address, just correct it in the **Send to** field and click **Resend to
customer**. Every attempt stays in the log — nothing gets overwritten — so you can always see the full
history of who this proposal has been emailed to and when.

## What you can't do here

Requesting changes, accepting, and sending proposals are gated to the Editor/Administrator roles — a
technician account cannot reach any of these actions even by guessing a URL or calling the API
directly. If something you expect to be able to do isn't available, check with whoever administers the
site about your account's role (see the [Administrator guide](administrator-guide.md)).

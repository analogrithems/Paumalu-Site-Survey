# Technician guide

You use this app to run a residential electrical inspection from your phone, on site, instead of
filling out a paper checklist. Nothing here requires wp-admin — you never see it, and you don't need
to.

## Signing in and finding your surveys

Go to `/survey/` on the company site and log in with the account Josh set up for you. You land on
**My surveys** — every inspection you've started, with its status (Draft, Submitted, Changes
requested, or Accepted) and a red/yellow/blue count of anything failed so far. You only ever see your
own surveys here, never another technician's.

Tap **New survey** to start one, or tap an existing card to keep working on it.

## Filling out an inspection

The form is split into steps along the top: Customer & site, Panels, each inspection section, Upgrade
opportunities, Summary, and Notes. Tap a step to jump straight to it, or use **← Back** / **Next →** to
move through in order — there's no requirement to go in order, and nothing is lost by skipping around.

- **Customer & site** — name, contact info, service address, year built, service size, meter number.
- **Panels** — every panel you inspect (main panel, plus any subpanels) gets its own entry with brand,
  model, amperage, its own checklist, and its own electrical readings (actual L1-N / L2-N / L1-L2 volts
  and measured load, not just a checkmark). Tap **+ Add panel** for each subpanel you find; each one
  repeats the same panel-level checklist independently.
- **Inspection sections** — each item is Pass / Fail / N/A. A failed item lets you set a severity, add
  a note, and attach photos. The Safety Hazards section is worded the other way around — mark what you
  actually found as **Present** — but it's recorded the same way underneath.
- **Upgrade opportunities** — things worth quoting even though nothing is actually wrong (surge
  protection, EV charger circuit, generator interlock, and similar). Check the ones the homeowner might
  want and add a note; these don't count against the pass/fail totals.
- **Summary** — overall condition, a recommended timeframe, and free-text notes for immediate concerns,
  maintenance, and upgrades.

### Photos

Attach photos directly from an item's Fail state. They're resized and compressed on your phone before
upload, so this works fine over weak signal, and HEIC photos from an iPhone are converted automatically
— you don't need to change your camera settings.

### Autosave

Your work saves automatically as you go — you don't need to hit a save button, and there's no "did that
save?" moment. If your phone loses signal mid-survey, what you've typed is also kept on the device
itself; if you reopen a survey with unsynced local changes, you'll be offered a chance to restore them
before anything is overwritten.

## Submitting for review

Once you've filled in the required fields (customer name, service address, at least one answered
item), the **Submit for review** button appears on the Summary step. Submitting sends the survey to
Josh (or whoever reviews at your company) and moves it out of your active editing queue — though you
can still open and read it while it's under review.

## If changes are requested

If a reviewer sends your survey back, you'll see a banner right at the top of the form explaining what
they asked for, with a shortcut straight to the Notes step to read the full note and reply. Fix what
was asked, then submit again the same way — there's no separate "resubmit" step, submitting again is
all it takes.

## Editing after submission

You can keep editing your own surveys even after they've been submitted or accepted — nothing locks
you out. If you edit a survey after it's already been reviewed, the reviewer will see a note that
things changed since they last looked at it, so don't worry about being blocked; just be aware your
edit will flag for a second look.

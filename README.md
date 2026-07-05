# mod_confcheckin

Conference Check-in — a Moodle activity module for selling/issuing conference tickets, generating QR-coded badges, and recording attendance.

Part of the [Conference Tools](https://github.com/adamjenkins/moodle-conference-tools) suite:

- [mod_confsubmissions](https://github.com/adamjenkins/moodle-mod_confsubmissions) — call for abstracts / submissions
- [mod_confprogram](https://github.com/adamjenkins/moodle-mod_confprogram) — reviewer vetting workflow + public program display
- [mod_confscheduler](https://github.com/adamjenkins/moodle-mod_confscheduler) — drag-and-drop block schedule / timetable
- **mod_confcheckin** (this plugin) — tickets, badges, QR check-in, certificates

## What it does (once fully built)

- **Ticket types**: organisers define multiple ticket types per instance (e.g. 1-day/3-day, student, presenter, attendee) with price, currency, capacity, a validity window (day-based access rules), and an optional "presenter only" restriction checked against `mod_confprogram`'s API (accepted submission + is a speaker). A ticket type can also be linked to a course group or enrolment method so that joining/enrolling automatically grants a free ticket, kept in sync in real time.
- **Purchase & payment**: tickets are bought through Moodle's `core_payment` subsystem (`classes/payment/service_provider.php`, modeled on `enrol_fee`'s implementation), working generically with whatever gateway(s) are installed. Free tickets are issued via promo code, a specified free-entry path, or an auto-grant group/enrolment link, with no receipt generated for a free ticket.
- **Badges, tickets, receipts & certificates**: organiser-edited TinyMCE templates (with embeddable placeholder fields pulled from `mod_confprogram`, when the ticket holder is a presenter, the ticket's own course, and the user profile — using a sitewide-configurable delimiter, default `[[fullname]]`) are rendered to PDF via Moodle's `pdf` class (TCPDF), each badge carrying a unique QR code generated via `core_qrcode`.
- **Check-in**: a web-based QR scanner (usable both in a browser and, via `db/mobile.php`, inside the Moodle app as a responsive web view) records a check-in against a ticket. Attendance certificates are gated on a recorded check-in and downloadable by the attendee.

## Current status

Ticket types, presenter-ticket eligibility, and the purchase flow (Phase 4.3); badge/ticket/receipt/certificate template editing, QR-coded PDF generation, and per-user/bulk downloads (Phase 4.4); the QR check-in scanner, attendance certificates, and a Moodle app webview addon (Phase 4.5); and a sitewide placeholder-delimiter setting plus group/enrolment-linked auto-grant tickets with an orphaned-tickets report (Phase 4.5 follow-up) — all per the coordination repo's `TASKLIST.md` — are built. `tickettypes.php`/`promocodes.php` let organisers manage ticket types (name, price, currency, capacity, presenter-only flag, validity window, group/enrolment auto-grant link) and promo codes; `purchase.php` lets attendees claim a price-zero ticket, redeem a promo code, or pay for a priced ticket type via `core_payment` (`classes/payment/service_provider.php`, modeled on `enrol_fee`). `templates.php` lets organisers edit each template type in TinyMCE, with placeholders substituted (`classes/local/placeholder.php`) and rendered to a real PDF (`classes/local/pdf_generator.php`, Moodle's own `pdf`/TCPDF wrapper) — `badge.php` for a single ticket, `badges.php` for a bulk ZIP. `scan.php`/`amd/src/scanner.js` record check-ins; `orphanedtickets.php` lets organisers manually revoke a granted ticket whose group/enrolment link no longer holds.

## Architecture notes

- **Capacity/redemption-count race safety**: `classes/local/ticket_service.php` uses `SELECT ... FOR UPDATE` row locking inside a Moodle delegated transaction to make a capacity check-and-increment (and a promo code's `timesused` check-and-increment) a single indivisible operation under concurrent requests — Moodle's DML API does not expose an affected-row count from a conditional `UPDATE`, so that simpler approach isn't available. Verified live: two simultaneous claims of a capacity-1 ticket type correctly result in exactly one issued ticket, never zero or two.
- **Presenter-ticket eligibility** (`classes/local/eligibility.php`) deliberately does NOT check `mod_confprogram`'s Display-phase embargo — a presenter can claim their presenter ticket the moment their submission is accepted, not only once the programme is publicly displayed. This is a deliberate product decision, not an oversight; see that class's docblock if it ever needs revisiting.
- **QR tokens** (`ticket_service::generate_qrtoken()`) are generated via `bin2hex(random_bytes(32))` — a genuine CSPRNG, hex-encoded to avoid the slight modulo bias Moodle's own `random_string()` helper has. A guessable token would let anyone forge/scan another attendee's check-in.
- **`paymentarea`/`itemid` convention**: `paymentarea` is always `'tickettype'`, `itemid` is a `confcheckin_tickettype.id` — nothing is inserted into `confcheckin_ticket` until `service_provider::deliver_order()` runs after a successful payment (matching `enrol_fee`'s own pattern of only calling `enrol_user()` inside `deliver_order()`, never pre-creating a pending row).
- **A price-zero ticket type, a promo code redemption, and a group/enrolment auto-grant all bypass `core_payment` entirely** (origin `free`/`promo`/`grant` respectively) — there's nothing to actually charge, so no payment record is created and no receipt is offered (receipts only ever apply to a genuinely paid `origin = 'purchase'` ticket).
- **QR check-in scanning, dual-path design**: no JS QR-decoding library ships in Moodle core. `amd/src/scanner.js` always offers a plain, always-focused text input that auto-submits on Enter (reliable because USB/Bluetooth badge scanners emulate keyboard input, zero dependency); it additionally feature-detects the native browser `BarcodeDetector` API (`'BarcodeDetector' in window`) for camera-based scanning as a bonus, since that API is not universally supported (notably absent in Safari/WebKit).
- **`qrtoken` is globally unique**, not per-instance, so `checkin_service::record_checkin()` looks it up with no instance filter, then explicitly re-checks the ticket's own instance against where the scan happened — a deliberate, documented departure from this project's usual "same message regardless" IDOR pattern, justified because the caller already holds `scancheckin` and is scanning a badge an attendee physically handed them.
- **Mobile app webview addon** (`db/mobile.php`/`classes/output/mobile.php`) uses the documented `<core-iframe>` site-plugins pattern to reuse `scan.php`/`view.php` inside the Moodle app, rather than a fully custom Ionic-JS addon. **Unverified against a real Moodle app client** — no mobile emulator/build tooling is available in this environment; a known limitation, matching this project's established pattern for untestable integrations.
- **Privacy: `confcheckin_checkin` is a two-person record** (an attendee via `ticketid`, a `scannedby` staff member). Both are covered by the read-side privacy methods, but deleting a user's own data never touches a `scannedby` reference on someone else's check-in — there is no nullable/anonymisation slot for that `NOT NULL` column, and it is treated as an audit record, akin to Moodle's own grade history. A documented, accepted limitation.
- **Template placeholder delimiter is sitewide, not per-instance** (`mod_confcheckin/delimiterstart`/`delimiterend` admin settings, default `[[`/`]]`): organisers across a site share one convention, and changing it after templates are authored requires updating those templates regardless of scope, so a per-instance setting would only multiply that maintenance burden.
- **Group/enrolment auto-grant tickets are never auto-revoked** when the granting membership/enrolment is later removed (`ticket_service::find_orphaned_tickets()`/`revoke_ticket()`, `orphanedtickets.php`) — a deliberate choice (user feedback) to avoid surprising an attendee by yanking a ticket over an unrelated group change; an organiser reviews and revokes each one manually instead. `revoke_ticket()` is the first ticket-removal path this plugin has ever had, and decrements `confcheckin_tickettype.soldcount` to free the capacity.
- **A ticket type's `groupid`/`enrolid` is re-verified as belonging to its own course at both the form-validation and page level** (`classes/form/tickettype_form.php::validation()` + `tickettypes.php`), not just scoped in the rendered `<select>` — a `moodle-reviewer` pass caught that a crafted POST could otherwise link a ticket type to another course's group or enrolment method, letting unrelated membership churn in that other course silently consume this instance's ticket capacity.
- **`[[presentationinfo]]` is a "template within a template"**: each template TYPE (badge/ticket/receipt/certificate) has its own configurable per-presentation mini format string (`confcheckin_template.presentationinfoformat`), applied once per accepted submission a ticket holder presents and joined with a line break — so, unlike `[[submissiontitle]]`/`[[track]]` (kept, unchanged, for backwards compatibility), it lists ALL of a multi-presentation speaker's talks, not just the first. The mini format's own placeholder syntax (`{title}`/`{track}`) is deliberately single-braced, distinct from the sitewide `[[ ]]`-style delimiter that wraps `[[presentationinfo]]` itself, so the two nesting levels can never be confused or collide — see `classes/local/placeholder.php::render_presentationinfo()`.
- **`eligibility::find_presenter_submissions()`/`placeholder`'s `presentationinfoformat` lookup are both cached per request via `cache::MODE_REQUEST`** (a `moodle-reviewer` finding, Phase 4.6: a bulk badge/ticket ZIP export otherwise re-resolves the whole `mod_confprogram`/`mod_confsubmissions` chain and re-queries `presentationinfoformat` once per ticket, all against the same instance). Since these cache a DB-backed value, writes must invalidate them explicitly — `templates.php` calls `placeholder::forget_presentationinfo_format()` immediately after saving, so a save-then-render within the same request never sees a stale cached value.

## Requirements

- Moodle 5.2 (`2026042000`) or later.
- **mod_confprogram** installed in the same course (the only hard dependency, needed for presenter-ticket eligibility checks via its public API). Unlike `mod_confscheduler`, this plugin does **not** depend on `mod_confsubmissions` directly — see the coordination repo's `RELATIONS.md` for the full dependency graph.

## Installation

```
git clone https://github.com/adamjenkins/moodle-mod_confcheckin.git mod/confcheckin
php admin/cli/upgrade.php
```

## License

GNU GPL v3 or later. See [LICENSE](LICENSE).

## Author

Adam Jenkins <adam@wisecat.net>

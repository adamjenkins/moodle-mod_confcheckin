# mod_confcheckin

Conference Check-in — a Moodle activity module for selling/issuing conference tickets, generating QR-coded badges, and recording attendance.

Part of the [Conference Tools](https://github.com/adamjenkins/moodle-conference-tools) suite:

- [mod_confsubmissions](https://github.com/adamjenkins/moodle-mod_confsubmissions) — call for abstracts / submissions
- [mod_confprogram](https://github.com/adamjenkins/moodle-mod_confprogram) — reviewer vetting workflow + public program display
- [mod_confscheduler](https://github.com/adamjenkins/moodle-mod_confscheduler) — drag-and-drop block schedule / timetable
- **mod_confcheckin** (this plugin) — tickets, badges, QR check-in, certificates

## What it does (once fully built)

- **Ticket types**: organisers define multiple ticket types per instance (e.g. 1-day/3-day, student, presenter, attendee) with price, currency, capacity, a validity window (day-based access rules), and an optional "presenter only" restriction checked against `mod_confprogram`'s API (accepted submission + is a speaker).
- **Purchase & payment**: tickets are bought through Moodle's `core_payment` subsystem (`classes/payment/service_provider.php`, modeled on `enrol_fee`'s implementation), working generically with whatever gateway(s) are installed. Free tickets are issued via promo code or a specified free-entry path, with no receipt generated for free tickets.
- **Badges, tickets, receipts & certificates**: organiser-edited TinyMCE templates (with embeddable placeholder fields pulled from `mod_confprogram`, when the ticket holder is a presenter, and the user profile) are rendered to PDF via Moodle's `pdf` class (TCPDF), each badge carrying a unique QR code generated via `core_qrcode`.
- **Check-in**: a web-based QR scanner (usable both in a browser and, via `db/mobile.php`, inside the Moodle app as a responsive web view) records a check-in against a ticket. Attendance certificates are gated on a recorded check-in and downloadable by the attendee.

## Current status

Ticket types, presenter-ticket eligibility, and the purchase flow (Phase 4.3 of the coordination repo's `TASKLIST.md`) are built. `tickettypes.php`/`promocodes.php` let organisers manage ticket types (name, price, currency, capacity, presenter-only flag, validity window) and promo codes; `purchase.php` lets attendees claim a price-zero ticket, redeem a promo code, or pay for a priced ticket type via `core_payment` (`classes/payment/service_provider.php`, modeled on `enrol_fee`). PDF/badge generation, QR-code rendering, the check-in scanner, and attendance certificates (Phases 4.4-4.5) are not yet built.

## Architecture notes

- **Capacity/redemption-count race safety**: `classes/local/ticket_service.php` uses `SELECT ... FOR UPDATE` row locking inside a Moodle delegated transaction to make a capacity check-and-increment (and a promo code's `timesused` check-and-increment) a single indivisible operation under concurrent requests — Moodle's DML API does not expose an affected-row count from a conditional `UPDATE`, so that simpler approach isn't available. Verified live: two simultaneous claims of a capacity-1 ticket type correctly result in exactly one issued ticket, never zero or two.
- **Presenter-ticket eligibility** (`classes/local/eligibility.php`) deliberately does NOT check `mod_confprogram`'s Display-phase embargo — a presenter can claim their presenter ticket the moment their submission is accepted, not only once the programme is publicly displayed. This is a deliberate product decision, not an oversight; see that class's docblock if it ever needs revisiting.
- **QR tokens** (`ticket_service::generate_qrtoken()`) are generated via `bin2hex(random_bytes(32))` — a genuine CSPRNG, hex-encoded to avoid the slight modulo bias Moodle's own `random_string()` helper has. A guessable token would let anyone forge/scan another attendee's check-in once Phase 4.5 builds the scanner.
- **`paymentarea`/`itemid` convention**: `paymentarea` is always `'tickettype'`, `itemid` is a `confcheckin_tickettype.id` — nothing is inserted into `confcheckin_ticket` until `service_provider::deliver_order()` runs after a successful payment (matching `enrol_fee`'s own pattern of only calling `enrol_user()` inside `deliver_order()`, never pre-creating a pending row).
- **A price-zero ticket type and a promo code redemption both bypass `core_payment` entirely** (origin `free`/`promo` respectively) — there's nothing to actually charge, so no payment record is created and no receipt is offered (receipts only ever apply to a genuinely paid `origin = 'purchase'` ticket, in a later phase).

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

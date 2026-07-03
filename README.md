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

This repo is currently at the **scaffold stage** (Phases 4.1-4.2 of the coordination repo's `TASKLIST.md`): plugin skeleton, full schema, capabilities, a real (non-null) privacy provider, base language strings, and a minimal settings/view page. None of the ticket purchase flow, payment integration, PDF/badge generation, QR scanning, or check-in logic described above is implemented yet — those are Phases 4.3-4.5.

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

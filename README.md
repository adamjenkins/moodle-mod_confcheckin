# mod_confcheckin

**Conference Check-in** — a Moodle activity for issuing and selling conference tickets, printing QR-coded badges, recording attendance, and issuing certificates.

*Documentation: English (this file) · [日本語](README.ja.md)*

Part of the [Conference Tools](https://github.com/adamjenkins/moodle-conference-tools) suite:

- [mod_confsubmissions](https://github.com/adamjenkins/moodle-mod_confsubmissions) — call for abstracts
- [mod_confprogram](https://github.com/adamjenkins/moodle-mod_confprogram) — reviewer vetting + public program
- [mod_confscheduler](https://github.com/adamjenkins/moodle-mod_confscheduler) — drag-and-drop block schedule
- **mod_confcheckin** (this plugin) — tickets, badges, QR check-in

## What it does

- **Ticket types** — define any number per instance (e.g. 1-day/3-day, student, presenter), each with a price (defaults to JPY, any ISO 4217 currency), capacity, validity window, and an optional cap on how many of that type a single user may hold (default 1). A type can be *presenter only* (checked against Conference Program — an accepted submission you speak on), or gated on membership of a course group or an enrolment method.
- **Purchase & free tickets** — priced tickets are bought through Moodle's `core_payment` (any installed gateway). Price-zero types, promo codes, and group/enrolment auto-grants issue a ticket directly, with no payment or receipt.
- **Auto-grant** — link a ticket type to a course group or enrolment method to issue a free ticket automatically on join/enrol, kept in sync. An **Orphaned tickets** report lists auto-granted tickets whose link no longer holds, for manual revocation.
- **Badges, tickets, receipts & certificates** — organiser-edited templates (with placeholder fields drawn from the program, course and user profile) render to PDF, each badge carrying a unique QR code. Download per-attendee or as a bulk ZIP.
- **Check-in** — a web QR scanner (a plain text field that any USB/Bluetooth badge scanner drives, plus optional camera scanning) records attendance; it also works inside the Moodle app. A successful camera scan flashes the preview green with a checkmark and plays a short (mutable) beep. Attendance certificates unlock once a check-in is recorded.
- **Check-in report** — a sortable list of every enrolled participant showing ticket-holding and check-in status/time, including who hasn't checked in yet and who holds no ticket at all, with a manual check-in/remove-check-in toggle. Visible to editing teachers and managers.

## Requirements

- Moodle 5.2 (`2026042000`) or later.
- mod_confprogram installed in the same course (its only hard dependency, for presenter-ticket eligibility). It does **not** depend on mod_confsubmissions directly.

## Installation

```
git clone https://github.com/adamjenkins/moodle-mod_confcheckin.git mod/confcheckin
php admin/cli/upgrade.php
```

## Notes

- Ticket QR tokens are cryptographically random and globally unique; a restored ticket always gets a fresh token, and each ticket type's sold count is recomputed from the tickets that actually exist.
- The badge/ticket placeholder delimiter is a site-wide admin setting (default `[[ ]]`).
- The Moodle-app view uses the `<core-iframe>` site-plugins pattern and has not been verified against a real app build in this environment.

## License

GNU GPL v3 or later. See [LICENSE](LICENSE).

## Author

Adam Jenkins <adam@wisecat.net>

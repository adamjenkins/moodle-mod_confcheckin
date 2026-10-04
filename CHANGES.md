# Changes

## v0.1.1

First tagged release (the in-development v0.1.0 was never tagged).

Conference Check-in: a Moodle activity for issuing and selling conference
tickets, printing QR-coded badges, recording attendance, and issuing
certificates. Part of the Conference Tools suite.

- Ticket types with a price, capacity, an optional availability window, an
  optional per-user cap, and presenter-only or group/enrolment eligibility.
- Paid tickets through Moodle's payment subsystem; free tickets, promo codes
  and group/enrolment auto-grants issue a ticket directly.
- Organiser-edited badge, ticket, receipt and certificate templates rendered to
  PDF, each badge carrying a unique QR code.
- A camera-based QR check-in scanner and a sortable
  check-in report with a manual check-in toggle.
- Supported on Moodle 5.2–5.3. Requires mod_confprogram.
- Installable with Composer (`adamjenkins/moodle-mod_confcheckin`, which also
  requires `adamjenkins/moodle-mod_confprogram`).
- Releases are published to the camp registry.

See `changelog.md` for the full development history of this version.

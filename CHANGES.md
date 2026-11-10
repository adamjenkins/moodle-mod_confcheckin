# Changes

## v0.1.4

- Security: badge, ticket, receipt and certificate templates can no longer make the server fetch
  arbitrary web addresses or read files from the server's disk. Remote images in a template are
  now downloaded through Moodle's own connection settings (so the site's blocked hosts and ports
  apply) and embedded if they are PNG, JPEG or GIF; any other image source is left out of the PDF.
- Security: a presenter's submission title or track name can no longer break a template that
  places it inside an HTML attribute.
- Security: the payment account must be one that is available in this course. The activity
  settings refuse any other account, checkout refuses to start without a usable one, and a
  restored activity keeps its payment account only when restored on the same site into a course
  that can use it (otherwise the organiser chooses one again).
- Buyers are no longer charged for a ticket that would then be refused: visibility, eligibility,
  the availability window, the per-user limit and remaining capacity are checked before checkout
  starts, as well as when the ticket is issued.
- Promo codes: after 10 wrong codes a user cannot redeem codes in that activity for 15 minutes,
  and new codes must be at least 6 characters long. Existing shorter codes keep working.
- Privacy: ticket payments are now included when a user's data is exported or deleted.
- Restoring a backup checks ticket types and promo codes. Ticket types with an invalid price,
  currency or capacity are restored hidden so the organiser can review them; promo codes that are
  invalid or clash with an existing code are skipped.
- The check-in report respects separate groups: a viewer who cannot access all groups sees, and
  can check in, only members of their own group. The report now shows the group menu, and its
  description is clearer.
- The README no longer claims the scanner has been tested in the Moodle app.

See `changelog.md` for the full development history.

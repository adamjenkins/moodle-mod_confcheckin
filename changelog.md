# Changelog

## [0.1.0] - Unreleased

- Initial scaffold (Phases 4.1-4.2): plugin skeleton (`version.php`,
  `lib.php`, minimal `mod_form.php`/`view.php`), full schema (`confcheckin`,
  `confcheckin_tickettype`, `confcheckin_ticket`, `confcheckin_checkin`,
  `confcheckin_template`, `confcheckin_promocode`), capabilities
  (`:addinstance`, `:purchase`, `:managetickettypes`, `:scancheckin`,
  `:downloadbadges`, `:viewowncertificate`, `:managetemplates`), and a real
  (non-null) privacy provider — unlike `mod_confscheduler`, this plugin does
  store personal data (ticket ownership, check-in timestamps), so
  `get_metadata()` is fully implemented while the request-side methods
  (`get_contexts_for_userid`, `export_user_data`, `delete_data_for_user`,
  `delete_data_for_all_users_in_context`, and the `core_userlist_provider`
  methods) are stubbed to throw a `coding_exception` rather than silently
  under-reporting personal data, pending Phase 4.6.
- `$plugin->dependencies` on `mod_confprogram` only (presenter-ticket
  eligibility, via its public API in a later phase) — deliberately not on
  `mod_confsubmissions`/`mod_confscheduler` directly, per the coordination
  repo's `RELATIONS.md` dependency graph.
- `confcheckin_tickettype.price`/`.currency` are `char(20)`/`char(3)`
  fields, not integer minor-unit cents as originally sketched: verified
  against this Moodle checkout's own `{payments}.amount`/`{payments}.currency`
  (core_payment) and `{enrol}.cost`/`{enrol}.currency` (enrol_fee) columns,
  both of which store a decimal-string amount plus an ISO 4217 currency
  code in exactly that shape. Matching it now means Phase 4.3's
  `core_payment` integration needs no schema change.
- No cross-plugin table references in this schema at all (unlike
  `mod_confprogram`/`mod_confscheduler`'s `submissionid` columns): the only
  planned cross-plugin coupling is a future PHP-level call into
  `mod_confprogram\api::get_decision()`/`mod_confsubmissions\api::get_speakers()`
  for presenter-ticket eligibility, not a schema-level reference.
- No ticket purchase flow, payment integration, PDF/badge generation, QR
  scanning, or check-in logic yet — `view.php` renders only the activity
  intro plus a "still under construction" notice. See `README.md`'s
  "Current status" section.

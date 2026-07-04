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
  `get_metadata()` is fully implemented, while the request-side methods
  (`get_contexts_for_userid`, `export_user_data`, `delete_data_for_user`,
  `delete_data_for_all_users_in_context`, and the `core_userlist_provider`
  methods) return honest empty/no-op results rather than throwing —
  correct at scaffold time since no code path yet wrote to
  `confcheckin_ticket`/`confcheckin_checkin` (a `moodle-reviewer` pass on a
  sibling plugin's near-identical scaffold decision established that
  throwing here would only generate false-positive Data Protection Officer
  alerts on every unrelated GDPR request site-wide, with zero correctness
  benefit over returning empty directly — see `mod_confscheduler`'s
  `SUMMARY.md` entry in the coordination repo for the full reasoning this
  plugin followed from the start). Phase 4.3 below replaces these stubs
  with real implementations now that ticket issuance gives
  `confcheckin_ticket.userid` actual rows to report.
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

- Phase 4.3: ticket types, presenter-ticket eligibility, promo codes, and
  the `core_payment` purchase flow. Schema bumped once (`2026070401`,
  adding `confcheckin.confprogramcmid`/`.paymentaccountid` and
  `confcheckin_tickettype.soldcount`); see `db/upgrade.php`.
  - `confprogramcmid` (nullable, unlike `mod_confscheduler`'s hard
    requirement — a confcheckin instance with no link simply has no usable
    `presenteronly` ticket types) and `paymentaccountid` (nullable — an
    instance selling only free/promo ticket types never needs one) are
    now settable in `mod_form.php`'s General section, both server-validated
    (`confprogramcmid` against a course-scoped option set, matching
    `mod_confscheduler`'s established pattern).
  - `tickettypes.php`/`promocodes.php` (both gated on
    `mod/confcheckin:managetickettypes`) give organisers full CRUD over
    ticket types and promo codes, following the shared
    `classes/local/instance_helper.php` IDOR-scoping pattern (the
    plain-page equivalent of `mod_confscheduler`'s
    `scheduler_context_trait`) every entry point in this plugin now uses.
  - `classes/local/eligibility.php::is_presenter()` checks whether a user
    is a speaker (by userid — a manual-entry co-presenter never matches)
    on an accepted submission in the linked `mod_confprogram` instance.
    Deliberately does NOT respect the Display-phase embargo — a presenter
    can claim their ticket the moment they're accepted, a deliberate
    product decision documented in that class's docblock, not an
    oversight.
  - `classes/local/ticket_service.php` issues tickets for all three
    origins (`free`, `promo`, `purchase`) with genuine concurrency safety:
    a capacity check-and-increment (and a promo code's `timesused`
    check-and-increment) is made atomic via `SELECT ... FOR UPDATE` row
    locking inside a Moodle delegated transaction, since Moodle's DML API
    exposes no affected-row count from a conditional `UPDATE` to build the
    more obvious single-statement version. QR tokens are generated via
    `bin2hex(random_bytes(32))`, a genuine CSPRNG.
  - `classes/payment/service_provider.php` (modeled on `enrol_fee`'s
    implementation) integrates with `core_payment`: `paymentarea` is
    always `'tickettype'`, `itemid` is a `confcheckin_tickettype.id`, and
    nothing is inserted into `confcheckin_ticket` until `deliver_order()`
    runs after a successful payment. `deliver_order()` still re-checks
    capacity via `ticket_service` even though `purchase.php` only offers a
    pay button when capacity looked available at render time, since a real
    gateway checkout can take long enough for that to change; if capacity
    fills before payment completes, `core_payment`'s own payment record
    still exists (the gateway already captured the money) but no ticket is
    created — there is no automated refund path yet, a documented,
    deliberate tradeoff rather than risking an oversold ticket type.
  - `purchase.php` (gated on `mod/confcheckin:purchase`) lists visible
    ticket types, hides a `presenteronly` type entirely (not merely
    disables it) for an ineligible user, and offers three claim paths: a
    price-zero type issues directly (`origin = 'free'`), a promo code
    issues directly for whichever type it grants regardless of that
    type's own price (`origin = 'promo'`) — treated as its own
    independent authorisation that deliberately bypasses the presenter
    check, since an organiser handing someone a specific code is itself
    the grant — and any other type goes through a real `core_payment`
    checkout (`origin = 'purchase'`).
  - `classes/privacy/provider.php`'s six request-side methods are now
    implemented for real for `confcheckin_ticket` (module context, keyed
    by `userid`); `confcheckin_checkin.scannedby` remains an honest,
    documented no-op until check-in recording exists.
  - 42/42 PHPUnit passing (was 5), phpcs/moodlecheck clean. Independently
    verified live: a genuinely eligible presenter (a real accepted
    submission's userid-speaker) saw and claimed a `presenteronly` ticket
    type; a non-presenter neither saw it nor could claim it via a crafted
    direct POST (both the UI-hiding and the server-side re-check
    confirmed); two simultaneous claims of a capacity-1 ticket type
    correctly resulted in exactly one issued ticket; a promo code
    redemption correctly issued a ticket with `origin = 'promo'` and
    incremented `timesused`. The paid `core_payment`/`paygw_paypal` path
    has code review + unit test coverage only — no sandbox credentials
    are available in this environment to live-test an actual gateway
    checkout, a limitation documented since this project's Plugin 4
    planning stage.

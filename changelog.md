# Changelog

## [0.1.0] - Unreleased

- Added a Japanese (`lang/ja/confcheckin.php`) language pack, translating every
  string in `lang/en/confcheckin.php` (verified live: every key present in both,
  no extras or omissions on either side).
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

- Phase 4.4: TinyMCE badge/ticket/receipt/certificate templates, QR-coded PDFs,
  and per-user/bulk downloads. No schema change (`confcheckin_template` already
  existed from Phase 4.2) — version bumped (`2026070501`) purely to register
  this release.
  - `classes/local/placeholder.php`: `build_context()` produces one text/HTML
    replacement per recognised `{{name}}` placeholder (`fullname`, `email`,
    `tickettype`, `confcheckinname`, `origin`, `qrtoken`, `qrcode`, and, for
    an eligible presenter only, `submissiontitle`/`track` — both `''` for
    anyone else) for a ticket; every value except `qrcode` is HTML-escaped
    (`s()`/`format_string()`) since `render()` performs a raw string
    substitution with no escaping of its own. `qrcode` is rendered via
    `qr_image_tag()`: `core_qrcode` (`lib/classes/qrcode.php`, a thin TCPDF
    wrapper already in Moodle core) generates a PNG, embedded directly as a
    `data:image/png;base64,...` `<img>` tag — TCPDF's `writeHTML()` supports
    base64 data-URI images natively, so no temp file is needed. `render()`
    silently drops (replaces with `''`) any placeholder the context has no
    entry for, rather than leaving the literal `{{name}}` text in the
    rendered PDF. `classes/local/eligibility.php::is_presenter()` was
    refactored into a new `find_presenter_submission()` (returning the
    accepted submission itself, not just a bool) that both `is_presenter()`
    and `build_context()`'s presenter-only placeholders now share, so this
    doesn't duplicate that class's own cross-plugin lookup chain.
  - `classes/local/pdf_generator.php`: renders a template (or, until an
    organiser configures one, a simple built-in fallback per type) to a real
    PDF via Moodle's own `pdf` wrapper (`lib/pdflib.php`, TCPDF). `build()`
    returns the constructed `\pdf` object rather than already-`Output()`'d
    bytes, so a caller chooses delivery mode itself: `'D'` (direct download,
    `badge.php`), `'S'` (raw string, bundled into a ZIP by `badges.php`), or
    `'I'` (inline). A real bug caught by `test_get_template_content_blank_row_falls_back_to_default`:
    `get_template_content()`'s original blank-check (`trim(strip_tags(...))`)
    treated a template containing only a lone `&nbsp;` (which TinyMCE
    routinely leaves in an otherwise-empty paragraph, so the editor doesn't
    collapse it) as "configured" content, rendering it verbatim instead of
    falling back to the built-in default — `strip_tags()` does not decode
    HTML entities and `trim()` does not strip a decoded non-breaking space
    either. Fixed by decoding entities and stripping U+00A0 before the
    blank check.
  - `classes/form/template_form.php` + `templates.php` (gated on
    `mod/confcheckin:managetemplates`): one TinyMCE `editor` element per
    template type, pre-filled with the built-in fallback content until an
    organiser saves their own.
  - `badge.php` (single-ticket download) and `badges.php` (bulk ZIP,
    gated on `mod/confcheckin:downloadbadges`, `RISK_PERSONAL`): a
    ticket's own holder can always download it; no receipt is offered for a
    free/promo-origin ticket (nothing was paid, matching Phase 4.3's "no
    receipt generated for free tickets" decision) — enforced in both the
    single-ticket and bulk paths, not just hidden client-side. A deleted
    user's ticket, or one whose ticket type was itself later deleted, is
    silently skipped in the bulk ZIP rather than failing the whole download.
  - `purchase.php`'s "Your tickets" table and `view.php` now link to these
    new screens/downloads.
  - 52/52 PHPUnit passing (was 42), phpcs/moodlecheck clean. Independently
    verified live via a CLI harness (no browser tool available this
    session): placeholder substitution, QR-per-token uniqueness, default and
    organiser-customised template rendering, and real PDF byte output
    (`%PDF` magic bytes) for every template type, using a real issued ticket
    on this checkout.

- Phase 4.5: QR check-in scanner, attendance certificates, and a Moodle app
  webview addon. No schema change for this part — version bumped
  (`2026070502`) to register the new `db/services.php` AJAX function and
  `db/mobile.php` addon.
  - `scan.php` (gated on `mod/confcheckin:scancheckin`) + `amd/src/scanner.js`:
    a dual-path scanner design, since no JS QR-decoding library ships in
    Moodle core. Path 1 (always available): a plain, always-focused text
    input that auto-submits on Enter — reliable because USB/Bluetooth badge
    scanners emulate keyboard input, zero dependency. Path 2 (progressive
    enhancement): the native browser `BarcodeDetector` API, feature-detected
    (`'BarcodeDetector' in window`) since it is not universally supported
    (notably absent in Safari/WebKit) — a bonus, never a requirement.
  - `classes/local/checkin_service.php::record_checkin()` (called via a new
    `mod_confcheckin_record_checkin` AJAX external function,
    `classes/external/record_checkin.php`) looks up a ticket by its globally
    unique `qrtoken` with no instance filter (the index really is
    global), then explicitly re-checks the found ticket's own `confcheckin`
    field against the instance the scan happened in, throwing a distinct
    `error:qrtokenwrongevent` if it doesn't match — a deliberate departure
    from this project's usual "same message regardless" IDOR pattern,
    justified because the caller already holds `scancheckin` and is
    scanning a badge an attendee physically handed them, not guessing ids.
    Idempotent: re-scanning an already-checked-in ticket returns
    `alreadycheckedin = true` rather than erroring or duplicating.
  - `badge.php`/`badges.php`: a `certificate` template type is now valid,
    gated on `mod/confcheckin:viewowncertificate` (ticket holder) or
    `:downloadbadges` (organiser bulk download), and additionally requires
    `checkin_service::has_checked_in()` to be true — an attendee cannot
    download a certificate before actually checking in. `purchase.php`'s
    "Your tickets" table gained a "Checked in" column and only offers the
    certificate download once checked in.
  - `db/mobile.php` + `classes/output/mobile.php`: a `CoreCourseModuleDelegate`
    addon using the documented `<core-iframe>` site-plugins pattern (rather
    than a fully custom Ionic-JS addon) to reuse `scan.php`/`view.php`
    inside the Moodle app's webview, resolved by capability. **Unverified
    against a real Moodle app client** — no mobile emulator/build tooling
    available in this environment, matching this project's established
    "known limitation, no test environment" pattern.
  - `classes/privacy/provider.php`: `confcheckin_checkin` rows are
    two-person records (an attendee via `ticketid`, and a `scannedby` staff
    member) — both are now covered by `get_contexts_for_userid`/
    `get_users_in_context`/`export_user_data`, but `delete_data_for_user`
    only ever removes a user's own ticket+check-in (as attendee);
    `scannedby` references are deliberately preserved on delete (no
    nullable/anonymisation slot exists for that `NOT NULL` column, and it is
    treated as an audit record, akin to Moodle's own grade history) — a
    documented, accepted limitation, not an oversight.
  - 64/64 PHPUnit passing (was 52), phpcs/moodlecheck clean.

- Phase 4.5 follow-up (user feedback): a sitewide template placeholder
  delimiter setting, two new placeholders, and group/enrolment-linked
  auto-grant tickets with a manual "orphaned tickets" report. Schema bumped
  (`2026070503`, adding `confcheckin_tickettype.groupid`/`.enrolid`).
  - `settings.php` (new): `mod_confcheckin/delimiterstart`/`delimiterend`
    admin settings, defaulting to `[[`/`]]` (not the double-curly-brace
    convention this feature originally shipped with — curly braces can
    visually collide with TinyMCE's own HTML/CSS authoring context).
    Sitewide, not per-instance: organisers across a site share one
    convention to learn, and changing it after templates are authored means
    updating those templates regardless of scope, so a per-instance setting
    would only multiply that maintenance burden.
    `classes/local/placeholder.php::render()`/`wrap()` now build their regex
    /example text from the configured pair (falling back to `[[`/`]]` if
    unset/blank) instead of a hardcoded `{{...}}`; `pdf_generator.php`'s
    built-in fallback templates and `templates.php`'s "available
    placeholders" help text are both built dynamically from the current
    setting, so they always match what `render()` actually recognises.
  - Two new placeholders: `coursefullname`/`courseshortname`, resolved via
    `get_course($confcheckin->course)` in `build_context()`.
  - `confcheckin_tickettype.groupid`/`.enrolid` (mutually exclusive,
    validated in `classes/form/tickettype_form.php`): a ticket type can be
    linked to a course group or a specific enrolment method instance, so
    that joining the group / enrolling via that method automatically issues
    a free ticket (`origin = 'grant'`) — kept in sync in real time by two
    new event observers (`classes/observer.php`,
    `\core\event\group_member_added`/`\core\event\user_enrolment_created`,
    registered in the new `db/events.php`). Matched by the specific `{enrol}.id`
    instance, not merely the enrol plugin name, since a course can have
    several instances of the same method (e.g. two self-enrolment keys).
    `ticket_service::issue_granted_ticket()` is idempotent (a user who
    already holds a ticket of that type, of ANY origin, is never issued a
    duplicate) and still capacity-checked, but unlike `issue_free_ticket()`
    does not require the type's own price to be zero (a grant is a
    deliberate complimentary allocation regardless of nominal price).
    Saving a group/enrolment link on `tickettypes.php` also immediately
    calls `sync_group_grants()`/`sync_enrol_grants()` to retroactively grant
    to every CURRENT member/enrolled user, not just future ones.
  - User feedback, 2026-07-05, on what happens when a granted ticket's
    membership is later revoked: "leave the ticket alone, but add a report
    for orphaned tickets that allows editingteachers to manually revoke
    tickets" — explicitly rejecting auto-revocation, to avoid surprising an
    attendee by yanking a ticket over an unrelated group change.
    `ticket_service::find_orphaned_tickets()` + new `orphanedtickets.php`
    (gated on `mod/confcheckin:managetickettypes`) implement exactly that:
    a `grant`-origin ticket whose holder is no longer a group
    member/enrolled via the linking method is listed with a manual "Revoke"
    action. `ticket_service::revoke_ticket()` (the first ticket-removal path
    this plugin has ever had) deletes the ticket and any check-in row, and
    decrements `soldcount` to free the capacity — `soldcount`'s own
    docblock in `db/install.xml` updated accordingly (previously "never
    decremented, no refund/cancellation flow exists yet").
  - 82/82 PHPUnit passing (was 64), phpcs/moodlecheck clean. Live-verified
    via CLI: `confcheckin_group_options()`/`confcheckin_enrol_options()`
    against a real course's groups/enrolment methods, and the configured
    delimiter round-tripping through `placeholder::wrap()` and
    `pdf_generator::default_template()`.

- Phase 4.5 follow-up, `moodle-reviewer` pass fixes: a `moodle-reviewer` pass
  over both Phase 4.5 and its same-day follow-up found one critical, two high,
  and two medium findings, all fixed here. No schema change.
  - **Critical**: neither `classes/form/tickettype_form.php::validation()` nor
    `tickettypes.php` re-checked that a submitted `groupid`/`enrolid` actually
    belonged to the confcheckin's own course — only the rendered `<select>`
    options were course-scoped (`lib.php`'s `confcheckin_group_options()`/
    `confcheckin_enrol_options()`), so a crafted POST from an editingteacher in
    one course could link a ticket type to another course's group or
    enrolment method, letting unrelated cross-course membership churn
    silently consume capacity and leak that course's users' names/emails via
    bulk badge downloads. Fixed with a form-level check (`array_key_exists()`
    against the offered options) and a page-level `record_exists()` recheck
    scoped to `courseid`, matching this file's existing "never trust a
    submitted id transitively" pattern already applied to `tickettypeid`.
  - **High**: `ticket_service::revoke_ticket()` decremented `soldcount` via a
    plain `get_record()`/`set_field()` pair with no row locking, unlike every
    other method in this class — two concurrent revokes of different tickets
    of the same type could both read the same stale `soldcount` and
    under-decrement. Fixed by locking via the existing `lock_tickettype()` and
    wrapping the whole method in the class's usual try/catch/rollback.
  - **High**: `classes/output/mobile.php::mobile_course_view()` never called
    `require_login()`, relying on `has_capability()` alone — core's own
    `tool_mobile\external::get_content()` docblock states mobile callbacks are
    responsible for their own security checks, and `has_capability()` does not
    enforce course visibility/start-date/enrolment-suspension the way
    `require_login()` does. Fixed to match `view.php`/`badge.php`'s identical
    `require_login($course, true, $cm)` call.
  - **Medium**: the `confcheckin_checkin:scannedby` retention rationale (an
    operational audit record, never anonymised even on the scanning staff
    member's own deletion request) previously lived only in code
    docblocks/`changelog.md`, not anywhere a DPO reviewing a privacy request
    would see it. The `privacy:metadata:confcheckin_checkin:scannedby` lang
    string (EN+JA) now states the retention rationale directly.
  - **Medium**: `revoke_ticket()` had no `try`/`catch`/rollback around its
    transaction, unlike every sibling method — folded into the locking fix
    above.
  - Two low-severity/style items were also addressed: a new
    `tests/observer_test.php` case proves a capacity-exhausted linked ticket
    type does not let `issue_granted_ticket()`'s exception escape the event
    observer (asserted via `assertDebuggingCalled()`); a new
    `tests/form/tickettype_form_test.php` case proves an out-of-course
    `groupid`/`enrolid` is rejected. A third low item (no settings-page
    validation preventing a degenerate delimiter configuration) was left as
    documented, non-blocking UX polish — not a security issue, since
    `preg_quote()` is already applied before any regex is built from it.
- User feedback (2026-07-05): "the templates should have a single field for
  presenters called `[[presentationinfo]]`. This field should list all the
  presentations a presenter is presenting. What information is included
  should be configurable as a setting on the template editing page (sort of a
  template within a template)." Added the `[[presentationinfo]]` placeholder,
  listing EVERY accepted submission the ticket holder presents (unlike the
  existing `[[submissiontitle]]`/`[[track]]`, which only ever covered the
  first — both kept, unchanged, for backwards compatibility with
  already-authored templates). `classes/local/eligibility.php` gained
  `find_presenter_submissions()` (plural), returning all matches;
  `find_presenter_submission()` (singular) is now a thin wrapper around it.
  Each template TYPE (badge/ticket/receipt/certificate) has its own
  configurable per-presentation mini format string
  (`confcheckin_template.presentationinfoformat`, a new nullable text column,
  editable via a new "Presentation info format" textarea on `templates.php`'s
  form) — a genuine "template within a template": every accepted submission
  is run through it and the results joined with a line break. The mini format
  has its own small, fixed placeholder syntax (`{title}`/`{track}`,
  deliberately single-braced and NOT the sitewide `[[ ]]`/configured
  delimiter, so the two nesting levels can never be confused or collide) --
  see `classes/local/placeholder.php::render_presentationinfo()`. Left unset,
  a template type falls back to a built-in default of just `{title}` (not
  `{title} ({track})`, which would show an ugly empty `()` for the common
  case of a submission with no track). New tests:
  `eligibility_test::test_find_presenter_submissions_returns_every_accepted_submission()`,
  `placeholder_test::test_build_context_presentationinfo_lists_every_presentation()`
  (default format, a custom per-type format, and confirming a DIFFERENT
  template type with no format row of its own still falls back to the
  default rather than inheriting another type's setting). 86/86 PHPUnit
  passing (was 82), phpcs/moodlecheck clean, EN/JA lang parity verified
  (153/153 keys), live-verified: the new form field renders, saves, and
  persists correctly on reload.
  - 84/84 PHPUnit passing (was 82), phpcs/moodlecheck clean.
- Phase 4.6 (final cross-cutting pass): a `moodle-reviewer` pass over the
  whole plugin, focused especially on the new `[[presentationinfo]]` feature,
  came back APPROVE with 0 critical/high findings. One **medium** finding
  fixed: `eligibility::find_presenter_submissions()` re-resolved the whole
  `mod_confprogram`/`mod_confsubmissions` chain and re-queried every accepted
  submission's speakers from scratch on every call, with no memoization --
  for a bulk badge/ticket ZIP export (`badges.php`, one call per ticket, all
  against the same `confprogramcmid`), this was effectively
  O(tickets × accepted submissions) repeated identical queries within a
  single request. Fixed by caching the resolved accepted-submissions-with-
  speakers list per `confprogramcmid` (`eligibility::get_accepted_with_speakers()`)
  and, similarly, `placeholder.php`'s per-(confcheckin, templatetype)
  `presentationinfoformat` lookup (`placeholder::get_presentationinfo_format()`)
  -- both via a `cache::MODE_REQUEST` store (the same ad-hoc,
  no-`db/caches.php`-registration pattern `tool_usertours\local\filter\role`
  already uses), not a plain static array, specifically so Moodle's own
  PHPUnit test-reset machinery clears them between tests. Caching a
  DB-backed value this way needs explicit invalidation on write: added
  `placeholder::forget_presentationinfo_format()`, called by `templates.php`
  immediately after saving a template, so a save-then-render within the same
  request sees the just-saved format rather than a stale cached one --
  caught live by `tests/local/placeholder_test.php`'s own
  save-then-read-again assertion, which failed the moment the cache was
  introduced until this invalidation call was added. Two **low** findings
  also fixed: `templates.php`'s "available placeholders" help text was
  missing `[[qrtoken]]` (added); everything else (capability enforcement on
  the new `presentationinfoformat` field, no cross-user data leak via
  `[[presentationinfo]]`, escaping discipline, DB migration correctness,
  privacy classification, EN/JA lang parity, and all Phase 4.5 fixes) was
  confirmed already correct with no changes needed. Added `.github/workflows/ci.yml`/
  `moodle-release.yml` (copied from `mod_confprogram`'s, fully plugin-agnostic
  bar the release workflow's `PLUGIN` env var). Live-verified the complete
  ticket lifecycle end-to-end on the demo site: purchase/claim a ticket,
  download the badge (with `[[presentationinfo]]` listing multiple
  presentations), scan its real QR token to record a check-in, download the
  resulting certificate. 86/86 PHPUnit passing, phpcs/moodlecheck clean,
  deployed copy byte-identical to source.

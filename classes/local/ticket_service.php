<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_confcheckin\local;

/**
 * Ticket issuance: capacity-safe, concurrency-safe creation of confcheckin_ticket
 * rows for all four origins (purchase, free, promo, grant).
 *
 * ** Capacity race-condition design (read this before changing anything here) **
 *
 * Buying the last available seat of a capacity-limited ticket type must not
 * oversell under concurrent purchases. A naive "COUNT(*) existing tickets, then
 * INSERT if under capacity" is NOT safe: two concurrent requests can both pass
 * the COUNT(*) check before either INSERTs, both then insert, and the type is
 * oversold by one.
 *
 * The obvious Moodle-native alternative -- a single conditional
 * `UPDATE ... SET soldcount = soldcount + 1 WHERE soldcount < capacity` and then
 * checking "did it affect a row" -- turns out not to be available: Moodle's DML
 * API (moodle_database::execute()) does not expose an affected-row count to
 * callers on any driver (checked in this checkout's
 * lib/dml/mysqli_native_moodle_database.php::execute(), which always returns
 * bool true regardless of whether any row matched the WHERE clause).
 *
 * Instead, this uses row-level locking: `SELECT ... FOR UPDATE` on the
 * confcheckin_tickettype row, inside a Moodle delegated transaction
 * ($DB->start_delegated_transaction()). On mysql/postgres this makes a second
 * concurrent caller's SELECT ... FOR UPDATE block until the first transaction
 * commits or rolls back, at which point it sees the updated soldcount and can
 * correctly decide reject/accept -- the read-check-write becomes indivisible
 * because no other transaction can even READ the row (for locking purposes)
 * while the first is still deciding. SQLite has no FOR UPDATE syntax (used by
 * PHPUnit in some configurations), so it is skipped there via
 * $DB->get_dbfamily(); this is safe because SQLite's own transaction model
 * already serialises writers at the whole-database level, and because this
 * project's PHPUnit tests exercise the boundary logic (capacity N succeeds,
 * N+1 cleanly fails) rather than genuine multi-connection concurrency, which
 * PHPUnit cannot exercise anyway. The real concurrency guarantee is verified
 * live against this checkout's actual mariadb backend (see this plugin's
 * changelog.md for the live verification notes).
 *
 * The same locking pattern is used for confcheckin_promocode.timesused, for
 * the identical reason (two simultaneous redemptions of the last remaining use
 * of a promo code must not both succeed).
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ticket_service {
    /**
     * Database families that support `SELECT ... FOR UPDATE` row locking, which is
     * the mechanism this class relies on for capacity/redemption-count safety.
     */
    private const LOCKING_DBFAMILIES = ['mysql', 'postgres'];

    /**
     * Generates a unique, cryptographically-random qrtoken for a new ticket.
     *
     * Uses random_bytes() (a CSPRNG) hex-encoded to exactly 64 characters, matching
     * confcheckin_ticket.qrtoken's char(64) column and its unique index. A guessable
     * token would let anyone forge/scan another attendee's check-in (see this
     * plugin's db/install.xml table comment), so this must never be a sequential id,
     * timestamp, or non-CSPRNG source. bin2hex(random_bytes(32)) is used directly
     * rather than core's random_string()/complex_random_string() helpers: both of
     * those select each output character via `ord($randombytes[$i]) % poollen`,
     * which is CSPRNG-seeded but introduces a slight modulo bias in the character
     * distribution; hex-encoding sidesteps that entirely since 256 evenly divides
     * into two hex nibbles with zero bias, at the cost of a smaller (but still
     * enormous, 32 bytes = 256 bits of entropy) alphabet.
     *
     * @return string A 64-character lowercase hex string
     */
    public static function generate_qrtoken(): string {
        return bin2hex(random_bytes(32));
    }

    /**
     * Issues a free ticket (origin = 'free') for a price-zero ticket type.
     *
     * @param int $confcheckinid The confcheckin instance id
     * @param int $tickettypeid The confcheckin_tickettype id (must belong to $confcheckinid)
     * @param int $userid The user id the ticket is issued to
     * @return \stdClass The newly-inserted confcheckin_ticket record
     * @throws \moodle_exception if the ticket type does not belong to this instance, is not
     *         actually price-zero, has no remaining capacity, or $userid is not eligible
     *         (presenteronly / group / enrolment-method requirement)
     */
    public static function issue_free_ticket(int $confcheckinid, int $tickettypeid, int $userid): \stdClass {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        try {
            $tickettype = self::lock_tickettype($confcheckinid, $tickettypeid);

            if (abs((float) $tickettype->price) >= 0.005) {
                // Rounds to a non-zero price at 2dp; this path is only for genuinely
                // free ticket types (see purchase.php, which only offers this path
                // for price === '0.00' ticket types in the first place -- this is a
                // defence-in-depth re-check, not the only gate).
                throw new \moodle_exception('error:tickettypenotfree', 'confcheckin');
            }

            self::require_eligible($confcheckinid, $tickettype, $userid);
            self::require_within_maxperuser($tickettype, $userid);

            self::reserve_capacity_locked($tickettype);

            $ticket = self::insert_ticket(
                $confcheckinid,
                $tickettype->id,
                $userid,
                'free',
                null,
                $tickettype->addtogroupid !== null ? (int) $tickettype->addtogroupid : null
            );

            $transaction->allow_commit();

            return $ticket;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Redeems a promo code, issuing a ticket (origin = 'promo') for the ticket type it
     * grants, and atomically incrementing the code's timesused.
     *
     * @param int $confcheckinid The confcheckin instance id
     * @param string $code The promo code string, as entered by the user
     * @param int $userid The user id the ticket is issued to
     * @return \stdClass The newly-inserted confcheckin_ticket record
     * @throws \moodle_exception if the code does not exist (scoped to this instance), has
     *         expired, has no remaining uses, or its ticket type has no remaining capacity
     */
    public static function redeem_promocode(int $confcheckinid, string $code, int $userid): \stdClass {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        try {
            $promocode = self::lock_promocode_by_code($confcheckinid, $code);
            if ($promocode === null) {
                // Same message whether the code plainly doesn't exist or belongs to a
                // different instance -- no enumeration oracle for guessing codes across
                // instances.
                throw new \moodle_exception('error:invalidpromocode', 'confcheckin');
            }

            if ($promocode->timeexpires !== null && (int) $promocode->timeexpires < time()) {
                throw new \moodle_exception('error:promocodeexpired', 'confcheckin');
            }

            if ($promocode->maxuses !== null && (int) $promocode->timesused >= (int) $promocode->maxuses) {
                throw new \moodle_exception('error:promocodeexhausted', 'confcheckin');
            }

            // Re-scope the ticket type to this instance even though promocode.tickettypeid
            // was itself already instance-scoped at creation time (promocodes.php) -- never
            // trust a stored id transitively without re-verifying at the point of use.
            $tickettype = self::lock_tickettype($confcheckinid, (int) $promocode->tickettypeid);

            // Unlike require_eligible() (deliberately skipped here -- see that
            // method's own docblock: a promo code is its own authorisation), the
            // maxperuser cap IS still enforced for a promo redemption: a code is
            // meant to unlock eligibility, not to bypass a hard per-user ticket
            // limit on the type it grants.
            self::require_within_maxperuser($tickettype, $userid);

            self::reserve_capacity_locked($tickettype);

            $DB->set_field('confcheckin_promocode', 'timesused', (int) $promocode->timesused + 1, ['id' => $promocode->id]);

            $ticket = self::insert_ticket(
                $confcheckinid,
                $tickettype->id,
                $userid,
                'promo',
                (int) $promocode->id,
                $tickettype->addtogroupid !== null ? (int) $tickettype->addtogroupid : null
            );

            $transaction->allow_commit();

            return $ticket;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Issues a paid ticket (origin = 'purchase'). Called only from
     * classes/payment/service_provider.php::deliver_order(), after core_payment has
     * already collected a successful payment -- see that class's docblock for what
     * happens if capacity has been exhausted by the time this runs (a rare race
     * between initiating and completing a real gateway checkout).
     *
     * @param int $confcheckinid The confcheckin instance id
     * @param int $tickettypeid The confcheckin_tickettype id (must belong to $confcheckinid)
     * @param int $userid The user id the ticket is issued to
     * @return \stdClass The newly-inserted confcheckin_ticket record
     * @throws \moodle_exception if the ticket type does not belong to this instance, has no
     *         remaining capacity, or $userid is not eligible (presenteronly / group /
     *         enrolment-method requirement) -- this is the only place a paid ticket is
     *         actually created, so this re-check (added 2026-07-06 alongside the new
     *         group/enrolment eligibility requirement) is what stops a crafted direct
     *         core_payment request from buying an ineligible ticket type that
     *         purchase.php merely hides from the UI
     */
    public static function issue_purchased_ticket(int $confcheckinid, int $tickettypeid, int $userid): \stdClass {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        try {
            $tickettype = self::lock_tickettype($confcheckinid, $tickettypeid);

            // Visibility is part of the same defence-in-depth re-check as
            // eligibility below: purchase.php merely HIDES an invisible type from
            // the list, but core_payment itemids are guessable sequential ints, so
            // without this a direct gateway request could buy a hidden ("Staff",
            // draft next-year early-bird) paid type never offered to the buyer
            // (FABLE.md review, 2026-07-09). get_payable() additionally blocks the
            // checkout from even starting for users without mod/confcheckin:purchase.
            if (empty($tickettype->visible)) {
                throw new \moodle_exception('error:invalidtickettype', 'confcheckin');
            }

            self::require_eligible($confcheckinid, $tickettype, $userid);
            self::require_within_maxperuser($tickettype, $userid);

            self::reserve_capacity_locked($tickettype);

            $ticket = self::insert_ticket(
                $confcheckinid,
                $tickettype->id,
                $userid,
                'purchase',
                null,
                $tickettype->addtogroupid !== null ? (int) $tickettype->addtogroupid : null
            );

            $transaction->allow_commit();

            return $ticket;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Issues a "granted" ticket (origin = 'grant') for a ticket type linked to a
     * course group or enrolment method (Phase 4.5 follow-up), called from
     * classes/observer.php on group join/enrolment, and from sync_group_grants()/
     * sync_enrol_grants() when an organiser links (or re-syncs) a ticket type.
     *
     * Idempotent: if this exact user already holds a ticket of this exact type
     * (regardless of origin -- e.g. they already purchased one before joining the
     * group), the existing ticket is returned unchanged rather than issuing a
     * second one. This is different from issue_free_ticket()/issue_purchased_ticket(),
     * which have no such check, because THIS method's callers (an event observer, a
     * bulk sync loop) can legitimately be invoked more than once for the same
     * user/type pair (e.g. re-running "sync now", or a user leaving and rejoining a
     * group) and must not create duplicates each time.
     *
     * Unlike issue_free_ticket(), this does NOT require the ticket type's price to
     * be zero: a group/enrolment grant is a deliberate complimentary allocation
     * regardless of the type's normal price (e.g. a "VIP" paid ticket type an
     * organiser also wants to comp to a "Volunteers" group).
     *
     * @param int $confcheckinid The confcheckin instance id
     * @param int $tickettypeid The confcheckin_tickettype id (must belong to $confcheckinid)
     * @param int $userid The user id to grant a ticket to
     * @return \stdClass The (possibly pre-existing) confcheckin_ticket record
     * @throws \moodle_exception if the ticket type does not belong to this instance
     */
    public static function issue_granted_ticket(int $confcheckinid, int $tickettypeid, int $userid): \stdClass {
        global $DB;

        // Capped fetch, not get_record(): with maxperuser > 1 (or unlimited) a user
        // can legitimately hold several tickets of one type, and get_record() on a
        // non-unique pair fires Moodle's "found more than one record" developer
        // warning on every observer event/sync for such users (FABLE.md review,
        // 2026-07-09). This method only needs "does one exist" -- any one will do.
        $existing = $DB->get_records(
            'confcheckin_ticket',
            ['tickettypeid' => $tickettypeid, 'userid' => $userid],
            'id ASC',
            '*',
            0,
            1
        );
        if ($existing) {
            return reset($existing);
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            $tickettype = self::lock_tickettype($confcheckinid, $tickettypeid);

            // Re-check for a race: another request may have inserted a ticket for
            // this user/type between the unlocked check above and this locked one.
            // Same capped fetch as above.
            $racedtickets = $DB->get_records(
                'confcheckin_ticket',
                ['tickettypeid' => $tickettypeid, 'userid' => $userid],
                'id ASC',
                '*',
                0,
                1
            );
            if ($racedtickets) {
                $transaction->allow_commit();
                return reset($racedtickets);
            }

            self::reserve_capacity_locked($tickettype);

            $ticket = self::insert_ticket(
                $confcheckinid,
                $tickettype->id,
                $userid,
                'grant',
                null,
                $tickettype->addtogroupid !== null ? (int) $tickettype->addtogroupid : null
            );

            $transaction->allow_commit();

            return $ticket;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Revokes (permanently deletes) an issued ticket and its check-in record, if
     * any, decrementing the ticket type's soldcount so the freed capacity becomes
     * available again. This is the only way a confcheckin_ticket row is ever
     * removed after issuance -- see db/install.xml's own soldcount field comment.
     *
     * Used by orphanedtickets.php to let an organiser manually revoke an
     * auto-granted ticket whose granting group membership/enrolment no longer
     * holds (Phase 4.5 follow-up, user feedback, 2026-07-05: "add a report for
     * orphaned tickets that allows editingteachers to manually revoke tickets" --
     * auto-revoking on group/enrolment removal was explicitly rejected in favour of
     * this manual report, to avoid surprising an attendee by yanking a ticket
     * over an unrelated group change).
     *
     * @param int $ticketid The confcheckin_ticket id to revoke
     * @return void
     */
    public static function revoke_ticket(int $ticketid): void {
        global $DB;

        $ticket = $DB->get_record('confcheckin_ticket', ['id' => $ticketid]);
        if (!$ticket) {
            return;
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->delete_records('confcheckin_checkin', ['ticketid' => $ticketid]);
            $DB->delete_records('confcheckin_ticket', ['id' => $ticketid]);

            // Locked the same way as every issuance path in this class: two concurrent
            // revokes of different tickets of the SAME ticket type must not both read
            // the same stale soldcount and each compute one decrement, which would
            // under-decrement and permanently understate real available capacity.
            $tickettype = self::lock_tickettype((int) $ticket->confcheckin, (int) $ticket->tickettypeid);
            $DB->set_field(
                'confcheckin_tickettype',
                'soldcount',
                max(0, (int) $tickettype->soldcount - 1),
                ['id' => $tickettype->id]
            );

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Recomputes every ticket type's soldcount for an instance from the actual
     * confcheckin_ticket rows -- the same recovery backup/restore's
     * after_restore() performs. Needed by any deletion path that removes ticket
     * rows WITHOUT going through revoke_ticket()'s per-ticket decrement: the
     * privacy provider's three delete methods were exactly such a path, leaving
     * soldcount permanently overstated (a sold-out type whose buyers were
     * GDPR-erased stayed "sold out" forever with no UI recourse -- FABLE.md
     * review, 2026-07-09).
     *
     * Deliberately does NOT touch confcheckin_promocode.timesused: a promo
     * code's use count is treated as an audit record of redemptions that
     * happened (same posture as the provider's documented scannedby retention),
     * not a live capacity counter -- reducing it would let a capped code be
     * redeemed again because a past redeemer was erased.
     *
     * @param int $confcheckinid The confcheckin instance id
     * @return void
     */
    public static function recount_soldcount(int $confcheckinid): void {
        global $DB;

        foreach ($DB->get_records('confcheckin_tickettype', ['confcheckin' => $confcheckinid], '', 'id') as $type) {
            $DB->set_field(
                'confcheckin_tickettype',
                'soldcount',
                $DB->count_records('confcheckin_ticket', ['tickettypeid' => $type->id]),
                ['id' => $type->id]
            );
        }
    }

    /**
     * Issues a granted ticket to every CURRENT member of a ticket type's linked
     * group, for a ticket type that was just linked (or re-synced) to that group --
     * without this, only users who join the group AFTER the link is configured
     * would ever get a ticket via classes/observer.php's real-time handler.
     *
     * @param int $confcheckinid The confcheckin instance id
     * @param int $tickettypeid The confcheckin_tickettype id (must belong to $confcheckinid)
     * @param int $groupid The course group id
     * @return int How many tickets were newly issued (excludes users who already held one)
     */
    public static function sync_group_grants(int $confcheckinid, int $tickettypeid, int $groupid): int {
        global $DB;

        $issued = 0;
        foreach (groups_get_members($groupid, 'u.id') as $member) {
            $before = $DB->record_exists('confcheckin_ticket', ['tickettypeid' => $tickettypeid, 'userid' => $member->id]);
            self::issue_granted_ticket($confcheckinid, $tickettypeid, (int) $member->id);
            if (!$before) {
                $issued++;
            }
        }

        return $issued;
    }

    /**
     * Issues a granted ticket to every user CURRENTLY enrolled via a ticket type's
     * linked enrolment method instance -- the enrolment-method equivalent of
     * sync_group_grants(), for the same reason.
     *
     * @param int $confcheckinid The confcheckin instance id
     * @param int $tickettypeid The confcheckin_tickettype id (must belong to $confcheckinid)
     * @param int $enrolid The {enrol}.id enrolment method instance id
     * @return int How many tickets were newly issued (excludes users who already held one)
     */
    public static function sync_enrol_grants(int $confcheckinid, int $tickettypeid, int $enrolid): int {
        global $DB;

        $userids = $DB->get_fieldset_select(
            'user_enrolments',
            'userid',
            'enrolid = :enrolid',
            ['enrolid' => $enrolid]
        );

        $issued = 0;
        foreach (array_unique($userids) as $userid) {
            $before = $DB->record_exists('confcheckin_ticket', ['tickettypeid' => $tickettypeid, 'userid' => $userid]);
            self::issue_granted_ticket($confcheckinid, $tickettypeid, (int) $userid);
            if (!$before) {
                $issued++;
            }
        }

        return $issued;
    }

    /**
     * Finds every "orphaned" granted ticket in a confcheckin instance: a ticket
     * with origin = 'grant' whose ticket type is still linked to a group the
     * holder is no longer a member of, or an enrolment method instance the holder
     * is no longer enrolled via. Powers orphanedtickets.php's report -- see
     * revoke_ticket()'s docblock for why this is a manual report rather than an
     * automatic revocation.
     *
     * @param int $confcheckinid The confcheckin instance id
     * @return array{ticket: \stdClass, tickettype: \stdClass, reason: string}[] Keyed by ticket id
     */
    public static function find_orphaned_tickets(int $confcheckinid): array {
        global $DB;

        $tickets = $DB->get_records('confcheckin_ticket', ['confcheckin' => $confcheckinid, 'origin' => 'grant']);
        if (!$tickets) {
            return [];
        }

        $tickettypes = $DB->get_records('confcheckin_tickettype', ['confcheckin' => $confcheckinid]);

        $orphaned = [];
        foreach ($tickets as $ticket) {
            $tickettype = $tickettypes[$ticket->tickettypeid] ?? null;
            if (!$tickettype) {
                // The ticket type itself was deleted entirely; nothing left to
                // re-check membership against, but the ticket is still real and
                // still checked-in-able, so it is not reported as orphaned here.
                continue;
            }

            $reason = null;
            if (!empty($tickettype->groupid) && !groups_is_member((int) $tickettype->groupid, (int) $ticket->userid)) {
                $reason = 'group';
            } else if (!empty($tickettype->enrolid)) {
                $stillenrolled = $DB->record_exists('user_enrolments', [
                    'enrolid' => $tickettype->enrolid,
                    'userid'  => $ticket->userid,
                ]);
                if (!$stillenrolled) {
                    $reason = 'enrol';
                }
            }

            if ($reason !== null) {
                $orphaned[$ticket->id] = ['ticket' => $ticket, 'tickettype' => $tickettype, 'reason' => $reason];
            }
        }

        return $orphaned;
    }

    /**
     * Whether a ticket type currently has remaining capacity, WITHOUT locking or
     * reserving a seat. Suitable only for read/display purposes (e.g. purchase.php
     * deciding whether to show a "sold out" label) -- never for a purchase decision,
     * since the answer can be stale the instant after it is returned. Use
     * reserve_capacity_locked() (via one of the issue_*() methods above) for any
     * actual reservation.
     *
     * @param \stdClass $tickettype A confcheckin_tickettype record
     * @return bool
     */
    public static function has_capacity_for_display(\stdClass $tickettype): bool {
        return $tickettype->capacity === null || (int) $tickettype->soldcount < (int) $tickettype->capacity;
    }

    /**
     * Whether a given user has already reached (or, in principle, exceeded) a
     * ticket type's maxperuser cap -- for display purposes only (user request,
     * 2026-07-08: purchase.php deciding whether to show a "Get ticket"/"Buy"
     * button at all, so a user who is already at their limit sees a clear reason
     * instead of a confusing failed-purchase error after clicking). Same
     * "read-only, can go stale the instant after it's returned, never for the
     * actual purchase decision" caveat as has_capacity_for_display() above --
     * require_within_maxperuser() (called inside each issue_*() method's own
     * locked transaction) is the real enforcement.
     *
     * @param \stdClass $tickettype A confcheckin_tickettype record
     * @param int $userid The user id to check
     * @return bool
     */
    public static function has_reached_maxperuser_for_display(\stdClass $tickettype, int $userid): bool {
        global $DB;

        if ($tickettype->maxperuser === null) {
            return false;
        }

        $existingcount = $DB->count_records('confcheckin_ticket', [
            'tickettypeid' => $tickettype->id,
            'userid'       => $userid,
        ]);

        return $existingcount >= (int) $tickettype->maxperuser;
    }

    /**
     * Whether a ticket type is currently within its acquisition (availability)
     * window -- validfrom/validto now mean "when this ticket type may be
     * purchased/claimed", not ticket validity/admission (user request,
     * 2026-07-10; useful for early-bird-style campaigns). Both null (the common
     * case) means no restriction. Used both for real enforcement
     * (require_eligible() below) and for display (purchase.php deciding whether
     * to show/disable the acquisition button and what message to show).
     *
     * @param \stdClass $tickettype A confcheckin_tickettype record
     * @param int|null $now Unix timestamp to check against; null means the real current time (a fixed value is used by tests)
     * @return bool
     */
    public static function is_within_availability_window(\stdClass $tickettype, ?int $now = null): bool {
        $now = $now ?? time();

        if (!empty($tickettype->validfrom) && $now < (int) $tickettype->validfrom) {
            return false;
        }
        if (!empty($tickettype->validto) && $now > (int) $tickettype->validto) {
            return false;
        }

        return true;
    }

    /**
     * Locks a confcheckin_tickettype row (see this class's docblock) and re-verifies it
     * belongs to the given confcheckin instance.
     *
     * @param int $confcheckinid The confcheckin instance id
     * @param int $tickettypeid The confcheckin_tickettype id
     * @return \stdClass The locked ticket type record
     * @throws \moodle_exception if the ticket type does not belong to this instance
     */
    private static function lock_tickettype(int $confcheckinid, int $tickettypeid): \stdClass {
        global $DB;

        $sql = 'SELECT * FROM {confcheckin_tickettype} WHERE id = :id AND confcheckin = :confcheckin';
        if (in_array($DB->get_dbfamily(), self::LOCKING_DBFAMILIES, true)) {
            $sql .= ' FOR UPDATE';
        }

        $tickettype = $DB->get_record_sql($sql, ['id' => $tickettypeid, 'confcheckin' => $confcheckinid]);
        if (!$tickettype) {
            throw new \moodle_exception('error:invalidtickettype', 'confcheckin');
        }

        return $tickettype;
    }

    /**
     * Locks a confcheckin_promocode row by its code string, scoped to a confcheckin
     * instance (codes are only unique per instance, not globally -- see
     * db/install.xml's confcheckincode index).
     *
     * @param int $confcheckinid The confcheckin instance id
     * @param string $code The promo code string
     * @return \stdClass|null The locked promo code record, or null if not found
     */
    private static function lock_promocode_by_code(int $confcheckinid, string $code): ?\stdClass {
        global $DB;

        $sql = 'SELECT * FROM {confcheckin_promocode} WHERE confcheckin = :confcheckin AND code = :code';
        if (in_array($DB->get_dbfamily(), self::LOCKING_DBFAMILIES, true)) {
            $sql .= ' FOR UPDATE';
        }

        $promocode = $DB->get_record_sql($sql, ['confcheckin' => $confcheckinid, 'code' => $code]);

        return $promocode ?: null;
    }

    /**
     * Re-checks (server-side, defence-in-depth) that a user is eligible for a ticket
     * type before it is actually issued -- see eligibility::is_eligible_for_tickettype()
     * for what "eligible" covers (presenteronly, and the group/enrolment-method
     * requirement added 2026-07-06). Called from issue_free_ticket() and
     * issue_purchased_ticket(); deliberately NOT called from redeem_promocode() or
     * issue_granted_ticket() -- a promo code redemption is its own independent
     * authorisation that bypasses eligibility entirely (same precedent purchase.php's
     * docblock already documents for presenteronly), and a granted ticket's whole
     * premise IS the (different) groupid/enrolid auto-grant condition, not this gate.
     *
     * @param int $confcheckinid The confcheckin instance id
     * @param \stdClass $tickettype A confcheckin_tickettype record (already locked by the caller)
     * @param int $userid The user id the ticket would be issued to
     * @return void
     * @throws \moodle_exception if $userid does not meet the ticket type's eligibility requirements
     */
    private static function require_eligible(int $confcheckinid, \stdClass $tickettype, int $userid): void {
        global $DB;

        $confcheckin = $DB->get_record('confcheckin', ['id' => $confcheckinid]);
        $confprogramcmid = ($confcheckin && !empty($confcheckin->confprogramcmid))
            ? (int) $confcheckin->confprogramcmid
            : null;

        if (!eligibility::is_eligible_for_tickettype($userid, $tickettype, $confprogramcmid)) {
            throw new \moodle_exception('error:noteligible', 'confcheckin');
        }

        // Availability window (user request, 2026-07-10): validfrom/validto are the
        // acquisition window, enforced here the same way as the eligibility check
        // above. Deliberately NOT checked in redeem_promocode() -- same precedent as
        // presenteronly/eligibilitygroupid above: a promo code redemption bypasses
        // eligibility checks entirely, since an organiser handing someone a code is
        // itself the authorisation.
        if (!self::is_within_availability_window($tickettype)) {
            throw new \moodle_exception('error:notavailable', 'confcheckin');
        }
    }

    /**
     * Re-checks (server-side, defence-in-depth) that issuing one more ticket of
     * this type to this user would not exceed the ticket type's configured
     * maxperuser cap (user request, 2026-07-08; default 1, null means unlimited --
     * see db/install.xml's own field comment). Called from issue_free_ticket(),
     * issue_purchased_ticket() and redeem_promocode() -- the three user-initiated
     * "claim a ticket" paths -- but deliberately NOT from issue_granted_ticket(),
     * whose own idempotency (see that method's docblock) already caps a single
     * user at exactly one ticket per type regardless of this setting, and whose
     * whole premise (a group/enrolment-triggered comp) is orthogonal to a
     * self-service purchase limit.
     *
     * Must be called on an ALREADY-LOCKED ticket type (see lock_tickettype())
     * within the same still-open transaction: locking the ticket type row also
     * serialises concurrent issuance attempts for that type, which is what stops
     * two rapid double-submits from the same user both passing this count check
     * before either has actually inserted its row.
     *
     * @param \stdClass $tickettype A ticket type record already locked by lock_tickettype()
     * @param int $userid The user id a new ticket would be issued to
     * @return void
     * @throws \moodle_exception if the user already holds maxperuser (or more) tickets of this type
     */
    private static function require_within_maxperuser(\stdClass $tickettype, int $userid): void {
        global $DB;

        if ($tickettype->maxperuser === null) {
            return;
        }

        $existingcount = $DB->count_records('confcheckin_ticket', [
            'tickettypeid' => $tickettype->id,
            'userid'       => $userid,
        ]);

        if ($existingcount >= (int) $tickettype->maxperuser) {
            throw new \moodle_exception('error:maxperuserexceeded', 'confcheckin');
        }
    }

    /**
     * Checks a LOCKED ticket type's remaining capacity and, if available, increments
     * its soldcount in place. Must only be called on a row already locked by
     * lock_tickettype() within the same still-open transaction -- calling this on an
     * unlocked row reintroduces the exact race this class exists to prevent.
     *
     * @param \stdClass $tickettype A ticket type record already locked by lock_tickettype()
     * @return void
     * @throws \moodle_exception if the ticket type has no remaining capacity
     */
    private static function reserve_capacity_locked(\stdClass $tickettype): void {
        global $DB;

        if ($tickettype->capacity !== null && (int) $tickettype->soldcount >= (int) $tickettype->capacity) {
            throw new \moodle_exception('error:tickettypesoldout', 'confcheckin');
        }

        $DB->set_field('confcheckin_tickettype', 'soldcount', (int) $tickettype->soldcount + 1, ['id' => $tickettype->id]);
    }

    /**
     * Inserts a confcheckin_ticket row with a freshly-generated qrtoken.
     *
     * @param int $confcheckinid The confcheckin instance id
     * @param int $tickettypeid The confcheckin_tickettype id
     * @param int $userid The user id the ticket is issued to
     * @param string $origin One of purchase, free, promo or grant
     * @param int|null $promocodeid The confcheckin_promocode id, when $origin is promo; else null
     * @param int|null $addtogroupid The ticket type's addtogroupid (user request, 2026-07-07),
     *        or null for no auto-add. Every caller already has the locked ticket type record
     *        in scope, so this is passed rather than re-fetched here.
     * @return \stdClass The newly-inserted confcheckin_ticket record
     */
    private static function insert_ticket(
        int $confcheckinid,
        int $tickettypeid,
        int $userid,
        string $origin,
        ?int $promocodeid,
        ?int $addtogroupid = null
    ): \stdClass {
        global $DB;

        $now = time();
        $record = (object) [
            'confcheckin'   => $confcheckinid,
            'tickettypeid'  => $tickettypeid,
            'userid'        => $userid,
            'origin'        => $origin,
            'promocodeid'   => $promocodeid,
            'qrtoken'       => self::generate_qrtoken(),
            'timecreated'   => $now,
            'timemodified'  => $now,
        ];

        $record->id = $DB->insert_record('confcheckin_ticket', $record);

        if ($addtogroupid) {
            // Best-effort: a stale group id (deleted after the ticket type was
            // configured) must never break the ticket issuance a buyer is actively
            // waiting on -- same "side effect failure must not break the primary
            // action" posture as notifier.php's message_send() try/catch elsewhere in
            // this project. groups_add_member() is itself idempotent (a no-op if
            // already a member), so this is also safe to call on every issuance
            // without an existing-membership check first.
            //
            // Note: groups_add_member() (Moodle core) itself silently no-ops -- no
            // exception, just a false return this method does not check -- for a user
            // not enrolled in the group's course. That is expected here, not a bug in
            // this feature: a real ticket buyer must already hold
            // mod/confcheckin:purchase in this course context to have reached the
            // purchase flow at all, so is virtually always already enrolled.
            try {
                groups_add_member($addtogroupid, $userid);
            } catch (\Throwable $e) {
                // phpcs:ignore moodle.PHP.ForbiddenFunctions.FoundWithAlternative
                error_log('mod_confcheckin addtogroupid group-add failed: ' . $e->getMessage());
            }
        }

        return $record;
    }
}

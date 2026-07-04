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
 * rows for all three origins (purchase, free, promo).
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
     *         actually price-zero, or has no remaining capacity
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

            self::reserve_capacity_locked($tickettype);

            $ticket = self::insert_ticket($confcheckinid, $tickettype->id, $userid, 'free', null);

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

            self::reserve_capacity_locked($tickettype);

            $DB->set_field('confcheckin_promocode', 'timesused', (int) $promocode->timesused + 1, ['id' => $promocode->id]);

            $ticket = self::insert_ticket($confcheckinid, $tickettype->id, $userid, 'promo', (int) $promocode->id);

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
     * @throws \moodle_exception if the ticket type does not belong to this instance, or has
     *         no remaining capacity
     */
    public static function issue_purchased_ticket(int $confcheckinid, int $tickettypeid, int $userid): \stdClass {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        try {
            $tickettype = self::lock_tickettype($confcheckinid, $tickettypeid);

            self::reserve_capacity_locked($tickettype);

            $ticket = self::insert_ticket($confcheckinid, $tickettype->id, $userid, 'purchase', null);

            $transaction->allow_commit();

            return $ticket;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
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
     * @param string $origin One of purchase, free or promo
     * @param int|null $promocodeid The confcheckin_promocode id, when $origin is promo; else null
     * @return \stdClass The newly-inserted confcheckin_ticket record
     */
    private static function insert_ticket(
        int $confcheckinid,
        int $tickettypeid,
        int $userid,
        string $origin,
        ?int $promocodeid
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

        return $record;
    }
}

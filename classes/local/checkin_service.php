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
 * Check-in recording (Phase 4.5): looks up a ticket by its scanned QR token and
 * records a confcheckin_checkin row for it.
 *
 * A ticket's qrtoken is globally unique (see db/install.xml's own unique index),
 * so lookup does not need instance-scoping to find the row -- but the row found
 * MUST still belong to the confcheckin instance the scan happened in, or a staff
 * member at one event could accidentally (or a mischievous attendee could
 * deliberately) check themselves into a completely different event's instance.
 * That mismatch is reported with its own distinct message rather than a generic
 * "not found" -- unlike the enumeration-oracle-avoidance pattern used elsewhere in
 * this project for user-supplied ids, the "attacker" model here does not apply:
 * the caller already holds mod/confcheckin:scancheckin (organiser-only) and is
 * scanning a QR code an attendee physically handed them, not guessing at ids, so a
 * clear "wrong event" message is more useful for real day-of-event operations than
 * obscuring it would be.
 *
 * Re-scanning an already-checked-in ticket is handled gracefully (returns
 * 'alreadycheckedin' => true, does not insert a second row or throw) -- the
 * database's own unique index on confcheckin_checkin.ticketid is the authoritative
 * guarantee this can never happen twice even under a race, per db/install.xml's
 * own table comment.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class checkin_service {
    /**
     * Records a check-in for the ticket identified by a scanned QR token.
     *
     * @param int $confcheckinid The confcheckin instance id the scan happened in
     * @param string $qrtoken The scanned confcheckin_ticket.qrtoken value
     * @param int $scannedby The user id performing the scan
     * @return array{ticketid: int, fullname: string, tickettype: string, alreadycheckedin: bool}
     * @throws \moodle_exception if no ticket has this token, or it belongs to a different instance
     */
    public static function record_checkin(int $confcheckinid, string $qrtoken, int $scannedby): array {
        global $DB;

        $qrtoken = trim($qrtoken);
        $ticket = $DB->get_record('confcheckin_ticket', ['qrtoken' => $qrtoken]);
        if (!$ticket) {
            throw new \moodle_exception('error:invalidqrtoken', 'confcheckin');
        }
        if ((int) $ticket->confcheckin !== $confcheckinid) {
            throw new \moodle_exception('error:qrtokenwrongevent', 'confcheckin');
        }

        $existing = $DB->get_record('confcheckin_checkin', ['ticketid' => $ticket->id]);
        if (!$existing) {
            $DB->insert_record('confcheckin_checkin', (object) [
                'ticketid'    => $ticket->id,
                'scannedby'   => $scannedby,
                'timecreated' => time(),
            ]);
        }

        $user = \core_user::get_user((int) $ticket->userid);
        $tickettype = $DB->get_record('confcheckin_tickettype', ['id' => $ticket->tickettypeid]);

        return [
            'ticketid'         => (int) $ticket->id,
            'fullname'         => $user ? fullname($user) : '',
            'tickettype'       => $tickettype ? format_string($tickettype->name) : '',
            'alreadycheckedin' => (bool) $existing,
        ];
    }

    /**
     * Whether a ticket has a recorded check-in -- gates certificate downloads
     * (badge.php/badges.php, per Phase 4.5's "gated on a recorded check-in" spec).
     *
     * @param int $ticketid The confcheckin_ticket id
     * @return bool
     */
    public static function has_checked_in(int $ticketid): bool {
        global $DB;

        return $DB->record_exists('confcheckin_checkin', ['ticketid' => $ticketid]);
    }

    /**
     * Sets or clears a ticket's check-in state directly by ticket id (user
     * request, 2026-07-08: a manual check-in/remove-check-in toggle on
     * report.php, for a ticket holder who doesn't have their QR code to hand,
     * or to undo an accidental/mistaken scan). Idempotent in both directions,
     * matching record_checkin()'s own behaviour: setting true when already
     * checked in, or false when not checked in, is a no-op rather than an error.
     *
     * @param int $ticketid The confcheckin_ticket id
     * @param bool $checkedin The desired check-in state
     * @param int $scannedby The user id performing this action (recorded only
     *     when transitioning to checked-in; a cleared check-in leaves no trace
     *     of who cleared it, matching a DELETE having no "who" column to put it in)
     * @return void
     */
    public static function set_checkin(int $ticketid, bool $checkedin, int $scannedby): void {
        global $DB;

        $existing = $DB->get_record('confcheckin_checkin', ['ticketid' => $ticketid]);

        if ($checkedin) {
            if (!$existing) {
                $DB->insert_record('confcheckin_checkin', (object) [
                    'ticketid'    => $ticketid,
                    'scannedby'   => $scannedby,
                    'timecreated' => time(),
                ]);
            }
        } else if ($existing) {
            $DB->delete_records('confcheckin_checkin', ['id' => $existing->id]);
        }
    }

    /**
     * Returns every confcheckin_ticket row for an instance, decorated with its
     * ticket type name and (if checked in) check-in timestamp -- the data
     * report.php needs, keyed by userid so it can be looked up per enrolled user.
     *
     * A user may hold more than one ticket for the same instance (see
     * db/install.xml's own comment on confcheckin_ticket: "at most one active
     * ticket per user" is a business rule, not a DB constraint, e.g. after a
     * refund + repurchase), so each value is an array of ticket rows, never a
     * single one.
     *
     * @param int $confcheckinid The confcheckin instance id
     * @return array<int, \stdClass[]> Ticket rows (each with 'tickettypename' and
     *     'checkedintime', the latter null if not checked in), keyed by userid
     */
    public static function get_tickets_by_user(int $confcheckinid): array {
        global $DB;

        $sql = "SELECT t.id, t.userid, t.tickettypeid, tt.name AS tickettypename, c.timecreated AS checkedintime
                  FROM {confcheckin_ticket} t
                  JOIN {confcheckin_tickettype} tt ON tt.id = t.tickettypeid
             LEFT JOIN {confcheckin_checkin} c ON c.ticketid = t.id
                 WHERE t.confcheckin = :confcheckin
              ORDER BY t.userid, tt.sortorder, tt.name";
        $records = $DB->get_records_sql($sql, ['confcheckin' => $confcheckinid]);

        $byuser = [];
        foreach ($records as $record) {
            $byuser[(int) $record->userid][] = $record;
        }

        return $byuser;
    }
}

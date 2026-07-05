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
}

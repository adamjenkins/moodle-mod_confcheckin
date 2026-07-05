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

namespace mod_confcheckin;

use mod_confcheckin\local\ticket_service;

/**
 * Event observers powering the group/enrolment-linked auto-grant tickets (Phase
 * 4.5 follow-up, user feedback, 2026-07-05: "add a way to setup a ticket by group
 * (synced so that adding a user to a group grants the ticket automatically), or
 * enrolment method").
 *
 * Both handlers are deliberately simple pass-throughs to
 * ticket_service::issue_granted_ticket(), which is itself idempotent and
 * capacity-checked -- no business logic lives here beyond resolving "which
 * confcheckin_tickettype rows are linked to this group/enrol instance" and
 * calling that method for each. A capacity-exhausted ticket type simply fails to
 * grant silently (caught and logged via debugging()) rather than throwing, since
 * a failure here must never break the group-membership/enrolment operation that
 * triggered it.
 *
 * Only NEWLY joining/enrolling users are granted a ticket this way -- an
 * organiser who links an EXISTING group/enrolment method to a ticket type after
 * the fact must separately trigger \mod_confcheckin\local\ticket_service::
 * sync_group_grants()/sync_enrol_grants() (done automatically by tickettypes.php
 * when saving such a link) to retroactively grant to current members.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * A user was added to a course group: grant a ticket for every confcheckin
     * ticket type linked to that group.
     *
     * @param \core\event\group_member_added $event
     * @return void
     */
    public static function group_member_added(\core\event\group_member_added $event): void {
        global $DB;

        $groupid = (int) $event->objectid;
        $userid = (int) $event->relateduserid;

        $tickettypes = $DB->get_records('confcheckin_tickettype', ['groupid' => $groupid]);
        foreach ($tickettypes as $tickettype) {
            try {
                ticket_service::issue_granted_ticket((int) $tickettype->confcheckin, (int) $tickettype->id, $userid);
            } catch (\Throwable $e) {
                debugging(
                    'mod_confcheckin: failed to auto-grant ticket type ' . $tickettype->id
                        . ' to user ' . $userid . ' on group join: ' . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }
    }

    /**
     * A user was enrolled in a course: grant a ticket for every confcheckin ticket
     * type linked to the specific enrolment method instance used.
     *
     * @param \core\event\user_enrolment_created $event
     * @return void
     */
    public static function user_enrolment_created(\core\event\user_enrolment_created $event): void {
        global $DB;

        $userid = (int) $event->relateduserid;

        // The event's own objectid is the {user_enrolments}.id row, not the enrol
        // instance id -- and 'other'/enrol only gives the enrol METHOD NAME (e.g.
        // 'manual'), not which of possibly several instances of that method was
        // used. The user_enrolments row itself is the only place that links to the
        // exact {enrol}.id this plugin's own tickettype.enrolid matches against.
        $enrolid = $DB->get_field('user_enrolments', 'enrolid', ['id' => $event->objectid]);
        if (!$enrolid) {
            return;
        }

        $tickettypes = $DB->get_records('confcheckin_tickettype', ['enrolid' => $enrolid]);
        foreach ($tickettypes as $tickettype) {
            try {
                ticket_service::issue_granted_ticket((int) $tickettype->confcheckin, (int) $tickettype->id, $userid);
            } catch (\Throwable $e) {
                debugging(
                    'mod_confcheckin: failed to auto-grant ticket type ' . $tickettype->id
                        . ' to user ' . $userid . ' on enrolment: ' . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }
    }
}

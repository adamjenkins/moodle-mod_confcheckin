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

namespace mod_confcheckin\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;

/**
 * Privacy provider for mod_confcheckin.
 *
 * Unlike mod_confscheduler, this plugin DOES store personal data in its own
 * tables: confcheckin_ticket.userid (the ticket holder) and
 * confcheckin_checkin.scannedby (the staff member who performed a scan).
 * confcheckin_tickettype, confcheckin_template and confcheckin_promocode
 * hold only instance configuration, never personal data.
 *
 * get_metadata() below is fully implemented for this scaffold phase (Phase
 * 4.1/4.2), describing every table/field that holds personal data so the
 * plugin never under-reports what it stores, even before the request-side
 * logic exists. get_contexts_for_userid(), get_users_in_context(),
 * export_user_data(), delete_data_for_all_users_in_context(),
 * delete_data_for_user() and delete_data_for_users() are all deliberately
 * implemented as honest empty/no-op results rather than throwing: no code
 * path anywhere in this plugin yet writes to confcheckin_ticket or
 * confcheckin_checkin (ticket issuance lands in Phase 4.3, check-in
 * recording in Phase 4.5), so an empty result is factually correct today,
 * not an under-report. Throwing instead would not be safer -- Moodle's
 * privacy manager (core_privacy\manager::handled_component_class_callback())
 * catches any \Throwable from every installed component's provider on every
 * GDPR export/delete request and context purge site-wide, converts it to an
 * empty result anyway, and additionally emails every Data Protection
 * Officer on the site about the exception -- so throwing here would only
 * generate DPO alert-fatigue noise for unrelated users' requests, with zero
 * correctness benefit over returning empty directly. These must be
 * replaced with real implementations once Phases 4.3/4.5 give
 * confcheckin_ticket/confcheckin_checkin actual rows to report.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Returns metadata describing the personal data stored by this plugin.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {

        $collection->add_database_table('confcheckin_ticket', [
            'userid'       => 'privacy:metadata:confcheckin_ticket:userid',
            'origin'       => 'privacy:metadata:confcheckin_ticket:origin',
            'qrtoken'      => 'privacy:metadata:confcheckin_ticket:qrtoken',
            'timecreated'  => 'privacy:metadata:confcheckin_ticket:timecreated',
            'timemodified' => 'privacy:metadata:confcheckin_ticket:timemodified',
        ], 'privacy:metadata:confcheckin_ticket');

        $collection->add_database_table('confcheckin_checkin', [
            'scannedby'   => 'privacy:metadata:confcheckin_checkin:scannedby',
            'timecreated' => 'privacy:metadata:confcheckin_checkin:timecreated',
        ], 'privacy:metadata:confcheckin_checkin');

        return $collection;
    }

    /**
     * Returns the list of contexts that contain personal data for the given user.
     *
     * Stub: no code path yet writes to confcheckin_ticket/confcheckin_checkin, so an
     * empty contextlist is the factually correct answer today. Replace with a real
     * implementation once Phase 4.3/4.5 give those tables rows to report. See this
     * class's docblock for why this returns empty rather than throwing.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        return new contextlist();
    }

    /**
     * Gets the list of users within the specified context who have personal data.
     *
     * Stub: no-op, see get_contexts_for_userid()'s docblock.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
    }

    /**
     * Exports personal data for the approved contexts belonging to the user.
     *
     * Stub: no-op, see get_contexts_for_userid()'s docblock.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
    }

    /**
     * Deletes all personal data for all users in the specified context.
     *
     * Stub: no-op, see get_contexts_for_userid()'s docblock.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
    }

    /**
     * Deletes all personal data for the specified user in the given contexts.
     *
     * Stub: no-op, see get_contexts_for_userid()'s docblock.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
    }

    /**
     * Deletes personal data for the given users in the specified context.
     *
     * Stub: no-op, see get_contexts_for_userid()'s docblock.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
    }
}

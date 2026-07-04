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
use core_privacy\local\request\helper as request_helper;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for mod_confcheckin.
 *
 * Unlike mod_confscheduler, this plugin DOES store personal data in its own
 * tables: confcheckin_ticket.userid (the ticket holder) and
 * confcheckin_checkin.scannedby (the staff member who performed a scan).
 * confcheckin_tickettype, confcheckin_template and confcheckin_promocode
 * hold only instance configuration, never personal data.
 *
 * Phase 4.3 implements the six request-side methods for real, now that ticket
 * issuance (Phase 4.3) gives confcheckin_ticket.userid actual rows to report --
 * see this plugin's changelog.md for the scaffold-phase rationale for why
 * these were previously honest empty/no-op stubs rather than throwing.
 * confcheckin_checkin.scannedby is still empty (check-in recording is Phase
 * 4.5), so the checkin-related code paths below remain forward-looking no-ops
 * until then, documented at each spot rather than silently omitted.
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
     * Returns the list of (module) contexts that contain a confcheckin_ticket issued
     * to the given user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $sql = "SELECT ctx.id
                  FROM {confcheckin_ticket} t
                  JOIN {confcheckin} cc ON cc.id = t.confcheckin
                  JOIN {course_modules} cm ON cm.instance = cc.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'confcheckin'
                  JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                 WHERE t.userid = :userid";

        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, ['contextlevel' => CONTEXT_MODULE, 'userid' => $userid]);

        return $contextlist;
    }

    /**
     * Gets the list of users within the specified module context who hold a
     * confcheckin_ticket for that instance.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        if (!$context instanceof \context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('confcheckin', $context->instanceid);
        if (!$cm) {
            return;
        }

        $sql = 'SELECT userid FROM {confcheckin_ticket} WHERE confcheckin = :confcheckin';
        $userlist->add_from_sql('userid', $sql, ['confcheckin' => $cm->instance]);
    }

    /**
     * Exports each approved context's confcheckin_ticket rows belonging to the
     * requesting user.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }

            $cm = get_coursemodule_from_id('confcheckin', $context->instanceid);
            if (!$cm) {
                continue;
            }

            $tickets = $DB->get_records('confcheckin_ticket', [
                'confcheckin' => $cm->instance,
                'userid'      => $user->id,
            ]);
            if (!$tickets) {
                continue;
            }

            $contextdata = request_helper::get_context_data($context, $user);
            writer::with_context($context)->export_data([], $contextdata);

            $exportdata = [];
            foreach ($tickets as $ticket) {
                $exportdata[] = (object) [
                    'tickettypeid' => $ticket->tickettypeid,
                    'origin'       => $ticket->origin,
                    'qrtoken'      => $ticket->qrtoken,
                    'timecreated'  => \core_privacy\local\request\transform::datetime($ticket->timecreated),
                    'timemodified' => \core_privacy\local\request\transform::datetime($ticket->timemodified),
                ];
            }

            writer::with_context($context)->export_data(
                [get_string('privacy:metadata:confcheckin_ticket', 'confcheckin')],
                (object) ['tickets' => $exportdata]
            );
        }
    }

    /**
     * Deletes every confcheckin_ticket row for the confcheckin instance behind the
     * given module context, for every user.
     *
     * confcheckin_checkin.scannedby is not touched: no code path writes to that table
     * yet (Phase 4.5), so there is nothing to purge there today -- see this class's
     * docblock.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if (!$context instanceof \context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('confcheckin', $context->instanceid);
        if (!$cm) {
            return;
        }

        $DB->delete_records('confcheckin_ticket', ['confcheckin' => $cm->instance]);
    }

    /**
     * Deletes the requesting user's confcheckin_ticket rows in each approved context.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }

            $cm = get_coursemodule_from_id('confcheckin', $context->instanceid);
            if (!$cm) {
                continue;
            }

            $DB->delete_records('confcheckin_ticket', [
                'confcheckin' => $cm->instance,
                'userid'      => $user->id,
            ]);
        }
    }

    /**
     * Deletes the confcheckin_ticket rows for the given users within a single module
     * context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('confcheckin', $context->instanceid);
        if (!$cm) {
            return;
        }

        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['confcheckin'] = $cm->instance;
        $DB->delete_records_select('confcheckin_ticket', "confcheckin = :confcheckin AND userid $insql", $params);
    }
}

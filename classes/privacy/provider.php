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
 * Phase 4.3 implemented the six request-side methods for real for
 * confcheckin_ticket, now that ticket issuance gives confcheckin_ticket.userid
 * actual rows to report -- see this plugin's changelog.md for the scaffold-phase
 * rationale for why these were previously honest empty/no-op stubs rather than
 * throwing. Phase 4.5 (check-in recording) now gives confcheckin_checkin.scannedby
 * actual rows too, so this class covers both tables for real as of this phase.
 *
 * A confcheckin_checkin row involves TWO distinct people: the ticket holder (via
 * ticketid -> confcheckin_ticket.userid, the attendee being checked in) and
 * scannedby (the staff member who performed the scan). Both are covered by
 * get_contexts_for_userid()/get_users_in_context()/export_user_data() below.
 * delete_data_for_user()/delete_data_for_users() only ever remove a user's OWN
 * ticket (and its own check-in row, as the attendee) -- a check-in row's
 * scannedby reference is deliberately left in place even when the SCANNING staff
 * member requests their own data be deleted, since confcheckin_checkin.scannedby
 * is NOT NULL with no anonymisation placeholder value in this schema, and this is
 * treated as an operational audit record of who performed a check-in (the same
 * treatment Moodle core gives similar "who did this action to someone else's
 * record" columns, e.g. grade history) rather than the scanning user's own
 * personal data to purge on request. This is a known, documented limitation (see
 * RELATIONS.md), not an oversight.
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
     * Returns the list of (module) contexts where the given user either holds a
     * confcheckin_ticket, or performed a check-in scan (confcheckin_checkin.scannedby)
     * for someone else's ticket.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $ticketsql = "SELECT ctx.id
                  FROM {confcheckin_ticket} t
                  JOIN {confcheckin} cc ON cc.id = t.confcheckin
                  JOIN {course_modules} cm ON cm.instance = cc.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'confcheckin'
                  JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                 WHERE t.userid = :userid";
        $contextlist->add_from_sql($ticketsql, ['contextlevel' => CONTEXT_MODULE, 'userid' => $userid]);

        $scannedbysql = "SELECT ctx.id
                  FROM {confcheckin_checkin} chk
                  JOIN {confcheckin_ticket} t ON t.id = chk.ticketid
                  JOIN {confcheckin} cc ON cc.id = t.confcheckin
                  JOIN {course_modules} cm ON cm.instance = cc.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'confcheckin'
                  JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel2
                 WHERE chk.scannedby = :scannedby";
        $contextlist->add_from_sql($scannedbysql, ['contextlevel2' => CONTEXT_MODULE, 'scannedby' => $userid]);

        return $contextlist;
    }

    /**
     * Gets the list of users within the specified module context who hold a
     * confcheckin_ticket for that instance, or who performed a check-in scan there.
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

        $userlist->add_from_sql(
            'userid',
            'SELECT userid FROM {confcheckin_ticket} WHERE confcheckin = :confcheckin',
            ['confcheckin' => $cm->instance]
        );

        $userlist->add_from_sql(
            'scannedby',
            'SELECT DISTINCT chk.scannedby
               FROM {confcheckin_checkin} chk
               JOIN {confcheckin_ticket} t ON t.id = chk.ticketid
              WHERE t.confcheckin = :confcheckin',
            ['confcheckin' => $cm->instance]
        );
    }

    /**
     * Exports each approved context's confcheckin_ticket rows (with their own
     * check-in status) belonging to the requesting user, plus a separate list of
     * check-ins the requesting user performed as staff (scannedby) on OTHER
     * tickets in that context.
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

            $contextdatawritten = false;
            $writecontextdataonce = static function () use (&$contextdatawritten, $context, $user): void {
                if (!$contextdatawritten) {
                    writer::with_context($context)->export_data([], request_helper::get_context_data($context, $user));
                    $contextdatawritten = true;
                }
            };

            $tickets = $DB->get_records('confcheckin_ticket', [
                'confcheckin' => $cm->instance,
                'userid'      => $user->id,
            ]);
            if ($tickets) {
                $writecontextdataonce();

                $exportdata = [];
                foreach ($tickets as $ticket) {
                    $checkin = $DB->get_record('confcheckin_checkin', ['ticketid' => $ticket->id]);
                    $exportdata[] = (object) [
                        'tickettypeid' => $ticket->tickettypeid,
                        'origin'       => $ticket->origin,
                        'qrtoken'      => $ticket->qrtoken,
                        'timecreated'  => \core_privacy\local\request\transform::datetime($ticket->timecreated),
                        'timemodified' => \core_privacy\local\request\transform::datetime($ticket->timemodified),
                        'checkedin'    => $checkin
                            ? \core_privacy\local\request\transform::datetime($checkin->timecreated)
                            : null,
                    ];
                }

                writer::with_context($context)->export_data(
                    [get_string('privacy:metadata:confcheckin_ticket', 'confcheckin')],
                    (object) ['tickets' => $exportdata]
                );
            }

            $scannedsql = 'SELECT chk.id, chk.ticketid, chk.timecreated
                              FROM {confcheckin_checkin} chk
                              JOIN {confcheckin_ticket} t ON t.id = chk.ticketid
                             WHERE t.confcheckin = :confcheckin AND chk.scannedby = :scannedby';
            $scanned = $DB->get_records_sql($scannedsql, ['confcheckin' => $cm->instance, 'scannedby' => $user->id]);
            if ($scanned) {
                $writecontextdataonce();

                $exportdata = [];
                foreach ($scanned as $checkin) {
                    $exportdata[] = (object) [
                        'ticketid'    => $checkin->ticketid,
                        'timecreated' => \core_privacy\local\request\transform::datetime($checkin->timecreated),
                    ];
                }

                writer::with_context($context)->export_data(
                    [get_string('privacy:metadata:confcheckin_checkin', 'confcheckin')],
                    (object) ['checkinsperformed' => $exportdata]
                );
            }
        }
    }

    /**
     * Deletes every confcheckin_checkin and confcheckin_ticket row for the
     * confcheckin instance behind the given module context, for every user.
     *
     * confcheckin_checkin rows are deleted BEFORE confcheckin_ticket (mirroring
     * lib.php::confcheckin_delete_instance()'s own ordering rationale): once a
     * ticket row is gone there is no way to find which check-in rows belonged to
     * it, orphaning them with no cleanup path.
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

        $ticketids = $DB->get_fieldset_select(
            'confcheckin_ticket',
            'id',
            'confcheckin = :confcheckin',
            ['confcheckin' => $cm->instance]
        );
        if ($ticketids) {
            [$insql, $params] = $DB->get_in_or_equal($ticketids);
            $DB->delete_records_select('confcheckin_checkin', "ticketid $insql", $params);
        }

        $DB->delete_records('confcheckin_ticket', ['confcheckin' => $cm->instance]);
    }

    /**
     * Deletes the requesting user's own confcheckin_ticket row (and its own
     * check-in row, as the attendee) in each approved context.
     *
     * Does NOT touch a confcheckin_checkin row where this user is only the
     * scannedby (they checked in someone ELSE's ticket) -- see this class's own
     * docblock for why that is a deliberate, documented limitation rather than an
     * oversight.
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

            $ticketids = $DB->get_fieldset_select(
                'confcheckin_ticket',
                'id',
                'confcheckin = :confcheckin AND userid = :userid',
                ['confcheckin' => $cm->instance, 'userid' => $user->id]
            );
            if ($ticketids) {
                [$insql, $params] = $DB->get_in_or_equal($ticketids);
                $DB->delete_records_select('confcheckin_checkin', "ticketid $insql", $params);
            }

            $DB->delete_records('confcheckin_ticket', [
                'confcheckin' => $cm->instance,
                'userid'      => $user->id,
            ]);
        }
    }

    /**
     * Deletes the confcheckin_ticket rows (and their own check-in rows, as the
     * attendee) for the given users within a single module context. Does NOT
     * touch a confcheckin_checkin row where one of these users is only the
     * scannedby -- see this class's own docblock.
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

        $ticketids = $DB->get_fieldset_select(
            'confcheckin_ticket',
            'id',
            "confcheckin = :confcheckin AND userid $insql",
            $params
        );
        if ($ticketids) {
            [$ticketinsql, $ticketparams] = $DB->get_in_or_equal($ticketids);
            $DB->delete_records_select('confcheckin_checkin', "ticketid $ticketinsql", $ticketparams);
        }

        $DB->delete_records_select('confcheckin_ticket', "confcheckin = :confcheckin AND userid $insql", $params);
    }
}

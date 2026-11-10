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
 * RELATIONS.md), not an oversight. The security audit of 2026-10-28 (F9) reviewed
 * this retention and it is kept as designed.
 *
 * Ticket payments (core_payment's {payments} rows with component mod_confcheckin,
 * paymentarea 'tickettype', itemid = confcheckin_tickettype.id) are exported and
 * deleted through \core_payment\privacy\consumer_provider (security audit
 * 2026-10-28, F5), the same way enrol_fee handles its own payments. core_payment's
 * provider calls the request methods below again with its own contextlist or
 * userlist (component 'core_payment'); when it does, these methods handle only the
 * payment rows and leave the ticket data to this plugin's own call.
 *
 * @package    mod_confcheckin
 * @copyright  2020 Shamim Rezaie <shamim@moodle.com>
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_payment\privacy\consumer_provider,
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** Payment area this plugin uses with core_payment. */
    private const PAYMENTAREA = 'tickettype';

    /**
     * Returns the context id a ticket payment belongs to: the module context of the
     * ticket type's activity, or null when the ticket type no longer exists (core
     * then files the payment under the system context).
     *
     * @param string $paymentarea Payment area
     * @param int $itemid The confcheckin_tickettype id
     * @return int|null
     */
    public static function get_contextid_for_payment(string $paymentarea, int $itemid): ?int {
        global $DB;

        if ($paymentarea !== self::PAYMENTAREA) {
            return null;
        }

        $sql = "SELECT ctx.id
                  FROM {confcheckin_tickettype} tt
                  JOIN {course_modules} cm ON cm.instance = tt.confcheckin
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'confcheckin'
                  JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                 WHERE tt.id = :itemid";
        $contextid = $DB->get_field_sql($sql, ['contextlevel' => CONTEXT_MODULE, 'itemid' => $itemid]);

        return $contextid ? (int) $contextid : null;
    }

    /**
     * SQL selecting the ids of this plugin's payments that belong to a context: the
     * activity's ticket types for a module context, or payments whose ticket type no
     * longer exists for the system context.
     *
     * @param \context $context The context
     * @return array|null [$sql, $params], or null when the context holds none of this plugin's payments
     */
    private static function get_payment_sql_for_context(\context $context): ?array {
        $params = ['component' => 'mod_confcheckin', 'paymentarea' => self::PAYMENTAREA];

        if ($context instanceof \context_module) {
            $cm = get_coursemodule_from_id('confcheckin', $context->instanceid);
            if (!$cm) {
                return null;
            }
            $sql = "SELECT p.id
                      FROM {payments} p
                      JOIN {confcheckin_tickettype} tt ON tt.id = p.itemid
                     WHERE p.component = :component AND p.paymentarea = :paymentarea
                           AND tt.confcheckin = :confcheckin";
            $params['confcheckin'] = $cm->instance;
            return [$sql, $params];
        }

        if ($context instanceof \context_system) {
            $sql = "SELECT p.id
                      FROM {payments} p
                 LEFT JOIN {confcheckin_tickettype} tt ON tt.id = p.itemid
                     WHERE p.component = :component AND p.paymentarea = :paymentarea AND tt.id IS NULL";
            return [$sql, $params];
        }

        return null;
    }

    /**
     * Adds the users with ticket payments in the userlist's context.
     *
     * @param userlist $userlist
     * @return void
     */
    private static function add_payment_users(userlist $userlist): void {
        $paymentsql = self::get_payment_sql_for_context($userlist->get_context());
        if ($paymentsql === null) {
            return;
        }
        [$sql, $params] = $paymentsql;

        $userlist->add_from_sql('userid', "SELECT userid FROM {payments} WHERE id IN ($sql)", $params);
    }

    /**
     * Exports the user's ticket payments in each approved context.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    private static function export_payments(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;
        $subcontext = [get_string('privacy:path:payments', 'confcheckin')];

        foreach ($contextlist->get_contexts() as $context) {
            $paymentsql = self::get_payment_sql_for_context($context);
            if ($paymentsql === null) {
                continue;
            }
            [$sql, $params] = $paymentsql;
            $params['userid'] = $userid;

            $itemids = $DB->get_fieldset_sql(
                "SELECT DISTINCT itemid FROM {payments} WHERE id IN ($sql) AND userid = :userid",
                $params
            );
            foreach ($itemids as $itemid) {
                \core_payment\privacy\provider::export_payment_data_for_user_in_context(
                    $context,
                    $subcontext,
                    $userid,
                    'mod_confcheckin',
                    self::PAYMENTAREA,
                    (int) $itemid
                );
            }
        }
    }

    /**
     * Deletes ticket payments in a context, optionally only those of some users.
     *
     * @param \context $context The context
     * @param int[]|null $userids Only these users' payments, or null for everyone's
     * @return void
     */
    private static function delete_payments(\context $context, ?array $userids = null): void {
        global $DB;

        $paymentsql = self::get_payment_sql_for_context($context);
        if ($paymentsql === null || $userids === []) {
            return;
        }
        [$sql, $params] = $paymentsql;

        if ($userids !== null) {
            [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'payuser');
            $sql = "SELECT id FROM {payments} WHERE id IN ($sql) AND userid $usersql";
            $params += $userparams;
        }

        \core_payment\privacy\provider::delete_data_for_payment_sql($sql, $params);
    }

    /**
     * Returns metadata describing the personal data stored by this plugin.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {

        $collection->add_database_table('confcheckin_ticket', [
            'userid'       => 'privacy:metadata:confcheckin_ticket:userid',
            'tickettypeid' => 'privacy:metadata:confcheckin_ticket:tickettypeid',
            'promocodeid'  => 'privacy:metadata:confcheckin_ticket:promocodeid',
            'origin'       => 'privacy:metadata:confcheckin_ticket:origin',
            'qrtoken'      => 'privacy:metadata:confcheckin_ticket:qrtoken',
            'timecreated'  => 'privacy:metadata:confcheckin_ticket:timecreated',
            'timemodified' => 'privacy:metadata:confcheckin_ticket:timemodified',
        ], 'privacy:metadata:confcheckin_ticket');

        $collection->add_database_table('confcheckin_checkin', [
            'scannedby'   => 'privacy:metadata:confcheckin_checkin:scannedby',
            'timecreated' => 'privacy:metadata:confcheckin_checkin:timecreated',
        ], 'privacy:metadata:confcheckin_checkin');

        $collection->add_subsystem_link('core_payment', [], 'privacy:metadata:core_payment');

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
        if ($userlist->get_component() === 'core_payment') {
            self::add_payment_users($userlist);
            return;
        }

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

        if ($contextlist->get_component() === 'core_payment') {
            self::export_payments($contextlist);
            return;
        }

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

        // This method gets no component, so both this plugin's call and
        // core_payment's call remove the payments as well; doing it twice is harmless.
        self::delete_payments($context);

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

        // Ticket rows were removed without revoke_ticket()'s per-ticket decrement,
        // so recompute soldcount from what actually remains (FABLE.md review,
        // 2026-07-09) -- see ticket_service::recount_soldcount()'s docblock.
        \mod_confcheckin\local\ticket_service::recount_soldcount((int) $cm->instance);
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

        if ($contextlist->get_component() === 'core_payment') {
            foreach ($contextlist->get_contexts() as $context) {
                self::delete_payments($context, [(int) $user->id]);
            }
            return;
        }

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

            // See delete_data_for_all_users_in_context()'s matching comment.
            \mod_confcheckin\local\ticket_service::recount_soldcount((int) $cm->instance);
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

        if ($userlist->get_component() === 'core_payment') {
            self::delete_payments($userlist->get_context(), array_map('intval', $userlist->get_userids()));
            return;
        }

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

        // See delete_data_for_all_users_in_context()'s matching comment.
        \mod_confcheckin\local\ticket_service::recount_soldcount((int) $cm->instance);
    }
}

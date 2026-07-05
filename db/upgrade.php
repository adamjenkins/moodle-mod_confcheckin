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

/**
 * Upgrade steps for mod_confcheckin.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Runs upgrade steps between versions.
 *
 * @param int $oldversion Plugin version being upgraded from
 * @return bool
 */
function xmldb_confcheckin_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026070401) {
        // Phase 4.3: link to a mod_confprogram instance for presenter-ticket eligibility
        // (nullable soft-link, same pattern as mod_confscheduler's confprogramcmid), and
        // a nullable core_payment account id for paid ticket types.
        $table = new xmldb_table('confcheckin');

        $field = new xmldb_field('confprogramcmid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'introformat');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('paymentaccountid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'confprogramcmid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Phase 4.3: atomic capacity-tracking counter for confcheckin_tickettype. See
        // db/install.xml's table comment and classes/local/ticket_service.php for why
        // this is a maintained counter, not a COUNT(*) over confcheckin_ticket.
        $table = new xmldb_table('confcheckin_tickettype');

        $field = new xmldb_field('soldcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'visible');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026070401, 'confcheckin');
    }

    if ($oldversion < 2026070503) {
        // Phase 4.5 follow-up: a ticket type can be linked to a course group or
        // enrolment method so that joining/enrolling automatically grants a free
        // ticket (origin 'grant'), kept in sync by classes/observer.php. Mutually
        // exclusive (enforced in classes/form/tickettype_form.php's validation, not
        // here) -- see db/install.xml's own field comments.
        $table = new xmldb_table('confcheckin_tickettype');

        $field = new xmldb_field('groupid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'soldcount');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('enrolid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'groupid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026070503, 'confcheckin');
    }

    return true;
}

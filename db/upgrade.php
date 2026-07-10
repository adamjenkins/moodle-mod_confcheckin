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

    if ($oldversion < 2026070504) {
        // Unified [[presentationinfo]] placeholder (user feedback, 2026-07-05): lists
        // every accepted submission a ticket holder presents, rendered once per
        // submission via a per-template-type "template within a template" format
        // string, instead of the older single-submission {{submissiontitle}}/{{track}}
        // pair (both kept for backwards compatibility -- see README.md).
        $table = new xmldb_table('confcheckin_template');

        $field = new xmldb_field('presentationinfoformat', XMLDB_TYPE_TEXT, null, null, null, null, null, 'contentformat');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026070504, 'confcheckin');
    }

    if ($oldversion < 2026070601) {
        // Group/enrolment-method eligibility requirement (user request, 2026-07-06):
        // distinct from groupid/enrolid above (which auto-grant a ticket), these gate
        // whether a user may purchase/claim this ticket type at all via the
        // self-service purchase.php flow -- see classes/local/eligibility.php.
        $table = new xmldb_table('confcheckin_tickettype');

        $field = new xmldb_field('eligibilitygroupid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'enrolid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('eligibilityenrolid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'eligibilitygroupid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026070601, 'confcheckin');
    }

    if ($oldversion < 2026070701) {
        // The "Add to group" feature (user request, 2026-07-07): the OPPOSITE direction from
        // groupid above -- a user issued a ticket of this type is automatically added
        // to this group, instead of group membership auto-granting a ticket. See
        // db/install.xml's field comment.
        $table = new xmldb_table('confcheckin_tickettype');

        $field = new xmldb_field('addtogroupid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'eligibilityenrolid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026070701, 'confcheckin');
    }

    if ($oldversion < 2026070801) {
        // User request (2026-07-08): default currency changed from USD to JPY for
        // NEW ticket types -- existing rows keep whatever currency they already
        // have, since every row was written with an explicit currency value (the
        // column default only ever applied to a direct insert that omitted it).
        $table = new xmldb_table('confcheckin_tickettype');

        $field = new xmldb_field('currency', XMLDB_TYPE_CHAR, '3', null, XMLDB_NOTNULL, null, 'JPY', 'price');
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_default($table, $field);
        }

        // The "Max tickets per user" feature (user request, 2026-07-08): a per-ticket-type cap
        // on how many a single user may hold, default 1, null means unlimited --
        // see db/install.xml's field comment and
        // classes/local/ticket_service.php's require_within_maxperuser().
        $field = new xmldb_field('maxperuser', XMLDB_TYPE_INTEGER, '10', null, null, null, '1', 'capacity');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            // Existing rows keep their pre-feature behaviour (no cap): add_field()
            // backfills the column default ('1') into every existing row, which
            // would silently change mid-sale policy -- the OPPOSITE choice from
            // the currency default just above (FABLE.md review, 2026-07-09). The
            // '1' default is for genuinely NEW ticket types only.
            $DB->set_field_select('confcheckin_tickettype', 'maxperuser', null, '1 = 1');
        }

        upgrade_mod_savepoint(true, 2026070801, 'confcheckin');
    }

    if ($oldversion < 2026070901) {
        // Repair for sites that ran the ORIGINAL 2026070801 step before the fix
        // above existed (FABLE.md review, 2026-07-09): add_field() had backfilled
        // maxperuser = 1 into every pre-existing ticket type, retroactively
        // capping previously-unlimited types at 1 per user. A backfilled row is
        // indistinguishable from an organiser genuinely choosing 1 in the single
        // day the feature has existed, so this uses timecreated as the heuristic:
        // only types CREATED BEFORE the feature landed (2026-07-08) are reset to
        // unlimited -- a type created after it carries whatever the organiser
        // actually saved. Runs only when upgrading THROUGH the broken step
        // (fresh installs and post-fix upgraders have nothing backfilled).
        $featurelanded = make_timestamp(2026, 7, 8, 0, 0, 0);
        $DB->set_field_select(
            'confcheckin_tickettype',
            'maxperuser',
            null,
            'maxperuser = 1 AND timecreated < :featurelanded',
            ['featurelanded' => $featurelanded]
        );

        upgrade_mod_savepoint(true, 2026070901, 'confcheckin');
    }

    if ($oldversion < 2026071001) {
        // Validfrom/validto are repurposed from (never-enforced) ticket admission
        // validity to an acquisition/availability window (user request, 2026-07-10,
        // e.g. early-bird campaigns) -- see those fields' own install.xml comments.
        // showavailability controls whether the window is shown to users; existing
        // ticket types default to shown (1), matching validfrom/validto's previous
        // always-shown-when-set display behaviour on purchase.php.
        $table = new xmldb_table('confcheckin_tickettype');
        $field = new xmldb_field('showavailability', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'validto');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026071001, 'confcheckin');
    }

    return true;
}

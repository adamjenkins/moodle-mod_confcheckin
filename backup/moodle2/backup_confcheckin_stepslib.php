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
 * Defines the backup structure for mod_confcheckin.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Defines the complete confcheckin structure for backup, with file annotations.
 *
 * Instance CONFIGURATION (ticket types, promo codes, badge/ticket/receipt/certificate
 * templates) is always included, regardless of the 'userinfo' setting. Issued tickets
 * and their check-ins are user data and only included when 'userinfo' is on.
 *
 * confprogramcmid (nullable) is a cross-activity reference into a sibling
 * mod_confprogram instance; tickettype.groupid/enrolid/eligibilitygroupid/
 * eligibilityenrolid reference course-level groups/enrolment methods -- see
 * restore_confcheckin_stepslib.php's docblock for why all of these are resolved in
 * after_restore(), not here or in restore's own process_*() methods. paymentaccountid
 * (a core_payment account id) is NOT remapped at all -- core_payment accounts are a
 * site-level concept, not scoped per course, so the same account id is valid on the
 * destination site too (or, if it no longer exists, core_payment's own checkout already
 * handles a missing/invalid account gracefully -- nothing here needs to duplicate that).
 *
 * No persistent file storage exists for generated badge/ticket/receipt/certificate
 * PDFs (see pdf_generator.php -- they are rendered on demand, never saved), so this
 * plugin has nothing to annotate/back up there; only the standard intro file area
 * applies. Template content itself is a plain rich-text editor field with maxfiles 0
 * (see classes/form/template_form.php) -- no embedded file picker to annotate either.
 */
class backup_confcheckin_activity_structure_step extends backup_activity_structure_step {
    /**
     * Defines the confcheckin activity structure for backup.
     *
     * @return backup_nested_element The root element, wrapped into standard activity structure
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $confcheckin = new backup_nested_element('confcheckin', ['id'], [
            'name', 'intro', 'introformat', 'confprogramcmid', 'paymentaccountid',
            'timecreated', 'timemodified',
        ]);

        $tickettypes = new backup_nested_element('tickettypes');
        $tickettype = new backup_nested_element('tickettype', ['id'], [
            'name', 'price', 'currency', 'capacity', 'presenteronly', 'validfrom',
            'validto', 'sortorder', 'visible', 'soldcount', 'groupid', 'enrolid',
            'eligibilitygroupid', 'eligibilityenrolid', 'timecreated', 'timemodified',
        ]);

        $promocodes = new backup_nested_element('promocodes');
        $promocode = new backup_nested_element('promocode', ['id'], [
            'code', 'tickettypeid', 'maxuses', 'timesused', 'timeexpires', 'timecreated',
        ]);

        $templates = new backup_nested_element('templates');
        $template = new backup_nested_element('template', ['id'], [
            'templatetype', 'content', 'contentformat', 'presentationinfoformat',
            'timecreated', 'timemodified',
        ]);

        $tickets = new backup_nested_element('tickets');
        $ticket = new backup_nested_element('ticket', ['id'], [
            'tickettypeid', 'userid', 'origin', 'promocodeid', 'qrtoken', 'timecreated',
            'timemodified',
        ]);

        $checkins = new backup_nested_element('checkins');
        $checkin = new backup_nested_element('checkin', ['id'], [
            'scannedby', 'timecreated',
        ]);

        // Build the tree. Ticket types come before promocodes/tickets -- both reference
        // tickettypeid, and restore must have already mapped this plugin's own
        // 'confcheckin_tickettype' ids by the time those siblings are processed.
        $confcheckin->add_child($tickettypes);
        $tickettypes->add_child($tickettype);

        $confcheckin->add_child($promocodes);
        $promocodes->add_child($promocode);

        $confcheckin->add_child($templates);
        $templates->add_child($template);

        $confcheckin->add_child($tickets);
        $tickets->add_child($ticket);

        $ticket->add_child($checkins);
        $checkins->add_child($checkin);

        // Define sources.
        $confcheckin->set_source_table('confcheckin', ['id' => backup::VAR_ACTIVITYID]);
        $tickettype->set_source_table(
            'confcheckin_tickettype',
            ['confcheckin' => backup::VAR_PARENTID],
            'sortorder ASC'
        );
        $promocode->set_source_table('confcheckin_promocode', ['confcheckin' => backup::VAR_PARENTID]);
        $template->set_source_table('confcheckin_template', ['confcheckin' => backup::VAR_PARENTID]);

        // The rest only happen if we are including user info.
        if ($userinfo) {
            $ticket->set_source_table('confcheckin_ticket', ['confcheckin' => backup::VAR_PARENTID]);
            $checkin->set_source_table('confcheckin_checkin', ['ticketid' => backup::VAR_PARENTID]);
        }

        // Define id annotations.
        $ticket->annotate_ids('user', 'userid');
        $checkin->annotate_ids('user', 'scannedby');

        // Define file annotations.
        $confcheckin->annotate_files('mod_confcheckin', 'intro', null);

        // Return the root element, wrapped into standard activity structure.
        return $this->prepare_activity_structure($confcheckin);
    }
}

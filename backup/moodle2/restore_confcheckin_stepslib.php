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
 * Defines the restore structure for mod_confcheckin.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Structure step to restore one confcheckin activity.
 *
 * confprogramcmid is a cross-activity reference into a sibling mod_confprogram
 * instance in the same course backup -- NOT a value this step can resolve during its
 * own main structure processing, since restore does not guarantee that sibling has
 * already been restored by the time this step's process_confcheckin() runs (activities
 * are restored in whatever order the backup file lists them, not in dependency order).
 * groupid/enrolid/eligibilitygroupid/eligibilityenrolid/addtogroupid on a ticket type reference
 * course-level groups/enrolment methods (core's own 'group'/'enrol' restore mappings,
 * set by course-level restore steps that in practice run before any activity's own
 * structure step, but treated here with the same after_restore()-deferred caution as
 * the genuinely cross-activity references, for consistency and safety). Every activity's
 * main structure step completes before ANY activity's after_restore() runs, so that IS
 * the safe place to resolve all of these -- this class inserts every affected row with
 * its OLD (unmapped) value during the main pass, then fixes them all up in
 * after_restore() below.
 *
 * paymentaccountid is deliberately NEVER remapped -- see
 * backup_confcheckin_stepslib.php's docblock for why.
 */
class restore_confcheckin_activity_structure_step extends restore_activity_structure_step {
    /**
     * Defines the confcheckin activity structure for restore.
     *
     * @return array The restore_path_element[] paths, wrapped into standard activity structure
     */
    protected function define_structure() {
        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('confcheckin', '/activity/confcheckin');
        $paths[] = new restore_path_element('confcheckin_tickettype', '/activity/confcheckin/tickettypes/tickettype');
        $paths[] = new restore_path_element('confcheckin_promocode', '/activity/confcheckin/promocodes/promocode');
        $paths[] = new restore_path_element('confcheckin_template', '/activity/confcheckin/templates/template');

        if ($userinfo) {
            $paths[] = new restore_path_element('confcheckin_ticket', '/activity/confcheckin/tickets/ticket');
            $paths[] = new restore_path_element(
                'confcheckin_checkin',
                '/activity/confcheckin/tickets/ticket/checkins/checkin'
            );
        }

        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restores the main confcheckin instance record. confprogramcmid is left as its
     * old (unmapped) value here -- see this class's docblock -- and corrected in
     * after_restore().
     *
     * @param array|stdClass $data The parsed confcheckin element
     * @return void
     */
    protected function process_confcheckin($data) {
        global $DB;

        $data = (object) $data;
        $data->course = $this->get_courseid();

        $newitemid = $DB->insert_record('confcheckin', $data);
        $this->apply_activity_instance($newitemid);
    }

    /**
     * Restores a ticket type. groupid/enrolid/eligibilitygroupid/eligibilityenrolid are
     * left as their old (unmapped) values here -- see this class's docblock -- and
     * corrected in after_restore(). Records its own old-to-new id mapping, required by
     * process_confcheckin_promocode()/process_confcheckin_ticket() to resolve
     * tickettypeid against.
     *
     * @param array|stdClass $data The parsed tickettype element
     * @return void
     */
    protected function process_confcheckin_tickettype($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->confcheckin = $this->get_new_parentid('confcheckin');
        $data->validfrom = $data->validfrom !== null ? $this->apply_date_offset($data->validfrom) : null;
        $data->validto = $data->validto !== null ? $this->apply_date_offset($data->validto) : null;

        // A backup made before maxperuser existed (or before it joined the backup
        // field list -- FABLE.md review, 2026-07-09: it was omitted, so every
        // restore silently converted "unlimited"/custom caps to the column
        // default of 1) has no maxperuser element. Restore such rows as NULL
        // (unlimited), the behaviour those ticket types actually had.
        if (!property_exists($data, 'maxperuser') || $data->maxperuser === '') {
            $data->maxperuser = null;
        }

        $newitemid = $DB->insert_record('confcheckin_tickettype', $data);
        $this->set_mapping('confcheckin_tickettype', $oldid, $newitemid);
    }

    /**
     * Restores a promo code. tickettypeid is resolved via the 'confcheckin_tickettype'
     * mapping set above -- tickettypes are always restored first (see
     * backup_confcheckin_stepslib.php's docblock), so this mapping is guaranteed to
     * already exist. Records its own old-to-new id mapping, required by
     * process_confcheckin_ticket() to resolve promocodeid against.
     *
     * @param array|stdClass $data The parsed promocode element
     * @return void
     */
    protected function process_confcheckin_promocode($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->confcheckin = $this->get_new_parentid('confcheckin');
        $data->tickettypeid = $this->get_mappingid('confcheckin_tickettype', $data->tickettypeid);
        $data->timeexpires = $data->timeexpires !== null ? $this->apply_date_offset($data->timeexpires) : null;

        $newitemid = $DB->insert_record('confcheckin_promocode', $data);
        $this->set_mapping('confcheckin_promocode', $oldid, $newitemid);
    }

    /**
     * Restores a badge/ticket/receipt/certificate template.
     *
     * @param array|stdClass $data The parsed template element
     * @return void
     */
    protected function process_confcheckin_template($data) {
        global $DB;

        $data = (object) $data;
        $data->confcheckin = $this->get_new_parentid('confcheckin');

        $DB->insert_record('confcheckin_template', $data);
    }

    /**
     * Restores an issued ticket. tickettypeid/promocodeid are resolved via this
     * plugin's own mappings, set above (tickettypes/promocodes are always restored
     * before tickets -- see backup_confcheckin_stepslib.php's docblock). qrtoken is
     * deliberately regenerated, never carried over from the backup: it carries a
     * sitewide UNIQUE constraint (see install.xml), so restoring the identical value
     * would collide outright if the original course (or an earlier restore of the same
     * backup) still exists on the same site: and even where no collision would occur,
     * reusing the exact same secret would let a physically-printed badge from the
     * original ticket check in against the RESTORED copy's activity too, which a
     * restore should not do -- the restored ticket is a new, independent copy.
     * Records its own old-to-new id mapping, required by
     * process_confcheckin_checkin() to resolve its parent ticket via
     * get_new_parentid('confcheckin_ticket').
     *
     * @param array|stdClass $data The parsed ticket element
     * @return void
     */
    protected function process_confcheckin_ticket($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->confcheckin = $this->get_new_parentid('confcheckin');
        $data->tickettypeid = $this->get_mappingid('confcheckin_tickettype', $data->tickettypeid);
        if (!empty($data->promocodeid)) {
            $data->promocodeid = $this->get_mappingid('confcheckin_promocode', $data->promocodeid) ?: null;
        }
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->qrtoken = \mod_confcheckin\local\ticket_service::generate_qrtoken();

        $newitemid = $DB->insert_record('confcheckin_ticket', $data);
        $this->set_mapping('confcheckin_ticket', $oldid, $newitemid);
    }

    /**
     * Restores a check-in event for a ticket.
     *
     * @param array|stdClass $data The parsed checkin element
     * @return void
     */
    protected function process_confcheckin_checkin($data) {
        global $DB;

        $data = (object) $data;
        $data->ticketid = $this->get_new_parentid('confcheckin_ticket');
        $data->scannedby = $this->get_mappingid('user', $data->scannedby);

        $DB->insert_record('confcheckin_checkin', $data);
    }

    /**
     * Restores files attached to the confcheckin intro.
     *
     * @return void
     */
    protected function after_execute() {
        $this->add_related_files('mod_confcheckin', 'intro', null);
    }

    /**
     * Fixes up every cross-activity/course-level reference now that ALL activities in
     * this course restore have completed their main structure step (see this class's
     * docblock for why this can only safely happen here), and recomputes each ticket
     * type's soldcount from the actually-restored confcheckin_ticket rows (it must not
     * simply carry over the backed-up value, which would overstate usage whenever
     * 'userinfo' was off and no ticket rows travelled at all).
     *
     * @return void
     */
    protected function after_restore() {
        global $DB;

        $confcheckinid = $this->task->get_activityid();

        $confcheckin = $DB->get_record('confcheckin', ['id' => $confcheckinid], '*', MUST_EXIST);
        if ($confcheckin->confprogramcmid !== null) {
            $newcmid = $this->get_mappingid('course_module', $confcheckin->confprogramcmid);
            // Null (this column is nullable, unlike the sibling plugins' equivalent
            // required links) when the linked mod_confprogram instance wasn't included
            // in this backup/restore -- a confcheckin instance with no link simply has
            // no usable presenteronly ticket types, a real, already-supported state (see
            // classes/local/eligibility.php's docblock), not a broken one.
            $DB->set_field('confcheckin', 'confprogramcmid', $newcmid ?: null, ['id' => $confcheckinid]);
        }

        $tickettypes = $DB->get_records('confcheckin_tickettype', ['confcheckin' => $confcheckinid]);
        foreach ($tickettypes as $tickettype) {
            foreach (['groupid', 'enrolid', 'eligibilitygroupid', 'eligibilityenrolid', 'addtogroupid'] as $field) {
                if (empty($tickettype->$field)) {
                    continue;
                }
                $mappingname = in_array($field, ['groupid', 'eligibilitygroupid', 'addtogroupid'], true) ? 'group' : 'enrol';
                $newid = $this->get_mappingid($mappingname, $tickettype->$field);
                $DB->set_field('confcheckin_tickettype', $field, $newid ?: null, ['id' => $tickettype->id]);
            }

            $soldcount = $DB->count_records('confcheckin_ticket', ['tickettypeid' => $tickettype->id]);
            $DB->set_field('confcheckin_tickettype', 'soldcount', $soldcount, ['id' => $tickettype->id]);
        }
    }
}

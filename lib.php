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
 * Library functions for mod_confcheckin.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Returns the features this module supports.
 *
 * FEATURE_BACKUP_MOODLE2 is deliberately not claimed yet: no backup/restore
 * steps have been written for this plugin's tables (which, once Phases
 * 4.3-4.5 land, will include payment/ticket/check-in records with real
 * personal-data implications for backup/restore). Add the backup/restore
 * steplibs before flipping this to true.
 *
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed True if module supports feature, false if not, null if doesn't know
 */
function confcheckin_supports($feature) {
    return match ($feature) {
        FEATURE_MOD_INTRO        => true,
        FEATURE_SHOW_DESCRIPTION => true,
        FEATURE_BACKUP_MOODLE2   => false, // Not yet implemented; set true once backup/restore steps exist.
        FEATURE_GRADE_HAS_GRADE  => false,
        FEATURE_MOD_PURPOSE      => MOD_PURPOSE_OTHER,
        default                  => null,
    };
}

/**
 * Adds a new instance of the confcheckin activity.
 *
 * @param stdClass $data Data from the settings form
 * @param mod_confcheckin_mod_form|null $form The form instance
 * @return int The id of the newly inserted record
 */
function confcheckin_add_instance(stdClass $data, ?mod_confcheckin_mod_form $form = null) {
    global $DB;

    $data->timecreated  = time();
    $data->timemodified = $data->timecreated;

    if (!isset($data->intro)) {
        $data->intro = '';
    }
    if (!isset($data->introformat)) {
        $data->introformat = FORMAT_HTML;
    }

    return $DB->insert_record('confcheckin', $data);
}

/**
 * Updates an existing instance of the confcheckin activity.
 *
 * @param stdClass $data Data from the settings form
 * @param mod_confcheckin_mod_form|null $form The form instance
 * @return bool
 */
function confcheckin_update_instance(stdClass $data, ?mod_confcheckin_mod_form $form = null) {
    global $DB;

    $data->timemodified = time();
    $data->id = $data->instance;

    return $DB->update_record('confcheckin', $data);
}

/**
 * Deletes an instance of the confcheckin activity and all associated data.
 *
 * Moodle's DDL layer never creates DB-enforced foreign key constraints on any
 * supported driver, so no real database would reject an out-of-order delete
 * here. The order below still matters for a different reason: this instance's
 * confcheckin_ticket ids are collected before confcheckin_ticket itself is
 * deleted, because confcheckin_checkin rows (which hold scannedby personal
 * data) are looked up by ticketid -- deleting the tickets first would leave
 * no way to identify which check-in rows belong to this instance, orphaning
 * them with no cleanup path.
 *
 * @param int $id The instance id
 * @return bool
 */
function confcheckin_delete_instance($id) {
    global $DB;

    if (!$confcheckin = $DB->get_record('confcheckin', ['id' => $id])) {
        return false;
    }

    $ticketids = $DB->get_fieldset_select(
        'confcheckin_ticket',
        'id',
        'confcheckin = :confcheckin',
        ['confcheckin' => $id]
    );
    if ($ticketids) {
        [$insql, $params] = $DB->get_in_or_equal($ticketids);
        $DB->delete_records_select('confcheckin_checkin', "ticketid $insql", $params);
    }

    $DB->delete_records('confcheckin_ticket', ['confcheckin' => $id]);
    $DB->delete_records('confcheckin_promocode', ['confcheckin' => $id]);
    $DB->delete_records('confcheckin_tickettype', ['confcheckin' => $id]);
    $DB->delete_records('confcheckin_template', ['confcheckin' => $id]);

    $DB->delete_records('confcheckin', ['id' => $id]);

    return true;
}

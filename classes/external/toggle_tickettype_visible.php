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

namespace mod_confcheckin\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_confcheckin\local\instance_helper;

/**
 * AJAX-only external function backing the "visible" switch on the Manage ticket
 * types page (tickettypes.php) -- lets an organiser quickly enable/disable a ticket
 * type without navigating to the edit form (user request, 2026-07-10).
 *
 * The tickettypeid is re-scoped to the cmid-derived instance via
 * instance_helper::require_tickettype_in_instance() before writing, the same
 * chain-of-custody discipline every id-taking write in this project's suite follows.
 *
 * Capability/context validation is done directly here (self::validate_context()),
 * not via instance_helper's require_manage() -- that helper's require_login($course,
 * true, $cm) is the page-script pattern (tickettypes.php etc.), not the AJAX/web
 * services pattern every other external function in this project's suite uses (see
 * mod_confscheduler\external\scheduler_context_trait for the same distinction).
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class toggle_tickettype_visible extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'         => new external_value(PARAM_INT, 'The confcheckin course-module id'),
            'tickettypeid' => new external_value(PARAM_INT, 'The confcheckin_tickettype id'),
            'visible'      => new external_value(PARAM_BOOL, 'The desired visible state'),
        ]);
    }

    /**
     * Sets (or unsets) a ticket type's visible flag.
     *
     * @param int $cmid The confcheckin course-module id
     * @param int $tickettypeid The confcheckin_tickettype id
     * @param bool $visible The desired visible state
     * @return array{visible: bool}
     */
    public static function execute(int $cmid, int $tickettypeid, bool $visible): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'         => $cmid,
            'tickettypeid' => $tickettypeid,
            'visible'      => $visible,
        ]);

        $cm = get_coursemodule_from_id('confcheckin', $params['cmid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        self::validate_context($context);
        require_capability('mod/confcheckin:managetickettypes', $context);

        $confcheckin = $DB->get_record('confcheckin', ['id' => $cm->instance], '*', MUST_EXIST);
        $tickettype = instance_helper::require_tickettype_in_instance(
            (int) $confcheckin->id,
            $params['tickettypeid']
        );

        $DB->set_field('confcheckin_tickettype', 'visible', $params['visible'] ? 1 : 0, ['id' => $tickettype->id]);

        return ['visible' => $params['visible']];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'visible' => new external_value(PARAM_BOOL, 'The visible state after this call'),
        ]);
    }
}

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
use mod_confcheckin\local\checkin_service;

/**
 * AJAX-only external function backing the QR scanner (scan.php/amd/src/scanner.js):
 * records a check-in for the ticket identified by a scanned/typed QR token.
 *
 * The confcheckinid passed to checkin_service::record_checkin() is derived here
 * from the validated cmid, never taken from client input -- the qrtoken itself is
 * client-supplied (that is the whole point: it is what was scanned), but
 * record_checkin() re-scopes the ticket it resolves to against this validated
 * instance id before recording anything.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class record_checkin extends external_api {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'    => new external_value(PARAM_INT, 'The confcheckin course-module id'),
            'qrtoken' => new external_value(PARAM_ALPHANUM, 'The scanned/typed QR token'),
        ]);
    }

    /**
     * Records a check-in.
     *
     * @param int $cmid The confcheckin course-module id
     * @param string $qrtoken The scanned/typed QR token
     * @return array{ticketid: int, fullname: string, tickettype: string, alreadycheckedin: bool}
     */
    public static function execute(int $cmid, string $qrtoken): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'qrtoken' => $qrtoken]);

        $cm = get_coursemodule_from_id('confcheckin', $params['cmid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        self::validate_context($context);
        require_capability('mod/confcheckin:scancheckin', $context);

        $confcheckin = $DB->get_record('confcheckin', ['id' => $cm->instance], '*', MUST_EXIST);

        return checkin_service::record_checkin((int) $confcheckin->id, $params['qrtoken'], (int) $USER->id);
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ticketid'         => new external_value(PARAM_INT, 'The confcheckin_ticket id'),
            'fullname'         => new external_value(PARAM_TEXT, 'The ticket holder\'s full name'),
            'tickettype'       => new external_value(PARAM_TEXT, 'The ticket type name'),
            'alreadycheckedin' => new external_value(PARAM_BOOL, 'Whether this ticket was already checked in before this scan'),
        ]);
    }
}
